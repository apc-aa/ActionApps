<?php

declare(strict_types=1);

namespace AA\Mailbulk;

use AA;
use AA\IO\DB\DB_AA;
use AA\Later\BatchFailed;
use AA\Later\BatchRun;
use AA\Later\Toexecute;
use AA_Collection;
use AA_Credentials;
use AA_Log;
use Closure;

/**
 * The control of the sending of one item to one list, for an editor of all items of the item's slice when
 * the list belongs to that slice (Run::mayControlPair()). Prints the control and runs its actions; the actions
 * come only as POST through central/responder.php (AA_Responder_Mailbulk), never from expanding a template.
 * One Gate serves one request: it keeps what it read of each item and list, and the rights to each list and
 * slice - a listing of a hundred items asks for them once.
 */
class Gate
{
    /** act() errors: the action was refused or failed - the text says why */
    public const ERR_FAILED = 1;
    public const ERR_UNKNOWN_ACTION = 2;
    /** no right, or the list does not belong to the item's slice */
    public const ERR_DENIED = 9;

    /** what info() gives */
    public const INFO = ['state', 'total', 'sent', 'failed', 'skipped', 'uncertain', 'date'];

    /** the actions that send nothing: a sending started before stops even after the list left the slice */
    private const WITHOUT_RELATION = ['stop', 'state'];

    /** @var array<string, ?string> item id => its slice */
    private array $slices = [];
    /** @var array<string, ?string> pair and relation => why not; null = allowed */
    private array $denials = [];
    /** @var array<string, bool> list => it exists */
    private array $lists = [];
    /** @var array<string, bool> list, slice and relation => the user may control the sendings */
    private array $rights = [];
    /** @var array<string, AA_Collection> one per list: the letters of its items read the list once */
    private array $collections = [];
    /** @var array<string, Campaign> */
    private array $campaigns = [];
    /** @var array<string, ItemLetter> */
    private array $letters = [];
    /** @var array<string, ?string> */
    private array $problems = [];

    public function __construct()
    {
        require_once constant('AA_BASE_PATH') . 'modules/alerts/alerts_sending.php';
    }

    /** The slice of an item the page has read already - the control need not read it again.
     *  @param string $slice long id */
    public function itemSeen(string $itemId, string $slice): void
    {
        if (is_long_id($itemId) && is_long_id($slice)) {
            $this->slices[strtolower($itemId)] ??= strtolower($slice);
        }
    }

    /** the user may send the item to the list; with $toSend false: may stop its sending and see its state */
    public function allowed(string $list, string $itemId, bool $toSend = true): bool
    {
        return $this->denial($list, strtolower($itemId), $toSend) === null;
    }

    /** the control as HTML; '' without the right, or for a list not of the item's slice with nothing to stop */
    public function control(string $list, string $itemId): string
    {
        // also when it prints nothing: the page of a visitor must not be served from the cache to an editor
        $this->noStore();
        $itemId = strtolower($itemId);
        try {
            if (($this->denial($list, $itemId, false) !== null)
                || (($this->denial($list, $itemId, true) !== null) && ($this->campaignOf($list, $itemId)->active() === null))) {
                return '';
            }
            return $this->render($list, $itemId);
        } catch (\Throwable $e) {
            $this->logFailure($list, $itemId, $e);
            return '<span class="aa-mailbulk-text">' . safe(_m('The sending cannot be shown - see the log')) . '</span>';
        }
    }

    /** one value of the sending for a listing of one's own; '' without the right or for an unknown $what */
    public function info(string $list, string $itemId, string $what): string
    {
        $this->noStore();
        $itemId = strtolower($itemId);
        if (!in_array($what, self::INFO, true)) {
            return '';
        }
        try {
            if ($this->denial($list, $itemId, true) !== null) {
                return '';
            }
            $campaign = $this->campaignOf($list, $itemId);
            if ($what === 'state') {
                return $campaign->state();
            }
            $value = $campaign->counts()[$what];
            return (($what === 'date') && ($value === 0)) ? '' : (string) $value;
        } catch (\Throwable $e) {
            $this->logFailure($list, $itemId, $e);
            return '';
        }
    }

