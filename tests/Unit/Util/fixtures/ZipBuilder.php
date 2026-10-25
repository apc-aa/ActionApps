<?php

declare(strict_types=1);

namespace Tests\Unit\Util\Fixtures;

/** ZIP archives built by hand, as the writers of office documents build them - no ext-zip in the tests */
final class ZipBuilder
{
    /**
     * @param list<array{0: string, 1: string, 2?: bool, 3?: array{flags?: int, extra?: string, localExtra?: string, descriptor?: bool}}> $entries
     *        name, content, deflated (default stored), and: general purpose flags, the extra field of the directory
     *        and of the local header (default the same), a data descriptor after the data with zero sizes in the local header
     * @param bool $zip64 as Info-ZIP -fz: every size and offset in ZIP64 fields, with the ZIP64 end records
     */
    public static function zip(array $entries, string $comment = '', bool $zip64 = false): string
    {
        $local   = '';
        $central = '';
        foreach ($entries as $entry) {
            [$name, $content] = $entry;
            $deflated         = $entry[2] ?? false;
            $options          = $entry[3] ?? [];
            $data             = $deflated ? (string) gzdeflate($content) : $content;
            $crc              = crc32($content);
            $descriptor       = $options['descriptor'] ?? false;
            $flags            = ($options['flags'] ?? 0) | ($descriptor ? 8 : 0);
            $extra            = $options['extra'] ?? '';
            $localExtra       = $options['localExtra'] ?? $extra;
            [$compressed, $size, $offset] = [strlen($data), strlen($content), strlen($local)];
            if ($zip64) {
                $localExtra .= pack('vvPP', 1, 16, $size, $compressed);
                $extra      .= pack('vvPPP', 1, 24, $size, $compressed, $offset);
                [$compressed, $size, $offset] = [0xFFFFFFFF, 0xFFFFFFFF, 0xFFFFFFFF];
            }
            $common = pack('vvvvv', $zip64 ? 45 : 20, $flags, $deflated ? 8 : 0, 0, 0x21);
            $sizes  = pack('VVV', $crc, $compressed, $size);
            $local .= "PK\x03\x04" . $common . ($descriptor ? pack('VVV', 0, 0, 0) : $sizes) . pack('vv', strlen($name), strlen($localExtra))
                . $name . $localExtra . $data . ($descriptor ? "PK\x07\x08" . pack('VVV', $crc, strlen($data), strlen($content)) : '');
            $central .= "PK\x01\x02" . pack('v', 45) . $common . $sizes . pack('vvvvvVV', strlen($name), strlen($extra), 0, 0, 0, 0, $offset) . $name . $extra;
        }
        $count = count($entries);
        if (!$zip64) {
            return $local . $central . "PK\x05\x06" . pack('vvvvVVv', 0, 0, $count, $count, strlen($central), strlen($local), strlen($comment)) . $comment;
        }
        $record = strlen($local) + strlen($central);

        return $local . $central
            . "PK\x06\x06" . pack('PvvVVPPPP', 44, 45, 45, 0, 0, $count, $count, strlen($central), strlen($local))
            . "PK\x06\x07" . pack('VPV', 0, $record, 1)
            . "PK\x05\x06" . pack('vvvvVVv', 0, 0, 0xFFFF, 0xFFFF, 0xFFFFFFFF, 0xFFFFFFFF, strlen($comment)) . $comment;
    }

    /** a minimal OOXML document whose main part is in $folder (word/, xl/, ppt/) */
    public static function ooxml(string $folder, bool $zip64 = false): string
    {
        return self::zip([
            ['[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>', true],
            ['_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>', true],
            [$folder . 'document.xml', '<?xml version="1.0" encoding="UTF-8"?><document/>', true],
        ], '', $zip64);
    }

    /** a minimal ODF document of $mimetype, the entry mimetype first and stored */
    public static function odf(string $mimetype): string
    {
        return self::zip([
            ['mimetype', $mimetype],
            ['content.xml', '<?xml version="1.0" encoding="UTF-8"?><office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"/>', true],
            ['META-INF/manifest.xml', '<?xml version="1.0" encoding="UTF-8"?><manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0"/>', true],
        ]);
    }

    /** a minimal ISDOCX: the invoice and its manifest */
    public static function isdocx(): string
    {
        return self::zip([
            ['manifest.xml', '<?xml version="1.0" encoding="UTF-8"?><manifest xmlns="http://isdoc.cz/namespace/2013/manifest"><maindocument filename="faktura.isdoc"/></manifest>', true],
            ['faktura.isdoc', '<?xml version="1.0" encoding="UTF-8"?><Invoice xmlns="http://isdoc.cz/namespace/2013" version="6.0.2"/>', true],
        ]);
    }
}
