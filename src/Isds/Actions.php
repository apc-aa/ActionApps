<?php

declare(strict_types=1);

namespace AA\Isds;

use AA\Central\ResponderPolicy;
use AA\Later\BatchRun;
use AA\Later\Toexecute;
use AA\Util\FormToken;
use AA\Util\LogOnce;
use AA_Log;

/**
 * The actions of a page of a mass sending, at most one a request. The gate (Gate::page()) runs them
 * once it opened the slices to a user who may send, and only for a POST of the same origin carrying
 * the token of a form it gave (Gate::fields()) - bound to the action, the binding of the page and the
 * campaign. The core gets the login of the request, never a value the request carries. Input is read
 * from $_POST only: a cookie of the same name is no form.
 */
class Actions
{
    /** log.type: an action that failed on an internal error, the place in the code without its message; once a day */
    public const LOG_EVENT = 'ISDS_ACT';

    /** the form is not one the gate gave for this page, or it came from elsewhere - which, the user is not told */
    public const EXPIRED = 'EXPIRED';
    /** the request was larger than post_max_size, so PHP read none of it; the param is the limit in bytes */
    public const TOO_LARGE = 'TOO_LARGE';
    /** the core failed on something no code tells; the action may have done part of its work */
    public const INTERNAL = 'INTERNAL';
    /** no campaign of the page has that id, or its run sends from other slices */
    public const UNKNOWN = 'UNKNOWN';
    /** Continue: the sending goes on */
    public const RESUMED = 'RESUMED';
    /** Stop: nothing more is sent, what may have gone is still looked up */
    public const STOPPING = 'STOPPING';
    /** Abandon: the sending is given up, what may have gone stays uncertain */
    public const ABANDONED = SendRun::ABANDONED;
    /** Stop: every message has been tried, nothing is left to stop */
    public const ALL_SENT = SendRun::ALL_SENT;
    /** Stop: the sending stands in an error a stop no longer ends - only Abandon would */
    public const CONFIRM_ABANDON = SendRun::CONFIRM_ABANDON;
    /** what the core refuses with: another request holds the campaign, the user may not control its run,
     *  a step of it may still work, or the run does not wait for the action */
    public const BUSY        = SendRun::BUSY;
    public const FORBIDDEN   = BatchRun::FORBIDDEN;
    public const LEASE       = 'LEASE';
    public const WRONG_STATE = 'WRONG_STATE';

    /** the actions run here; the form of another one of Gate::ACTIONS is refused as one of no action */
    private const RUNS = ['resume', 'stop', 'abandon'];

    /** an action ran in this request: a form is acted on once, whichever gate on the page it names */
    private static bool $consumed = false;

    /** @var ?array{string, string, list<string>} the log row of the action done: event, selector, params */
    private ?array $done = null;

    /**
     * @param \Closure(Setup, string): ?array{slice: string, active: bool, filter: string, state: string} $campaignOfPage as Gate::campaignOfPage()
     * @param \Closure(string): ?SendRun                                                                  $run            as Gate::run()
     * @param \Closure(Setup, string): ?ActionResult                                                      $answered       as Gate::answered()
     */
    public function __construct(private Access $access, private \Closure $campaignOfPage, private \Closure $run, private \Closure $answered) {}

    /**
     * The action the request asks of this page: a result taken() when it ran; an untaken one - the
     * form expired, or the request was too large to read - for this gate to show while another may
     * still take the form; null when none is asked (a GET, another form) or an action ran already
     * @param bool $guarded the page goes out with the headers that keep it out of frames of other sites
     */
    public function run(Setup $setup, bool $guarded): ?ActionResult
    {
        $server = $_SERVER;
        if ((($server['REQUEST_METHOD'] ?? '') !== 'POST') || self::$consumed) {
            return null;
        }
        $asked    = isset($_POST['isds_action']);
        $limit    = $this->postMaxSize();
        $tooLarge = !$asked && ($limit > 0) && ((int) ($server['CONTENT_LENGTH'] ?? 0) > $limit);
        if (!$asked && !$tooLarge) {
            return null;
        }
        // before the size: a page of another site learns nothing of this one; and a page sent without
        // the headers against framing (?debug printed before the gate) may be clicked in a frame unseen
        if (!$guarded || ResponderPolicy::isForeignBrowserRequest($server)) {
            return new ActionResult('', ActionResult::EXPIRED, self::EXPIRED);
        }
        if ($tooLarge) {
            return new ActionResult('', ActionResult::REFUSED, self::TOO_LARGE, params: [$limit]);
        }
        $action   = self::posted('isds_action');
        $campaign = Gate::campaignOf($action, self::posted('isds_campaign'));
        $token    = self::posted('isds_token');
        if (!in_array($action, self::RUNS, true) || is_null($campaign) || !FormToken::checkPost(Gate::purpose($action, $setup, $campaign), $token, $server)) {
            return new ActionResult('', ActionResult::EXPIRED, self::EXPIRED);
        }
        self::$consumed = true;
        // the same form again - a double click, a POST reloaded: what the first did, nothing done twice
        $earlier = ($this->answered)($setup, $this->access->uid());
        if ($earlier) {
            return $earlier;
        }
        try {
            $result = $this->act($setup, $action, $campaign);
        } catch (\Throwable $e) {
            // its message may quote a password or a letter
            $this->logFailure("$action:$campaign", Toexecute::failureParams($setup->site, $e));

            return new ActionResult($action, ActionResult::FAILED, self::INTERNAL, $campaign);
        }
        if ($this->done) {
            try {
                $this->log(...$this->done);
            } catch (\Throwable) {
                // the action is done: a log row lost must not tell the user it failed
            }
        }

        return $result;
    }

