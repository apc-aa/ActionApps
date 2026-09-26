<?php

declare(strict_types=1);

namespace Tests\Unit\Widget;

use AA\Upload\UploadPolicy;
use AA\Upload\UploadToken;
use AA\Widget\RichtextMarkup;
use PHPUnit\Framework\TestCase;

final class RichtextMarkupTest extends TestCase
{
    private const SLICE = '0123456789abcdef0123456789abcdef';
    private const NOW   = 1790000000;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // htmlattr(), safe(), constants for Secret::key()
    }

    public function test_wrap_escapes_the_attributes_and_keeps_the_textarea(): void
    {
        $this->assertSame(
            '<aa-richtext mode="code" l10n="{&quot;editor&quot;:&quot;&lt;Ed&gt;&quot;}" editor="editor"><textarea name="x">a</textarea></aa-richtext>',
            RichtextMarkup::wrap('<textarea name="x">a</textarea>', 'code', ['l10n' => '{"editor":"<Ed>"}', 'editor' => 'editor']),
        );
    }

    public function test_nobody_gets_no_upload(): void
    {
        $this->assertSame([], RichtextMarkup::uploadAttrs(self::SLICE, UploadPolicy::NONE, 'u1', self::NOW, '/aa/api/files.php'));
    }

    public function test_an_editor_gets_a_token_the_api_accepts_and_the_document_types(): void
    {
        $attrs = RichtextMarkup::uploadAttrs(self::SLICE, UploadPolicy::USER, 'u1', self::NOW, '/aa/api/files.php');
        $this->assertSame(['user', '/aa/api/files.php', self::SLICE], [$attrs['upload'], $attrs['upload-url'], $attrs['slice']]);
        $this->assertSame(UploadPolicy::USER, UploadToken::verify($attrs['upload-token'], self::SLICE, 'u1', self::NOW + 60));
        $this->assertSame(UploadPolicy::documentTypes(), json_decode($attrs['upload-types'], true));
        $this->assertSame('jpg,jpeg,png,gif,webp,avif', $attrs['upload-images']);
    }

    public function test_anonymous_gets_images_only(): void
    {
        $attrs = RichtextMarkup::uploadAttrs(self::SLICE, UploadPolicy::ANON, '', self::NOW, '/aa/api/files.php');
        $this->assertSame('anon', $attrs['upload']);
        $this->assertArrayNotHasKey('upload-types', $attrs);
        $this->assertSame(implode(',', UploadPolicy::imageTypes(UploadPolicy::ANON)), $attrs['upload-images']);
        $this->assertStringNotContainsString('gif', $attrs['upload-images']);
    }
}
