<?php

declare(strict_types=1);

namespace AA\Isds;

use AA\IO\DB\DB_AA;
use AA\Isds\Registry\Sync;
use AA\Later\BatchFailed;
use AA\Util\FormToken;
use AA\Util\LogOnce;
use AA_Credentials;
use AA_Items;
use AA_Module_Site;
use AA_Set;
use ItemContent;
use zids;

/**
 * {datovkabulk:page:<settings>:{(<UI>)}:<refusal>} - the page of a mass sending. Opens the two slices
 * only on a page the site module renders of its own site, to a logged-in user AA lets send (Access),
 * with settings Setup accepts for that site; only then expands the UI the template deferred. Everyone
 * else gets the refusal, the UI stays unexpanded and the slices stay closed. Once opened, the slices
 * stay readable to the rest of the request, never to a next one through the token of an {ajax}.
 * Inside the UI the other subcommands give the settings, the fields of a form, the recipients and
 * the progress of a sending, outside of it nothing.
 * Before the UI the gate runs the action a form of it posts (Actions), then sends the browser to the
 * same page with the key of the result (isds_r): a reload shows the result again and repeats nothing.
 * Only that page shows it, to the same user under the same binding, for FLASH_SECONDS; the same form
 * posted again in that time - a double click - does nothing more and shows it too. An open gate keeps the page out of
 * frames of other sites - or, when the headers went out before it (?debug), gives no form at all.
 *
 * What the template must keep in mind:
 * - Nothing before the gate should read the two slices: items and {view}s read before it are read
 *   again after it, but {define}, {array}, {table}, {dictionary} and the item of an enclosing {item}
 *   or {view} row keep what they read masked. Even inside the gate {dictionary} may serve a copy an
 *   earlier request stored in the page cache. Nor may the page be the page of an item of the two
 *   slices: site.php reads the routed item before any spot.
 * - A UI not wrapped in {(...)} is expanded before the gate decides - and once more by it.
 * - {(...)} ends at the first ")}" - a ")}" in the UI (inline JavaScript) ends the deferral early.
 */
class Gate
{
    /** log.type: settings of a page that are not ready, once a day */
    public const LOG_EVENT = 'ISDS_SETUP';

    /** log.type: a campaign whose recipients cannot be told - its stored filter or the source slice is broken, once a day */
    public const RECIPIENTS_EVENT = 'ISDS_RCPT';

    /** log.type: a view name none of the views of the two slices has - the page shows nothing for it, once a day */
    public const VIEW_EVENT = 'ISDS_VIEW';
    /** the entry view of the page; the views it shows are named isds_... */
    public const ENTRY_VIEW = 'isdscampainer';

    /** the groups messages() sorts the messages of a campaign into */
    public const GROUPS = ['problem', 'checking', 'wait', 'sent', 'ok', 'stopped', 'skip', 'recent', 'test'];

    /** the actions a form may name - its token binds the action, the binding of the page and the campaign */
    public const ACTIONS = ['save', 'clone', 'test', 'send', 'stop', 'abandon', 'resume', 'verify', 'fixbox'];
    /** of ACTIONS those whose shown form is posted again on purpose (autosave): each post acts - unless
     *  it carries files, which a post again would attach again (reposted()) */
    public const REPOSTED = ['save'];

    /** $_SESSION key of the results kept for the page after an action, by their key */
    private const FLASH = 'aa_isds_result';
    /** a result older is not shown; newer ones are kept no more than FLASH_MAX */
    private const FLASH_SECONDS = 600;
    private const FLASH_MAX     = 10;

    /** what progress() tells */
    private const PROGRESS = [
        'state', 'total', 'sent', 'failed', 'skipped', 'uncertain', 'stopped', 'queued', 'until', 'delivered', 'accepted',
        'messages', 'attention', 'checking', 'pending', 'verify', 'stop_by', 'error',
    ];

    /** states of progress() in which the code of the run still tells why it waits or stopped */
    private const ERROR_SHOWN = [SendRun::SENDING, SendRun::ERROR, 'watching', 'watch_error'];

    /** items of a list the page shows at once; campaigns fewer, each a longer row */
    private const PAGE_SIZE      = 50;
    private const CAMPAIGNS_PAGE = 30;

    /** messages of the group recent of messages(): those the sending got to last */
    private const RECENT = 6;

    /** what filter() and source() tell */
    private const FILTER = ['types', 'membership', 'include', 'exclude', 'includepage', 'excludepage'];
    private const SOURCE = ['name', 'box', 'type', 'member', 'reason'];

    /** items search() finds at most, and the characters of the text it looks for */
    private const FOUND        = 20;
    private const QUERY_LENGTH = 100;

    /** what events() puts into its row for each entry of the timeline - the keys of the entry */
    private const EVENT_KEYS = ['AT' => 'at', 'KIND' => 'kind', 'CODE' => 'code', 'TEXT' => 'message', 'DMID' => 'dmID', 'BY' => 'by'];

    /** param() of each name: the values of isds_<name> of the URL it gives; page is a number */
    private const PARAMS = [
        'tab'  => ['history', 'check'],
        'show' => self::GROUPS,
        'step' => ['test', 'send', 'search', 'files'],
        'part' => ['progress', 'search'],
        'list' => ['get', 'skip'],
    ];

    /** the settings of the UI being expanded; null outside every gate */
    private static ?Setup $open = null;

    /** what an action did, for the UI being expanded: of this request, or kept for the page after it */
    private static ?ActionResult $shown = null;

    /** the UI being expanded goes out with the headers against framing - only then it gets forms */
    private static bool $guarded = false;

    /**
     * Items the UI being expanded listed or asked of: long id => false while not read, null for no such
     * item, else as readItems() gives it. A question of source() or events() reads all those not read yet at once.
     * @var array<string, false|null|array{slice: string, active: bool, values: array<string, list<string>>}>
     */
    private static array $items = [];

    /**
     * @param callable(string): string $expand expands the deferred UI - AA::Un() in the context of the template
     */
    public function page(string $settings, string $ui, string $refusal, callable $expand): string
    {
        $site   = $this->site();
        $access = $this->access();
        if (is_null($site) || !$access->loggedIn()) {
            return $refusal;
        }
        // what follows differs by the user
        $this->noStore();
        $setup = $this->setup($settings, $site);
        if ($setup instanceof SetupException) {
            $this->log($site . ':' . ($setup->campaignSlice ?: '-'), $setup->problems[0]->text ?? '');
            return $access->mayFix($site, $setup->campaignSlice) ? $this->problems($setup) : $refusal;
        }
        if (!$access->may($setup->campaignSlice)) {
            return $refusal;
        }
        // a frame of another site - a sibling *.ecn.cz too - could make the user click a form unseen
        \AA::$headers['XFO']             = 'X-Frame-Options: SAMEORIGIN';
        \AA::$headers['frame-ancestors'] = "'self'";
        // ?debug printed before the gate: the headers cannot go out any more. Printed after it - a {view}
        // of the UI -, they go out at once: so the one that matters goes now, not with the page at its end
        $guarded = !$this->headersSent();
        if ($guarded) {
            $this->sendHeader(\AA::$headers['XFO']);
        }
        AA_Credentials::singleton()->openForRequest($setup->campaignSlice, $setup->messageSlice);
        $result = $this->actions($access)->run($setup, $guarded);
        if ($result?->taken() ?? false) {
            $this->redirect(self::afterAction($result->campaign, $this->keep($setup, $access->uid(), $result)));
        }
        $outer         = [self::$open, self::$shown, self::$guarded, self::$items];
        self::$open    = $setup;
        self::$shown   = $result ?? $this->kept($setup, $access->uid());
        self::$guarded = $guarded;
        self::$items   = [];
        try {
            return $expand($ui);
        } finally {
            [self::$open, self::$shown, self::$guarded, self::$items] = $outer;
        }
    }

