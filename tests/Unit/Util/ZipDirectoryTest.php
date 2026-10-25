<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use AA\Util\ZipDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Util\Fixtures\ZipBuilder;

require_once __DIR__ . '/fixtures/ZipBuilder.php';

final class ZipDirectoryTest extends TestCase
{
    private const ODT = 'application/vnd.oasis.opendocument.text';

    /** $run fails the test on any warning or notice, which PHPUnit would only report */
    private static function quietly(\Closure $run): mixed
    {
        set_error_handler(static function (int $level, string $message): never {
            throw new \ErrorException($message, 0, $level);
        });
        try {
            return $run();
        } finally {
            restore_error_handler();
        }
    }

    private static function read(string $zip): ?ZipDirectory
    {
        return self::quietly(static fn() => ZipDirectory::read($zip));
    }

    /** $zip (no comment) with $bytes put at $offset of its end record */
    private static function withEnd(string $zip, int $offset, string $bytes): string
    {
        return substr_replace($zip, $bytes, strlen($zip) - 22 + $offset, strlen($bytes));
    }

    /** $zip built with ZipBuilder::zip(..., zip64: true), $bytes put at $offset of its ZIP64 end record */
    private static function withRecord64(string $zip, int $offset, string $bytes): string
    {
        return substr_replace($zip, $bytes, strlen($zip) - 22 - 20 - 56 + $offset, strlen($bytes));
    }

    /** $zip (no comment) with a ZIP64 end record and locator added, its end record left as it is - as Python writes them */
    private static function withZip64Records(string $zip): string
    {
        $end      = strlen($zip) - 22;
        $record   = unpack('vcount/x2/Vsize/Voffset', $zip, $end + 8);
        $record64 = "PK\x06\x06" . pack('PvvVVPPPP', 44, 45, 45, 0, 0, $record['count'], $record['count'], $record['size'], $record['offset'])
            . "PK\x06\x07" . pack('VPV', 0, $end, 1);

        return substr($zip, 0, $end) . $record64 . substr($zip, $end);
    }

    public function test_read_namesInOrder(): void
    {
        self::assertSame(['[Content_Types].xml', '_rels/.rels', 'word/document.xml'], self::read(ZipBuilder::ooxml('word/'))?->names);
    }

    public function test_read_emptyArchive(): void
    {
        $zip = self::read(ZipBuilder::zip([]));

        self::assertSame([], $zip?->names);
        self::assertNull($zip->leadingStored());
    }

    public function test_leadingStored_contentOfStoredFirstEntry(): void
    {
        self::assertSame(self::ODT, self::read(ZipBuilder::odf(self::ODT))?->leadingStored());
    }

    public function test_leadingStored_nullForDeflatedFirstEntry(): void
    {
        self::assertNull(self::read(ZipBuilder::zip([['mimetype', self::ODT, true]]))?->leadingStored());
    }

    public function test_leadingStored_nullForEncryptedFirstEntry(): void
    {
        self::assertNull(self::read(ZipBuilder::zip([['mimetype', self::ODT, false, ['flags' => 1]]]))?->leadingStored());
    }

    // the local header must be the one the directory names
    public function test_leadingStored_nullWhenLocalNameDiffers(): void
    {
        self::assertNull(self::read(substr_replace(ZipBuilder::odf(self::ODT), 'mimetypf', 30, 8))?->leadingStored());
    }

    // the sizes of the directory count: a writer streaming the data puts zeros into the local header
    public function test_leadingStored_withDataDescriptor(): void
    {
        $zip = ZipBuilder::zip([['mimetype', self::ODT, false, ['descriptor' => true]], ['content.xml', '<a/>', true, ['descriptor' => true]]]);

        self::assertSame(self::ODT, self::read($zip)?->leadingStored());
    }

    // as Info-ZIP writes them: the local extra field differs from that of the directory
    public function test_leadingStored_withExtraFields(): void
    {
        $zip = ZipBuilder::zip([['mimetype', self::ODT, false, ['extra' => "UT\x05\x00\x03\x01\x02\x03\x04", 'localExtra' => "UT\x09\x00\x03\x01\x02\x03\x04\x01\x02\x03\x04"]]]);

        self::assertSame(self::ODT, self::read($zip)?->leadingStored());
    }

