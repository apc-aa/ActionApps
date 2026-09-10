<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA_Poll;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../modules/polls/include/poll.class.php';

/**
 * Regression test for finding N2 (2026-09): SQL injection in the public poll vote.
 *
 * modules/polls/poll.php hands $_REQUEST straight to AA_Poll::processPoll(), which calls
 * registerVote($request['vote_id']) on a poll built from $request['poll_id']. Both ids used
 * to be interpolated into SQL unescaped, with no authentication in front. registerVote() now
 * validates them with is_long_id() before anything reaches the database.
 *
 * No DB is needed: the guard returns before loadSettings()/CVarset, and that is itself the
 * assertion - a regression that moves SQL back in front of the guard fails here with a
 * connection error instead of a clean false. The accepting direction (a well-formed 32-char
 * hex pair passes the guard) is deliberately not exercised: it continues into AA_Metabase and
 * would need a real connection. is_long_id() itself is pinned by ZidsFunctionsTest.
 */
final class PollRegisterVoteGuardTest extends TestCase
{
    /** Well-formed, so each test varies exactly one id. */
    private const VALID_ID = '2438f1a5adf91ca7f7160d5071249b80';

    /** @return array<string, array{string}> */
    public static function malformedIds(): array
    {
        return [
            'quote breakout' => ["x' OR '1'='1"],
            'comment escape' => ["' OR 1=1 -- "],
            'union select'   => ["a' UNION SELECT 1,2,3 -- "],
            'empty'          => [''],
            'too short'      => [str_repeat('a', 31)],
            'too long'       => [str_repeat('a', 33)],
            'non hex'        => [str_repeat('g', 32)],
            // exactly 32 chars, so a length-only guard would let it through
            'payload len 32' => ["' OR 1=1--" . str_repeat('a', 22)],
        ];
    }

    #[DataProvider('malformedIds')]
    public function testMalformedPollIdIsRejectedBeforeAnySql(string $poll_id): void
    {
        self::assertFalse((new AA_Poll($poll_id))->registerVote(self::VALID_ID));
    }

    #[DataProvider('malformedIds')]
    public function testMalformedVoteIdIsRejectedBeforeAnySql(string $vote_id): void
    {
        self::assertFalse((new AA_Poll(self::VALID_ID))->registerVote($vote_id));
    }
}
