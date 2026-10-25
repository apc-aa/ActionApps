<?php

declare(strict_types=1);

namespace AA\Isds;

use AA\Later\BatchFailed;
use AA\Util\FormToken;
use AA\Util\LogOnce;
use AA_Credentials;
use AA_Items;
use AA_Module_Site;
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

    /** the actions a form may name - its token binds the action, the binding of the page and the campaign */
    public const ACTIONS = ['save', 'clone', 'test', 'send', 'stop', 'abandon', 'resume', 'verify', 'fixbox'];
    /** of ACTIONS those whose shown form is posted again on purpose (autosave): each post acts */
    public const REPOSTED = ['save'];

    /** $_SESSION key of the results kept for the page after an action, by their key */
    private const FLASH = 'aa_isds_result';
    /** a result older is not shown; newer ones are kept no more than FLASH_MAX */
    private const FLASH_SECONDS = 600;
    private const FLASH_MAX     = 10;

    /** what progress() tells */
    private const PROGRESS = ['state', 'total', 'sent', 'failed', 'skipped', 'uncertain', 'stopped', 'queued', 'until', 'delivered', 'accepted'];

    /** the settings of the UI being expanded; null outside every gate */
    private static ?Setup $open = null;

    /** what an action did, for the UI being expanded: of this request, or kept for the page after it */
    private static ?ActionResult $shown = null;

    /** the UI being expanded goes out with the headers against framing - only then it gets forms */
    private static bool $guarded = false;

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
        $outer         = [self::$open, self::$shown, self::$guarded];
        self::$open    = $setup;
        self::$shown   = $result ?? $this->kept($setup, $access->uid());
        self::$guarded = $guarded;
        try {
            return $expand($ui);
        } finally {
            [self::$open, self::$shown, self::$guarded] = $outer;
        }
    }

    /**
     * The hidden fields of a form asking for $action on the campaign: the token binds both and the
     * binding of the page, so a form of one action, campaign or page cannot be posted as another.
     * '' on a page that could not be kept out of frames; for abandon '' unless the run is one a stop no
     * longer ends (SendRun::abandonable()) - the template offers it in place of Stop then.
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

        return getFrmHiddenEasy('isds_action', $action)
            . getFrmHiddenEasy('isds_campaign', $campaign)
            . getFrmHiddenEasy('isds_token', FormToken::get(self::purpose($action, self::$open, $campaign)))
            // the token is the same in every form of the action: this tells one shown form from another
            . getFrmHiddenEasy('isds_form', bin2hex(random_bytes(8)));
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

    /** the test boxes of the page, dash-separated for a <select> of the test form (its field isds_box) */
    public function testBoxes(): string
    {
        return safe(implode('-', self::$open->testBoxes ?? []));
    }

    /**
     * The recipients of an active campaign of the page by its stored filter, counted anew on each
     * request: count - how many get the letter, skipped - how many are left out for their box, ids -
     * the items that get it, dash-separated for {view} and {ids}. It counts a queued or sent campaign
     * anew too; its count when it was queued is stored in the campaign (Schema::CAMPAIGN_RECIPIENTS).
     * '' for another item or question, and on any error. ids is '' for no recipients too, and {view}
     * with no ids lists the whole slice - guard it:
     *     {ifset:{datovkabulk:recipients:<id>:ids}:{view:N:{datovkabulk:recipients:<id>:ids}}}
     * (not _#1 - AA expands the inner command first). The guard hides the output only: the inner {view}
     * still runs once over the whole slice.
     * @param string $campaign long id of an active item of the campaign slice
     */
    public function recipients(string $campaign, string $what): string
    {
        $setup = self::$open;
        $id    = ModuleId::normalize($campaign);
        if (is_null($setup) || is_null($id) || !in_array($what, ['count', 'skipped', 'ids'], true)) {
            return '';
        }
        // once a request for the three answers, a failure too; a closure cannot key the cache itself
        $recipients = \AA::Contentcache()->_get_result(get_hash(self::class . '::recipients', $setup, $id), fn(): ?array => $this->campaignRecipients($setup, $id), [], self::class . '>recipients');
        if (is_null($recipients)) {
            return '';
        }
        $sendable = array_filter($recipients, static fn(Recipient $r): bool => $r->sendable());

        return match ($what) {
            'count'   => (string) count($sendable),
            'skipped' => (string) (count($recipients) - count($sendable)),
            default   => implode('-', array_map(static fn(Recipient $r): string => $r->itemId, $sendable)),
        };
    }

    /**
     * How far the sending of a campaign of the page got, read anew on each request from the record of
     * its newest run (SendRun::progressFigures()), never from the message items or ISDS. state: while
     * the campaign has no run, the state of its item; then the run's - sending (queued, working or
     * waiting), error (sending waits for Continue - one stopped already or with every message tried
     * for Continue or Abandon, fields()), abandoned (sending given up in error); once the
     * sending is over (a stopped one too) watching (the delivery is followed, waiting for its rounds
     * too), watch_error (the following waits for Continue or Abandon); done at the end, the following
     * given up too. A sending a person stopped is watching first when it sent anything, then done,
     * what it left unsent counted as stopped. total: the recipients frozen, skipped ones too - sent,
     * failed (refused, not found by the lookup, or sent and then not delivered), uncertain, skipped,
     * stopped and queued add up to it. delivered and accepted: of the messages sent, 0 while the
     * delivery is not followed (SendMaps::delivery()). until: the unix time a pause of sending waits
     * till. '' with no run, for a record that cannot be read, another item or question; until '' when
     * nothing waits and while the delivery is followed.
     * @param string $campaign long id of an item of the campaign slice
     */
    public function progress(string $campaign, string $what): string
    {
        $setup = self::$open;
        $id    = ModuleId::normalize($campaign);
        if (is_null($setup) || is_null($id) || !in_array($what, self::PROGRESS, true)) {
            return '';
        }
        return $this->progressOf($setup, $id)[$what] ?? '';
    }

    /** @return array<string, string> campaignProgress(), once a request for all the answers and fields() */
    private function progressOf(Setup $setup, string $id): array
    {
        // a closure cannot key the cache itself
        return \AA::Contentcache()->_get_result(get_hash(self::class . '::progress', $setup, $id), fn(): array => $this->campaignProgress($setup, $id), [], self::class . '>progress');
    }

    /**
     * Keeps the result in the session for the page the browser is sent to. Of the results kept before,
     * those older than FLASH_SECONDS go, and the oldest beyond FLASH_MAX - autosaves add one every few seconds.
     * @return string its key - random, so a link cannot name the result of another
     */
    private function keep(Setup $setup, string $uid, ActionResult $result): string
    {
        $key  = bin2hex(random_bytes(8));
        $now  = $this->now();
        $kept = is_array($_SESSION[self::FLASH] ?? null) ? $_SESSION[self::FLASH] : [];
        $kept = array_filter($kept, static fn(mixed $entry): bool => is_array($entry) && ((int) ($entry['at'] ?? 0) > $now - self::FLASH_SECONDS));
        // only what ran is repeated to the same form again: a refusal or BUSY may not hold the next click
        $ran        = in_array($result->status, [ActionResult::DONE, ActionResult::FAILED], true);
        $kept[$key] = ['binding' => $setup->bindingKey(), 'uid' => $uid, 'at' => $now, 'form' => $ran ? self::postedForm() : '', 'result' => $result->toArray()];
        // a key of digits only is an int key: keep the keys as they are
        $_SESSION[self::FLASH] = array_slice($kept, -self::FLASH_MAX, null, true);

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
        $form = in_array($_POST['isds_action'] ?? '', self::REPOSTED, true) ? '' : self::postedForm();
        foreach (($form === '') ? [] : $this->keptFor($setup, $uid) as $entry) {
            if (($entry['form'] ?? '') === $form) {
                return ActionResult::fromArray($entry['result'] ?? null);
            }
        }

        return null;
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

    /** the form shown once (fields()), as the session keeps it - a hash, not its token; '' = none told */
    private static function postedForm(): string
    {
        $token = $_POST['isds_token'] ?? '';
        $form  = $_POST['isds_form'] ?? '';
        if (!is_string($token) || !is_string($form) || (preg_match('/^[0-9a-f]{16}\z/', $form) !== 1)) {
            return '';
        }

        return hash('sha256', "$token:$form");
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
        // only an active campaign tells a filter
        $stored = $this->campaignOfPage($setup, $id);
        if (is_null($stored) || !$stored['active']) {
            return null;
        }
        try {
            // a campaign stored without a filter only previews the default audience; sending must refuse
            // it, never widen it to the default, so the fallback stays here, not in fromJson()
            $filter = (trim($stored['filter']) === '') ? RecipientFilter::default($setup) : RecipientFilter::fromJson($stored['filter'], $setup);
            $config = $this->rules()->registryConfig($setup->sourceSlice) ?? throw new \RuntimeException('The source slice has no settings of data boxes');

            return $this->resolver()->resolve($setup, $filter, $config);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->log("$setup->site:$setup->campaignSlice:$id", $e->getMessage(), self::RECIPIENTS_EVENT);

            return null;
        }
    }

    /**
     * @return array<string, string> progress() of each question it answers, and abandon '1' when only
     *                               Abandon ends the run (fields()); [] = not a campaign of the page
     */
    private function campaignProgress(Setup $setup, string $id): array
    {
        $stored = $this->campaignOfPage($setup, $id);
        if (is_null($stored)) {
            return [];
        }
        $run = $this->run($id);
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
        $known = ['state' => $state, 'abandon' => $run->abandonable() ? '1' : ''];
        try {
            $counts = $run->progressFigures();
        } catch (\JsonException|BatchFailed) {
            // edited outside AA: no figure can be trusted, the state still can
            return $known;
        }
        $counts['until'] = ($counts['until'] > 0) ? $counts['until'] : '';

        return $known + array_map('strval', $counts);
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

    /** the newest run of the campaign, read-only; null = never queued */
    protected function run(string $campaign): ?SendRun
    {
        return SendRun::ofSubject($campaign, 1)[0] ?? null;
    }

    protected function resolver(): Recipients
    {
        return new Recipients();
    }

    /**
     * @param string $selector site:campaign slice, '-' for none; of RECIPIENTS_EVENT site:campaign slice:campaign
     * @param string $problem  the first one - the rest the page tells who may fix them; of RECIPIENTS_EVENT what failed
     */
    protected function log(string $selector, string $problem, string $event = self::LOG_EVENT): void
    {
        LogOnce::daily($event, $selector, $problem);
    }
}
