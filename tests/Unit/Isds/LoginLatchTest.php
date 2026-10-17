<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Credential\Secret as Key;
use AA\Isds\Account;
use AA\Isds\LoginLatch;
use AA\Util\Secret;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Isds\Fixtures\MemoryLatch;

/**
 * LoginLatch: what it writes to the log and when a refusal or a block stands. The rows live in
 * MemoryLatch; a latch over the rows of another is the next run.
 */
final class LoginLatchTest extends TestCase
{
    private const LOGIN    = 'nszm01login';
    private const PASSWORD = 'Test-Pa55-never-shown';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';     // the constants Secret::key() derives from
        require_once __DIR__ . '/fixtures/MemoryLatch.php';
    }

    public function test_refusedAt_noneUntilRefused(): void
    {
        $latch = new MemoryLatch();
        self::assertNull($latch->refusedAt(self::account()));

        $latch->refuse(self::account());

        self::assertSame($latch->now, $latch->refusedAt(self::account()));
        self::assertSame($latch->now, $latch->nextRun()->refusedAt(self::account()), 'kept for the next run');
    }

    // a new login or password is a new try; the environment is part of the account
    public function test_refusedAt_ofThisLoginAndPasswordOnly(): void
    {
        $latch = new MemoryLatch();
        $latch->refuse(self::account());
        $next = $latch->nextRun();

        self::assertNull($next->refusedAt(self::account(password: 'Another-Pa55')));
        self::assertNull($next->refusedAt(self::account(login: 'other01')));
    }

    // a dictionary of 8-character passwords against a plain hash would find it
    public function test_refuse_writesAKeyedFingerprintOnly(): void
    {
        $latch = new MemoryLatch();

        $latch->refuse(self::account());

        self::assertCount(1, $latch->rows);
        $row = $latch->rows[0];
        self::assertSame([LoginLatch::REFUSED, $latch->now], [$row['params'], $row['time']]);
        self::assertSame(self::account()->fingerprint(Secret::key('isds-login')), $row['selector']);
        self::assertNotSame(hash('sha256', 'test' . "\0" . self::LOGIN . "\0" . self::PASSWORD), $row['selector']);
        $all = serialize($latch->rows);
        foreach ([self::LOGIN, self::PASSWORD, base64_encode(self::LOGIN . ':' . self::PASSWORD)] as $secret) {
            self::assertStringNotContainsString($secret, $all);
        }
        self::assertSame(64, strlen($row['selector']), 'fits log.selector');
        self::assertLessThanOrEqual(10, strlen(LoginLatch::LOG_EVENT), 'fits log.type');
    }

    // the log learns of it only once ISDS took the login: a try without an answer leaves the next run latched
    public function test_forget_forThisRunUntilAccepted(): void
    {
        $earlier = new MemoryLatch();
        $earlier->refuse(self::account());
        $run = $earlier->nextRun();

        self::assertTrue($run->forget(self::account()));
        self::assertNull($run->refusedAt(self::account()));
        self::assertNotNull($run->nextRun()->refusedAt(self::account()), 'no answer yet');
        self::assertFalse($run->forget(self::account()), 'nothing stands');

        $run->accepted(self::account());
        $run->accepted(self::account());

        self::assertNull($run->nextRun()->refusedAt(self::account()), 'the next run may try too');
        self::assertSame([LoginLatch::REFUSED, LoginLatch::FORGOTTEN], array_column($run->rows, 'params'));
    }

    // two accounts of one login in one run: the second must not try what the first was refused
    public function test_forget_neverTheRefusalOfThisRun(): void
    {
        $latch = new MemoryLatch();
        $latch->refuse(self::account());

        self::assertFalse($latch->forget(self::account()));
        self::assertSame($latch->now, $latch->refusedAt(self::account()));
        self::assertSame([LoginLatch::REFUSED], array_column($latch->rows, 'params'));
    }

    public function test_forget_nothingWrittenWithoutARefusal(): void
    {
        $latch = new MemoryLatch();

        self::assertFalse($latch->forget(self::account()));
        $latch->accepted(self::account());
        self::assertSame([], $latch->rows);
    }

    // the address of the server, for every account of the environment; the login is not at fault
    public function test_block_ofTheEnvironment(): void
    {
        $latch = new MemoryLatch();

        $latch->block(Account::TEST);

        self::assertSame($latch->now, $latch->blockedAt(Account::TEST));
        self::assertNull($latch->blockedAt(Account::PRODUCTION));
        self::assertNull($latch->refusedAt(self::account()));
        self::assertSame([['selector' => 'blocked:test', 'params' => LoginLatch::BLOCKED, 'time' => $latch->now]], $latch->rows);
    }

    // ISDS lifts it itself: one try then tells, and a block again latches again
    public function test_blockedAt_forBlockHours(): void
    {
        $latch = new MemoryLatch();
        $latch->block(Account::TEST);
        $hours = LoginLatch::BLOCK_HOURS * 3600;

        self::assertSame($latch->now, $latch->nextRun($hours - 1)->blockedAt(Account::TEST));
        $later = $latch->nextRun($hours);
        self::assertNull($later->blockedAt(Account::TEST));

        $later->block(Account::TEST);
        self::assertSame($later->now, $later->blockedAt(Account::TEST));
    }

    public function test_forget_theBlockOnceAccepted(): void
    {
        $earlier = new MemoryLatch();
        $earlier->block(Account::TEST);
        self::assertFalse($earlier->forget(self::account()), 'blocked in this run');
        $run = $earlier->nextRun();

        self::assertTrue($run->forget(self::account()));
        self::assertNull($run->blockedAt(Account::TEST));
        self::assertNotNull($run->nextRun()->blockedAt(Account::TEST), 'no answer yet');

        $run->accepted(self::account());

        self::assertNull($run->nextRun()->blockedAt(Account::TEST));
        self::assertSame(['blocked:test', 'blocked:test'], array_column($run->rows, 'selector'));
        self::assertSame([LoginLatch::BLOCKED, LoginLatch::FORGOTTEN], array_column($run->rows, 'params'));
    }

    private static function account(string $login = self::LOGIN, string $password = self::PASSWORD): Account
    {
        return new Account(Account::TEST, $login, new Key($password));
    }
}
