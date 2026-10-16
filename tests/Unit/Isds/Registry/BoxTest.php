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
}
