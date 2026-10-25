<?php

declare(strict_types=1);

namespace AA\Isds;

/**
 * What an action of a page of a mass sending did (Actions): codes, numbers and ids, no text - Advice
 * makes the text in the language of the page when it shows. The session keeps it till the page the
 * browser is sent to after the action, as plain values (toArray()).
 */
final readonly class ActionResult
{
    public const DONE    = 'done';
    public const REFUSED = 'refused';
    /** another request holds the campaign: nothing was done, the same may be tried again */
    public const BUSY    = 'busy';
    /** the form was not taken: not one of this page, or not posted from it */
    public const EXPIRED = 'expired';
    /** the action ran and failed: an internal error - it may have done part of its work -, or ISDS refused the test message sent */
    public const FAILED  = 'failed';

    /** bytes of the detail kept: it may quote stored data, and the session holds it */
    public const DETAIL_MAX = 2000;

    /** plain text of the core that may quote stored data - escape it whenever shown, never log it */
    public string $detail;

    /**
     * @param string           $action   the action that ran; '' = none - the form was not taken
     * @param string           $status   DONE, REFUSED, BUSY, EXPIRED or FAILED
     * @param string           $code     what Advice tells
     * @param string           $campaign long id the action was on; '' = none
     * @param list<int|string> $params   what the text of the code names: numbers, ids AA or ISDS gave - a
     *                                   status of ISDS, a reference number AA checked -, and a field of the
     *                                   form (Drafts) with the name of a file refused, in safe letters
     *                                   (UploadPolicy::safeName()), never stored text
     * @param string           $charge   what a test message cost (TestResult::charge()), a second code
     *                                   Advice tells beside the first; '' = nothing
     */
    public function __construct(
        public string $action,
        public string $status,
        public string $code,
        public string $campaign = '',
        string $detail = '',
        public array $params = [],
        public string $charge = '',
    ) {
        $this->detail = mb_strcut($detail, 0, self::DETAIL_MAX, 'UTF-8');
    }

    /** an action ran: the page reloaded after it must show its result, not repeat it */
    public function taken(): bool
    {
        return $this->action !== '';
    }

    /** @return array{action: string, status: string, code: string, campaign: string, detail: string, params: list<int|string>, charge: string} */
    public function toArray(): array
    {
        return ['action' => $this->action, 'status' => $this->status, 'code' => $this->code, 'campaign' => $this->campaign, 'detail' => $this->detail, 'params' => $this->params, 'charge' => $this->charge];
    }

    /** what toArray() gave; null for anything else */
    public static function fromArray(mixed $values): ?self
    {
        if (!is_array($values)) {
            return null;
        }
        foreach (['action', 'status', 'code', 'campaign', 'detail'] as $key) {
            if (!is_string($values[$key] ?? null)) {
                return null;
            }
        }
        $params = $values['params'] ?? null;
        if (!is_array($params) || !array_is_list($params) || array_filter($params, static fn(mixed $p): bool => !is_int($p) && !is_string($p))) {
            return null;
        }
        // a result an older AA kept in the session has none
        $charge = $values['charge'] ?? '';

        return is_string($charge) ? new self($values['action'], $values['status'], $values['code'], $values['campaign'], $values['detail'], $params, $charge) : null;
    }
}