    /**
     * The hidden fields of a form asking for $action on the campaign: the token binds both and the
     * binding of the page, so a form of one action, campaign or page cannot be posted as another.
     * '' on a page that could not be kept out of frames; for abandon '' unless the run is one a stop no
     * longer ends (SendRun::abandonable()) - the template offers it in place of Stop then; for fixbox ''
     * unless the page could take a box from the register (fixable()) - the form adds isds_recipient and,
     * as isds_box, regbox() of the row.
     * The form of a new campaign and of a copy carries a nonce of its own: every post of the form shown
     * makes the one draft (Drafts::newId(), copyId()).
     * @param string $campaign long id; '' only to save a new campaign
     */
    public function fields(string $action, string $campaign = ''): string
    {
        $campaign = self::campaignOf($action, $campaign);
        if (is_null(self::$open) || !self::$guarded || !in_array($action, self::ACTIONS, true) || is_null($campaign)) {
            return '';
        }
        if (($action === 'abandon') && (($this->progressOf(self::$open, $campaign)['abandon'] ?? '') !== '1')) {
            return '';
        }
        if (($action === 'fixbox') && !$this->fixable(self::$open)) {
            return '';
        }

        return getFrmHiddenEasy('isds_action', $action)
            . getFrmHiddenEasy('isds_campaign', $campaign)
            . getFrmHiddenEasy('isds_token', FormToken::get(self::purpose($action, self::$open, $campaign)))
            // the token is the same in every form of the action: this tells one shown form from another
            . getFrmHiddenEasy('isds_form', bin2hex(random_bytes(8)))
            // a new draft and a copy take their id from it; any other form names its campaign
            . ((($campaign === '') || ($action === 'clone')) ? getFrmHiddenEasy('isds_nonce', bin2hex(random_bytes(16))) : '');
    }

    /**
     * The campaign the page was sent to after an action (isds_campaign of its URL), when it is an item
     * of the campaign slice of the page: the template learns the id of a draft saved new. '' otherwise.
     */
    public function campaign(): string
    {
        return safe($this->pageCampaign() ?? '');
    }

    /** campaign() unescaped, in small letters; null = none */
    private function pageCampaign(): ?string
    {
        $id = ModuleId::normalize(is_string($_GET['isds_campaign'] ?? null) ? $_GET['isds_campaign'] : '');

        return (is_null(self::$open) || is_null($id) || is_null($this->campaignOfPage(self::$open, $id))) ? null : $id;
    }

    /**
     * View $name of the two slices of the page, as {view} shows it (AA_Stringexpand_View::output()). Found
     * by its name among the views of the two slices only, so that a view of another slice of the site
     * cannot pass for it; a name two of them have shows nothing (State Check names it - CheckIsdsViews),
     * a name none has neither (logged, State Check lists it). A listing shows all the items $ids names and only them - the ids
     * a subcommand of the gate gives -, in their order: none for no ids, never the slice by its
     * conditions, which cannot tell the campaign of the page. A static view takes no ids. Only the names
     * pageView() allows, so that State Check sees every name the page may show.
     * @param string $ids      dash-separated long ids; anything else in it is left out
     * @param string $settings as the third parameter of {view} - from the text of the template only,
     *                         never from the URL; what would page, sort or group the ids does not count
     */
    public function view(string $name, string $ids = '', string $settings = ''): string
    {
        $setup = self::$open;
        if (is_null($setup) || !self::pageView($name)) {
            return '';
        }
        $vids = $this->once('views', fn(): array => $this->viewIds($setup, $name), $setup, $name);
        if (!$vids) {
            $this->log("$setup->site:$name", 'No view of the slices of the page has this name', self::VIEW_EVENT);
        }
        $listed = array_values(array_filter(array_map(static fn(string $id): ?string => ModuleId::normalize($id), explode_ids($ids))));
        // read at the first question of a row (source(), events()), all at once
        self::$items += array_fill_keys($listed, false);

        return (count($vids) === 1) ? $this->showView((int) $vids[0], implode('-', $listed), ($settings === '') ? null : $settings) : '';
    }

    public static function pageView(string $name): bool
    {
        return ($name === self::ENTRY_VIEW) || (str_starts_with($name, 'isds_') && ($name !== 'isds_'));
    }

    /**
     * The campaigns of the page in the active bin, the last edited first: count - how many, else the
     * dash-separated ids of the page of the list param:page shows, for {datovkabulk:view}
     */
    public function campaigns(string $what = ''): string
    {
        $setup = self::$open;
        if (is_null($setup) || !in_array($what, ['', 'count'], true)) {
            return '';
        }
        $ids = $this->once('campaigns', fn(): array => $this->campaignIds($setup), $setup);

        return ($what === 'count') ? (string) count($ids) : self::pageOf($ids, self::CAMPAIGNS_PAGE);
    }

    /**
     * The message items of a group of the campaign the page shows (campaign()) - count: how many, else
     * their dash-separated ids for {datovkabulk:view}, of the page param:page shows. From the same reading
     * of the run as progress(), so that a group holds what it counts (SendMaps::fatesOf()); each by its
     * recipient's name, as the recipients were frozen:
     * - problem: failed, and with progress verify uncertain too (attention);
     * - checking: uncertain before verify;
     * - wait: queued, and while the delivery is followed sent not delivered yet (queued + pending);
     * - sent: sent while the delivery is not followed (sent; pending is '' then);
     * - ok: delivered (delivered); stopped; skip: skipped for the box (skipped);
     * - recent: the last six the sending got to, the one in flight too, the last first - never paged;
     * - test: the test messages of the campaign in the active bin, the newest first - a run does not
     *   know them; never paged, a page shows them beside a paged list.
     * '' with no run, of a campaign that is not of the page, of an unknown group, and for a record that
     * cannot be read; test without a run too.
     */
    public function messages(string $group, string $what = ''): string
    {
        $setup = self::$open;
        $id    = $this->pageCampaign();
        if (is_null($setup) || is_null($id) || !in_array($group, self::GROUPS, true) || !in_array($what, ['', 'count'], true)) {
            return '';
        }
        if ($group === 'test') {
            $ids = $this->once('tests', fn(): array => $this->testMessages($setup, $id), $setup, $id);
        } else {
            $ids = $this->once('messages', fn(): ?array => $this->campaignMessages($setup, $id), $setup, $id)[$group] ?? null;
            if (is_null($ids)) {
                // '' tells no count from 0, as progress()
                return '';
            }
        }
        if ($what === 'count') {
            return (string) count($ids);
        }

        return in_array($group, ['recent', 'test'], true) ? safe(implode('-', $ids)) : self::pageOf($ids, self::PAGE_SIZE);
    }