    /**
     * An action of the control: prepare (the question before a send), send, test, stop, resume, state.
     * @return array{string, int} [HTML of the control or of the question; on an error its plain text, the error]
     */
    public function act(string $action, string $list, string $itemId): array
    {
        $itemId = strtolower($itemId);
        try {
            $toSend = !in_array($action, self::WITHOUT_RELATION, true);
            $denial = $this->denial($list, $itemId, $toSend);
            if ($denial !== null) {
                $slice = $this->sliceOf($itemId);
                if ($toSend && ($slice !== null) && ($this->denial($list, $itemId, false) === null)) {
                    // a list not of the slice - for State Check
                    $this->logRefusal($list, $slice, $itemId);
                }
                return [$denial, self::ERR_DENIED];
            }
            return match ($action) {
                'prepare' => $this->prepare($list, $itemId),
                'send'    => $this->send($list, $itemId),
                'test'    => $this->test($list, $itemId),
                'stop'    => $this->change($list, $itemId, fn(Run $run) => $run->end($this->uid())),
                'resume'  => $this->change($list, $itemId, fn(Run $run) => $run->resume($this->uid())),
                'state'   => [$this->render($list, $itemId), 0],
                default   => [_m('Unknown action'), self::ERR_UNKNOWN_ACTION],
            };
        } catch (BatchFailed $e) {
            return [self::refusal($e->reason), self::ERR_FAILED];
        } catch (\Throwable $e) {
            $this->logFailure($list, $itemId, $e);
            return [_m('The action failed - see the log'), self::ERR_FAILED];
        }
    }

    // ------------------------------------------------------------ what tests replace

    /** the slice of the item; null = no such item */
    protected function itemSlice(string $itemId): ?string
    {
        return Run::sliceOfItem($itemId);
    }

    protected function listExists(string $list): bool
    {
        return DB_AA::test('alerts_collection', [['id', $list]]);
    }

    /** @param ?string $slice the item's slice, null = the item is gone */
    protected function mayControl(string $list, ?string $slice, bool $needsRelation): bool
    {
        return Run::mayControlPair($this->uid(), $list, $slice, $needsRelation);
    }

    protected function logRefusal(string $list, string $slice, string $itemId): void
    {
        AA_Collection::logRefusal($list, $slice, $itemId);
    }

    /** as a step of a run logs its failure - never the text, which may carry data or a secret */
    protected function logFailure(string $list, string $itemId, \Throwable $e): void
    {
        AA_Log::write(Toexecute::FAILED_EVENT, "Mailbulk_{$list}_{$itemId}", Toexecute::failureParams($itemId, $e));
    }

    protected function campaign(string $list, string $itemId): Campaign
    {
        return Campaign::of($list, $itemId);
    }

    protected function letter(string $list, string $itemId): ItemLetter
    {
        return new ItemLetter($this->collectionOf($list), $itemId);
    }

    /** the address of the list's site, as the admin set it */
    protected function siteUrl(string $list): string
    {
        return (string) $this->collectionOf($list)->getSliceUrl();
    }

    protected function mailer(): Mailer
    {
        return new AaMailer();
    }

    /** $fn with the item's slice open, and the readers' too when $readers - as a step of the run has them */
    protected function withSlices(string $list, string $itemId, bool $readers, Closure $fn): mixed
    {
        $slice  = $this->sliceOf($itemId);
        $slices = $readers ? Run::slicesOf($list, $slice) : array_filter([(string) $slice], is_long_id(...));
        return AA_Credentials::singleton()->withSlicesOpen($slices, $fn);
    }

    protected function uid(): string
    {
        return (string) AuthGetUid();
    }

    /** where a test goes when the item names no test address */
    protected function userEmail(): string
    {
        return (string) (AA::$perm->getIDsInfo($this->uid())['mail'] ?? '');
    }

    protected function noStore(): void
    {
        AA::Pagecache()->switchCacheNostore();
    }

