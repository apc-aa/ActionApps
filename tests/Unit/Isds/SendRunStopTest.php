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
 * Stop never gives a sending up, Abandon does - and only where a stop can no longer end it: a stale
 * Stop form, a second tab or a colleague cannot do what cannot be undone.
 */
final class SendRunStopTest extends TestCase
{
    use SendRunCase;

    /**
     * The runs a stop can no longer end: in error, stopped already or every message tried, or following
     * delivery
     * @return array<string, array{\Closure(): string}> makes the run, gives its id
     */
    public static function abandonable(): array
    {
        return [
            'error, stopped already'     => [static fn(): string => self::inError(['stop_by' => Sending::USER])],
            'error, every message tried' => [static fn(): string => self::inError(self::tried(3))],
            'watch_error'                => [static fn(): string => self::inError(self::watched(3), self::WATCH)],
        ];
    }

    /**
     * The runs a stop still ends, or that are over
     * @return array<string, array{\Closure(): string, string}> makes the run, gives its id; what a stop says
     */
    public static function notAbandonable(): array
    {
        return [
            'sending'                       => [static fn(): string => self::queued(3), ''],
            'sending, stopped already'      => [static fn(): string => self::queued(3, ['stop_by' => Sending::USER]), ''],
            'sending, every message tried'  => [static fn(): string => self::queued(3, self::tried(3), ['i' => 3, 'p' => 'send']), SendRun::ALL_SENT],
            'error, something still unsent' => [static fn(): string => self::inError(), ''],
            'watching'                      => [static fn(): string => self::queued(3, self::watched(3), self::WATCH), SendRun::ALL_SENT],
            'done'                          => [static fn(): string => self::inError([], self::SEND, SendRun::DONE), 'WRONG_STATE'],
            'abandoned'                     => [static fn(): string => self::inError(['stop_by' => Sending::USER], self::SEND, SendRun::ENDED), 'WRONG_STATE'],
        ];
    }

    // a second click, another tab: the run stopped once stands in error - the stop only asks
    public function test_stop_errorStoppedAsksToAbandon(): void
    {
        $id     = self::inError(['stop_by' => Sending::USER]);
        $before = [self::stored($id), self::queue()];

        self::assertSame(SendRun::CONFIRM_ABANDON, TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, false));

        self::assertSame($before, [self::stored($id), self::queue()], 'nothing written, nothing queued');
        self::assertSame([], self::items()->writes);
    }

    // following delivery stopped by an error: Stop does not end it, Abandon would
    public function test_stop_watchErrorAsksToAbandon(): void
    {
        $id     = self::inError(self::watched(3), self::WATCH);
        $before = [self::stored($id), self::queue()];

        self::assertSame(SendRun::CONFIRM_ABANDON, TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, false));

        self::assertSame($before, [self::stored($id), self::queue()]);
        self::assertSame(SendRun::ERROR, self::stored($id)['state']);
    }

    // every message tried, the run still going: nothing is left to stop, nothing to give up
    public function test_stop_allTriedWhileSendingIsAllSent(): void
    {
        $id     = self::queued(2, self::tried(2), ['i' => 2, 'p' => 'send']);
        $before = self::stored($id);

        self::assertSame([SendRun::ALL_SENT, 'WRONG_STATE'], [TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, false), TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, true)]);

        self::assertSame($before, self::stored($id));
    }

    // its last step may still work: the run stands, the stop would take effect only on Continue - so it waits too
    public function test_stop_errorWithLiveStepIsLease(): void
    {
        $id = self::inError();
        self::storedSet($id, 'lease', (string) (self::NOW + 100));
        self::storedSet($id, 'lease_tok', 'working-step');
        $before = [self::stored($id), self::queue()];

        self::assertSame('LEASE', TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, false));

        self::assertSame($before, [self::stored($id), self::queue()], 'not even stop_by written');
    }

    /** @param \Closure(): string $run */
    #[DataProvider('abandonable')]
    public function test_stop_abandonOnlyWhereStopWould(\Closure $run): void
    {
        $id = $run();
        self::assertTrue(TestSendRun::get($id)?->abandonable(), 'offered');
        self::assertSame(SendRun::CONFIRM_ABANDON, TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, false));

        self::assertSame(SendRun::ABANDONED, TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, true));

        self::assertSame([SendRun::ENDED, SendRun::ABANDONED], [self::stored($id)['state'], json_decode(self::stored($id)['progress'], true)['note']]);
        self::assertSame('WRONG_STATE', TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, true), 'given up once');
    }

    /** @param \Closure(): string $run */
    #[DataProvider('notAbandonable')]
    public function test_stop_abandonElsewhereChangesNothing(\Closure $run, string $stop): void
    {
        $id     = $run();
        $before = [self::stored($id), self::queue()];
        self::assertFalse(TestSendRun::get($id)?->abandonable(), 'not offered');

        self::assertSame('WRONG_STATE', TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, true));

        self::assertSame($before, [self::stored($id), self::queue()]);
        self::assertSame([], self::items()->writes);
        self::assertSame($stop, TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, false), 'a stop does what it did');
    }

    // never queued: nothing to give up
    public function test_stop_abandonWithoutRunWrongState(): void
    {
        self::assertSame('WRONG_STATE', TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, true));
    }

    // the right is asked of Abandon as of Stop, before the locks and under them
    public function test_stop_abandonRefusesForeignUser(): void
    {
        $id = self::inError(['stop_by' => Sending::USER]);

        self::assertSame(SendRun::FORBIDDEN, TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::READER, true));

        self::assertSame(SendRun::ERROR, self::stored($id)['state']);
    }

    /**
     * A run of three recipients stopped by an error in $state, no step of it queued
     * @param array<string, string>     $props
     * @param array<string, int|string> $position
     */
    private static function inError(array $props = [], array $position = self::SEND, string $state = SendRun::ERROR): string
    {
        return self::leftInError(self::queued(3, $props, $position), state: $state);
    }
}
