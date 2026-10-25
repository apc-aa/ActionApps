<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Isds\SendRun;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Isds\Fixtures\SendRunCase;
use Tests\Unit\Isds\Fixtures\Sending;
use Tests\Unit\Isds\Fixtures\TestSendRun;

require_once __DIR__ . '/fixtures/SendRunCase.php';

/**
 * The figures of the page of a sending (SendRun::progressFigures()): each recipient once by its fate in
 * the record, one never tried stopped only once nothing sends it any more - over TableDb's object_text.
 */
final class SendRunProgressTest extends TestCase
{
    use SendRunCase;

    /** the figures that are not 0 - the rest are */
    private const ZERO = ['sent' => 0, 'failed' => 0, 'uncertain' => 0, 'skipped' => 0, 'stopped' => 0, 'queued' => 0, 'delivered' => 0, 'accepted' => 0, 'total' => 6, 'until' => 0];

    /**
     * Six recipients, the fifth frozen skipped as a duplicate: the record, the state of the run and its
     * position, and the figures
     * @return array<string, array{array<string, string>, string, array<string, int|string>, array<string, int>}>
     */
    public static function records(): array
    {
        $once    = ['dm_ids' => '{"0":"901"}', 'uncertain' => '{"1":1700}', 'failed' => '{"2":"1233"}', 'skips' => '{"3":"inactive"}', 'cursor' => '4'];
        $first   = ['dm_ids' => '{"0":"901"}', 'cursor' => '1'];
        $watch   = ['watch_from' => (string) (self::NOW - 3600)];
        $pausing = ['i' => 2, 'p' => 'send', 'u' => self::NOW + 600, 'w' => 1, 's' => self::NOW - 60];

        return [
            'nothing tried yet'           => [[], SendRun::SENDING, self::SEND, ['skipped' => 1, 'queued' => 5]],
            'each map once'               => [$once, SendRun::SENDING, self::SEND, ['sent' => 1, 'failed' => 1, 'uncertain' => 1, 'skipped' => 2, 'queued' => 1]],
            'each map once, stopped'      => [$once + ['stop_by' => Sending::USER], SendRun::SENDING, self::SEND, ['sent' => 1, 'failed' => 1, 'uncertain' => 1, 'skipped' => 2, 'stopped' => 1]],
            'in flight'                   => [['dm_ids' => '{"0":"901","1":"902"}', 'inflight' => '5', 'inflight_at' => '1800', 'cursor' => '6'], SendRun::SENDING, self::SEND, ['sent' => 2, 'uncertain' => 1, 'skipped' => 1, 'queued' => 2]],
            'in flight, recorded too'     => [['uncertain' => '{"2":"1700"}', 'inflight' => '2', 'inflight_at' => '1800', 'cursor' => '3'], SendRun::SENDING, self::SEND, ['uncertain' => 1, 'skipped' => 1, 'queued' => 4]],
            'maps over each other'        => [['dm_ids' => '{"0":"901","1":"902"}', 'uncertain' => '{"1":1700,"2":1710}', 'failed' => '{"0":"1233","2":"notfound"}', 'cursor' => '3'], SendRun::SENDING, self::SEND, ['sent' => 2, 'failed' => 1, 'skipped' => 1, 'queued' => 2]],
            'pausing'                     => [$first, SendRun::SENDING, $pausing, ['sent' => 1, 'skipped' => 1, 'queued' => 4, 'until' => self::NOW + 600]],
            // a run in error waits for Continue, not for the end of its pause
            'pausing, in error'           => [$first, SendRun::ERROR, $pausing, ['sent' => 1, 'skipped' => 1, 'queued' => 4]],
            'done'                        => [$first, SendRun::DONE, self::SEND, ['sent' => 1, 'skipped' => 1, 'stopped' => 4]],
            'abandoned'                   => [$first, SendRun::ENDED, self::SEND, ['sent' => 1, 'skipped' => 1, 'stopped' => 4]],
            // following delivery sends nothing: one left untried - a record edited so - is stopped
            'watching, one never tried'   => [$first + $watch, SendRun::SENDING, self::WATCH, ['sent' => 1, 'skipped' => 1, 'stopped' => 4]],
            'watching, failed after sent' => [self::tried(6) + $watch + ['dm_state' => '{"0":"3:0:0:0","1":"8:1900:1800:0","2":"6:1900:1800:1900"}'], SendRun::SENDING, self::WATCH, ['sent' => 4, 'failed' => 2, 'delivered' => 1, 'accepted' => 1]],
            'a dmID edited into null'     => [['dm_ids' => '{"0":null,"1":"902"}', 'cursor' => '2'], SendRun::SENDING, self::SEND, ['sent' => 2, 'skipped' => 1, 'queued' => 3]],
            'a code edited into null'     => [['failed' => '{"0":null}', 'skips' => '{"1":"inactive"}', 'cursor' => '2'], SendRun::SENDING, self::SEND, ['failed' => 1, 'skipped' => 2, 'queued' => 3]],
            // each index the record names counts once, beyond the recipients too
            'indexes beyond the recipients' => [['dm_ids' => '{"6":"907"}', 'skips' => '{"7":"inactive"}'], SendRun::SENDING, self::SEND, ['sent' => 1, 'skipped' => 2, 'queued' => 5]],
        ];
    }

    /**
     * @param array<string, string>     $props
     * @param array<string, int|string> $position
     * @param array<string, int>        $figures
     */
    #[DataProvider('records')]
    public function test_progressFigures_byRecord(array $props, string $state, array $position, array $figures): void
    {
        $id = self::queued(6, $props, $position, [4 => 'duplicate']);
        self::storedSet($id, 'state', $state);

        self::assertSame(array_replace(self::ZERO, $figures), (TestSendRun::get($id) ?? throw new \LogicException('No run'))->progressFigures());
    }
}
