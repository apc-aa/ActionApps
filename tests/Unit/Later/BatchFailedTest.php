<?php

declare(strict_types=1);

namespace Tests\Unit\Later;

use AA\Later\BatchFailed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A run stores and shows only an error code and a place - never an exception text,
 *  which may carry source data or a secret. */
final class BatchFailedTest extends TestCase
{
    public function test_construct_rejects_lowercase_reason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BatchFailed('source down');
    }

    public function test_construct_keeps_reason_and_where(): void
    {
        $e = new BatchFailed('DEFINITION', 'stream:csu/job:3');

        $this->assertSame('DEFINITION', $e->reason);
        $this->assertSame('stream:csu/job:3', $e->where);
        $this->assertSame('DEFINITION', $e->getMessage(), 'the message is the code only, a log never gets source text from it');
    }

    public function test_construct_keeps_a_detail_of_words_and_a_number(): void
    {
        foreach (['', 'dns', 'too_large', 'http:404'] as $detail) {
            $this->assertSame($detail, (new BatchFailed('SOURCE', 'stream 3', $detail))->detail);
        }
    }

    /** @return array<string, array{string}> */
    public static function badDetails(): array
    {
        return [
            'a URL'        => ['https://api.example.org/data?token=hunter2'],
            'a sentence'   => ['Not Found'],
            'upper case'   => ['DNS'],
            'a long word'  => [str_repeat('a', 33)],
            'two numbers'  => ['http:404:1'],
        ];
    }

    #[DataProvider('badDetails')]
    public function test_construct_rejects_detail_that_is_no_short_word(string $detail): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BatchFailed('SOURCE', '', $detail);
    }

    /** @return array<string, array{string}> */
    public static function badPlaces(): array
    {
        return [
            'a URL with a query'  => ['https://api.example.org/data?token=hunter2'],
            'source data'         => ['row "Praha, 12 345"'],
            'a newline'           => ["stream 3\njob 4"],
            'over 200 characters' => [str_repeat('a', 201)],
            'a backslash'         => ['AA\\Import\\Run'],
        ];
    }

    #[DataProvider('badPlaces')]
    public function test_construct_rejects_where_that_is_no_own_place(string $where): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BatchFailed('SOURCE_LOGIN', $where);
    }

    public function test_construct_accepts_own_places(): void
    {
        foreach (['', 'stream 3', 'job#12', 'csu:3/okres-1.2', 'úloha_7', str_repeat('a', 200)] as $where) {
            $this->assertSame($where, (new BatchFailed('X', $where))->where);
        }
    }

    /** @return array<string, array{string, bool}> */
    public static function codes(): array
    {
        return [
            '64 characters'   => ['A' . str_repeat('B', 63), true],
            '65 characters'   => ['A' . str_repeat('B', 64), false],
            'one letter'      => ['X', true],
            'digits after'    => ['HTTP_503', true],
            'leading digit'   => ['5XX', false],
            'leading _'       => ['_X', false],
            'empty'           => ['', false],
            'trailing newline' => ["TRANSPORT\n", false],
        ];
    }

    #[DataProvider('codes')]
    public function test_isCode_bounds(string $code, bool $valid): void
    {
        $this->assertSame($valid, BatchFailed::isCode($code));
    }
}