    /**
     * A value of the URL of the page the template may branch on: isds_<name> when it is one of PARAMS,
     * else ''; page - the page of a list, a number of up to four digits, 1 for anything else. The
     * template never reads the URL itself ({qs}): it could put anything there.
     */
    public function param(string $name): string
    {
        if (is_null(self::$open)) {
            return '';
        }
        if ($name === 'page') {
            return (string) self::pageNumber();
        }
        $value = $_GET["isds_$name"] ?? null;

        return (is_string($value) && in_array($value, self::PARAMS[$name] ?? [], true)) ? $value : '';
    }

    /** the page of a list the URL asks for (isds_page), the first one unless it names one */
    private static function pageNumber(): int
    {
        $page = $_GET['isds_page'] ?? null;

        return (is_string($page) && (preg_match('/^\d{1,4}\z/', $page) === 1)) ? max(1, (int) $page) : 1;
    }

    /**
     * @param list<string> $ids
     * @return string the dash-separated ids of the page pageNumber() of $ids, $size a page, escaped
     */
    private static function pageOf(array $ids, int $size): string
    {
        return safe(implode('-', array_slice($ids, (self::pageNumber() - 1) * $size, $size)));
    }

    /**
     * The purpose of the token of a form: the action, the binding of the page and the campaign - two
     * pages of one campaign slice, a test one and a production one, take no form of each other
     * @param string $campaign long id in small letters
     */
    public static function purpose(string $action, Setup $setup, string $campaign): string
    {
        return "isds:$action:{$setup->bindingKey()}:$campaign";
    }

    /** the campaign a form of $action names, in small letters: '' only to save a new one; null = none */
    public static function campaignOf(string $action, string $campaign): ?string
    {
        return (($campaign === '') && ($action === 'save')) ? '' : ModuleId::normalize($campaign);
    }

    /** Account::TEST or Account::PRODUCTION - from datovka_conf.php, never from the text of the template */
    public function env(): string
    {
        return safe(self::$open->environment ?? '');
    }

    /** the box the page sends from, when AA knows it without asking ISDS; '' otherwise */
    public function sender(): string
    {
        return safe(self::$open->senderBox ?? '');
    }

    /**
     * What the action did, escaped: text - in the language of the page, how to go on, with what the
     * core said and what a test message cost -, or its code, status, action or charge (what a test
     * message cost) for the conditions of a template. '' with no result: after the redirect only the
     * page with its key (isds_r) shows it, a reload of the progress or another tab does not.
     */
    public function result(string $what = 'text'): string
    {
        $result = is_null(self::$open) ? null : self::$shown;
        if (is_null($result)) {
            return '';
        }

        return safe(match ($what) {
            '', 'text' => Advice::join(Advice::text($result->code, $result->params), trim($result->detail), Advice::text($result->charge)),
            'code'     => $result->code,
            'status'   => $result->status,
            'action'   => $result->action,
            'charge'   => $result->charge,
            default    => '',
        });
    }

    /**
     * What a code means to the person on the page, escaped: Advice::alone() of the code; with run
     * Advice::ofRun() - of progress error; not of Schema::CAMPAIGN_ERROR_TEXT, which outlives the state it
     * held in (an abandoned campaign keeps a code that asks for Continue). The code
     * isds with a status (advice:isds:<status>, Schema::MESSAGE_ERROR_CODE of digits) - what that refusal
     * of ISDS means. '' for a code Advice does not know, outside the gate, and without run for a code
     * whose text names a value the template cannot give (QUEUED, TEST_SENT, TEST_UNCERTAIN, SAME_LETTER,
     * COUNT_CHANGED, TOO_MANY, CONTENT) - {datovkabulk:result} tells those with it.
     * @param string $when run, the status for isds, else ''
     */
    public function advice(string $code, string $when = ''): string
    {
        if (is_null(self::$open)) {
            return '';
        }

        return safe(match (true) {
            $code === 'isds' => (preg_match('/^\d{1,5}\z/', $when) === 1) ? Advice::isds($when) : '',
            $when === ''     => Advice::alone($code),
            $when === 'run'  => Advice::ofRun($code),
            default          => '',
        });
    }

    /** the test boxes of the page, dash-separated for a <select> of the test form (its field isds_box) */
    public function testBoxes(): string
    {
        return safe(implode('-', self::$open->testBoxes ?? []));
    }

    /**
     * The recipients of an active campaign of the page by its stored filter, counted anew on each
     * request: count - how many get the letter, skipped - how many are left out for their box; ids,
     * skippedids - the items that get it, or are left out, of the page of the list param:page shows,
     * in the order they are sent in, dash-separated for {datovkabulk:view}. It counts a queued or sent
     * campaign anew too; its count when it was queued is stored in the campaign
     * (Schema::CAMPAIGN_RECIPIENTS). '' for another item or question, and on any error.
     * @param string $campaign long id of an active item of the campaign slice
     */
    public function recipients(string $campaign, string $what): string
    {
        $setup = self::$open;
        $id    = ModuleId::normalize($campaign);
        if (is_null($setup) || is_null($id) || !in_array($what, ['count', 'skipped', 'ids', 'skippedids'], true)) {
            return '';
        }
        $recipients = $this->recipientsOf($setup, $id);
        if (is_null($recipients)) {
            return '';
        }
        $sendable = array_filter($recipients, static fn(Recipient $r): bool => $r->sendable());
        $ids      = static fn(array $rs): array => array_values(array_map(static fn(Recipient $r): string => $r->itemId, $rs));

        return match ($what) {
            'count'      => (string) count($sendable),
            'skipped'    => (string) (count($recipients) - count($sendable)),
            'ids'        => self::pageOf($ids($sendable), self::PAGE_SIZE),
            default      => self::pageOf($ids(array_diff_key($recipients, $sendable)), self::PAGE_SIZE),
        };
    }

