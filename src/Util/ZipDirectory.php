<?php

declare(strict_types=1);

namespace AA\Util;

/**
 * The central directory of a ZIP archive - the names of its entries and the content of a first entry
 * stored uncompressed - read without ext-zip, which AA does not require. Nothing is decompressed. An
 * archive this cannot read for sure (truncated, split into volumes, an offset out of the file) is no
 * archive here: read() returns null, never a warning. Linear in the size of the archive.
 */
final readonly class ZipDirectory
{
    private const END_SIGNATURE     = "PK\x05\x06";
    private const END64_SIGNATURE   = "PK\x06\x06";
    private const LOCATOR_SIGNATURE = "PK\x06\x07";
    private const CENTRAL_SIGNATURE = "PK\x01\x02";
    private const LOCAL_SIGNATURE   = "PK\x03\x04";
    private const END_SIZE          = 22;
    private const END64_SIZE        = 56;
    private const LOCATOR_SIZE      = 20;
    private const CENTRAL_SIZE      = 46;
    private const LOCAL_SIZE        = 30;
    private const MAX_COMMENT       = 0xFFFF;
    /** a size of all ones: the value is in the ZIP64 extra field */
    private const SATURATED_SIZE    = 0xFFFFFFFF;
    /**
     * End records whose directory is walked: a signature can also be in the comment or in bytes
     * appended to the archive, and each walk may cost the whole directory
     */
    private const MAX_WALKS         = 4;
    private const FLAG_ENCRYPTED    = 1;
    private const METHOD_STORED     = 0;

    /**
     * @param int                                                                     $directory where the central directory starts - no entry data beyond
     * @param list<string>                                                            $names     in the order of the central directory
     * @param ?array{method: int, flags: int, compressed: int, size: int, offset: int} $first     the first entry of the directory
     */
    private function __construct(private string $zip, private int $directory, public array $names, private ?array $first) {}

    /** null = not a ZIP archive this can read */
    public static function read(string $zip): ?self
    {
        $length = strlen($zip);
        $from   = max(0, $length - self::END_SIZE - self::MAX_COMMENT);
        $last   = $length - self::END_SIZE;
        $walks  = self::MAX_WALKS;
        // the last end record wins, as in Info-ZIP and Python: bytes may follow its comment
        while (($last >= $from) && ($walks > 0)) {
            // strrpos with a negative offset finds the last signature starting at most at $last
            $end = strrpos($zip, self::END_SIGNATURE, $last - $length);
            if (($end === false) || ($end < $from)) {
                return null;
            }
            $last    = $end - 1;
            $comment = unpack('v', $zip, $end + 20);
            if (($comment === false) || ($end + self::END_SIZE + $comment[1] > $length)) {
                continue;
            }
            $bounds = self::bounds($zip, $end);
            if ($bounds === null) {
                continue;
            }
            --$walks;
            $directory = self::walk($zip, ...$bounds);
            if ($directory !== null) {
                return $directory;
            }
        }

        return null;
    }

    /** the first entry of the directory when it is stored, unencrypted, at the start of the archive - where ODF names its type */
    public function leadingStored(): ?string
    {
        $first = $this->first;
        if (($first === null) || ($first['offset'] !== 0) || ($first['method'] !== self::METHOD_STORED) || (($first['flags'] & self::FLAG_ENCRYPTED) !== 0)
            || ($first['compressed'] !== $first['size']) || ($first['size'] === self::SATURATED_SIZE) || (self::LOCAL_SIZE > $this->directory)
            || (substr_compare($this->zip, self::LOCAL_SIGNATURE, 0, 4) !== 0)) {
            return null;
        }
        // the sizes in the local header may be zero when a data descriptor follows: those of the directory count
        $local = unpack('vmethod/x16/vname/vextra', $this->zip, 8);
        if (($local === false) || ($local['method'] !== self::METHOD_STORED) || (substr($this->zip, self::LOCAL_SIZE, $local['name']) !== $this->names[0])) {
            return null;
        }
        $data = self::LOCAL_SIZE + $local['name'] + $local['extra'];

        return ($data + $first['size'] <= $this->directory) ? substr($this->zip, $data, $first['size']) : null;
    }

    /**
     * Where the central directory of the end record at $end starts, how many entries it has, and where
     * it ends - at the ZIP64 record when there is one, else at the end record. Constant time.
     * @return ?array{int, int, int}
     */
    private static function bounds(string $zip, int $end): ?array
    {
        $record = unpack('vdisk/vdirectoryDisk/vonDisk/vcount/Vsize/Voffset', $zip, $end + 4);
        if (($record === false) || ($record['disk'] !== 0) || ($record['directoryDisk'] !== 0) || ($record['onDisk'] !== $record['count'])) {
            return null;
        }
        $locator = $end - self::LOCATOR_SIZE;
        if (($locator >= 0) && (substr_compare($zip, self::LOCATOR_SIGNATURE, $locator, 4) === 0)) {
            $record = self::record64($zip, $locator);
            if ($record === null) {
                return null;
            }
            [$count, $size, $offset, $directoryEnd] = $record;
        } else {
            [$count, $size, $offset, $directoryEnd] = [$record['count'], $record['size'], $record['offset'], $end];
        }
        // nothing before the archive, nothing between its directory and the end records
        if (($offset < 0) || ($size < 0) || ($offset + $size !== $directoryEnd) || ($count < 0) || ($count > intdiv($size, self::CENTRAL_SIZE))) {
            return null;
        }

        return [$offset, $count, $directoryEnd];
    }

    /**
     * The count, size and offset of the directory from the ZIP64 record the locator at $locator points to, and where that record starts
     * @return ?array{int, int, int, int}
     */
    private static function record64(string $zip, int $locator): ?array
    {
        // 64-bit fields above PHP_INT_MAX come out negative: refused by the checks of the caller
        $pointer = unpack('Vdisk/Precord/Vdisks', $zip, $locator + 4);
        if (($pointer === false) || ($pointer['disk'] !== 0) || ($pointer['disks'] > 1)) {
            return null;
        }
        $at = $pointer['record'];
        if (($at < 0) || ($at + self::END64_SIZE > $locator) || (substr_compare($zip, self::END64_SIGNATURE, $at, 4) !== 0)) {
            return null;
        }
        $record = unpack('Psize/x4/Vdisk/VdirectoryDisk/PonDisk/Pcount/PdirectorySize/Poffset', $zip, $at + 4);
        if (($record === false) || ($record['disk'] !== 0) || ($record['directoryDisk'] !== 0) || ($record['onDisk'] !== $record['count'])
            || ($record['size'] !== $locator - $at - 12)) {
            return null;
        }

        return [$record['count'], $record['directorySize'], $record['offset'], $at];
    }

    private static function walk(string $zip, int $at, int $count, int $end): ?self
    {
        $start = $at;
        $names = [];
        $first = null;
        for ($i = 0; $i < $count; ++$i) {
            if (($at + self::CENTRAL_SIZE > $end) || (substr_compare($zip, self::CENTRAL_SIGNATURE, $at, 4) !== 0)) {
                return null;
            }
            $entry = unpack('vflags/vmethod/x8/Vcompressed/Vsize/vname/vextra/vcomment/x8/Voffset', $zip, $at + 8);
            if ($entry === false) {
                return null;
            }
            $next = $at + self::CENTRAL_SIZE + $entry['name'] + $entry['extra'] + $entry['comment'];
            if ($next > $end) {
                return null;
            }
            $names[] = substr($zip, $at + self::CENTRAL_SIZE, $entry['name']);
            $first ??= ['method' => $entry['method'], 'flags' => $entry['flags'], 'compressed' => $entry['compressed'], 'size' => $entry['size'], 'offset' => $entry['offset']];
            $at = $next;
        }

        return ($at === $end) ? new self($zip, $start, $names, $first) : null;
    }
}
