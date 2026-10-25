<?php

declare(strict_types=1);

namespace AA\Isds;

/**
 * What came of a test message of a draft (SendQueue::test()): sent, failed or uncertain - its message
 * item ($msg) tells the same; a login or certificate failure while asking the price fails with no item -,
 * another test on its way (busy, nothing sent), or refused with a code before anything was sent. The detail is plain text that may quote stored data or the words of ISDS -
 * never log it. Escape every text of it (safe()) whenever shown. Across a redirect only $msg or a code goes.
 */
final readonly class TestResult
{
    public const STATUS_SENT      = 'sent';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_UNCERTAIN = 'uncertain';
    public const STATUS_REFUSED   = 'refused';
    public const STATUS_BUSY      = 'busy';

    /** another test of the pair of slices is on its way, or one died a moment ago - try again later */
    public const BUSY = SendRun::BUSY;
    /** the user may not send from the campaign slice (Access) */
    public const FORBIDDEN = SendStart::FORBIDDEN;
    /** no active item of the campaign slice of the page has that id */
    public const UNKNOWN = SendStart::UNKNOWN;
    /** the campaign is no draft; ALREADY: a run of it exists */
    public const NOT_DRAFT = SendStart::NOT_DRAFT;
    public const ALREADY   = SendStart::ALREADY;
    /** the page has no test box - in production only datovka_conf.php gives one (Accounts::TEST_BOXES) */
    public const NO_TEST_BOX = 'NO_TEST_BOX';
    /** the box asked for is not a test box of the page, or no box at all */
    public const NOT_TEST_BOX = 'NOT_TEST_BOX';
    /** the box the account sends from */
    public const OWN_BOX = 'OWN_BOX';
    /** ISDS is being maintained (SendPace::maintenanceEnd()); the detail is the time it ends */
    public const MAINTENANCE = SendRun::MAINTENANCE;
    /** this letter went, or may have gone, to this box already; $msg, $ref, $dmId and $at are those of that test */
    public const SAME_LETTER = 'SAME_LETTER';
    /** production: the message would be a postal data message, which someone pays for, and paid_tests of
     *  the account does not allow it */
    public const PAID = 'PAID';
    /** production: ISDS did not say whether the message would be paid - the box may not exist, the login
     *  may be refused; $isdsCode is the status ISDS answered with, if any - with paid_tests only such a
     *  status refuses. Check the box and try again */
    public const PAID_UNKNOWN = 'PAID_UNKNOWN';
    // and as SendStart: ACCOUNT, SETTINGS, CONTENT, SERVER, MEMORY

    /** what a test sent or uncertain cost (charge()), as a code that goes across a redirect: a paid message
     *  went - only paid_tests of the account lets one go -, or one may have, of which ISDS did not say */
    public const CHARGED        = 'CHARGED';
    public const MAY_BE_CHARGED = 'MAY_BE_CHARGED';

    /**
     * @param string $status   STATUS_*
     * @param string $code     '' = sent or uncertain; BUSY; a code of refusal; failed: Outcome::LOGIN,
     *                         BLOCKED, CONFIG, UNAVAILABLE when nothing left, '' when ISDS refused it
     * @param string $msg      the message item of the test (of the earlier one for SAME_LETTER)
     * @param string $box      the test box
     * @param string $ref      dmSenderRefNumber, by which the message is found in the box's sent ones
     * @param string $dmId     sent: the id ISDS gave the message
     * @param string $isdsCode the status ISDS answered with, '' = none
     * @param string $detail   what to fix, or the words of ISDS - escape it
     * @param ?bool  $paid     whether the message would be paid in production; null = not known
     * @param int    $at       SAME_LETTER: when the earlier test was sent, 0 = not known
     * @param ?bool  $charged  whether the message cost money: null = it may have; false for any that did not go
     */
    private function __construct(
        public string $status,
        public string $code = '',
        public string $msg = '',
        public string $box = '',
        public string $ref = '',
        public string $dmId = '',
        public string $isdsCode = '',
        public string $detail = '',
        public ?bool $paid = null,
        public int $at = 0,
        public ?bool $charged = false,
    ) {}

    /** @param string $code BUSY or a code of refusal */
    public static function refused(string $code, string $detail = '', string $msg = '', string $ref = '', string $dmId = '', string $isdsCode = '', int $at = 0): self
    {
        if ($code === '') {
            throw new \InvalidArgumentException('A refusal has a code');
        }
        return new self(($code === self::BUSY) ? self::STATUS_BUSY : self::STATUS_REFUSED, $code, $msg, ref: $ref, dmId: $dmId, isdsCode: $isdsCode, detail: $detail, at: $at);
    }

    /**
     * the test as its message item was marked: nothing left (queued) is failed with the run's code
     * @param ?bool $charges whether the message costs money if it goes: production and paid; null = not known
     */
    public static function of(Outcome $outcome, string $msg, string $box, string $ref, ?bool $paid, ?bool $charges): self
    {
        $status = match ($outcome->state) {
            Schema::MESSAGE_STATE_SENT      => self::STATUS_SENT,
            Schema::MESSAGE_STATE_UNCERTAIN => self::STATUS_UNCERTAIN,
            default                         => self::STATUS_FAILED,
        };
        // an uncertain one may say ISDS did not answer: no matter, it is not sent again either way
        $code = ($status === self::STATUS_FAILED) ? $outcome->code : '';
        $charged = ($status === self::STATUS_FAILED) ? false : $charges;
        return new self($status, $code, $msg, $box, $ref, $outcome->dmId, $outcome->isdsCode, $outcome->text, $paid, charged: $charged);
    }

    /** CHARGED, MAY_BE_CHARGED - an uncertain one may not have gone -, or '' when it cost nothing */
    public function charge(): string
    {
        return match (true) {
            $this->charged === false                                           => '',
            ($this->charged === true) && ($this->status === self::STATUS_SENT) => self::CHARGED,
            default                                                            => self::MAY_BE_CHARGED,
        };
    }
}
