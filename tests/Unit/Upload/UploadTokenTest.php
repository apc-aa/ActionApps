<?php

declare(strict_types=1);

namespace Tests\Unit\Upload;

use AA\Upload\UploadPolicy;
use AA\Upload\UploadToken;
use PHPUnit\Framework\TestCase;

final class UploadTokenTest extends TestCase
{
    private const SLICE = '0123456789abcdef0123456789abcdef';
    private const NOW   = 1790000000;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // DB_PASSWORD, DB_NAME for Secret::key()
    }

    public function test_user_token_is_bound_to_slice_user_and_time(): void
    {
        $token = UploadToken::issue(self::SLICE, UploadPolicy::USER, 'u1', self::NOW);
        $this->assertSame(UploadPolicy::USER, UploadToken::verify($token, self::SLICE, 'u1', self::NOW + 100));
        $this->assertNull(UploadToken::verify($token, self::SLICE, 'u2', self::NOW), 'another user');
        $this->assertNull(UploadToken::verify($token, str_repeat('f', 32), 'u1', self::NOW), 'another slice');
        $this->assertNull(UploadToken::verify($token, self::SLICE, 'u1', self::NOW + UploadToken::TTL + 1), 'expired');
    }

    public function test_anonymous_token_survives_the_page_cache_and_any_viewer(): void
    {
        $token = UploadToken::issue(self::SLICE, UploadPolicy::ANON, 'whoever', self::NOW);
        $this->assertSame(UploadPolicy::ANON, UploadToken::verify($token, self::SLICE, '', self::NOW + 30 * 86400));
        $this->assertSame(UploadPolicy::ANON, UploadToken::verify($token, self::SLICE, 'u9', self::NOW));
    }

    public function test_forged_tokens_are_refused(): void
    {
        $anon = UploadToken::issue(self::SLICE, UploadPolicy::ANON, '', self::NOW);
        $this->assertNull(UploadToken::verify('user' . substr($anon, 4), self::SLICE, '', self::NOW), 'mode swapped');
        $this->assertNull(UploadToken::verify('', self::SLICE, '', self::NOW));
        $this->assertNull(UploadToken::verify('user.1.x', self::SLICE, '', self::NOW));
        $this->assertNull(UploadToken::verify('none.0.' . str_repeat('0', 64), self::SLICE, '', self::NOW));
    }
}
