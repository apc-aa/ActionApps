<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Isds\AttachmentPolicy;
use AA\Isds\File;
use AA\Isds\Message;
use AA\Upload\UploadDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Util\Fixtures\ZipBuilder;

require_once __DIR__ . '/../Util/fixtures/ZipBuilder.php';

final class AttachmentPolicyTest extends TestCase
{
    private const URL = 'https://example.org/img_upload/abc';
    private const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
    private const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
    /** an empty ZIP archive: the container of every ZIP-based format */
    private const ZIP = "PK\x05\x06\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0";
    private const PEM = "-----BEGIN CERTIFICATE-----\nMIIBszCCAVmgAwIBAgIUQ0lJ\n-----END CERTIFICATE-----\n";
    private const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    private const PPTX = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
    private const ODT  = 'application/vnd.oasis.opendocument.text';
    private const ODS  = 'application/vnd.oasis.opendocument.spreadsheet';
    private const ODP  = 'application/vnd.oasis.opendocument.presentation';

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/isds-attachment-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("$this->dir/*") ?: []);
        rmdir($this->dir);
    }

    private function policy(): AttachmentPolicy
    {
        return new AttachmentPolicy(new UploadDir(self::URL, $this->dir));
    }

    public function test_file_pdfAsEnclosure(): void
    {
        $file = AttachmentPolicy::file('pozvanka.pdf', self::PDF);

        self::assertSame(['pozvanka.pdf', 'application/pdf', File::ENCLOSURE, self::PDF], [$file->name, $file->mimeType, $file->metaType, $file->content]);
    }

    public function test_file_czechName(): void
    {
        self::assertSame('Příloha č. 1 – žádost.pdf', AttachmentPolicy::file('Příloha č. 1 – žádost.pdf', self::PDF)->name);
    }

    public function test_file_nameOf255Characters(): void
    {
        $name = str_repeat('ž', File::MAX_NAME - 4) . '.pdf';

        self::assertSame($name, AttachmentPolicy::file($name, self::PDF)->name);
    }

    public function test_file_mainForTheLetter(): void
    {
        self::assertSame(File::MAIN, AttachmentPolicy::file('Dopis.pdf', self::PDF, File::MAIN)->metaType);
    }

    /** the declared type is the detected one when ISDS lists it, else the first ISDS type */
    #[DataProvider('accepted')]
    public function test_file_declaresType(string $name, string $content, string $mime): void
    {
        self::assertSame($mime, AttachmentPolicy::file($name, $content)->mimeType);
    }

    public static function accepted(): array
    {
        return [
            'extension in capitals'  => ['SKEN.PDF', self::PDF, 'application/pdf'],
            'png'                    => ['logo.png', base64_decode(self::PNG), 'image/png'],
            'gif'                    => ['logo.gif', base64_decode(self::GIF), 'image/gif'],
            'jpeg as jpg'            => ['foto.jpg', "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01", 'image/jpeg'],
            'docx'                   => ['dopis.docx', ZipBuilder::ooxml('word/'), self::DOCX],
            'odt'                    => ['dopis.odt', ZipBuilder::odf(self::ODT), self::ODT],
            'csv seen as plain text' => ['obce.csv', "a;b\n1;2\n", 'text/csv'],
            'json'                   => ['data.json', '{"obec": "Kolín"}', 'application/json'],
            'xml'                    => ['data.xml', '<?xml version="1.0"?><obec/>', 'text/xml'],
            'shapefile any content'  => ['mapa.shp', "\0\0\x27\x0A binary", 'application/octet-stream'],
            'certificate as pem'     => ['ca.cer', self::PEM, 'application/x-x509-ca-cert'],
        ];
    }

    /** a .txt is any text: finfo's guess of a list, a source file or a script does not matter */
    #[DataProvider('texts')]
    public function test_file_anyTextAsTxt(string $content): void
    {
        self::assertSame('text/plain', AttachmentPolicy::file('poznamka.txt', $content)->mimeType);
    }

    public static function texts(): array
    {
        return [
            'czech text'  => ["Zápis z jednání\n\nPřítomni: starosta\n"],
            'csv-like'    => ["jmeno,prijmeni,obec\nJan,Novak,Kolin\nPetr,Svoboda,Brno\n"],
            'c source'    => ["#include <stdio.h>\nint main(){}\n"],
            'shell'       => ["#!/bin/sh\necho hi\n"],
            'saved mail'  => ["From: a@b.cz\nTo: c@d.cz\nSubject: Pozvánka\n\nDobrý den\n"],
            'json'        => ['{"obec": "Kolín", "n": [1, 2, 3]}'],
        ];
    }

    #[DataProvider('refused')]
    public function test_file_refuses(string $name, string $content, string $message, ?string $detected = null): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        AttachmentPolicy::file($name, $content, detected: $detected);
    }

    public static function refused(): array
    {
        return [
            'no extension'          => ['pozvanka', self::PDF, 'not of a type'],
            'only an extension'     => ['.pdf', self::PDF, 'not of a type'],
            'dot at the end'        => ['pozvanka.', self::PDF, 'not of a type'],
            'executable'            => ['setup.exe', "MZ\x90\0\3" . str_repeat("\0", 60), 'not of a type'],
            'webp not on the list'  => ['foto.webp', 'RIFF....WEBPVP8 ', 'not of a type'],
            'svg not on the list'   => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>', 'not of a type'],
            'path'                  => ['dopisy/pozvanka.pdf', self::PDF, 'path'],
            'windows path'          => ['C:\dopisy\pozvanka.pdf', self::PDF, 'path'],
            'name over 255'         => [str_repeat('ž', File::MAX_NAME - 3) . '.pdf', self::PDF, 'longer'],
            'control character'     => ["pozvanka\n.pdf", self::PDF, 'UTF-8'],
            'empty'                 => ['pozvanka.pdf', '', 'empty'],
            'png named pdf'         => ['pozvanka.pdf', base64_decode(self::PNG), 'image/png, not .pdf'],
            'text named pdf'        => ['pozvanka.pdf', 'Dobrý den', 'not .pdf'],
            'pdf named png'         => ['logo.png', self::PDF, 'not .png'],
            'executable named doc'  => ['dopis.doc', "MZ\x90\0\3" . str_repeat("\0", 60), 'not .doc'],
            'zip named pdf'         => ['dopis.pdf', self::ZIP, 'not .pdf'],
            'html as text'          => ['dopis.txt', "<!DOCTYPE html>\n<html><body><p>a</p></body></html>", 'text/html, not .txt'],
            'html as shapefile'     => ['mapa.shp', "<!DOCTYPE html>\n<html><body><script>alert(1)</script></body></html>", 'text/html, not .shp'],
            'svg as shapefile'      => ['mapa.shp', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml, not .shp'],
            'zip'                   => ['data.zip', self::ZIP, 'one by one'],
            'zip in capitals'       => ['DATA.ZIP', self::ZIP, 'one by one'],
            'asic-e'                => ['podpis.asice', self::ZIP, 'one by one'],
            'asic-s'                => ['podpis.scs', self::ZIP, 'one by one'],
            'html'                  => ['dopis.html', "<!DOCTYPE html>\n<html><body><p>Dobrý den</p></body></html>", 'into the letter'],
            'htm'                   => ['dopis.HTM', "<!DOCTYPE html>\n<html><body><p>Dobrý den</p></body></html>", 'into the letter'],
            'xlsx named docx'       => ['dopis.docx', ZipBuilder::ooxml('xl/'), self::XLSX . ', not .docx', self::XLSX],
            'ods named odt'         => ['dopis.odt', ZipBuilder::odf(self::ODS), self::ODS . ', not .odt', self::ODS],
            // the content check of HTML is never reached: no UTF-16 script can slip past it
            'html in utf-16'        => ['dopis.html', "\xFF\xFE" . mb_convert_encoding('<script>alert(1)</script>', 'UTF-16LE', 'UTF-8'), 'into the letter'],
        ];
    }

    /** finfo of another version may call any of these a ZIP: the structure decides */
    #[DataProvider('documents')]
    public function test_file_acceptsDocument(string $name, string $content, string $mime): void
    {
        self::assertSame($mime, AttachmentPolicy::file($name, $content, detected: 'application/zip')->mimeType);
    }

    public static function documents(): array
    {
        $types = ['[Content_Types].xml', '<Types/>', true];
        $rels  = ['_rels/.rels', '<Relationships/>', true];

        return [
            'docx'                => ['dopis.docx', ZipBuilder::ooxml('word/'), self::DOCX],
            'xlsx'                => ['rozpocet.xlsx', ZipBuilder::ooxml('xl/'), self::XLSX],
            'pptx'                => ['prezentace.pptx', ZipBuilder::ooxml('ppt/'), self::PPTX],
            'odt'                 => ['dopis.odt', ZipBuilder::odf(self::ODT), self::ODT],
            'ods'                 => ['rozpocet.ods', ZipBuilder::odf(self::ODS), self::ODS],
            'odp'                 => ['prezentace.odp', ZipBuilder::odf(self::ODP), self::ODP],
            'isdocx'              => ['faktura.isdocx', ZipBuilder::isdocx(), 'text/isdocx'],
            'docx parts reversed' => ['dopis.docx', ZipBuilder::zip([['word/document.xml', '<w:document/>', true], $rels, $types]), self::DOCX],
            'docx names upper'    => ['dopis.docx', ZipBuilder::zip([['[CONTENT_TYPES].XML', '<Types/>', true], ['_RELS/.RELS', '<Relationships/>', true], ['Word/Document.xml', '<w:document/>', true]]), self::DOCX],
            'docx with a comment' => ['dopis.docx', ZipBuilder::zip([$types, $rels, ['word/document.xml', '<w:document/>', true]], 'Dopis obce'), self::DOCX],
            'docx in zip64'       => ['dopis.docx', ZipBuilder::ooxml('word/', true), self::DOCX],
            'docx streamed'       => ['dopis.docx', ZipBuilder::zip([[...$types, ['descriptor' => true]], [...$rels, ['descriptor' => true]], ['word/document.xml', '<w:document/>', true, ['descriptor' => true]]]), self::DOCX],
            'odt streamed'        => ['dopis.odt', ZipBuilder::zip([['mimetype', self::ODT, false, ['descriptor' => true]], ['content.xml', '<office:document-content/>', true, ['descriptor' => true]]]), self::ODT],
            'odt with a new line' => ['dopis.odt', ZipBuilder::odf(self::ODT) . "\n", self::ODT],
        ];
    }

    /** a ZIP that is not the document its extension says */
    #[DataProvider('notDocuments')]
    public function test_file_refusesNotDocument(string $name, string $content): void
    {
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("is not a .$extension document");
        AttachmentPolicy::file($name, $content, detected: 'application/zip');
    }

    public static function notDocuments(): array
    {
        $types = ['[Content_Types].xml', '<Types/>', true];
        $rels  = ['_rels/.rels', '<Relationships/>', true];
        $docx  = ZipBuilder::ooxml('word/');

        return [
            'zip named docx'          => ['dopis.docx', ZipBuilder::zip([['dopis.txt', 'Dobrý den']])],
            'empty zip named docx'    => ['dopis.docx', self::ZIP],
            'zip named xlsx'          => ['data.xlsx', ZipBuilder::zip([['data.csv', "a;b\n"]])],
            'zip named pptx'          => ['slidy.pptx', ZipBuilder::zip([['slide1.png', 'x']])],
            'zip named odt'           => ['dopis.odt', ZipBuilder::zip([['dopis.txt', 'Dobrý den']])],
            'zip named ods'           => ['data.ods', self::ZIP],
            'zip named odp'           => ['slidy.odp', ZipBuilder::zip([['slide1.png', 'x']])],
            'zip named isdocx'        => ['faktura.isdocx', ZipBuilder::zip([['faktura.xml', '<Invoice/>', true]])],
            'xlsx named docx'         => ['dopis.docx', ZipBuilder::ooxml('xl/')],
            'docx without types'      => ['dopis.docx', ZipBuilder::zip([$rels, ['word/document.xml', '<w:document/>', true]])],
            'docx without rels'       => ['dopis.docx', ZipBuilder::zip([$types, ['word/document.xml', '<w:document/>', true]])],
            'docx of an empty folder' => ['dopis.docx', ZipBuilder::zip([$types, $rels, ['word/', '']])],
            'docx truncated'          => ['dopis.docx', substr($docx, 0, -30)],
            'docx directory out'      => ['dopis.docx', substr_replace($docx, pack('V', 0x7FFFFFF0), -6, 4)],
            'docx with huge count'    => ['dopis.docx', substr_replace($docx, pack('vv', 0xFFFE, 0xFFFE), -14, 4)],
            'odt of other mimetype'   => ['dopis.odt', ZipBuilder::odf(self::ODT . 'x')],
            'ods named odt'           => ['dopis.odt', ZipBuilder::odf(self::ODS)],
            'odt mimetype deflated'   => ['dopis.odt', ZipBuilder::zip([['mimetype', self::ODT, true], ['content.xml', '<office:document-content/>', true]])],
            'odt mimetype second'     => ['dopis.odt', ZipBuilder::zip([['content.xml', '<office:document-content/>', true], ['mimetype', self::ODT]])],
            'odt mimetype renamed'    => ['dopis.odt', ZipBuilder::zip([['Mimetype', self::ODT]])],
            'odt mimetype encrypted'  => ['dopis.odt', ZipBuilder::zip([['mimetype', self::ODT, false, ['flags' => 1]]])],
        ];
    }

    // finfo of PHP 8.4 does not know a document whose first entry it does not expect (7-Zip writes them)
    public function test_file_documentFinfoDoesNotKnow(): void
    {
        self::assertSame(self::DOCX, AttachmentPolicy::file('dopis.docx', ZipBuilder::ooxml('word/'), detected: 'application/octet-stream')->mimeType);
    }

    public function test_file_refusesBinaryNamedDocument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a .docx document');
        AttachmentPolicy::file('dopis.docx', "\0\x01\x02 binary", detected: 'application/octet-stream');
    }

    // what the caller detected does not spare the check of the structure
    public function test_file_checksDocumentDetectedByTheCaller(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a .docx document');
        AttachmentPolicy::file('dopis.docx', ZipBuilder::ooxml('xl/'), detected: self::DOCX);
    }

    // an OOXML document under a password is an OLE file, no ZIP; the content here is only its header, what finfo says of a real one is given
    public function test_file_encryptedOoxml(): void
    {
        $ole = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 504);

        self::assertSame('application/encrypted', AttachmentPolicy::file('tajne.docx', $ole, detected: 'application/encrypted')->mimeType);
    }

    // finfo of any version calls a bare ZIP a ZIP or some binary: both go to the check of the structure
    public function test_fromUrl_checksDocument(): void
    {
        file_put_contents("$this->dir/dopis.docx", ZipBuilder::zip([['dopis.txt', 'Dobrý den']]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a .docx document');
        $this->policy()->fromUrl(self::URL . '/dopis.docx');
    }

    public function test_file_refusesOverMessageSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('larger');
        AttachmentPolicy::file('velka.txt', str_repeat('a', Message::MAX_BYTES + 1));
    }

    public function test_types_lowerCaseExtensionsWithMimeTypes(): void
    {
        foreach (AttachmentPolicy::TYPES as $extension => $types) {
            self::assertMatchesRegularExpression('~^[a-z0-9]+$~', $extension);
            self::assertNotSame([], $types, $extension);
            foreach ($types as $type) {
                self::assertMatchesRegularExpression('~^[a-z0-9.+-]+/[a-z0-9.+-]+$~', $type, $extension);
            }
        }
    }

    /** the archives refused here are the containers Message counts, and only they */
    public function test_file_refusesExactlyTheContainers(): void
    {
        foreach (array_keys(AttachmentPolicy::TYPES) as $extension) {
            $container = (new File("a.$extension", 'application/octet-stream', File::ENCLOSURE, 'x'))->isContainer();
            try {
                AttachmentPolicy::file("a.$extension", 'x');
                $refused = false;
            } catch (\InvalidArgumentException $e) {
                $refused = str_contains($e->getMessage(), 'one by one');
            }
            self::assertSame($container, $refused, $extension);
        }
    }

    public function test_fromUrl_readsUpload(): void
    {
        file_put_contents("$this->dir/pozvanka-2026.pdf", self::PDF);

        $file = $this->policy()->fromUrl(self::URL . '/pozvanka-2026.pdf');

        self::assertSame(['pozvanka-2026.pdf', 'application/pdf', File::ENCLOSURE, self::PDF], [$file->name, $file->mimeType, $file->metaType, $file->content]);
    }

    public function test_fromUrl_ownName(): void
    {
        file_put_contents("$this->dir/priloha-c.-1-zadost.pdf", self::PDF);

        self::assertSame('Příloha č. 1 – žádost.pdf', $this->policy()->fromUrl(self::URL . '/priloha-c.-1-zadost.pdf', 'Příloha č. 1 – žádost.pdf')->name);
    }

    // a letter written as a PDF is the main file of the message
    public function test_fromUrl_mainFile(): void
    {
        file_put_contents("$this->dir/dopis.pdf", self::PDF);

        self::assertSame(File::MAIN, $this->policy()->fromUrl(self::URL . '/dopis.pdf', '', File::MAIN)->metaType);
    }

    // the name is refused before up to 20 MB are read
    public function test_fromUrl_checksTheNameFirst(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('one by one');
        $this->policy()->fromUrl(self::URL . '/missing.zip');
    }

    public function test_fromUrl_refusesContentNotOfTheName(): void
    {
        file_put_contents("$this->dir/dopis.pdf", self::PDF);

        $this->expectException(\InvalidArgumentException::class);
        $this->policy()->fromUrl(self::URL . '/dopis.pdf', 'Dopis.docx');
    }

    public function test_fromUrl_refusesOtherSites(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->policy()->fromUrl('https://evil.example/img_upload/abc/dopis.pdf');
    }
}
