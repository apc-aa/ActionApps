<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Http\TransportException as HttpTransportException;
use AA\Isds\IsdsException;
use AA\Isds\Outcome;
use AA\Isds\SendPace;
use AA\Isds\SendResult;
use AA\Isds\TransportException;
use AA\Isds\UnavailableException;
use AA\Later\BatchFailed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SendPaceTest extends TestCase
{
    private const NOW = 1_900_000_000;

    private static function prague(string $time): int
    {
        return (new \DateTimeImmutable($time, new \DateTimeZone('Europe/Prague')))->getTimestamp();
    }

    // ------------------------------------------------------------ the maintenance of ISDS

    #[DataProvider('maintenance')]
    public function test_maintenanceEnd_friday_first_hour(string $now, ?string $end): void
    {
        self::assertSame(is_null($end) ? null : self::prague($end), SendPace::maintenanceEnd(self::prague($now)));
    }

    /** @return array<string, array{string, ?string}> 2030-03-22 and 2030-07-19 are Fridays */
    public static function maintenance(): array
    {
        return [
            'Thursday evening'              => ['2030-03-21 23:53:59', null],
            'a message before'              => ['2030-03-21 23:54:00', '2030-03-22 01:00:00'],
            'Friday midnight'               => ['2030-03-22 00:00:00', '2030-03-22 01:00:00'],
            'the last second'               => ['2030-03-22 00:59:59', '2030-03-22 01:00:00'],
            'one o\'clock'                  => ['2030-03-22 01:00:00', null],
            'Friday noon'                   => ['2030-03-22 12:00:00', null],
            'Thursday after midnight'       => ['2030-03-21 00:30:00', null],
            'Saturday after midnight'       => ['2030-03-23 00:30:00', null],
            'in summer time'                => ['2030-07-19 00:30:00', '2030-07-19 01:00:00'],
            // Thursday 22:30 by UTC
            'in summer, two hours ahead'    => ['2030-07-19 00:00:00', '2030-07-19 01:00:00'],
            // Friday 0:30 by UTC
            'Friday after midnight of UTC'  => ['2030-07-19 02:30:00', null],
        ];
    }

    // ------------------------------------------------------------ pauses

    #[DataProvider('pauses')]
    public function test_paused_doubles_up_to_ten_minutes(int $waits, int $wait): void
    {
        $at = ['p' => 'send', 'i' => 4] + (($waits > 0) ? ['w' => $waits, 'u' => self::NOW, 's' => self::NOW - 100] : []);

        $paused = SendPace::paused($at, self::NOW, Outcome::UNAVAILABLE);

        self::assertSame(['w' => $waits + 1, 'u' => self::NOW + $wait, 's' => ($waits > 0) ? self::NOW - 100 : self::NOW, 'p' => 'send', 'i' => 4], $paused);
    }

    /** @return array<string, array{int, int}> */
    public static function pauses(): array
    {
        return ['the first' => [0, 30], 'the second' => [1, 60], 'the fifth' => [4, 480], 'the sixth' => [5, 600], 'the twentieth' => [19, 600]];
    }

    #[DataProvider('pauseEnds')]
    public function test_pauseEnd_no_further_than_a_pause_ends(array $at, int $end): void
    {
        self::assertSame($end, SendPace::pauseEnd($at, self::NOW));
    }

    /** @return array<string, array{array<string, int>, int}> */
    public static function pauseEnds(): array
    {
        $furthest = 3600 + SendPace::MAINTENANCE_LEAD;
        return [
            'no pause'                  => [[], 0],
            'over'                      => [['u' => self::NOW - 5], self::NOW - 5],
            'the longest wait'          => [['u' => self::NOW + SendPace::LONGEST_WAIT], self::NOW + SendPace::LONGEST_WAIT],
            'as far as the maintenance' => [['u' => self::NOW + $furthest], self::NOW + $furthest],
            'further'                   => [['u' => self::NOW + $furthest + 1], 0],
        ];
    }

    // a count edited by hand starts again, never a wait of a fraction of a second
    public function test_paused_negative_count_is_the_first(): void
    {
        self::assertSame(['w' => 1, 'u' => self::NOW + 30, 's' => self::NOW], SendPace::paused(['w' => -3], self::NOW, Outcome::UNAVAILABLE));
    }

    #[DataProvider('longPauses')]
    public function test_paused_fails_after_six_hours(int $since, bool $fails): void
    {
        $at = ['p' => 'send', 'i' => 0, 'w' => 40, 'u' => self::NOW, 's' => self::NOW - $since];
        if ($fails) {
            $this->expectExceptionObject(new BatchFailed(Outcome::UNAVAILABLE));
        }

        self::assertSame(41, SendPace::paused($at, self::NOW, Outcome::UNAVAILABLE)['w']);
    }

    /** @return array<string, array{int, bool}> */
    public static function longPauses(): array
    {
        return ['six hours' => [6 * 3600, false], 'a second more' => [6 * 3600 + 1, true]];
    }

    // ------------------------------------------------------------ attempts alike in a row

    #[DataProvider('streaks')]
    public function test_streak_counts_attempts_alike(string $streak, \Closure $attempt, string $next, string $then): void
    {
        $streak = SendPace::streak($streak, Outcome::of($attempt()));

        self::assertSame([$next, $then], [$streak, SendPace::breaker($streak)]);
    }

    /** @return array<string, array{string, \Closure(): (SendResult|\Throwable), string, string}> */
    public static function streaks(): array
    {
        $sent      = static fn() => new SendResult('1001', '0000', 'OK');
        $unclear   = static fn() => TransportException::uncertain('ISDS answered HTTP 500');
        $noAnswer  = static fn() => new UnavailableException('ISDS asks to come back later');
        $refused   = static fn() => new IsdsException('Not for this box', '1233');
        $other     = static fn() => new IsdsException('Too large', '1234');
        $notSent   = static fn() => TransportException::fromHttp(new HttpTransportException('No connection', 0, new \RuntimeException('Could not resolve host: ws1.czebox.cz')));
        return [
            'the first unclear answer'     => ['', $unclear, '1:U', Outcome::NEXT],
            'the second one'               => ['1:U', $unclear, '2:U', Outcome::PAUSE],
            'no answer after one'          => ['1:U', $noAnswer, '2:U', Outcome::PAUSE],
            'sent ends it'                 => ['2:U', $sent, '', Outcome::NEXT],
            'a refusal ends it'            => ['2:U', $refused, '1:F1233', Outcome::NEXT],
            'the fourth refusal'           => ['3:F1233', $refused, '4:F1233', Outcome::NEXT],
            'the fifth refusal'            => ['4:F1233', $refused, '5:F1233', Outcome::FAIL],
            'another refusal starts again' => ['4:F1233', $other, '1:F1234', Outcome::NEXT],
            'unclear after refusals'       => ['4:F1233', $unclear, '1:U', Outcome::NEXT],
            'nothing sent keeps it'        => ['4:F1233', $notSent, '4:F1233', Outcome::NEXT],
        ];
    }

    public function test_breaker_same_refusal_without_a_code(): void
    {
        $streak = '';
        for ($i = 0; $i < 5; ++$i) {
            $streak = SendPace::streak($streak, Outcome::of(new IsdsException('Refused')));
        }

        self::assertSame(['5:F', Outcome::FAIL], [$streak, SendPace::breaker($streak)]);
    }

    // a record edited by hand passes for no streak, never for a tripped breaker
    #[DataProvider('odd')]
    public function test_breaker_odd_streak_is_none(string $streak): void
    {
        self::assertSame(Outcome::NEXT, SendPace::breaker($streak));
    }

    /** @return array<string, array{string}> */
    public static function odd(): array
    {
        return ['empty' => [''], 'no count' => ['U'], 'no kind' => ['7:'], 'another kind' => ['9:X']];
    }

    public function test_refusalDetail_only_a_status(): void
    {
        self::assertSame(['status:1233', '', ''], [SendPace::refusalDetail('1233'), SendPace::refusalDetail('Client'), SendPace::refusalDetail('123456')]);
    }
}
