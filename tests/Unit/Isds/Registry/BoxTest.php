<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Registry;

use AA\Isds\Registry\Box;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BoxTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function otherShapes(): array
    {
        return ['empty' => [''], 'six' => ['aaaaaa'], 'eight' => ['aaaaaaa1'], 'capitals' => ['AAAAAA1'], 'new line' => ["aaaaaa1\n"], 'dash' => ['aaa-aa1']];
    }

    // the id becomes a recipient address, also of a box that does not come from the list
    #[DataProvider('otherShapes')]
    public function test_construct_idOfAnotherShapeRefused(string $id): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Box($id, '', '', 'Obec', 'OVM', 10, true, false);
    }

    public function test_construct_idOfSevenSmallLettersOrDigits(): void
    {
        self::assertSame('a7kbrrn', (new Box('a7kbrrn', '', '', 'Obec', 'OVM', 10, true, false))->id);
    }

    public function test_normalizeId_trimsAndLowers(): void
    {
        self::assertSame('a7kbrrn', Box::normalizeId(" A7KBrrn\n"));
    }

    #[DataProvider('notIds')]
    public function test_normalizeId_refuses(string $id): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Box::normalizeId($id);
    }

    /** @return array<string, array{string}> */
    public static function notIds(): array
    {
        return ['empty' => [''], 'six' => ['aaaaaa'], 'eight' => ['aaaaaaa1'], 'dash' => ['aaa-aa1'], 'inner space' => ['aaa aa1'], 'accent' => ['aaaaaaž']];
    }

    #[DataProvider('icos')]
    public function test_normalizeIco(string $ico, string $expected): void
    {
        self::assertSame($expected, Box::normalizeIco($ico));
    }

    /** @return array<string, array{string, string}> */
    public static function icos(): array
    {
        return [
            'eight digits'          => ['00240044', '00240044'],
            'without leading zeros' => ['240044', '00240044'],
            'one digit'             => ['7', '00000007'],
            'spaces around'         => [" 00240044\t", '00240044'],
            'inner space'           => ['002 40044', '00240044'],
            'no-break space'        => ["00240044\u{A0}", '00240044'],
            'empty'                 => ['', ''],
            'only spaces'           => ['  ', ''],
            'nine digits'           => ['000235300', ''],
            'letters'               => ['CZ00235300', ''],
            'sign'                  => ['-235300', ''],
            'not UTF-8'             => ["0023\xFF5300", ''],
            'line end inside'       => ["0023\n5300", '00235300'],
        ];
    }
}