    public function test_read_zip64(): void
    {
        self::assertSame(['[Content_Types].xml', '_rels/.rels', 'word/document.xml'], self::read(ZipBuilder::ooxml('word/', true))?->names);
    }

    // the sizes are in the ZIP64 extra field, which leadingStored() does not read
    public function test_leadingStored_nullForZip64Sizes(): void
    {
        $zip = self::read(ZipBuilder::zip([['mimetype', self::ODT]], '', true));

        self::assertSame(['mimetype'], $zip?->names);
        self::assertNull($zip->leadingStored());
    }

    public function test_read_zip64RecordsWithEndRecordValues(): void
    {
        self::assertSame(self::ODT, self::read(self::withZip64Records(ZipBuilder::odf(self::ODT)))?->leadingStored());
    }

    // an entry whose sizes are in the ZIP64 extra field leaves the names readable
    public function test_read_entryWithSaturatedSizesAndOffset(): void
    {
        $zip       = ZipBuilder::odf(self::ODT);
        $first     = self::directoryOf($zip);
        $zip       = substr_replace($zip, pack('VV', 0xFFFFFFFF, 0xFFFFFFFF), $first + 20, 8);
        $zip       = substr_replace($zip, pack('V', 0xFFFFFFFF), $first + 42, 4);
        $directory = self::read($zip);

        self::assertSame(['mimetype', 'content.xml', 'META-INF/manifest.xml'], $directory?->names);
        self::assertNull($directory->leadingStored());
    }

    private static function directoryOf(string $zip): int
    {
        return unpack('V', $zip, strlen($zip) - 22 + 16)[1];
    }

    public function test_read_archiveWithComment(): void
    {
        self::assertSame(['a.txt'], self::read(ZipBuilder::zip([['a.txt', 'a']], 'Archiv obce'))?->names);
    }

    // a signature of the end record inside the comment is no end record
    public function test_read_endSignatureInComment(): void
    {
        $comment = "PK\x05\x06 - archiv podepsal starosta obce dne 1. 10. 2026";

        self::assertSame(['a.txt'], self::read(ZipBuilder::zip([['a.txt', 'a']], $comment))?->names);
    }

    // an end record ending the comment points to no directory: the one before it is the end record
    public function test_read_endRecordEndingTheComment(): void
    {
        self::assertSame(['a.txt'], self::read(ZipBuilder::zip([['a.txt', 'a']], "PK\x05\x06" . str_repeat("\0", 18)))?->names);
    }

    // the longest comment there is, full of signatures, is searched once
    public function test_read_longCommentOfSignatures(): void
    {
        $comment = str_repeat("PK\x05\x06", 0xFFFF >> 2) . 'PKx';

        self::assertSame(['a.txt'], self::read(ZipBuilder::zip([['a.txt', 'a']], $comment))?->names);
    }

    /**
     * Comment of $fakes end records, each pointing to a directory of one entry that is no directory -
     * every one of them costs a walk
     */
    private static function fakeEnds(int $fakes): string
    {
        $start   = strlen(ZipBuilder::zip([['a.txt', 'a']]));
        $comment = '';
        for ($i = 0; $i < $fakes; ++$i) {
            $comment .= str_repeat('x', 46);
            $comment .= "PK\x05\x06" . pack('vvvvVVv', 0, 0, 1, 1, 46, $start + strlen($comment) - 46, 0);
        }

        return ZipBuilder::zip([['a.txt', 'a']], $comment);
    }

    public function test_read_afterFakeEndRecords(): void
    {
        self::assertSame(['a.txt'], self::read(self::fakeEnds(3))?->names);
    }

    // the walks are bounded: a comment cannot make reading quadratic
    public function test_read_refusesTooManyFakeEndRecords(): void
    {
        self::assertNull(self::read(self::fakeEnds(4)));
    }