    /** a new request - for tests */
    public static function forget(): void
    {
        self::$consumed = false;
    }

    private function act(Setup $setup, string $action, string $campaign): ActionResult
    {
        // the id comes from the form: only an item of the campaign slice of the page, in any bin - a
        // sending of a campaign put in the trash still shows, so it must still be stopped
        $stored = ($campaign === '') ? null : ($this->campaignOfPage)($setup, $campaign);
        if (($campaign !== '') && is_null($stored)) {
            return self::refused($action, self::UNKNOWN, $campaign);
        }

        return match ($action) {
            'resume', 'stop', 'abandon' => $this->onRun($setup, $action, $campaign),
            default                     => throw new \LogicException("No action $action"),
        };
    }

    /** Continue, Stop or Abandon of the run of the campaign, done by the core under the lock of the campaign */
    private function onRun(Setup $setup, string $action, string $campaign): ActionResult
    {
        $run = $this->runOfPage($setup, $action, $campaign);
        if ($run instanceof ActionResult) {
            return $run;
        }
        $uid = $this->access->uid();
        [$code, $done] = match ($action) {
            // a run in error, waiting, hanging or with its step dead goes on now
            'resume' => [$this->resumeRun($campaign, $uid) ?: self::RESUMED, self::RESUMED],
            // never gives the sending up: a stale form of Stop must not do what cannot be undone
            'stop'   => [$this->stopRun($campaign, $uid, false) ?: self::STOPPING, self::STOPPING],
            // only giving up is done - anything else would be told as what cannot be undone
            default  => [$this->stopRun($campaign, $uid, true) ?: throw new \LogicException('Abandon did not give the sending up'), self::ABANDONED],
        };
        if ($code !== $done) {
            return self::refused($action, $code, $campaign);
        }
        $this->done = [SendQueue::LOG_EVENT, "$action:$campaign", [$uid, $done]];

        return new ActionResult($action, ActionResult::DONE, $done, $campaign);
    }

    /** the run of the campaign, when it sends from the slices of the page (Setup::ownsRun()) */
    private function runOfPage(Setup $setup, string $action, string $campaign): SendRun|ActionResult
    {
        $run = ($this->run)($campaign);
        if (is_null($run)) {
            // never queued: nothing to stop, continue or verify
            return self::refused($action, self::WRONG_STATE, $campaign);
        }

        return $setup->ownsRun($run) ? $run : self::refused($action, self::UNKNOWN, $campaign);
    }

    private static function refused(string $action, string $code, string $campaign): ActionResult
    {
        return new ActionResult($action, ($code === self::BUSY) ? ActionResult::BUSY : ActionResult::REFUSED, $code, $campaign);
    }

    /** a text the form posts; '' for none, and for an array */
    private static function posted(string $name): string
    {
        $value = $_POST[$name] ?? '';

        return is_string($value) ? $value : '';
    }

    // ------------------------------------------------------------ what tests replace

    /** bytes; 0 = no limit */
    protected function postMaxSize(): int
    {
        return ini_parse_quantity((string) ini_get('post_max_size'));
    }

    /** @return string '' = going on, or the code SendRun::resumeFor() refuses with */
    protected function resumeRun(string $campaign, string $uid): string
    {
        return SendRun::resumeFor($campaign, $uid);
    }

    /** @return string what SendRun::stop() says */
    protected function stopRun(string $campaign, string $uid, bool $abandon): string
    {
        return SendRun::stop($campaign, $uid, $abandon);
    }

    /** @param list<string> $params */
    protected function log(string $event, string $selector, array $params): void
    {
        AA_Log::write($event, $selector, $params);
    }

    protected function logFailure(string $selector, string $params): void
    {
        LogOnce::daily(self::LOG_EVENT, $selector, $params);
    }
}