    /**
     * The recipient filter of a campaign of the page in the active bin, as recipients() takes it:
     * types - comma-separated (Setup takes no comma in a type) -, membership; include, exclude - all
     * their ids, dash-separated, for the hidden fields of the draft form (isds_include, isds_exclude):
     * saving the form stores them as the whole filter; includepage, excludepage - only the page of them
     * param:page shows, 50 a page, for the chips of a {datovkabulk:view}. Ids only of the active items of
     * the source slice: a stored id of another item is left out. Of no campaign ('') the filter a new
     * one starts with. '' for another item or question, and for a stored filter that cannot be read
     * (logged as by recipients()) - '' then never means the filter a new campaign starts with.
     * @param string $campaign long id; '' = a new campaign
     */
    public function filter(string $campaign, string $what): string
    {
        $setup = self::$open;
        $id    = ($campaign === '') ? '' : ModuleId::normalize($campaign);
        if (is_null($setup) || is_null($id) || !in_array($what, self::FILTER, true)) {
            return '';
        }
        $filter = ($id === '') ? self::filterOf($setup, '') : $this->storedFilter($setup, $id);
        if (is_null($filter)) {
            return '';
        }

        return match ($what) {
            'types'       => safe(implode(',', $filter->types)),
            'membership'  => safe($filter->membership),
            'include'     => safe(implode('-', $this->ofSource($setup, $filter->include))),
            'exclude'     => safe(implode('-', $this->ofSource($setup, $filter->exclude))),
            'includepage' => self::pageOf($this->ofSource($setup, $filter->include), self::PAGE_SIZE),
            default       => self::pageOf($this->ofSource($setup, $filter->exclude), self::PAGE_SIZE),
        };
    }

    /**
     * What the page shows of an active item of the source slice: name; box - its data box field as
     * typed, trimmed; type - the label the page gives its type, the type itself when the page offers none;
     * member - 1 or 0, '' when the page tells no members; reason - why the campaign the page shows
     * (campaign()) skips it: missing, format or duplicate, '' when it gets the letter or is none of its
     * recipients. Escaped; '' for any other item or question. The first question reads at once every
     * item the listings of the UI got so far ({datovkabulk:view}).
     * @param string $item long id
     */
    public function source(string $item, string $what): string
    {
        $setup  = self::$open;
        $id     = ModuleId::normalize($item);
        $config = is_null($setup) ? null : $this->rules()->registryConfig($setup->sourceSlice);
        if (is_null($setup) || is_null($id) || is_null($config) || !in_array($what, self::SOURCE, true)) {
            return '';
        }
        $found = $this->item($id);
        if (is_null($found) || ($found['slice'] !== $setup->sourceSlice) || !$found['active']) {
            return '';
        }
        $first = static fn(string $field): string => ($field === '') ? '' : trim($found['values'][$field][0] ?? '');

        return self::text(match ($what) {
            'name'   => $first($config->nameField()),
            'box'    => $first($config->dsField()),
            'type'   => self::typeLabel($setup, $first($config->typeField())),
            'member' => ($setup->memberField === '') ? '' : (Recipients::isMember($first($setup->memberField)) ? '1' : '0'),
            default  => $this->skipReasons($setup)[$id] ?? '',
        });
    }

    /**
     * Active items of the source slice of the types the page offers whose name holds isds_q of the URL
     * - up to 100 characters, % and _ as typed, a backslash left out (Recipients::named()) -, by name:
     * the first 20, dash-separated for {datovkabulk:view}. '' for no text, a longer one, or one that is
     * not UTF-8.
     */
    public function search(): string
    {
        $setup = self::$open;
        $text  = $_GET['isds_q'] ?? null;
        if (is_null($setup) || !is_string($text) || !mb_check_encoding($text, 'UTF-8') || (mb_strlen($text) > self::QUERY_LENGTH) || (trim($text) === '')) {
            return '';
        }
        $text = trim($text);
        $ids  = $this->once('search', function () use ($setup, $text): array {
            $config = $this->rules()->registryConfig($setup->sourceSlice);

            return is_null($config) ? [] : $this->resolver()->named($setup->sourceSlice, $config, array_map('strval', array_keys($setup->types)), $text, self::FOUND);
        }, $setup, $text);

        return safe(implode('-', $ids));
    }

    /**
     * The message the page shows (isds_msg of its URL), when it is an item of the message slice of the
     * campaign the page shows (campaign()); '' otherwise
     */
    public function message(): string
    {
        $setup = self::$open;
        $id    = ModuleId::normalize(is_string($_GET['isds_msg'] ?? null) ? $_GET['isds_msg'] : '');

        return (is_null($setup) || is_null($id) || is_null($this->pageMessage($setup, $id))) ? '' : safe($id);
    }

    /**
     * The timeline of a message of the campaign the page shows, the newest first - an entry of unknown
     * time (at 0) at the end; entries of one time in the order AA learnt of them. Each entry makes $row
     * expanded with _#AT (unix time, '' when unknown), _#KIND, _#CODE, _#TEXT, _#DMID and _#BY (the
     * name of who verified it) put in, in one pass - an alias that only begins so (_#TEXTO___) stays.
     * The values are escaped, so that nothing of them expands. Write $row as {(...)}, so that what uses
     * them expands after they are in - and mind two traps:
     * - a {(...)} inside $row hides them: the outer one is swapped for a reference first; use {-...},
     *   or _#1 of {ifset:_#TEXT:...};
     * - {date:<format>:_#AT} shows today for an unknown time: give it the third parameter,
     *   {date:j. n. Y H\:i:_#AT:unknown}.
     * '' for another item.
     * @param string                   $message long id
     * @param callable(string): string $expand  expands a row - AA::Un() in the context of the template
     */
    public function events(string $message, string $row, callable $expand): string
    {
        $setup = self::$open;
        $id    = ModuleId::normalize($message);
        $item  = (is_null($setup) || is_null($id)) ? null : $this->pageMessage($setup, $id);
        if (is_null($item)) {
            return '';
        }
        $at       = static fn(array $entry): int => is_int($entry['at'] ?? null) ? $entry['at'] : 0;
        $timeline = SendItems::timeline($item['values'][Schema::MESSAGE_EVENTS][0] ?? '');
        // usort() keeps the order of equal ones
        usort($timeline, static fn(array $a, array $b): int => (($at($a) === 0) <=> ($at($b) === 0)) ?: ($at($b) <=> $at($a)));
        $out = '';
        foreach ($timeline as $entry) {
            $values         = array_map(static fn(string $key): string => (string) ($entry[$key] ?? ''), self::EVENT_KEYS);
            $values['AT']   = ($at($entry) === 0) ? '' : (string) $at($entry);
            $values['BY']   = self::verifiedBy($values['BY']);
            $values         = array_map(static fn(string $v): string => QuoteColons(self::text($v)), $values);
            $out           .= $expand((string) preg_replace_callback('/_#(AT|KIND|CODE|TEXT|DMID|BY)(?![A-Za-z0-9_])/', static fn(array $m): string => $values[$m[1]], $row));
        }

        return $out;
    }