    /** bytes a transfer or a disk image appends to the archive */
    #[DataProvider('appended')]
    public function test_read_bytesAfterTheArchive(string $appended): void
    {
        self::assertSame(self::ODT, self::read(ZipBuilder::odf(self::ODT) . $appended)?->leadingStored());
    }

    public static function appended(): array
    {
        return [
            'new line'   => ["\n"],
            'a sector'   => [str_repeat("\0", 512)],
            'signatures' => [str_repeat("PK\x05\x06", 100)],
        ];
    }

    #[DataProvider('unreadable')]
    public function test_read_refuses(string $zip): void
    {
        self::assertNull(self::read($zip));
    }

    public static function unreadable(): array
    {
        $zip = ZipBuilder::zip([['a.txt', 'a'], ['b.txt', 'b']]);
        // the directory starts after two local entries of 30 + 5 + 1 bytes
        $directory = 72;
        $zip64     = ZipBuilder::zip([['a.txt', 'a'], ['b.txt', 'b']], '', true);

        return [
            'empty'                             => [''],
            'not a zip'                         => ["%PDF-1.4\n%%EOF\n"],
            'end record only partly'            => [substr($zip, 0, -1)],
            'truncated in the directory'        => [substr($zip, 0, 40) . substr($zip, -22)],
            'comment longer than left'          => [self::withEnd($zip, 20, pack('v', 5))],
            'directory offset out of file'      => [self::withEnd($zip, 16, pack('V', 0x7FFFFFF0))],
            'directory size out of file'        => [self::withEnd($zip, 12, pack('V', 0x7FFFFFF0))],
            'huge count of entries'             => [self::withEnd($zip, 8, pack('vv', 0xFFFE, 0xFFFE))],
            'fewer entries listed'              => [self::withEnd($zip, 8, pack('vv', 1, 1))],
            // the values of a ZIP64 archive, the ZIP64 records missing
            'count of zip64 without its record'  => [self::withEnd($zip, 8, pack('vv', 0xFFFF, 0xFFFF))],
            'offset of zip64 without its record' => [self::withEnd($zip, 16, pack('V', 0xFFFFFFFF))],
            'split into volumes'                => [self::withEnd($zip, 4, pack('v', 1))],
            'entry name out of directory'       => [substr_replace($zip, pack('v', 0xFFFF), $directory + 28, 2)],
            'something prepended'               => ['MZ' . $zip],
            'zip64 locator out of file'         => [substr_replace($zip64, pack('P', 0x7FFFFFFFFFFFFFF0), -22 - 20 + 8, 8)],
            'zip64 locator above int'           => [substr_replace($zip64, pack('P', -16), -22 - 20 + 8, 8)],
            'zip64 huge count'                  => [self::withRecord64($zip64, 24, pack('PP', 1 << 40, 1 << 40))],
            'zip64 directory out of file'       => [self::withRecord64($zip64, 48, pack('P', 1 << 40))],
            'zip64 record of other size'        => [self::withRecord64($zip64, 4, pack('P', 52))],
            'zip64 split into volumes'          => [self::withRecord64($zip64, 16, pack('V', 1))],
        ];
    }

    // every cut or every broken byte of an archive is read without a warning
    #[DataProvider('archives')]
    public function test_read_brokenArchivesWithoutWarnings(string $zip): void
    {
        for ($length = 0; $length < strlen($zip); ++$length) {
            self::assertNull(self::read(substr($zip, 0, $length)), (string) $length);
        }
        for ($at = 0; $at < strlen($zip); ++$at) {
            $broken   = substr_replace($zip, chr(ord($zip[$at]) ^ 0xFF), $at, 1);
            $mimetype = self::quietly(static fn() => ZipDirectory::read($broken)?->leadingStored());
            self::assertTrue(($mimetype === null) || (strlen($mimetype) === strlen(self::ODT)), (string) $at);
        }
    }

    public static function archives(): array
    {
        return [
            'odf'   => [ZipBuilder::odf(self::ODT)],
            'zip64' => [ZipBuilder::zip([['mimetype', self::ODT], ['content.xml', '<a/>', true]], '', true)],
        ];
    }
}
