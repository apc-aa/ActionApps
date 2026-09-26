<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use AA\Util\FormToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FormTokenTest extends TestCase
{
    public function test_token_is_stable_per_purpose_and_checks(): void
    {
        $a = FormToken::get('repair');
        $this->assertSame($a, FormToken::get('repair'));
        $this->assertNotSame($a, FormToken::get('note'), 'a token of one form does not open another');
        $this->assertTrue(FormToken::check('repair', $a));
        $this->assertFalse(FormToken::check('note', $a));
        $this->assertFalse(FormToken::check('repair', ''));
        $this->assertFalse(FormToken::check('repair', 'deadbeef'));
    }

    /** @return array<string, array{string, ?string, bool}> method, token (null = the valid one), allowed */
    public static function posts(): array
    {
        return [
            'POST with the token'           => ['POST', null, true],
            'GET with the token'            => ['GET', null, false],
            'POST without a token'          => ['POST', '', false],
            'POST with a wrong token'       => ['POST', 'deadbeef', false],
            'POST with another form token'  => ['POST', FormToken::get('note'), false],
            'no method (CLI) with the token' => ['', null, false],
        ];
    }

    #[DataProvider('posts')]
    public function test_checkPost_allows_only_a_post_with_the_token(string $method, ?string $token, bool $allowed): void
    {
        $server = ($method === '') ? [] : ['REQUEST_METHOD' => $method];
        $this->assertSame($allowed, FormToken::checkPost('replace', $token ?? FormToken::get('replace'), $server));
    }
}