    /**
     * How far the sending of a campaign of the page got, read anew on each request from the record of
     * its newest run (SendRun::progressFigures()), never from the message items or ISDS. state: while
     * the campaign has no run, the state of its item; then the run's - sending (queued, working or
     * waiting), error (sending waits for Continue - one stopped already or with every message tried
     * for Continue or Abandon, fields()), abandoned (sending given up in error); once the
     * sending is over (a stopped one too) watching (the delivery is followed, waiting for its rounds
     * too - not that every message went), watch_error (the following waits for Continue or Abandon);
     * done at the end, the following given up too. A sending a person stopped is watching first when
     * it sent anything, then done, what it left unsent counted as stopped - only once nothing sends it
     * any more (SendRun::isOver()), so a template offers the copy for the unsent by stopped > 0 alone,
     * in any state; the copy takes the same recipients. total: the recipients frozen, skipped ones too - sent,
     * failed (refused, not found by the lookup, or sent and then not delivered), uncertain, skipped,
     * stopped and queued add up to it. delivered and accepted: of the messages sent, 0 while the
     * delivery is not followed (SendMaps::delivery()). until: the unix time a pause of sending waits
     * till. Counted of the same figures, so that a template counts nothing itself:
     * - messages: those that go - sent, failed, uncertain, stopped, queued (total less skipped);
     * - verify: '1' once nothing looks up a message of unknown fate any more (SendRun::verifiable()) -
     *   offer Verify of an uncertain message then, never before;
     * - attention: failed, and with verify uncertain too - a person verifies those. A message verified
     *   moves from uncertain to failed, so attention stays: tell that it may be sent again (a copy);
     * - checking: uncertain before verify. While the sending runs it finds out itself; in error it waits
     *   for Continue - say so, not that it is being checked; after Abandon AA still checks them for the
     *   few minutes until the run closes;
     * - pending: sent and not delivered yet, '' while the delivery is not followed; once the following
     *   ended (done) these were never delivered;
     * - stop_by: the name of who stopped the sending, escaped already - do not wrap it in {safe:}; ''
     *   for a sending given up (Abandon) that no one stopped before;
     * - error: the code the run failed or waits with (BatchRun::lastFailure()) when Advice::ofRun()
     *   has a text of it, for advice:<code>:run - only in sending, error, watching and watch_error; a
     *   run that ended keeps a code that no longer holds.
     * '' with no run, another item or question, and for a record whose maps cannot be read - verify,
     * stop_by and error are read apart from them and stay; until '' when nothing waits and while the
     * delivery is followed.
     * @param string $campaign long id of an item of the campaign slice
     */
    public function progress(string $campaign, string $what): string
    {
        $setup = self::$open;
        $id    = ModuleId::normalize($campaign);
        if (is_null($setup) || is_null($id) || !in_array($what, self::PROGRESS, true)) {
            return '';
        }
        $answer = $this->progressOf($setup, $id)[$what] ?? '';

        return ($what === 'stop_by') ? safe(self::userName($answer)) : $answer;
    }

    /** @return array<string, string> campaignProgress(), once a request for all the answers and fields() */
    private function progressOf(Setup $setup, string $id): array
    {
        return $this->once('progress', fn(): array => $this->campaignProgress($setup, $id), $setup, $id);
    }

    /**
     * $fn once a request for the same $what and $key - a closure cannot key the cache itself
     * @template T
     * @param \Closure(): T $fn
     * @return T
     */
    private function once(string $what, \Closure $fn, mixed ...$key): mixed
    {
        return \AA::Contentcache()->_get_result(get_hash(self::class . "::$what", ...$key), $fn, [], self::class . ">$what");
    }

    /**
     * The value of isds_box for Fix box of a recipient - an item of the source slice: the box the
     * register offers for it as surely as Fix box takes it, with a hash of the box the item has (Sync::
     * offered()). '' where fields:fixbox gives no form, the register offers nothing that sure, or the
     * item has the box. Put the form of Fix box only on rows that get a value. The first call of a
     * request reads the register and every item of the source slice; the rest reuse it.
     * @param string $item long id
     */
    public function regbox(string $item): string
    {
        $setup = self::$open;
        $id    = ModuleId::normalize($item);
        if (is_null($setup) || !self::$guarded || is_null($id) || !$this->fixable($setup)) {
            return '';
        }
        $offered = $this->once('regbox', function () use ($setup): array {
            try {
                return $this->offered($setup);
            } catch (\RuntimeException) {
                // the page of the register and the State Check tell what is wrong
                return [];
            }
        }, $setup);

        return safe($offered[$id] ?? '');
    }

    /**
     * Fix box can take a box: a page in production - a test one shares the source slice of production -,
     * whose source slice has its settings of data boxes, with a usable register. Once a request: a page
     * may offer the form on every row of its recipients.
     */
    private function fixable(Setup $setup): bool
    {
        return $this->once('fixable', function () use ($setup): bool {
            if (($setup->environment !== Account::PRODUCTION) || is_null($this->rules()->registryConfig($setup->sourceSlice))) {
                return false;
            }
            try {
                return Sync::usableRegister($this->registerInfo(), $this->now());
            } catch (\RuntimeException) {
                // no upload directory, or one PHP cannot look into: the page of the register says so
                return false;
            }
        }, $setup);
    }

    /**
     * Keeps the result in the session for the page the browser is sent to. Of the results kept before,
     * those older than FLASH_SECONDS go, and the oldest beyond FLASH_MAX - first those that guard no form
     * posted again (answered()): autosaves add one every few seconds, and must not push out the one that
     * keeps a Test from being sent twice.
     * @return string its key - random, so a link cannot name the result of another
     */
    private function keep(Setup $setup, string $uid, ActionResult $result): string
    {
        $key  = bin2hex(random_bytes(8));
        $now  = $this->now();
        $kept = is_array($_SESSION[self::FLASH] ?? null) ? $_SESSION[self::FLASH] : [];
        $kept = array_filter($kept, static fn(mixed $entry): bool => is_array($entry) && ((int) ($entry['at'] ?? 0) > $now - self::FLASH_SECONDS));
        // only what ran is repeated to the same form again: a refusal or BUSY may not hold the next click
        $ran        = in_array($result->status, [ActionResult::DONE, ActionResult::FAILED], true) && !self::reposted($result->action);
        $kept[$key] = ['binding' => $setup->bindingKey(), 'uid' => $uid, 'at' => $now, 'form' => $ran ? self::postedForm() : '', 'result' => $result->toArray()];
        while (count($kept) > self::FLASH_MAX) {
            $guardsNone = array_filter(array_diff_key($kept, [$key => true]), static fn(array $entry): bool => ($entry['form'] ?? '') === '');
            unset($kept[array_key_first($guardsNone) ?? array_key_first($kept)]);
        }
        $_SESSION[self::FLASH] = $kept;

        return $key;
    }

