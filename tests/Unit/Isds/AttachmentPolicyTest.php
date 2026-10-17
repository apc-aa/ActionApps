<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Isds\AttachmentPolicy;
use AA\Isds\File;
use AA\Isds\Message;
use AA\Upload\UploadDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AttachmentPolicyTest extends TestCase
{
    private const URL = 'https://example.org/img_upload/abc';
    private const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
    private const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
    /** an empty ZIP archive: the container of every ZIP-based format */
    private const ZIP = "PK\x05\x06\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0";
    private const PEM = "-----BEGIN CERTIFICATE-----\nMIIBszCCAVmgAwIBAgIUQ0lJ\n-----END CERTIFICATE-----\n";

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
            'docx seen as its zip'   => ['dopis.docx', self::ZIP, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'odt seen as its zip'    => ['dopis.odt', self::ZIP, 'application/vnd.oasis.opendocument.text'],
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
    public function test_file_refuses(string $name, string $content, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        AttachmentPolicy::file($name, $content);
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
            // the content check of HTML is never reached: no UTF-16 script can slip past it
            'html in utf-16'        => ['dopis.html', "\xFF\xFE" . mb_convert_encoding('<script>alert(1)</script>', 'UTF-16LE', 'UTF-8'), 'into the letter'],
        ];
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
