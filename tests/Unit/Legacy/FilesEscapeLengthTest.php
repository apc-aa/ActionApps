<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the filename length cap in Files::escape().
 *
 * Nothing in AA used to bound an uploaded filename: escape() only transliterates
 * and substitutes characters. A caption pasted as a filename therefore reached
 * the disk at its full length (biom.cz has live uploads of 209 bytes), which
 * breaks well beyond AA:
 *
 *  - MissCache derives its cache filename from the source name plus the phpThumb
 *    parameters and its own extension, so a ~200-byte source overflows NAME_MAX
 *    (255 bytes) and the thumbnail cannot be written at all.
 *  - Windows caps MAX_PATH at 260 bytes for the WHOLE path, eCryptfs caps
 *    NAME_MAX at 143 — such a file cannot be checked out, zipped or rsynced
 *    everywhere.
 *
 * The cap elides the MIDDLE of the name: the tail of a filename carries the more
 * identifying part (a numbered series, an author, a variant suffix) plus the
 * extension, so cutting the end would collapse distinct uploads into one name.
 *
 * These tests pin both halves: short names must pass through EXACTLY as before
 * (characterization — they pass against the unchanged code too), and long names
 * must come out within the cap with head, tail and extension intact.
 */
final class FilesEscapeLengthTest extends TestCase
{
    /** Cap asserted here; must match Files::MAX_FILENAME_LENGTH. */
    private const MAX = 140;

    public static function setUpBeforeClass(): void
    {
        if (!\defined('DEFAULT_LANG_INCLUDE')) {
            \define('DEFAULT_LANG_INCLUDE', 'en_utf8.php3');
        }
        require_once __DIR__ . '/../../../include/files.class.php';
    }

    /**
     * Characterization: names within the cap are untouched by the new code.
     *
     * @return array<string, array{0:string, 1:string}>
     */
    public static function shortNamesProvider(): array
    {
        return [
            'plain'                => ['graf-1.png', 'graf-1.png'],
            'dots kept'            => ['obr.7_tankovani.jpg', 'obr.7_tankovani.jpg'],
            'no extension'         => ['no-ext-file', 'no-ext-file'],
            'uppercase lowered'    => ['Photo.JPG', 'photo.jpg'],
            'spaces to dashes'     => ['my holiday photo.jpg', 'my-holiday-photo.jpg'],
            'runs collapsed'       => ['a---b...c.jpg', 'a-b.c.jpg'],
            'exactly at the cap'   => [str_repeat('a', self::MAX - 4) . '.jpg', str_repeat('a', self::MAX - 4) . '.jpg'],
        ];
    }

    #[DataProvider('shortNamesProvider')]
    public function testShortNamesArePassedThroughUnchanged(string $input, string $expected): void
    {
        self::assertSame($expected, \Files::escape($input));
    }

    /**
     * @return array<string, array{0:string, 1:string}> filename => expected extension
     */
    public static function longNamesProvider(): array
    {
        return [
            // the real biom.cz upload that overflows MissCache's NAME_MAX budget
            'biom caption as filename' => [
                'cilem-je-zajistit-aby-biomasa-pro-energetiku-nebyla-pestovana-na-ukor-ochrany-prirody-dobreho-stavu-zemedelske-pudy-nebo-lesu-a-aby-vysledna-bioenergie-prispivala-ke-zmirneni-zmeny-klimatu.bez-ohledu-na-ge.jpg',
                'jpg',
            ],
            'long with no extension' => [str_repeat('abcde-', 60), ''],
            'long jpeg'              => [str_repeat('x', 300) . '.jpeg', 'jpeg'],
            'one byte over the cap'  => [str_repeat('a', self::MAX - 3) . '.jpg', 'jpg'],
            'long name, long tail that only looks like an extension' => [
                str_repeat('y', 200) . '.this-is-not-an-extension',
                '',
            ],
        ];
    }

    #[DataProvider('longNamesProvider')]
    public function testLongNamesAreCappedKeepingHeadTailAndExtension(string $input, string $expectedExt): void
    {
        $out = \Files::escape($input);

        self::assertLessThanOrEqual(self::MAX, \strlen($out), "escaped name over the cap: $out");

        if ($expectedExt !== '') {
            self::assertStringEndsWith('.' . $expectedExt, $out, 'extension must survive intact');
        }

        // The elision marker sits between the surviving head and tail. It is "__"
        // and not "--" because escape() collapses "--" to "-", which would make a
        // second escape() of an already-elided name return something different.
        self::assertStringContainsString('__', $out, 'a shortened name must be marked as elided');

        // Both ends of the original name must still be recognisable.
        $escapedWhole = trim(strtolower(preg_replace('/[^\da-z\-_.]/i', '-', $input)), '-.');
        self::assertStringStartsWith(substr($escapedWhole, 0, 20), $out, 'head of the name must survive');
        self::assertStringEndsWith(substr($escapedWhole, -20), $out, 'tail of the name must survive');
    }

    /** Two long names differing only in their tail must not collapse into one filename. */
    public function testDistinctTailsStayDistinct(): void
    {
        $stem = str_repeat('spolecny-zaklad-', 15);
        self::assertNotSame(
            \Files::escape($stem . 'varianta-a.jpg'),
            \Files::escape($stem . 'varianta-b.jpg'),
        );
    }

    /**
     * Two long names differing ONLY in the elided middle must not collapse into
     * one filename. Head and tail alone are not enough: an upload field whose
     * policy is "overwrite"/"backup" (Files::uploadFile(), configurable per field
     * as "Upload policy", itemfunc.php) stores the client-supplied basename
     * directly, so a fold would let one upload replace an unrelated file - and
     * even under the default "new" policy it silently turns two distinct uploads
     * into name plus name_1, losing the part that told them apart.
     */
    public function testDistinctMiddlesStayDistinct(): void
    {
        $head = str_repeat('alpha-', 12);
        $tail = str_repeat('-omega', 12) . '.jpg';

        $victim   = \Files::escape($head . 'VICTIM-CONFIDENTIAL-PHOTO-2024' . $tail);
        $attacker = \Files::escape($head . 'attacker-payload-xyz-123456789' . $tail);

        self::assertNotSame($victim, $attacker, 'names differing only in the middle must not fold together');
        self::assertLessThanOrEqual(self::MAX, \strlen($victim));
        self::assertLessThanOrEqual(self::MAX, \strlen($attacker));
    }

    /** Escaping an already-escaped (and already elided) name must not change it again. */
    public function testCapIsIdempotent(): void
    {
        $once  = \Files::escape(str_repeat('z', 300) . '.jpg');
        self::assertSame($once, \Files::escape($once), 'escape() must be stable on its own output');
    }

    /** The result must stay a clean filename: no run of separators, no leading/trailing separator. */
    public function testCappedNameHasNoStraySeparators(): void
    {
        $out = \Files::escape(str_repeat('ab-', 80) . '-.-.-' . str_repeat('-cd', 30) . '.jpg');

        self::assertDoesNotMatchRegularExpression('/^[-.]|[-.]$/', $out, "stray leading/trailing separator: $out");
        self::assertDoesNotMatchRegularExpression('/[-.]{3,}/', $out, "run of separators: $out");
    }
}
