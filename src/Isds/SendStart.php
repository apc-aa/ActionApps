<?php

declare(strict_types=1);

namespace AA\Isds;

use AA\Later\BatchRun;

/**
 * What came of a request to send a campaign (SendQueue::queue()): its run queued, another request
 * holding the campaign right now (busy - nothing written, try again), or refused with a code that says
 * what to fix. Every detail is plain text that may quote stored data - a filter, slice and file names,
 * URLs, the status ISDS refused with: escape it (safe()) whenever shown, and never log it or put it
 * into a BatchFailed.
 */
final readonly class SendStart
{
    public const STATUS_QUEUED  = 'queued';
    public const STATUS_BUSY    = 'busy';
    public const STATUS_REFUSED = 'refused';

    /** another request queues, stops or continues the campaign now */
    public const BUSY = SendRun::BUSY;
    /** the user may not send from the campaign slice (Access) - as BatchRun refuses a run's control */
    public const FORBIDDEN = BatchRun::FORBIDDEN;
    /** no active item of the campaign slice of the page has that id */
    public const UNKNOWN = 'UNKNOWN';
    /** the campaign is no draft: queued or sent already, or its copy is to be sent */
    public const NOT_DRAFT = 'NOT_DRAFT';
    /** the campaign has a run, even if its state was set back to draft by hand: a campaign is sent once */
    public const ALREADY = 'ALREADY';
    /** the campaign stores no recipient filter: the default one is a preview, never a sending */
    public const NO_FILTER = 'NO_FILTER';
    /** the stored filter is not one the page offers */
    public const BAD_FILTER = 'BAD_FILTER';
    /** the recipients are not as many as the user confirmed - the territory changed meanwhile */
    public const COUNT_CHANGED = 'COUNT_CHANGED';
    /** more recipients than max_recipients of the page */
    public const TOO_MANY = 'TOO_MANY';
    /** no recipient gets the letter */
    public const NOTHING = 'NOTHING';
    /** no usable ISDS account is bound to the site and the two slices, or the box of its login is
     *  not the one the hosted account names; the detail tells how to bind one */
    public const ACCOUNT = 'ACCOUNT';
    /** the site, the slices or datovka_conf.php are not ready (SliceRules); the detail lists the problems */
    public const SETTINGS = SendRun::SETTINGS;
    /** the letter cannot go as a data message; the detail says why */
    public const CONTENT = 'CONTENT';
    /** the server could not make the letter now (Body\ServerException): nothing in the campaign to fix; the detail is for a developer */
    public const SERVER = 'SERVER';
    /** memory_limit is too low to freeze the files and cannot be raised; the detail is the MB needed */
    public const MEMORY = SendRun::MEMORY;
    /** a table the sending writes in one transaction is not InnoDB, or the database gave no transaction; the detail is the table */
    public const NO_TRANSACTION = BatchRun::NO_TRANSACTION;
    // and the codes of ISDS asked whose the login is, as a run gives them: Outcome::LOGIN, BLOCKED,
    // CONFIG, UNAVAILABLE and SendRun::REFUSED with the status as the detail

    /** STATUS_QUEUED, STATUS_BUSY or STATUS_REFUSED */
    public string $status;

    /**
     * @param string $code  '' = queued; BUSY; or why refused
     * @param string $runId the queued run
     */
    private function __construct(public string $code, public string $runId = '', public string $detail = '')
    {
        $this->status = match ($code) {
            ''         => self::STATUS_QUEUED,
            self::BUSY => self::STATUS_BUSY,
            default    => self::STATUS_REFUSED,
        };
    }

    public static function queued(string $runId): self
    {
        return new self('', $runId);
    }

    /** @param string $code BUSY or a code of refusal */
    public static function refused(string $code, string $detail = ''): self
    {
        if ($code === '') {
            throw new \InvalidArgumentException('A refusal has a code');
        }
        return new self($code, '', $detail);
    }
}
