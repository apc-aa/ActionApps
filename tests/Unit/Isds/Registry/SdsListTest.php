<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Registry;

use AA\Http\Response;
use AA\Http\ResponseTooLargeException;
use AA\Isds\Registry\Box;
use AA\Isds\Registry\SdsList;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Fixtures\FakeHttp;

/**
 * The fixture is 31 real boxes cut byte for byte from the 2026 file: the cities, city districts
 * without an IČO, subordinate boxes, a notary and names with entities the matching depends on.
 */
final class SdsListTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/fixtures/seznam_ds_ovm-sample.xml';

    private const ROOT = '<list xmlns="http://seznam.gov.cz/ovm/datafile/seznam_ds/v1" source-system="test">';

    private string $dir = '';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../fixtures/FakeHttp.php';
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sdslist-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            if (is_file($file) || is_link($file)) {
                unlink($file);
            } elseif (is_dir($file)) {
                rmdir($file);
            }
        }
        rmdir($this->dir);
    }

    public function test_fromXml_fixtureInFileOrder(): void
    {
        $ids = array_column(self::boxes(self::FIXTURE), 'id');

        self::assertSame([
            '8iedd6n', 'byvpj5f', '27hmz4e', '48ia97h', 'x2pbqzq', 'eynaeht', 'e23ma5f', 'r7skvpf',
            'xx3bzck', '6kibw39', 'xphb8g8', 'qykbwe7', 'erdb3s9', 'irnb7wg', 'bmzbv7c', 'scedv36',
            'g47bft8', 'p7vb6nr', 'eerak7a', 'bgxbr7u', 'jm6amhn', 'q7bb677', 'cpkw5zh', '3bmucqz',
            'mqhmhtu', 'a7kbrrn', '7c6by6u', '5zubv7w', '6iybfxn', 'qebd4jz', '3th5n6b',
        ], $ids);
    }

    public function test_fromXml_cityHasAllFields(): void
    {
        self::assertEquals(new Box('a7kbrrn', '44992785', '44992785', 'Statutární město Brno', 'OVM', 10, true, false), $this->fixtureBoxes()['a7kbrrn']);
    }

    // city districts of Brno, Ostrava and Liberec have no IČO of their own, only the authority id
    public function test_fromXml_cityDistrictWithoutIco(): void
    {
        $district = $this->fixtureBoxes()['qykbwe7'];

        self::assertSame('', $district->ico);
        self::assertSame('06217893', $district->idOvm);
        self::assertSame('Městská část Brno-střed', $district->name);
        self::assertTrue($district->isMaster);
    }

    public function test_fromXml_subordinateBoxes(): void
    {
        $boxes = $this->fixtureBoxes();

        self::assertEquals(new Box('r7skvpf', '00063941', '', 'MČ Praha 10 - DS pro zveřejňování (Městská část Praha 10)', 'OVM', 13, false, false), $boxes['r7skvpf']);
        self::assertFalse($boxes['scedv36']->isMaster);
        self::assertSame('44992785', $boxes['scedv36']->ico, 'the city police shares the city\'s IČO');
        self::assertFalse($boxes['qebd4jz']->isMaster, 'subtype 10 can be subordinate too');
        self::assertSame(10, $boxes['qebd4jz']->subtype);
    }

    public function test_fromXml_icoKeepsLeadingZeros(): void
    {
        self::assertSame('00240044', $this->fixtureBoxes()['erdb3s9']->ico);
    }

    public function test_fromXml_entitiesDecoded(): void
    {
        $boxes = $this->fixtureBoxes();

        self::assertSame('Akademie Vitae & základní umělecká škola a středisko volného času Praha, s.r.o.', $boxes['byvpj5f']->name);
        self::assertSame("Soukromá základní škola Acorn's & John's school s.r.o.", $boxes['3bmucqz']->name);
    }

    public function test_fromXml_notaryIsSubtype15(): void
    {
        self::assertEquals(new Box('3th5n6b', '70673683', '70673683', 'Adam Jaroslav - notář', 'OVM', 15, true, false), $this->fixtureBoxes()['3th5n6b']);
    }

    public function test_fromXml_personNameWhenNoTradeName(): void
    {
        $file = $this->write(self::list(self::box('jana001', '<name><person><firstName> Jana </firstName><lastName>Nováková</lastName></person><tradeName>  </tradeName></name>')));

        self::assertSame('Jana Nováková', self::boxes($file)[0]->name);
    }

    public function test_fromXml_valuesTrimmedEmptyElementsAndBooleans(): void
    {
        $file = $this->write(self::list(
            self::box(' aaaaaa1 ', '<subtype> 16 </subtype><ico/><idOVM> 123 </idOVM><pdz>true</pdz><hierarchy><isMaster>1</isMaster></hierarchy>')
            . self::box('aaaaaa2', '<subtype>x</subtype><pdz>false</pdz><hierarchy><isMaster>false</isMaster><masterId>aaaaaa1</masterId></hierarchy>'),
        ));

        self::assertEquals([
            new Box('aaaaaa1', '', '123', '', 'OVM', 16, true, true),
            new Box('aaaaaa2', '', '', '', 'OVM', 0, false, false),
        ], self::boxes($file));
    }

    public function test_fromXml_onlyBoxesInTheListCount(): void
    {
        $file = $this->write(self::list(self::box('aaaaaa1', '') . '<other><box><id>nested</id></box></other>'));

        self::assertSame(['aaaaaa1'], array_column(self::boxes($file), 'id'));
    }

    /** @return array<string, array{string}> */
    public static function otherIds(): array
    {
        return [
            'no id'     => ['<box><type>OVM</type></box>'],
            'empty box' => ['<box/>'],
            'empty id'  => [self::box('', '')],
            'capitals'  => [self::box('AAAAAA1', '')],
            'six'       => [self::box('aaaaaa', '')],
            'eight'     => [self::box('aaaaaaa1', '')],
            'space'     => [self::box('aaa aa1', '')],
            'dash'      => [self::box('aaa-aa1', '')],
        ];
    }

    // the id becomes a recipient address: another shape means the format changed, not a box to skip
    #[DataProvider('otherIds')]
    public function test_fromXml_boxWithAnotherIdRefusesTheList(string $box): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('with the id');
        self::boxes($this->write(self::list(self::box('aaaaaa1', '') . $box)));
    }

    public function test_fromXml_longIdCutInTheMessage(): void
    {
        try {
            self::boxes($this->write(self::list(self::box(str_repeat('a', 5000), ''))));
            self::fail('UnexpectedValueException expected');
        } catch (\UnexpectedValueException $e) {
            self::assertLessThan(400, strlen($e->getMessage()));
            self::assertStringContainsString('…', $e->getMessage());
        }
    }

    public function test_fromXml_emptyList(): void
    {
        self::assertSame([], self::boxes($this->write(self::list(''))));
    }

    /** @return array<string, array{string}> */
    public static function notTheList(): array
    {
        $fixture = (string) file_get_contents(self::FIXTURE);
        return [
            'empty file'     => [''],
            'json'           => ['{"error": "maintenance"}'],
            'html page'      => ['<html><head><title>Error</title></head><body><p>Service unavailable</p></body></html>'],
            'html5 doctype'  => ['<!DOCTYPE html><html><body>Error</body></html>'],
            'no namespace'   => ['<?xml version="1.0"?><list><box><id>a</id></box></list>'],
            'other root'     => ['<?xml version="1.0"?><seznam xmlns="http://seznam.gov.cz/ovm/datafile/seznam_ds/v1"/>'],
            'damaged inside' => [str_replace('<id>xx3bzck</id>', '<id>xx3bzck</i>', $fixture)],
            'cut off'        => [substr($fixture, 0, (int) (strlen($fixture) / 2))],
            'undefined entity' => [self::list(self::box('a', '<ico>&nbsp;</ico>'))],
            'internal entity'  => ['<?xml version="1.0"?><!DOCTYPE list [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;">]>'
                . self::ROOT . self::box('&b;', '') . '</list>'],
            'comment'          => [self::list(self::box('aaaaaa1', '<ico>1<!-- x -->2</ico>'))],
            'cdata'            => [self::list(self::box('aaaaaa1', '<ico><![CDATA[12]]></ico>'))],
            'instruction'      => [self::list(self::box('aaaaaa1', '<?x y?>'))],
            'second xml decl'  => [self::list('') . '<?xml version="1.0"?>'],
        ];
    }

    #[DataProvider('notTheList')]
    public function test_fromXml_notTheListRefused(string $xml): void
    {
        $this->expectException(\UnexpectedValueException::class);
        self::boxes($this->write($xml));
    }

    /** @return array<string, array{string}> */
    public static function notUtf8(): array
    {
        $utf16 = '<?xml version="1.0" encoding="UTF-16"?>' . self::ROOT . self::box('aaaaaa1', '') . '</list>';
        $list  = self::ROOT . self::box('aaaaaa1', '') . '</list>';
        return [
            'utf-16le with bom'    => ["\xFF\xFE" . mb_convert_encoding($utf16, 'UTF-16LE', 'UTF-8')],
            'utf-16le without bom' => [mb_convert_encoding($utf16, 'UTF-16LE', 'UTF-8')],
            'utf-16be'             => ["\xFE\xFF" . mb_convert_encoding($utf16, 'UTF-16BE', 'UTF-8')],
            'utf-7 declared'       => ['<?xml version="1.0" encoding="UTF-7"?>' . $list],
            'iso-8859-2 declared'  => ["<?xml version='1.0' encoding = 'ISO-8859-2'?>" . $list],
            'declaration not first' => ["\n" . self::list(self::box('aaaaaa1', ''))],
            'space after the bom'  => ["\xEF\xBB\xBF " . self::list(self::box('aaaaaa1', ''))],
        ];
    }

    // libxml would decode another encoding and find markup the scan of the bytes did not see
    #[DataProvider('notUtf8')]
    public function test_fromXml_notUtf8Refused(string $xml): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('UTF-8');
        self::boxes($this->write($xml));
    }

    public function test_fromXml_utf8BomAccepted(): void
    {
        self::assertSame(['aaaaaa1'], array_column(self::boxes($this->write("\xEF\xBB\xBF" . self::list(self::box('aaaaaa1', '')))), 'id'));
    }

    /** @return array<string, array{string, string}> */
    public static function mergedFields(): array
    {
        return [
            'id twice'          => ['<box><id>abc</id><id>defg</id></box>', '<id> twice'],
            'empty ico, ico'    => [self::box('aaaaaa1', '<ico/><ico>1</ico>'), '<ico> twice'],
            'element in the id' => ['<box><id>abc<b>zz</b>defg</id></box>', 'inside <id>'],
            'element in a name' => [self::box('aaaaaa1', '<name><tradeName>A<x:b xmlns:x="urn:x"/>B</tradeName></name>'), 'inside <name/tradeName>'],
        ];
    }

    // the texts of a field are joined, so these would make one value of two
    #[DataProvider('mergedFields')]
    public function test_fromXml_fieldsThatWouldMergeRefused(string $box, string $message): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage($message);
        self::boxes($this->write(self::list($box)));
    }

    public function test_fromXml_damageReportsTheLine(): void
    {
        $fixture = (string) file_get_contents(self::FIXTURE);
        $file    = $this->write(str_replace('<id>xx3bzck</id>', '<id>xx3bzck</i>', $fixture));
        $line    = substr_count(substr($fixture, 0, (int) strpos($fixture, '<id>xx3bzck</id>')), "\n") + 1;

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage("line $line:");
        self::boxes($file);
    }

    // nothing outside the file may reach a box or a message: the DOCTYPE is refused before libxml reads the file
    public function test_fromXml_externalEntityNotLoaded(): void
    {
        file_put_contents($this->dir . '/secret.txt', 'SECRET-MARKER');
        $xml = '<?xml version="1.0"?><!DOCTYPE list [<!ENTITY x SYSTEM "file://' . $this->dir . '/secret.txt">]>'
            . '<list xmlns="http://seznam.gov.cz/ovm/datafile/seznam_ds/v1"><box><id>&x;</id></box></list>';

        try {
            self::boxes($this->write($xml));
            self::fail('UnexpectedValueException expected');
        } catch (\UnexpectedValueException $e) {
            self::assertStringContainsString('DOCTYPE', $e->getMessage());
            self::assertStringNotContainsString('SECRET-MARKER', $e->getMessage());
        }
    }

    // libxml reads the start of the first box with the root; an error there must not be cleared with it
    public function test_fromXml_errorFoundWithTheRootRefused(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Namespace prefix x');
        self::boxes($this->write(self::list(self::box('aaaaaa1', '<x:foo/>'))));
    }

    /** @return array<string, array{string}> */
    public static function markup(): array
    {
        return ['comment' => ['<!-- -->'], 'instruction' => ['<?x y?>']];
    }

    // libxml builds a run of comments before it returns the first one, so they are refused unread -
    // also when the 1 MB chunks of the scan split the "<!" or "<?"
    #[DataProvider('markup')]
    public function test_fromXml_markupAcrossTheScanChunkRefused(string $markup): void
    {
        $head = '<?xml version="1.0" encoding="UTF-8"?>' . self::ROOT . '<box><id>aaaaaa1</id><ico>';
        $file = $this->write($head . str_repeat('a', 1024 * 1024 - 1 - strlen($head)) . $markup . '1</ico></box></list>');

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('<! or <? markup');
        self::boxes($file);
    }

    // XMLReader would decode %41 to A and read another file
    public function test_fromXml_percentInThePathRefused(): void
    {
        file_put_contents($this->dir . '/aA.xml', self::list(self::box('aaaaaa1', '')));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("must not contain '%'");
        SdsList::fromXml($this->dir . '/a%41.xml');
    }

    // unreadable, not a bad list - and before the first box is asked for
    public function test_fromXml_missingFileRefusedAtTheCall(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot read the list of data boxes');
        SdsList::fromXml($this->dir . '/none.xml');
    }

    // the caller's own XML code between two boxes, and after a failure, runs with its own libxml
    // settings and finds no errors left from the list
    public function test_fromXml_libxmlStateRestored(): void
    {
        $before = libxml_use_internal_errors(false);
        $loader = libxml_get_external_entity_loader();
        try {
            foreach (SdsList::fromXml(self::FIXTURE) as $box) {
                self::assertFalse(libxml_use_internal_errors());
                self::assertSame($loader, libxml_get_external_entity_loader());
            }
            try {
                self::boxes($this->write(self::list(self::box('a', '<ico>'))));
            } catch (\UnexpectedValueException) {
            }
            self::assertFalse(libxml_use_internal_errors());
            self::assertSame($loader, libxml_get_external_entity_loader());
            self::assertSame([], libxml_get_errors());
        } finally {
            libxml_use_internal_errors($before);
        }
    }

    public function test_download_writesTheList(): void
    {
        $http = new FakeHttp([new Response(200, (string) file_get_contents(self::FIXTURE))]);
        $to   = $this->dir . '/ovm.xml';

        self::assertSame($to, SdsList::download($http, $to));

        self::assertSame([['GET', SdsList::URL]], array_map(static fn(array $c): array => [$c['method'], $c['url']], $http->calls));
        self::assertCount(31, self::boxes($to));
        self::assertSame([$to], glob($this->dir . '/*'), 'no temporary file left');
        clearstatcache();
        self::assertSame(0o666 & ~umask(), fileperms($to) & 0o777, 'not the 0600 of tempnam()');
    }

    // a link planted under a predictable name must not be written through
    public function test_download_plantedLinkNotFollowed(): void
    {
        $to     = $this->dir . '/ovm.xml';
        $victim = $this->dir . '/victim.txt';
        file_put_contents($victim, 'victim');
        symlink($victim, $to . '.part');

        SdsList::download(new FakeHttp([new Response(200, (string) file_get_contents(self::FIXTURE))]), $to);

        self::assertSame('victim', file_get_contents($victim));
        self::assertTrue(is_link($to . '.part'));
        self::assertCount(31, self::boxes($to));
    }

    // a 3xx is not followed - the message says where it pointed
    public function test_download_redirectRefusedWithItsTarget(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 302 to https://www.datovka.gov.cz/elsewhere');
        SdsList::download(new FakeHttp([new Response(302, '', [], 'https://www.datovka.gov.cz/elsewhere')]), $this->dir . '/ovm.xml');
    }

    // tempnam() falls back to the system temp directory - that file must not stay there
    public function test_download_missingDirectoryRefused(): void
    {
        $name = 'sdslist-' . bin2hex(random_bytes(6)) . '.xml';

        try {
            SdsList::download(new FakeHttp([new Response(200, (string) file_get_contents(self::FIXTURE))]), $this->dir . '/missing/' . $name);
            self::fail('RuntimeException expected');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('missing or not writable', $e->getMessage());
        }
        self::assertSame([], glob(sys_get_temp_dir() . '/' . $name . '*'));
    }

    public function test_download_renameFailureRemovesTheTemporaryFile(): void
    {
        $to = $this->dir . '/ovm.xml';
        mkdir($to);     // rename() cannot put a file over a directory

        try {
            SdsList::download(new FakeHttp([new Response(200, (string) file_get_contents(self::FIXTURE))]), $to);
            self::fail('RuntimeException expected');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Cannot replace', $e->getMessage());
        }
        self::assertSame([$to], glob($this->dir . '/*'));
    }

    // the limit holds also with a client that has none
    public function test_download_overMaxBytesRefused(): void
    {
        try {
            SdsList::download(new FakeHttp([new Response(200, str_repeat(' ', SdsList::MAX_BYTES + 1))]), $this->dir . '/ovm.xml');
            self::fail('ResponseTooLargeException expected');
        } catch (ResponseTooLargeException) {
        }
        self::assertSame([], glob($this->dir . '/*'));
    }

    public function test_download_statusOtherThan200Refused(): void
    {
        $to = $this->dir . '/ovm.xml';

        try {
            SdsList::download(new FakeHttp([new Response(503, 'Service Unavailable')]), $to);
            self::fail('RuntimeException expected');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('503', $e->getMessage());
            self::assertStringContainsString('Service Unavailable', $e->getMessage());
        }
        self::assertSame([], glob($this->dir . '/*'));
    }

    /** @return array<string, array{string}> */
    public static function notAWholeList(): array
    {
        return [
            'error page' => ['<html><body>Údržba</body></html>'],
            'cut off'    => [substr((string) file_get_contents(self::FIXTURE), 0, 5000)],
            'no box'     => [self::list('')],
            'no box id'  => [self::list(self::box('', ''))],
        ];
    }

    // a 200 that is not the whole list must not replace the last good one
    #[DataProvider('notAWholeList')]
    public function test_download_notAWholeListKeepsThePreviousFile(string $body): void
    {
        $to = $this->dir . '/ovm.xml';
        file_put_contents($to, 'previous');

        try {
            SdsList::download(new FakeHttp([new Response(200, $body)]), $to);
            self::fail('UnexpectedValueException expected');
        } catch (\UnexpectedValueException) {
        }

        self::assertSame('previous', file_get_contents($to));
        self::assertSame([$to], glob($this->dir . '/*'), 'no temporary file left');
    }

    /** @return array<string, Box> */
    private function fixtureBoxes(): array
    {
        return array_column(self::boxes(self::FIXTURE), null, 'id');
    }

    /** @return list<Box> */
    private static function boxes(string $file): array
    {
        return iterator_to_array(SdsList::fromXml($file), false);
    }

    private function write(string $xml): string
    {
        $file = $this->dir . '/list-' . bin2hex(random_bytes(4)) . '.xml';
        file_put_contents($file, $xml);
        return $file;
    }

    private static function list(string $boxes): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . self::ROOT . $boxes . '</list>';
    }

    private static function box(string $id, string $inner): string
    {
        return "<box><id>$id</id><type>OVM</type>$inner</box>";
    }
}
