<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use AA\Util\FormToken;
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
}
