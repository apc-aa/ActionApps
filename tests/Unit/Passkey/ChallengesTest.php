<?php

declare(strict_types=1);

namespace Tests\Unit\Passkey;

use AA\Passkey\Challenges;
use PHPUnit\Framework\TestCase;

final class ChallengesTest extends TestCase
{
    private const NOW = 1790000000;

    public function test_take_once_withItsContext(): void
    {
        $bag        = [];
        $challenges = new Challenges($bag);
        $challenges->issue('register', 'abc', ['uid' => '7'], self::NOW);

        self::assertSame(['uid' => '7'], $challenges->take('register', 'abc', self::NOW));
        self::assertNull($challenges->take('register', 'abc', self::NOW), 'spent');
    }

    public function test_take_anotherPurpose_null(): void
    {
        $bag        = [];
        $challenges = new Challenges($bag);
        $challenges->issue('register', 'abc', [], self::NOW);

        self::assertNull($challenges->take('login', 'abc', self::NOW));
        self::assertSame([], $challenges->take('register', 'abc', self::NOW));
    }

    public function test_take_expired_null(): void
    {
        $bag        = [];
        $challenges = new Challenges($bag);
        $challenges->issue('login', 'abc', [], self::NOW);

        self::assertSame([], (new Challenges($bag))->take('login', 'abc', self::NOW + Challenges::LIFETIME));
        $challenges->issue('login', 'abc', [], self::NOW);
        self::assertNull($challenges->take('login', 'abc', self::NOW + Challenges::LIFETIME + 1));
    }

    public function test_issue_keepsTheNewestFive_inTheBag(): void
    {
        $bag        = [];
        $challenges = new Challenges($bag);
        foreach (range(1, 7) as $i) {
            $challenges->issue('login', "c$i", [], self::NOW);
        }

        self::assertCount(5, $bag['login'], 'the bag is the session - it stays small');
        self::assertNull($challenges->take('login', 'c2', self::NOW));
        self::assertSame([], $challenges->take('login', 'c3', self::NOW));
        self::assertSame([], $challenges->take('login', 'c7', self::NOW));
    }

    public function test_take_brokenBag_null(): void
    {
        $bag = ['login' => 'garbage'];
        self::assertNull((new Challenges($bag))->take('login', 'abc', self::NOW));
    }
}
