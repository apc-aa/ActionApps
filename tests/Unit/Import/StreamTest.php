<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use AA\Import\Stream;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A stream key lands in the run's cursor and its parameters in grabber URLs, so both are checked once, here. */
final class StreamTest extends TestCase
{
    #[DataProvider('badKeys')]
    public function test_stream_rejectsBadKey(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Stream($key, 'Label', []);
    }

    public static function badKeys(): array
    {
        return [
            'empty'     => [''],
            'space'     => ['a b'],
            'slash'     => ['a/b'],
            'colon'     => ['a:b'],
            'too long'  => [str_repeat('a', 65)],
            'newline'   => ["abc\n"],
            'non ascii' => ['obyvatelé'],
        ];
    }

    public function test_stream_acceptsKeyCharacters(): void
    {
        self::assertSame('Ab_0.9-x', (new Stream('Ab_0.9-x', 'Label', []))->key);
        self::assertSame(str_repeat('k', 64), (new Stream(str_repeat('k', 64), 'Label', []))->key);
    }

    #[DataProvider('badParamNames')]
    public function test_stream_rejectsBadParamName(string|int $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Stream('s1', 'Label', [$name => 'x']);
    }

    public static function badParamNames(): array
    {
        return [
            'upper case'   => ['Year'],
            'digit first'  => ['1year'],
            'list index'   => [0],
            'dash'         => ['geo-level'],
            'too long'     => ['a' . str_repeat('b', 32)],
            'underscore 1' => ['_year'],
        ];
    }

    public function test_stream_paramsBecomeStrings(): void
    {
        $stream = new Stream('s1', 'Label', ['year' => 2024, 'geo' => 'CZ0100']);

        self::assertSame(['year' => '2024', 'geo' => 'CZ0100'], $stream->params);
    }

    #[DataProvider('badParamValues')]
    public function test_stream_rejectsNonScalarParamValue(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Stream('s1', 'Label', ['year' => $value]);
    }

    public static function badParamValues(): array
    {
        return [
            'null'   => [null],
            'bool'   => [true],
            'float'  => [1.5],
            'array'  => [['2024']],
            'object' => [new \stdClass()],
        ];
    }

    public function test_stream_keepsCatalogKeyAndVersionField(): void
    {
        $stream = new Stream('s1', 'Label', [], 'cat-7', 'rok');

        self::assertSame('cat-7', $stream->catalogKey);
        self::assertSame('rok', $stream->versionField);
        self::assertSame('Label', $stream->label);
    }

    /** @return array<string, array{string, string, string}> key, name, shown */
    public static function displayNames(): array
    {
        return [
            'name and key'   => ['s1', 'Počet obyvatel', 'Počet obyvatel (s1)'],
            'no name'        => ['s1', '', 's1'],
            'only spaces'    => ['s1', '  ', 's1'],
            'the key itself' => ['s1', 's1', 's1'],
        ];
    }

    #[DataProvider('displayNames')]
    public function test_displayName_nameThenKey(string $key, string $name, string $shown): void
    {
        self::assertSame($shown, Stream::displayName($key, $name));
    }
}