    // ------------------------------------------------------------ actions

    /** @return array{string, int} */
    private function prepare(string $list, string $itemId): array
    {
        $campaign = $this->campaignOf($list, $itemId);
        if ($campaign->unfinished()) {
            return [self::refusal(Campaign::ACTIVE), self::ERR_FAILED];
        }
        return $this->withLetter($list, $itemId, static function (ItemLetter $letter) use ($campaign): array {
            $count = count($campaign->pending($letter));
            return ($count > 0) ? [safe(_m('Send to %1 recipients?', [$count])), 0] : [self::refusal(Campaign::NOBODY), self::ERR_FAILED];
        });
    }

    /** @return array{string, int}
     *  @throws BatchFailed what Campaign::start() refuses */
    private function send(string $list, string $itemId): array
    {
        $started = $this->withLetter($list, $itemId, function (ItemLetter $letter) use ($list, $itemId): array {
            $this->campaignOf($list, $itemId)->start($letter, $this->uid(), $this->trackBase($list));
            return ['', 0];
        });
        return ($started[1] === 0) ? $this->fresh($list, $itemId) : $started;
    }

    /** the mail of a reader to the test addresses, sent now - no run, no state, no reader's code
     *  @return array{string, int}
     *  @throws BatchFailed RENDER the mail could not be made */
    private function test(string $list, string $itemId): array
    {
        $tested = $this->withLetter($list, $itemId, function (ItemLetter $letter) use ($list, $itemId): array {
            $addresses = $letter->testAddresses($this->userEmail());
            if (!$addresses) {
                return [_m('There is no address to send the test to: fill %1 or your e-mail', [ItemLetter::MAILTEST]), self::ERR_FAILED];
            }
            $reader  = $letter->testReader($addresses[0]);
            $message = ($reader === null) ? null : $this->campaignOf($list, $itemId)->testMessage($letter, [$reader]);
            if ($message === null) {
                return [self::refusal(Campaign::NOBODY), self::ERR_FAILED];
            }
            if (!Campaign::hasContent($message)) {
                return [self::refusal(Campaign::EMPTY), self::ERR_FAILED];
            }
            $mailer = $this->mailer();
            $sent   = [];
            $failed = [];
            foreach ($addresses as $address) {
                $mailer->prepare(new Message($address, $message->subject, $message->html, $message->from, $message->headers));
                if ($mailer->send() === Outcome::Sent) {
                    $sent[] = $address;
                } else {
                    $failed[] = $address . ' (' . $mailer->detail() . ')';
                }
            }
            $note = $sent ? _m('The test went to %1.', [implode(', ', $sent)]) : '';
            if ($failed) {
                $note = trim($note . ' ' . _m('Not sent to %1.', [implode(', ', $failed)]));
            }
            return [$note, 0];
        });
        // what went where is said in the control, failed addresses too
        return ($tested[1] === 0) ? [$this->render($list, $itemId, $tested[0]), 0] : $tested;
    }

    /** stop or Continue the run not finished yet
     *  @param Closure(Run): void $change
     *  @return array{string, int}
     *  @throws BatchFailed what Run::end() or resume() refuses */
    private function change(string $list, string $itemId, Closure $change): array
    {
        $run = $this->campaignOf($list, $itemId)->active();
        if ($run === null) {
            return [_m('Nothing is being sent'), self::ERR_FAILED];
        }
        DB_AA::transaction(static fn() => $change($run::lockForUpdate((string) $run->getId()) ?? throw new BatchFailed('WRONG_STATE')));
        return $this->fresh($list, $itemId);
    }

    /** $fn with the letter and the slices of the readers open; the letter's problem is the error
     *  @param Closure(ItemLetter): array{string, int} $fn
     *  @return array{string, int} */
    private function withLetter(string $list, string $itemId, Closure $fn): array
    {
        $problem = $this->problemOf($list, $itemId);
        if ($problem !== null) {
            return [$problem, self::ERR_FAILED];
        }
        return $this->withSlices($list, $itemId, true, fn(): array => $fn($this->letterOf($list, $itemId)));
    }