    /** the result kept for the page a GET with its key asks for - of the same user and binding, not too old */
    private function kept(Setup $setup, string $uid): ?ActionResult
    {
        $key = $_GET['isds_r'] ?? null;
        if ((($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') || !is_string($key) || (preg_match('/^[0-9a-f]{16}\z/', $key) !== 1)) {
            return null;
        }

        return ActionResult::fromArray($this->keptFor($setup, $uid)[$key]['result'] ?? null);
    }

    /**
     * What the form posted did when it was posted before - a double click: the first result kept for it
     * of an action that ran; null = not posted yet, too long ago, a form that does not tell itself from
     * others, or one of REPOSTED
     */
    protected function answered(Setup $setup, string $uid): ?ActionResult
    {
        $action = $_POST['isds_action'] ?? '';
        $form   = (is_string($action) && self::reposted($action)) ? '' : self::postedForm();
        foreach (($form === '') ? [] : $this->keptFor($setup, $uid) as $entry) {
            if (($entry['form'] ?? '') === $form) {
                return ActionResult::fromArray($entry['result'] ?? null);
            }
        }

        return null;
    }

    /** $action posted now acts on each post of its form: one of REPOSTED, with no file */
    private static function reposted(string $action): bool
    {
        return in_array($action, self::REPOSTED, true) && !Drafts::carriesFile($_FILES['isds_files'] ?? null);
    }

    /** @return array<array-key, array<string, mixed>> the results kept for the user under the binding, not too old, the oldest first */
    private function keptFor(Setup $setup, string $uid): array
    {
        $since = $this->now() - self::FLASH_SECONDS;

        return array_filter(
            is_array($_SESSION[self::FLASH] ?? null) ? $_SESSION[self::FLASH] : [],
            static fn(mixed $entry): bool => is_array($entry) && (($entry['binding'] ?? '') === $setup->bindingKey()) && (($entry['uid'] ?? '') === $uid) && ((int) ($entry['at'] ?? 0) > $since),
        );
    }

    /**
     * the form shown once (fields()), as the session keeps it - a hash, not its token; '' = none told.
     * With the recipient of Fix box: a script may fill one shown form for each row.
     */
    private static function postedForm(): string
    {
        $token     = $_POST['isds_token'] ?? '';
        $form      = $_POST['isds_form'] ?? '';
        $recipient = $_POST['isds_recipient'] ?? '';
        if (!is_string($token) || !is_string($form) || !is_string($recipient) || (preg_match('/^[0-9a-f]{16}\z/', $form) !== 1)) {
            return '';
        }

        return hash('sha256', ($recipient === '') ? "$token:$form" : "$token:$form:$recipient");
    }

    /**
     * Where the browser goes after an action: the path of this page with its query, the campaign and
     * the key of the result in place of any it had. Never another host: "//host/..." and "/\host/..."
     * lead to the host they name (the router reads "//host/cz/x" as "/cz/x"), so such a path, or one
     * with a control character, leads to "/".
     */
    private static function afterAction(string $campaign, string $key): string
    {
        $uri            = (string) ($_SERVER['REQUEST_URI'] ?? '');
        [$path, $query] = explode('?', explode('#', $uri, 2)[0], 2) + [1 => ''];
        if ((preg_match('~^/(?![/\\\\])~', $path) !== 1) || (preg_match('/[\x00-\x20\x7f]/', $uri) === 1)) {
            [$path, $query] = ['/', ''];
        }
        $pairs = array_filter(explode('&', $query), static function (string $pair): bool {
            $name = (string) preg_replace('/\[.*$/s', '', urldecode(explode('=', $pair, 2)[0]));
            return ($pair !== '') && !in_array($name, ['isds_campaign', 'isds_r'], true);
        });
        if ($campaign !== '') {
            $pairs[] = 'isds_campaign=' . $campaign;
        }
        $pairs[] = 'isds_r=' . $key;

        return $path . '?' . implode('&', $pairs);
    }

    /** what to fix, and the pages that fix it - for a user of AA who may */
    private function problems(SetupException $e): string
    {
        $items = '';
        foreach ($e->problems as $problem) {
            $items .= '<li>' . safe($problem->text) . '</li>';
        }
        $links = '';
        foreach ($this->rules()->links($e->problems) as $label => $url) {
            $links .= '<li><a href="' . safe($url) . '">' . safe($label) . '</a></li>';
        }

        return '<div class="aa-isds-setup" role="alert"><p>' . safe(_m('The mass sending on this page is not ready - fix this first:')) . "</p><ul>$items</ul>"
            . (($links === '') ? '' : '<p>' . safe(_m('Where to fix it:')) . "</p><ul>$links</ul>") . '</div>';
    }

    /**
     * The item $id names, when it is a campaign of the page - an item of its campaign slice, in any bin.
     * The id comes from the page or a form.
     * @return ?array{slice: string, active: bool, filter: string, state: string} as stored()
     */
    protected function campaignOfPage(Setup $setup, string $id): ?array
    {
        $stored = $this->stored($id);

        return (($stored['slice'] ?? '') === $setup->campaignSlice) ? $stored : null;
    }

    /**
     * @return ?list<Recipient> null = not an active campaign of the page, or its recipients cannot be told (logged)
     */
    private function campaignRecipients(Setup $setup, string $id): ?array
    {
        $filter = $this->storedFilter($setup, $id);
        if (is_null($filter)) {
            return null;
        }
        try {
            $config = $this->rules()->sourceConfig($setup->sourceSlice);

            return $this->resolver()->resolve($setup, $filter, $config);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->log("$setup->site:$setup->campaignSlice:$id", $e->getMessage(), self::RECIPIENTS_EVENT);

            return null;
        }
    }

    /**
     * @return array<string, string> progress() of each question it answers - stop_by as the uid -, and
     *                               abandon '1' when only Abandon ends the run (fields()); [] = not a
     *                               campaign of the page
     */
    private function campaignProgress(Setup $setup, string $id): array
    {
        $stored = $this->campaignOfPage($setup, $id);
        if (is_null($stored)) {
            return [];
        }
        ['run' => $run, 'rows' => $rows] = $this->sending($setup, $id);
        if (is_null($run)) {
            // never queued, or its record deleted: the item tells the state; '' tells no count from 0
            return ['state' => in_array($stored['state'], Schema::CAMPAIGN_STATES, true) ? $stored['state'] : ''];
        }
        // another binding may share the campaign slice and send into a message slice of its own
        if (!$setup->ownsRun($run)) {
            return [];
        }
        // the record, not the position, tells the sending is over (a stopped one too): the step of look writes
        // watch_from before its position moves on, and stays at look if it then fails
        $watching = $run->watching();
        $state    = match ($run->state()) {
            SendRun::SENDING => $watching ? 'watching' : SendRun::SENDING,
            SendRun::ERROR   => $watching ? 'watch_error' : SendRun::ERROR,
            SendRun::DONE    => SendRun::DONE,
            // given up while following delivery: the sending was over, only the following ended early;
            // not "stopped": the campaign item says so of a sending a person stopped
            SendRun::ENDED => $watching ? SendRun::DONE : 'abandoned',
            default        => '',
        };
        $code       = $run->lastFailure()['code'] ?? '';
        $verifiable = $run->verifiable();
        $known      = [
            'state'   => $state,
            'abandon' => $run->abandonable() ? '1' : '',
            'verify'  => $verifiable ? '1' : '',
            // the uid: progress() asks AA for the name only when the template asks for it
            'stop_by' => $run->stoppedBy(),
            // a run that ended keeps its last code, which no longer holds
            'error'   => (in_array($state, self::ERROR_SHOWN, true) && Advice::knowsRun($code)) ? $code : '',
        ];
        if (is_null($rows)) {
            // the frozen recipients changed outside AA: no figure can be trusted, the state still can
            return $known;
        }
        try {
            $counts = $run->figuresOf($rows);
        } catch (\JsonException) {
            // a map edited outside AA, likewise
            return $known;
        }
        $derived = [
            // the parts of the bar of the page; total less skipped differs when a record edited by hand
            // names indexes beyond the recipients
            'messages'  => $counts['sent'] + $counts['failed'] + $counts['uncertain'] + $counts['stopped'] + $counts['queued'],
            'attention' => $counts['failed'] + ($verifiable ? $counts['uncertain'] : 0),
            'checking'  => $verifiable ? 0 : $counts['uncertain'],
            // delivered is 0 while the delivery is not followed: sent would all pass for waiting for it
            'pending'   => $run->watching() ? $counts['sent'] - $counts['delivered'] : '',
        ];
        $counts['until'] = ($counts['until'] > 0) ? $counts['until'] : '';

        return $known + array_map('strval', $counts + $derived);
    }

    /**
     * The message items of each group of messages() but test, from the record of the run
     * @return ?array<string, list<string>> group => long ids; null = no run of the page, or a record that cannot be read
     */
    private function campaignMessages(Setup $setup, string $id): ?array
    {
        ['run' => $run, 'rows' => $rows] = $this->sending($setup, $id);
        if (is_null($run) || is_null($rows) || !$setup->ownsRun($run)) {
            return null;
        }
        try {
            $fates   = $run->maps()->fatesOf($rows, $run->isOver());
            $reached = $run->maps()->reached();
        } catch (\JsonException) {
            return null;
        }
        $verifiable = $run->verifiable();
        // as progress() tells pending: delivered is 0 while the delivery is not followed
        $watching   = $run->watching();
        $groups     = array_fill_keys(array_diff(self::GROUPS, ['test']), []);
        // in the order frozen - by name, as the recipients were resolved
        foreach (array_intersect_key($fates, $rows) as $k => $fate) {
            $group = match ($fate) {
                Schema::MESSAGE_STATE_FAILED    => 'problem',
                Schema::MESSAGE_STATE_UNCERTAIN => $verifiable ? 'problem' : 'checking',
                Schema::MESSAGE_STATE_SENT      => isset($reached[$k]) ? 'ok' : ($watching ? 'wait' : 'sent'),
                Schema::MESSAGE_STATE_QUEUED    => 'wait',
                Schema::MESSAGE_STATE_STOPPED   => 'stopped',
                default                         => 'skip',
            };
            $groups[$group][] = $rows[$k]['msg'];
        }
        for ($k = min($run->tried(), count($rows)) - 1; ($k >= 0) && (count($groups['recent']) < self::RECENT); --$k) {
            $groups['recent'][] = $rows[$k]['msg'];
        }

        return $groups;
    }

    /**
     * The newest run of a campaign of the page and its frozen recipients, read once a request: progress()
     * and messages() count of the same reading, so that the figures and the lists of a page agree
     * @return array{run: ?SendRun, rows: ?list<array<string, string>>} run null = never queued; rows null =
     *                                                                   a run of another binding, or its recipients not as frozen
     */
    private function sending(Setup $setup, string $id): array
    {
        return $this->once('sending', function () use ($setup, $id): array {
            $run = $this->run($id);
            if (is_null($run) || !$setup->ownsRun($run)) {
                return ['run' => $run, 'rows' => null];
            }
            try {
                return ['run' => $run, 'rows' => $run->recipients()];
            } catch (BatchFailed) {
                return ['run' => $run, 'rows' => null];
            }
        }, $setup, $id);
    }

    /**
     * $text escaped for the page, # too: an alias in it (_#HEADLINE) would expand with the item of the
     * row - as would {...} if a command took it as a parameter, which QuoteColons() stops
     */
    private static function text(string $text): string
    {
        return str_replace('#', '&#35;', safe($text));
    }

    /** the label the page gives the type; the type itself when the page offers none */
    private static function typeLabel(Setup $setup, string $type): string
    {
        foreach ($setup->types as $offered => $label) {
            if (Recipients::typeKey($offered) === Recipients::typeKey($type)) {
                return $label;
            }
        }

        return $type;
    }

    /** @return ?list<Recipient> campaignRecipients(), once a request for all the answers, a failure too */
    private function recipientsOf(Setup $setup, string $id): ?array
    {
        return $this->once('recipients', fn(): ?array => $this->campaignRecipients($setup, $id), $setup, $id);
    }

    /** @return array<string, string> item => why the campaign the page shows skips it (Schema::BOX_PROBLEMS), of those it skips */
    private function skipReasons(Setup $setup): array
    {
        $campaign = $this->pageCampaign();

        return is_null($campaign) ? [] : $this->once('reasons', function () use ($setup, $campaign): array {
            $reasons = [];
            foreach ($this->recipientsOf($setup, $campaign) ?? [] as $r) {
                if (!$r->sendable()) {
                    $reasons[$r->itemId] = $r->reason;
                }
            }

            return $reasons;
        }, $setup, $campaign);
    }

    /**
     * @param list<string> $ids
     * @return list<string> Recipients::ofSource() of the source slice, once a request
     */
    private function ofSource(Setup $setup, array $ids): array
    {
        return $ids ? $this->once('ofSource', fn(): array => $this->resolver()->ofSource($setup->sourceSlice, $ids), $setup, $ids) : [];
    }

    /**
     * The item $id names, when it is a message of the campaign the page shows (campaign())
     * @return ?array{slice: string, active: bool, values: array<string, list<string>>} as item()
     */
    private function pageMessage(Setup $setup, string $id): ?array
    {
        $campaign = $this->pageCampaign();
        $item     = is_null($campaign) ? null : $this->item($id);

        return (!is_null($item) && ($item['slice'] === $setup->messageSlice) && (strtolower($item['values'][Schema::MESSAGE_CAMPAIGN][0] ?? '') === $campaign)) ? $item : null;
    }

    /**
     * An item the UI lists or asks of; the first question reads every item listed and not read yet, at
     * once - an id with no item too, and only once
     * @return ?array{slice: string, active: bool, values: array<string, list<string>>} as readItems(); null = no such item
     */
    private function item(string $id): ?array
    {
        self::$items += [$id => false];
        $unread       = array_map('strval', array_keys(self::$items, false, true));
        if ($unread) {
            self::$items = array_replace(self::$items, array_fill_keys($unread, null), $this->readItems($unread));
        }

        return self::$items[$id] ?: null;
    }

    /**
     * The filter an active campaign of the page stored, once a request; null = no such campaign, or a
     * filter that cannot be read - logged, for State Check
     */
    private function storedFilter(Setup $setup, string $id): ?RecipientFilter
    {
        return $this->once('filter', function () use ($setup, $id): ?RecipientFilter {
            // only an active campaign tells a filter
            $stored = $this->campaignOfPage($setup, $id);
            if (is_null($stored) || !$stored['active']) {
                return null;
            }
            try {
                return self::filterOf($setup, $stored['filter']);
            } catch (\InvalidArgumentException $e) {
                $this->log("$setup->site:$setup->campaignSlice:$id", $e->getMessage(), self::RECIPIENTS_EVENT);

                return null;
            }
        }, $setup, $id);
    }

    /**
     * The recipient filter a campaign stored: none - only a preview of the default audience; sending
     * must refuse it, never widen it to the default, so the fallback stays here, not in fromJson()
     * @throws \InvalidArgumentException see RecipientFilter::fromJson()
     */
    private static function filterOf(Setup $setup, string $stored): RecipientFilter
    {
        return (trim($stored) === '') ? RecipientFilter::default($setup) : RecipientFilter::fromJson($stored, $setup);
    }

    /**
     * Who verified a message, as the timeline keeps it ("uid name", SendRun::verify()): the name AA
     * shows of the login now, or the one kept when AA no longer knows it
     */
    private static function verifiedBy(string $by): string
    {
        [$uid, $kept] = explode(' ', $by, 2) + [1 => ''];
        $name         = self::userName($uid);

        return (($name === $uid) && ($kept !== '')) ? $kept : $name;
    }

    /** the name AA shows of a login; '' for none */
    private static function userName(string $uid): string
    {
        return ($uid === '') ? '' : \AA::$perm->getUserName($uid);
    }

    // ------------------------------------------------------------ what tests replace

    /** the site whose own page is being rendered; null in view.php, slice.php, a widget, cron */
    protected function site(): ?string
    {
        return AA_Module_Site::renderingPage();
    }

    protected function noStore(): void
    {
        \AA::Pagecache()->switchCacheNostore();
    }

    /** the Setup, checked once a request */
    protected function setup(string $settings, string $site): Setup|SetupException
    {
        return \AA::Contentcache()->get_result([Setup::class, 'checked'], [$settings, $site]);
    }

    protected function rules(): SliceRules
    {
        return new SliceRules();
    }

    protected function access(): Access
    {
        return new Access();
    }

    /** the actions of the page, for the login the gate let in */
    protected function actions(Access $access): Actions
    {
        return new Actions($access, $this->campaignOfPage(...), $this->run(...), $this->answered(...));
    }

    protected function headersSent(): bool
    {
        return headers_sent();
    }

    protected function sendHeader(string $header): void
    {
        header($header);
    }

    /** 303: the reload of the page it leads to is a GET */
    protected function redirect(string $url): never
    {
        go_url($url, '', false, 303);
    }

    protected function now(): int
    {
        return time();
    }

    /**
     * The item through the item cache of the request: its slice, whether it is in the active bin, the
     * recipient filter and the state it stores
     * @return ?array{slice: string, active: bool, filter: string, state: string} null = no such item
     */
    protected function stored(string $id): ?array
    {
        $item = AA_Items::getItem(new zids($id, 'l'));

        return $item ? ['slice' => $item->getSliceID(), 'active' => $item->isActive(), 'filter' => (string) $item->getval(Schema::CAMPAIGN_FILTER), 'state' => (string) $item->getval(Schema::CAMPAIGN_STATE)] : null;
    }

    /**
     * The items, read at once with the reading passwords of the request: the slice of each, whether it
     * is in the active bin, and its values. Not through AA_Items: it would read an id with no item again
     * on each question. The campaign of a form is read by stored(): an action writes it in this request.
     * @param list<string> $ids long ids in small letters
     * @return array<string, array{slice: string, active: bool, values: array<string, list<string>>}> long id => it; none for no such item
     */
    protected function readItems(array $ids): array
    {
        $read = [];
        foreach (GetItemContent(new zids($ids, 'l')) as $id => $content4id) {
            $item               = new ItemContent($content4id);
            $fields             = array_map('strval', array_keys($item->getContent()));
            $values             = array_map(static fn(string $field): array => array_map('strval', $item->getValuesArray($field)), array_combine($fields, $fields));
            $read[(string) $id] = ['slice' => $item->getSliceID(), 'active' => $item->isActive(), 'values' => $values];
        }

        return $read;
    }

    /**
     * @return ?array{state: string, time: int, size: int, sha256: string} Sync::registerInfo()
     * @throws \RuntimeException see Sync::registerInfo()
     */
    protected function registerInfo(): ?array
    {
        return Sync::registerInfo();
    }

    /**
     * @return array<string, string> Sync::offered() of the source slice
     * @throws \RuntimeException see Sync::offered() and SliceRules::sourceConfig()
     */
    protected function offered(Setup $setup): array
    {
        return (new Sync($this->rules()->sourceConfig($setup->sourceSlice)))->offered();
    }

    /** the newest run of the campaign, read-only; null = never queued */
    protected function run(string $campaign): ?SendRun
    {
        return SendRun::ofSubject($campaign, 1)[0] ?? null;
    }

    protected function resolver(): Recipients
    {
        return new Recipients();
    }

    /** @return list<int|string> ids of the views of the two slices of the page named $name */
    protected function viewIds(Setup $setup, string $name): array
    {
        return array_values(DB_AA::select('id', 'SELECT id FROM view', [['slice_id', [$setup->campaignSlice, $setup->messageSlice], 'l'], ['field3', $name]]));
    }

    /** AA_Stringexpand_View::output() of a listing of $ids alone */
    protected function showView(int $vid, string $ids, ?string $settings): string
    {
        return \AA_Stringexpand_View::output($vid, $ids, $settings, true);
    }

    /** @return list<string> long ids of the campaigns of the page in the active bin, the last edited first */
    protected function campaignIds(Setup $setup): array
    {
        return array_map('strval', (new AA_Set([$setup->campaignSlice], null, [['last_edit.......' => 'd']], \AA_BIN_ACTIVE))->query()->longids());
    }

    /** @return list<string> long ids of the test messages of the campaign in the active bin, the newest first */
    protected function testMessages(Setup $setup, string $campaign): array
    {
        return array_map('strval', SendItems::testsOf($setup->messageSlice, $campaign, \AA_BIN_ACTIVE, 'd')->query()->longids());
    }

    /**
     * @param string $selector site:campaign slice, '-' for none; of RECIPIENTS_EVENT site:campaign slice:campaign;
     *                         of VIEW_EVENT site:the name of the view
     * @param string $problem  the first one - the rest the page tells who may fix them; of RECIPIENTS_EVENT what failed
     */
    protected function log(string $selector, string $problem, string $event = self::LOG_EVENT): void
    {
        LogOnce::daily($event, $selector, $problem);
    }
}
