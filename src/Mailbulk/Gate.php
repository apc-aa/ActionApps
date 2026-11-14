<?php

declare(strict_types=1);

namespace AA\Mailbulk;

use AA;
use AA\IO\DB\DB_AA;
use AA\Later\BatchFailed;
use AA\Later\BatchRun;
use AA\Security\ViewSlices;
use AA_Collection;
use AA_Credentials;
use Closure;

/**
 * The control of the sending of one item to one list, for an editor of all items of the item's slice when
 * the list belongs to that slice. Prints the control and runs its actions; the actions come only as POST
 * through central/responder.php (AA_Responder_Mailbulk), never from expanding a template.
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

    /** @var array<string, ?string> item id => its slice */
    private array $slices = [];

    /** the user may send the item to the list */
    public function allowed(string $list, string $itemId): bool
    {
        return $this->denial($list, $itemId) === null;
    }

    /** the control as HTML; '' without the right */
    public function control(string $list, string $itemId): string
    {
        if (!$this->allowed($list, $itemId)) {
            return '';
        }
        // the figures change while sending, and the control is only for its editors
        $this->noStore();
        return $this->render($list, $itemId);
    }

    /** one value of the sending for a listing of one's own; '' without the right or for an unknown $what */
    public function info(string $list, string $itemId, string $what): string
    {
        if (!in_array($what, self::INFO, true) || !$this->allowed($list, $itemId)) {
            return '';
        }
        $this->noStore();
        $campaign = $this->campaign($list, $itemId);
        if ($what === 'state') {
            return $campaign->state();
        }
        $value = $campaign->counts()[$what];
        return (($what === 'date') && ($value === 0)) ? '' : (string) $value;
    }

    /**
     * An action of the control: prepare (the question before a send), send, test, stop, resume, state.
     * @return array{string, int} [the text - HTML of the control, or a message; the error, 0 = none]
     */
    public function act(string $action, string $list, string $itemId): array
    {
        $denial = $this->denial($list, $itemId, true);
        if ($denial !== null) {
            return [safe($denial), self::ERR_DENIED];
        }
        try {
            return match ($action) {
                'prepare' => $this->prepare($list, $itemId),
                'send'    => $this->send($list, $itemId),
                'test'    => $this->test($list, $itemId),
                'stop'    => $this->stop($list, $itemId),
                'resume'  => $this->resume($list, $itemId),
                'state'   => [$this->render($list, $itemId), 0],
                default   => [safe(_m('Unknown action')), self::ERR_UNKNOWN_ACTION],
            };
        } catch (BatchFailed $e) {
            return [safe(self::refusal($e->reason)), self::ERR_FAILED];
        }
    }

    // ------------------------------------------------------------ what tests replace

    /** the slice of the item; null = no such item */
    protected function itemSlice(string $itemId): ?string
    {
        $slices = ViewSlices::itemSlicesFromDb('l', [$itemId]);
        return $slices ? (string) reset($slices) : null;
    }

    protected function mayEdit(string $slice): bool
    {
        return IfSlPerm(PS_EDIT_ALL_ITEMS, $slice);
    }

    protected function relates(string $list, string $slice): bool
    {
        self::loadAlerts();
        return AA_Collection::relatesToSlice($list, $slice);
    }

    protected function logRefusal(string $list, string $slice, string $itemId): void
    {
        self::loadAlerts();
        AA_Collection::logRefusal($list, $slice, $itemId);
    }

    protected function campaign(string $list, string $itemId): Campaign
    {
        return Campaign::of($list, $itemId);
    }

    protected function letter(string $list, string $itemId): ItemLetter
    {
        self::loadAlerts();
        return new ItemLetter(new AA_Collection($list), $itemId);
    }

    protected function mailer(): Mailer
    {
        return new AaMailer();
    }

    /** $fn with the slices of the readers and of the item open, as a step of the run has them */
    protected function withSlices(string $list, string $slice, Closure $fn): mixed
    {
        $readers = DB_AA::select1('slice_id', 'SELECT slice_id FROM `alerts_collection`', [['id', $list]]);
        $slices  = array_unique(array_filter([is_string($readers) ? unpack_id($readers) : '', $slice], is_long_id(...)));
        return AA_Credentials::singleton()->withSlicesOpen(array_values($slices), $fn);
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
        return $this->withSlices($list, (string) $this->sliceOf($itemId), function () use ($list, $itemId): array {
            $letter  = $this->letter($list, $itemId);
            $problem = $letter->problem();
            if ($problem !== null) {
                return [safe($problem), self::ERR_FAILED];
            }
            $count = count($this->campaign($list, $itemId)->pending($letter));
            return ($count > 0) ? [safe(_m('Send to %1 recipients?', [$count])), 0] : [safe(self::refusal(Campaign::NOBODY)), self::ERR_FAILED];
        });
    }

    /** @return array{string, int}
     *  @throws BatchFailed what Campaign::start() refuses */
    private function send(string $list, string $itemId): array
    {
        $this->withSlices($list, (string) $this->sliceOf($itemId), fn(): Run => $this->campaign($list, $itemId)->start($this->letter($list, $itemId), $this->uid()));
        return [$this->render($list, $itemId), 0];
    }

    /** the mail of a reader to the test addresses, sent now - no run, no state, no reader's code
     *  @return array{string, int} */
    private function test(string $list, string $itemId): array
    {
        return $this->withSlices($list, (string) $this->sliceOf($itemId), function () use ($list, $itemId): array {
            $letter  = $this->letter($list, $itemId);
            $problem = $letter->problem();
            if ($problem !== null) {
                return [safe($problem), self::ERR_FAILED];
            }
            $addresses = $letter->testAddresses($this->userEmail());
            if (!$addresses) {
                return [safe(_m('There is no address to send the test to: fill %1 or your e-mail', [ItemLetter::MAILTEST])), self::ERR_FAILED];
            }
            $reader  = $letter->testReader($addresses[0]);
            $message = ($reader === null) ? null : $letter->messageFor($reader, true);
            if ($message === null) {
                return [safe(self::refusal(Campaign::NOBODY)), self::ERR_FAILED];
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
            return [$this->render($list, $itemId, $note), $sent ? 0 : self::ERR_FAILED];
        });
    }

    /** @return array{string, int}
     *  @throws BatchFailed what Run::end() refuses */
    private function stop(string $list, string $itemId): array
    {
        $run = $this->campaign($list, $itemId)->active();
        if ($run === null) {
            return [safe(_m('Nothing is being sent')), self::ERR_FAILED];
        }
        DB_AA::transaction(fn() => ($run::lockForUpdate((string) $run->getId()) ?? throw new BatchFailed('WRONG_STATE'))->end($this->uid()));
        return [$this->render($list, $itemId), 0];
    }

    /** Continue after an error: the same run goes on
     *  @return array{string, int}
     *  @throws BatchFailed what Run::resume() refuses */
    private function resume(string $list, string $itemId): array
    {
        $run = $this->campaign($list, $itemId)->active();
        if ($run === null) {
            return [safe(_m('Nothing is being sent')), self::ERR_FAILED];
        }
        DB_AA::transaction(fn() => ($run::lockForUpdate((string) $run->getId()) ?? throw new BatchFailed('WRONG_STATE'))->resume($this->uid()));
        return [$this->render($list, $itemId), 0];
    }

    // ------------------------------------------------------------ rendering

    /** itemSlice() once per item - the rights, the slices to open and the control all ask */
    private function sliceOf(string $itemId): ?string
    {
        if (!array_key_exists($itemId, $this->slices)) {
            $this->slices[$itemId] = $this->itemSlice($itemId);
        }
        return $this->slices[$itemId];
    }

    /** why the user may not send the item to the list; null = they may
     *  @param bool $log a list not of the slice is logged for State Check */
    private function denial(string $list, string $itemId, bool $log = false): ?string
    {
        self::loadAlerts();
        if (!AA_Collection::isValidId($list) || !is_long_id($itemId)) {
            return _m('Invalid list or item');
        }
        $slice = $this->sliceOf($itemId);
        if (($slice === null) || !$this->mayEdit($slice)) {
            return _m('You may not send this item');
        }
        if (!$this->relates($list, $slice)) {
            if ($log) {
                $this->logRefusal($list, $slice, $itemId);
            }
            return _m("The list is not related to the item's slice");
        }
        return null;
    }

    private function render(string $list, string $itemId, string $note = ''): string
    {
        $campaign = $this->campaign($list, $itemId);
        $runs     = $campaign->runs();
        $last     = $runs ? end($runs) : null;
        $state    = $campaign->state();
        $counts   = $campaign->counts();
        // how far the newest run got of its own readers - the figures over all runs count a stopped run only as far as it went
        $progress = (int) $last?->getProperty('cursor') . ' / ' . (int) $last?->getProperty('total');
        $parts    = [];
        $buttons  = [];
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
                $parts[]  = ($counts['date'] > 0) ? date('Y-m-d H:i', $counts['date']) : '';
                $parts[]  = self::figures($counts);
                $buttons  = ['send' => _m('Send to new…')];
                break;
            default:
                $buttons  = ['test' => _m('Test'), 'send' => _m('Send…')];
        }
        if (isset($buttons['send'])) {
            // a sending that cannot go says so in place of the buttons that would fail
            $problem = $this->withSlices($list, (string) $this->sliceOf($itemId), fn(): ?string => $this->letter($list, $itemId)->problem());
            if ($problem !== null) {
                $parts[] = $problem;
                $buttons = [];
            }
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
        $shown = [];
        foreach (['sent', 'failed', 'skipped', 'uncertain'] as $key) {
            $n = $counts[$key];
            if ($n > 0) {
                $shown[] = match ($key) {
                    'sent'      => _m('sent %1', [$n]),
                    'failed'    => _m('failed %1', [$n]),
                    'skipped'   => _m('skipped %1', [$n]),
                    'uncertain' => _m('uncertain %1', [$n]),
                };
            }
        }
        return implode(', ', $shown);
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
            Campaign::ACTIVE    => _m('A sending of the item to the list is not finished yet'),
            Campaign::BUSY      => _m('Someone else is starting the sending right now'),
            Campaign::NOBODY    => _m('Nobody is left to send the item to'),
            Campaign::EMPTY     => _m('The mail has no subject or no text'),
            Campaign::RENDER    => _m('The mail could not be made - see the log'),
            Campaign::PROBLEM   => _m('The item cannot be sent now'),
            BatchRun::FORBIDDEN => _m('You may not control this sending'),
            'LEASE'             => _m('A step of the sending is still working - try again in a minute'),
            'WRONG_STATE'       => _m('The sending is not in a state for this'),
            default             => _m('The action failed (%1)', [$reason]),
        };
    }

    private static function loadAlerts(): void
    {
        require_once constant('AA_BASE_PATH') . 'modules/alerts/alerts_sending.php';
    }
}