    // ------------------------------------------------------------ what one request reads once

    private function sliceOf(string $itemId): ?string
    {
        if (!array_key_exists($itemId, $this->slices)) {
            $this->slices[$itemId] = $this->itemSlice($itemId);
        }
        return $this->slices[$itemId];
    }

    private function collectionOf(string $list): AA_Collection
    {
        return $this->collections[$list] ??= new AA_Collection($list);
    }

    /** where the tracked addresses of the mails lead: the list's site with the path of this tree - known
     *  here, as a step may run in another tree; this tree's own address when the site gives no host */
    private function trackBase(string $list): string
    {
        $site   = parse_url($this->siteUrl($list)) ?: [];
        $scheme = strtolower((string) ($site['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || (($site['host'] ?? '') === '')) {
            return (string) constant('AA_INSTAL_URL');
        }
        return $scheme . '://' . $site['host'] . (isset($site['port']) ? ':' . $site['port'] : '') . constant('AA_INSTAL_PATH');
    }

    private function campaignOf(string $list, string $itemId): Campaign
    {
        return $this->campaigns["$list:$itemId"] ??= $this->campaign($list, $itemId);
    }

    private function letterOf(string $list, string $itemId): ItemLetter
    {
        return $this->letters["$list:$itemId"] ??= $this->letter($list, $itemId);
    }

    /** why the item cannot be sent now; with the item's slice open only - no template on a page runs with
     *  the readers' slice open */
    private function problemOf(string $list, string $itemId): ?string
    {
        $key = "$list:$itemId";
        if (!array_key_exists($key, $this->problems)) {
            $this->problems[$key] = $this->withSlices($list, $itemId, false, fn(): ?string => $this->letterOf($list, $itemId)->problem());
        }
        return $this->problems[$key];
    }

    /** the control after an action changed the sending: its runs read anew
     *  @return array{string, int} */
    private function fresh(string $list, string $itemId): array
    {
        unset($this->campaigns["$list:$itemId"]);
        return [$this->render($list, $itemId), 0];
    }

    /** why the user may not act on the sending; null = they may
     *  @param bool $toSend the action sends: the list must belong to the item's slice */
    private function denial(string $list, string $itemId, bool $toSend): ?string
    {
        $key = "$list:$itemId:" . (int) $toSend;
        if (!array_key_exists($key, $this->denials)) {
            $this->denials[$key] = $this->findDenial($list, $itemId, $toSend);
        }
        return $this->denials[$key];
    }

    private function findDenial(string $list, string $itemId, bool $toSend): ?string
    {
        if ($toSend) {
            return $this->denial($list, $itemId, false) ?? ($this->rights($list, $this->sliceOf($itemId), true) ? null : _m("The list is not related to the item's slice"));
        }
        if (!AA_Collection::isValidId($list) || !is_long_id($itemId) || !($this->lists[$list] ??= $this->listExists($list))) {
            return _m('Invalid list or item');
        }
        return $this->rights($list, $this->sliceOf($itemId), false) ? null : _m('You may not send this item');
    }

    /** mayControl() once per list, slice and relation - the items of a listing mostly share them */
    private function rights(string $list, ?string $slice, bool $needsRelation): bool
    {
        return $this->rights[$list . ':' . ($slice ?? '') . ':' . (int) $needsRelation] ??= $this->mayControl($list, $slice, $needsRelation);
    }

    // ------------------------------------------------------------ rendering

    private function render(string $list, string $itemId, string $note = ''): string
    {
        $campaign = $this->campaignOf($list, $itemId);
        $runs     = $campaign->runs();
        $last     = $runs ? end($runs) : null;
        $state    = $campaign->state();
        // how far the newest run got of its own readers - the figures over all runs count a stopped run only as far as it went
        $progress = (int) $last?->getProperty('cursor') . ' / ' . (int) $last?->getProperty('total');
        $parts    = [];
        switch ($state) {
            case Run::SENDING:
                $parts[]  = _m('Sending %1', [$progress]);
                $buttons  = ['stop' => _m('Stop')];
                break;
            case Run::ERROR:
                $parts[]  = self::errorOf($last);
                $parts[]  = $progress;
                $buttons  = ['resume' => _m('Continue'), 'stop' => _m('Stop')];
                break;
            case Run::ENDED:
                $parts[]  = _m('Stopped %1', [$progress]);
                $buttons  = ['send' => _m('Continue…')];
                break;
            case Run::DONE:
                $counts   = $campaign->counts();
                $parts[]  = ($counts['date'] > 0) ? date('Y-m-d H:i', $counts['date']) : '';
                $parts[]  = self::figures($counts);
                $buttons  = ['send' => _m('Send to new…')];
                break;
            default:
                $buttons  = ['test' => _m('Test'), 'send' => _m('Send…')];
        }
        if ($this->denial($list, $itemId, true) !== null) {
            // the list left the item's slice: what was started can only be stopped
            $parts[] = _m("The list is not related to the item's slice");
            $buttons = array_intersect_key($buttons, ['stop' => true]);
        } elseif (isset($buttons['send']) && (($problem = $this->problemOf($list, $itemId)) !== null)) {
            // a sending that cannot go says so in place of the buttons that would fail
            $parts[] = $problem;
            $buttons = [];
        }
        $html = '';
        foreach (array_filter([...$parts, $note], static fn(string $p): bool => $p !== '') as $part) {
            $html .= '<span class="aa-mailbulk-text">' . safe($part) . '</span> ';
        }
        foreach ($buttons as $action => $label) {
            $html .= '<button type="button" data-action="' . $action . '">' . safe($label) . '</button> ';
        }
        return '<span class="aa-mailbulk" data-aa-mailbulk data-list="' . safe($list) . '" data-item="' . safe($itemId) . '" data-state="' . safe($state) . '">' . trim($html) . '</span>';
    }

    /** sent / failed / skipped / uncertain, the nonzero ones
     *  @param array{total: int, sent: int, failed: int, skipped: int, uncertain: int, date: int} $counts */
    private static function figures(array $counts): string
    {
        $texts = [
            'sent'      => _m('sent %1', [$counts['sent']]),
            'failed'    => _m('failed %1', [$counts['failed']]),
            'skipped'   => _m('skipped %1', [$counts['skipped']]),
            'uncertain' => _m('uncertain %1', [$counts['uncertain']]),
        ];
        return implode(', ', array_intersect_key($texts, array_filter($counts)));
    }

    /** what the run in error says, for the user */
    private static function errorOf(?Run $run): string
    {
        $note = trim((string) $run?->getProperty('note'));
        if ($note !== '') {
            return $note;
        }
        return match ((string) $run?->getProperty('error_code')) {
            Run::RECIPIENTS => _m('The list of the readers of the sending is damaged'),
            default         => _m('The sending stopped on an error'),
        };
    }

    /** a refusal of the engine, for the user */
    private static function refusal(string $reason): string
    {
        return match ($reason) {
            Campaign::ACTIVE         => _m('A sending of the item to the list is not finished yet'),
            Campaign::BUSY           => _m('Someone else is starting the sending right now'),
            Campaign::LOCK           => _m('The database gives no lock to start the sending - try again later'),
            Campaign::NOBODY         => _m('Nobody is left to send the item to'),
            Campaign::EMPTY          => _m('The mail has no subject or no text'),
            Campaign::RENDER         => _m('The mail could not be made - see the log'),
            Campaign::PROBLEM        => _m('The item cannot be sent now'),
            BatchRun::FORBIDDEN      => _m('You may not control this sending'),
            BatchRun::NO_TRANSACTION => _m('The database cannot record the sending safely. Ask the administrator.'),
            'LEASE'                  => _m('A step of the sending is still working - try again in a minute'),
            'WRONG_STATE'            => _m('The sending is not in a state for this'),
            default                  => _m('The action failed (%1)', [$reason]),
        };
    }
}
