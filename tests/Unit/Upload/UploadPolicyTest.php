<?php

declare(strict_types=1);

namespace Tests\Unit\Upload;

use AA\Upload\UploadPolicy;
use AA\Util\Imgfunc;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UploadPolicyTest extends TestCase
{
    public function test_mode_offers_editors_everything_and_anonymous_images(): void
    {
        $this->assertSame(UploadPolicy::USER, UploadPolicy::mode(true, false));
        $this->assertSame(UploadPolicy::USER, UploadPolicy::mode(true, true));
        $this->assertSame(UploadPolicy::ANON, UploadPolicy::mode(false, true));
        $this->assertSame(UploadPolicy::NONE, UploadPolicy::mode(false, false));
    }

    /** @return array<string, array{0: ?string, 1: bool, 2: bool, 3: string}> */
    public static function effectiveCases(): array
    {
        return [
            'editor with a user token'                 => [UploadPolicy::USER, true, false, UploadPolicy::USER],
            'expired login with a user token'          => [UploadPolicy::USER, false, true, UploadPolicy::NONE],
            'editor on a cached public page'           => [UploadPolicy::ANON, true, true, UploadPolicy::ANON],
            'anonymous token, slice closed since'      => [UploadPolicy::ANON, false, false, UploadPolicy::NONE],
            'no or forged token'                       => [null, true, true, UploadPolicy::NONE],
        ];
    }

    #[DataProvider('effectiveCases')]
    public function test_effective_honours_the_token_only_while_the_request_may_do_it(?string $granted, bool $editor, bool $anonymous, string $expected): void
    {
        $this->assertSame($expected, UploadPolicy::effective($granted, $editor, $anonymous));
    }

    public function test_anonymous_allowed_by_posting_or_editing_but_not_cron_only(): void
    {
        $this->assertFalse(UploadPolicy::anonymousAllowed(0, 0));
        $this->assertTrue(UploadPolicy::anonymousAllowed(1, 0));
        $this->assertTrue(UploadPolicy::anonymousAllowed(3, 0));
        $this->assertTrue(UploadPolicy::anonymousAllowed(0, 3));
        // a password or HTTP authentication the upload request does not carry
        $this->assertFalse(UploadPolicy::anonymousAllowed(0, 4));
        $this->assertFalse(UploadPolicy::anonymousAllowed(0, 5));
        $this->assertFalse(UploadPolicy::anonymousAllowed(0, 6));
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: int, 4: ?string}> */
    public static function refusalCases(): array
    {
        $mb = 1024 * 1024;
        return [
            'editor pdf'                  => [UploadPolicy::USER, 'a.pdf', 'application/pdf', 1000, null],
            'editor docx seen as zip'     => [UploadPolicy::USER, 'a.docx', 'application/zip', 1000, null],
            'editor html'                 => [UploadPolicy::USER, 'a.html', 'text/html', 10, 'type'],
            'editor svg'                  => [UploadPolicy::USER, 'a.svg', 'image/svg+xml', 10, 'type'],
            'editor php'                  => [UploadPolicy::USER, 'a.php', 'text/x-php', 10, 'type'],
            'editor phar'                 => [UploadPolicy::USER, 'a.phar', 'application/octet-stream', 10, 'type'],
            'html posing as jpg'          => [UploadPolicy::USER, 'a.jpg', 'text/html', 10, 'mime'],
            'html posing as txt'          => [UploadPolicy::USER, 'a.txt', 'text/html', 10, 'mime'],
            'editor big file'             => [UploadPolicy::USER, 'a.mp4', 'video/mp4', 500 * $mb, null],
            'anonymous jpeg'              => [UploadPolicy::ANON, 'a.JPG', 'image/jpeg', $mb, null],
            'anonymous gif'               => [UploadPolicy::ANON, 'a.gif', 'image/gif', 10, 'type'],
            'anonymous pdf'               => [UploadPolicy::ANON, 'a.pdf', 'application/pdf', 10, 'type'],
            'anonymous over 5 MB'         => [UploadPolicy::ANON, 'a.png', 'image/png', 6 * $mb, 'size'],
            'nobody'                      => [UploadPolicy::NONE, 'a.jpg', 'image/jpeg', 10, 'type'],
        ];
    }

    #[DataProvider('refusalCases')]
    public function test_refusal(string $mode, string $name, string $mime, int $size, ?string $expected): void
    {
        $this->assertSame($expected, UploadPolicy::refusal($mode, $name, $mime, $size));
    }

    /** anonymous images are always re-encoded, so AVIF only where GD can write it */
    public function test_avif_for_editors_always_for_anonymous_where_gd_can(): void
    {
        $this->assertNull(UploadPolicy::refusal(UploadPolicy::USER, 'a.avif', 'image/avif', 1000));
        $this->assertSame(Imgfunc::avifSupported() ? null : 'type', UploadPolicy::refusal(UploadPolicy::ANON, 'a.avif', 'image/avif', 1000));
        $this->assertSame(Imgfunc::avifSupported(), in_array('avif', UploadPolicy::imageTypes(UploadPolicy::ANON), true));
        $this->assertSame(['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'], UploadPolicy::imageTypes(UploadPolicy::USER));
        $this->assertSame([], UploadPolicy::imageTypes(UploadPolicy::NONE));
        $this->assertSame('avif', UploadPolicy::anonExtension('image/avif'));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function names(): array
    {
        return [
            'plain'                       => ['photo.jpg', 'photo.jpg'],
            'upper-case extension'        => ['photo.JPG', 'photo.jpg'],
            'double extension'            => ['shell.php.jpg', 'shell_php.jpg'],
            'dot file'                    => ['.htaccess', 'htaccess'],
            'no extension'                => ['README', 'README'],
            'only an extension'           => ['.jpg', 'jpg'],
        ];
    }

    #[DataProvider('names')]
    public function test_safe_name_keeps_one_dot(string $in, string $out): void
    {
        $this->assertSame($out, UploadPolicy::safeName($in));
    }

    public function test_document_types_skip_images_and_name_the_canonical_type(): void
    {
        $types = UploadPolicy::documentTypes();
        $this->assertContains(['mimeType' => 'application/pdf', 'extensions' => ['pdf']], $types);
        $this->assertContains(['mimeType' => 'application/vnd.ms-excel', 'extensions' => ['xls']], $types);
        $this->assertNotContains('jpg', array_merge(...array_column($types, 'extensions')));
    }
}
