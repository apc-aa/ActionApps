<?php

declare(strict_types=1);

namespace AA\Isds;

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
 * Inside the UI the other subcommands give the settings, the fields of a form and the recipients,
 * outside of it nothing.
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

    /** the actions a form may name - its token binds the action and the campaign */
    public const ACTIONS = ['save', 'clone', 'test', 'send', 'stop', 'verify', 'fixbox'];

    /** the settings of the UI being expanded; null outside every gate */
    private static ?Setup $open = null;

    /** what the action of this request did, for {datovkabulk:result} */
    private static string $result = '';

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
        AA_Credentials::singleton()->openForRequest($setup->campaignSlice, $setup->messageSlice);
        $outer      = self::$open;
        self::$open = $setup;
        try {
            return $expand($ui);
        } finally {
            self::$open = $outer;
        }
    }

    /**
     * The hidden fields of a form asking for $action on the campaign: the token binds both and the
     * campaign slice, so a form of one action or campaign cannot be posted as another.
     * @param string $campaign long id; '' only to save a new campaign
     */
    public function fields(string $action, string $campaign = ''): string
    {
        $campaign = (($campaign === '') && ($action === 'save')) ? '' : ModuleId::normalize($campaign);
        if (is_null(self::$open) || !in_array($action, self::ACTIONS, true) || is_null($campaign)) {
            return '';
        }

        return getFrmHiddenEasy('isds_action', $action)
            . getFrmHiddenEasy('isds_campaign', $campaign)
            . getFrmHiddenEasy('isds_token', FormToken::get(self::purpose($action, self::$open->campaignSlice, $campaign)));
    }

    /** the purpose of the token of a form; ids in small letters */
    public static function purpose(string $action, string $campaignSlice, string $campaign): string
    {
        return "isds:$action:$campaignSlice:$campaign";
    }

    /** Account::TEST or Account::PRODUCTION - from include/config.php, never from the text of the template */
    public function env(): string
    {
        return safe(self::$open->environment ?? '');
    }

    /** the box the page sends from, when AA knows it without asking ISDS; '' otherwise */
    public function sender(): string
    {
        return safe(self::$open->senderBox ?? '');
    }

    /** what the action of this request did, escaped */
    public function result(): string
    {
        return is_null(self::$open) ? '' : safe(self::$result);
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

    /** the action of this request tells what it did - plain text */
    public static function report(string $text): void
    {
        self::$result = $text;
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
     * @return ?list<Recipient> null = not an active campaign of the page, or its recipients cannot be told (logged)
     */
    private function campaignRecipients(Setup $setup, string $id): ?array
    {
        // the id comes from the page: only an active item of the page's campaign slice tells a filter
        $stored = $this->stored($id);
        if (is_null($stored) || ($stored['slice'] !== $setup->campaignSlice) || !$stored['active']) {
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

    /**
     * The item through the item cache of the request: its slice, whether it is in the active bin, and
     * the recipient filter it stores
     * @return ?array{slice: string, active: bool, filter: string} null = no such item
     */
    protected function stored(string $id): ?array
    {
        $item = AA_Items::getItem(new zids($id, 'l'));

        return $item ? ['slice' => $item->getSliceID(), 'active' => $item->isActive(), 'filter' => (string) $item->getval(Schema::CAMPAIGN_FILTER)] : null;
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
