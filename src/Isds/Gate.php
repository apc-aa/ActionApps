<?php

declare(strict_types=1);

namespace AA\Isds;

use AA\Util\FormToken;
use AA\Util\LogOnce;
use AA_Credentials;
use AA_Module_Site;

/**
 * {datovkabulk:page:<settings>:{(<UI>)}:<refusal>} - the page of a mass sending. Opens the two slices
 * only on a page the site module renders of its own site, to a logged-in user AA lets send (Access),
 * with settings Setup accepts for that site; only then expands the UI the template deferred. Everyone
 * else gets the refusal, the UI stays unexpanded and the slices stay closed. Once opened, the slices
 * stay readable to the rest of the request, never to a next one through the token of an {ajax}.
 * Inside the UI the other subcommands give the settings and the fields of a form, outside of it nothing.
 *
 * What the template must keep in mind:
 * - What the request read of the two slices before the gate stays masked - AA_Items and {view} cache
 *   their results without the password - so nothing before the gate may read them. Nor may the page
 *   be the page of an item of the two slices: site.php reads the routed item before any spot.
 * - A UI not wrapped in {(...)} is expanded before the gate decides - and once more by it.
 * - {(...)} ends at the first ")}" - a ")}" in the UI (inline JavaScript) ends the deferral early.
 */
class Gate
{
    /** log.type: settings of a page that are not ready, once a day */
    public const LOG_EVENT = 'ISDS_SETUP';

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
        AA_Credentials::singleton()->openForRequest($setup->campaignSlice);
        AA_Credentials::singleton()->openForRequest($setup->messageSlice);
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
     * @param string $selector site:campaign slice, '-' for none
     * @param string $problem  the first one - the rest the page tells who may fix them
     */
    protected function log(string $selector, string $problem): void
    {
        LogOnce::daily(self::LOG_EVENT, $selector, $problem);
    }
}
