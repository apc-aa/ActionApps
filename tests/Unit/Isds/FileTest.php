<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Isds\File;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FileTest extends TestCase
{
    public function test_construct_keepsValues(): void
    {
        $file = new File('Příloha č. 1.pdf', 'application/pdf', File::ENCLOSURE, '%PDF');

        self::assertSame(['Příloha č. 1.pdf', 'application/pdf', 'enclosure', '%PDF', 4], [$file->name, $file->mimeType, $file->metaType, $file->content, $file->size()]);
    }

    public function test_construct_nameOf255CzechCharacters(): void
    {
        $name = str_repeat('ž', File::MAX_NAME - 4) . '.pdf';

        self::assertSame($name, (new File($name, 'application/pdf', File::MAIN, 'x'))->name);
    }

    public function test_construct_trimsName(): void
    {
        self::assertSame('Dopis.pdf', (new File(" Dopis.pdf\n", 'application/pdf', File::MAIN, 'x'))->name);
    }

    #[DataProvider('containers')]
    public function test_isContainer_byTypeOrExtension(string $name, string $mime, bool $expected): void
    {
        self::assertSame($expected, (new File($name, $mime, File::ENCLOSURE, 'x'))->isContainer());
    }

    public static function containers(): array
    {
        return [
            'zip by type'          => ['a.bin', 'application/zip', true],
            'zip by windows type'  => ['a.bin', 'application/x-zip-compressed', true],
            'asic by type'         => ['a.bin', 'application/vnd.etsi.asic-e+zip', true],
            'zip by extension'     => ['a.ZIP', 'application/octet-stream', true],
            'asic-s by extension'  => ['a.asics', 'application/octet-stream', true],
            'pdf'                  => ['a.pdf', 'application/pdf', false],
            'zip only in the name' => ['zip.pdf', 'application/pdf', false],
        ];
    }

    #[DataProvider('refused')]
    public function test_construct_refuses(string $name, string $mime, string $meta): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new File($name, $mime, $meta, 'x');
    }

    public static function refused(): array
    {
        return [
            'empty name'        => ['', 'application/pdf', File::MAIN],
            'blank name'        => ['  ', 'application/pdf', File::MAIN],
            'long name'         => [str_repeat('ž', File::MAX_NAME + 1), 'application/pdf', File::MAIN],
            'name not utf-8'    => ["Dopis\xE8.pdf", 'application/pdf', File::MAIN],
            'nul in name'       => ["Dopis.pdf\0.exe", 'application/pdf', File::MAIN],
            'line end in name'  => ["Dopis\n.pdf", 'application/pdf', File::MAIN],
            'no mime type'      => ['a.pdf', '', File::MAIN],
            'extension only'    => ['a.pdf', 'pdf', File::MAIN],
            'mime with space'   => ['a.pdf', 'application/ pdf', File::MAIN],
            'signature'         => ['a.pdf', 'application/pdf', 'signature'],
            'meta type unknown' => ['a.pdf', 'application/pdf', 'Main'],
        ];
    }
}
