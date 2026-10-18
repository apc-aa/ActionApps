<?php

declare(strict_types=1);

namespace AA\Isds;

use AA\Later\BatchFailed;

/**
 * When a mass sending may call ISDS, how long it waits, and when it stops trying - pure, so that each
 * rule is tested by itself and a run reads them the same in every step.
 */
final class SendPace
{
    /** the first pause in seconds, doubled with each next one up to LONGEST_WAIT */
    public const FIRST_WAIT   = 30;
    public const LONGEST_WAIT = 600;
    /** pauses in a row that long end the run in error: ISDS is not just being maintained */
    public const LONGEST_PAUSE = 6 * 3600;

    /** seconds a message takes beyond CreateMessage: its item marked sending before the request and what
     *  came of it recorded after it, before another step may claim the run */
    public const RECORD_RESERVE = 30;

    /** a message started this many seconds before the maintenance may still be on its way in it: the
     *  check of its box and CreateMessage, each to its timeout, and its records - a request cut by the
     *  maintenance ends uncertain */
    public const MAINTENANCE_LEAD = Client::TIMEOUT + Client::CREATE_TIMEOUT + self::RECORD_RESERVE;

    /** answers in a row that do not say what ISDS did with a message: the next message waits */
    public const UNCLEAR = 2;
    /** the same refusal in a row: something refuses every message, a person has to look */
    public const REFUSALS = 5;

    /**
     * The end of the weekly maintenance of ISDS - Friday 0:00 to 1:00 by Czech time (Provozní řád
     * ISDS § 20) - when $now is in it or MAINTENANCE_LEAD before it; null = ISDS may be called
     */
    public static function maintenanceEnd(int $now): ?int
    {
        $zone = new \DateTimeZone('Europe/Prague');
        foreach ([$now, $now + self::MAINTENANCE_LEAD] as $time) {
            $at = (new \DateTimeImmutable("@$time"))->setTimezone($zone);
            if ($at->format('N G') === '5 0') {
                return $at->setTime(1, 0)->getTimestamp();
            }
        }
        return null;
    }

    /**
     * The time the pause of $at waits till, 0 = none. No pause ends further ahead than LONGEST_WAIT or
     * the end of the hour of maintenance from its lead on; one that does - a record edited by hand, a
     * clock set back - waits for nothing, or the run would wait without a word for ever.
     * @param array<string, mixed> $at
     */
    public static function pauseEnd(array $at, int $now): int
    {
        $until = (int) ($at['u'] ?? 0);
        return ($until - $now > max(self::LONGEST_WAIT, 3600 + self::MAINTENANCE_LEAD)) ? 0 : $until;
    }

    /**
     * The position of one more pause: w pauses in a row, the step waits till u, the first began at s.
     * It moves the position, so that a pause spends none of the run's retries.
     * @param array<string, mixed> $at
     * @return array<string, mixed>
     * @throws BatchFailed $code after LONGEST_PAUSE of pauses in a row
     */
    public static function paused(array $at, int $now, string $code): array
    {
        $since = (int) ($at['s'] ?? $now);
        if ($now - $since > self::LONGEST_PAUSE) {
            throw new BatchFailed($code);
        }
        // a negative count would make the wait a fraction, which a position cannot hold
        $waits = max(0, (int) ($at['w'] ?? 0));
        return ['w' => $waits + 1, 'u' => $now + min(self::LONGEST_WAIT, self::FIRST_WAIT * 2 ** min($waits, 5)), 's' => $since] + $at;
    }

    /**
     * The attempts in a row that ended alike, after one more: "<n>:U" of unknown fate, "<n>:F<status>"
     * refused with that status. A message sent ends the streak; a message that never left (queued)
     * says nothing and keeps it.
     */
    public static function streak(string $streak, Outcome $outcome): string
    {
        $kind = match ($outcome->state) {
            Schema::MESSAGE_STATE_SENT      => '',
            Schema::MESSAGE_STATE_UNCERTAIN => 'U',
            Schema::MESSAGE_STATE_FAILED    => 'F' . $outcome->isdsCode,
            default                         => null,
        };
        if ($kind === null) {
            return $streak;
        }
        if ($kind === '') {
            return '';
        }
        [$count, $was] = explode(':', $streak, 2) + [1 => null];
        return (($was === $kind) ? (int) $count + 1 : 1) . ':' . $kind;
    }

    /** what the streak asks of the run: Outcome::NEXT, PAUSE after UNCLEAR answers, FAIL after REFUSALS */
    public static function breaker(string $streak): string
    {
        [$count, $kind] = explode(':', $streak, 2) + [1 => ''];
        return match (true) {
            ($kind === 'U') && ((int) $count >= self::UNCLEAR)                    => Outcome::PAUSE,
            str_starts_with($kind, 'F') && ((int) $count >= self::REFUSALS) => Outcome::FAIL,
            default                                                               => Outcome::NEXT,
        };
    }

    /** BatchFailed::$detail of a status ISDS refused with - the status alone, never its words */
    public static function refusalDetail(string $isdsCode): string
    {
        return (preg_match('/^\d{1,5}\z/', $isdsCode) === 1) ? "status:$isdsCode" : '';
    }
}
