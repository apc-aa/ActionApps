<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA_Validate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * url and e-mail have their own pattern, so the only parameter of the stored string
 * (field.input_validate) is the maximum length.
 */
final class ValidateUrlEmailTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
        require_once __DIR__ . '/../../../include/validate.php';
    }

    /** @return array<string, array{string, string, bool}> */
    public static function values(): array
    {
        return [
            'url short enough'    => ['url:30', 'https://example.org/a', true],
            'url too long'        => ['url:20', 'https://example.org/abcdef', false],
            'url not a url'       => ['url:30', 'example', false],
            'e-mail short enough' => ['e-mail:20', 'a@example.org', true],
            'e-mail too long'     => ['e-mail:10', 'abcdef@example.org', false],
            'no length'           => ['e-mail:', 'abcdef@example.org', true],
        ];
    }

    #[DataProvider('values')]
    public function test_validate_theParameterIsTheLength(string $validator, string $value, bool $valid): void
    {
        $this->assertSame($valid, AA_Validate::factoryByString($validator)->validate($value));
    }

    /** the browser stops at the length too, the input keeps its own type */
    public function test_getHtmlInputAttr_carriesTheLength(): void
    {
        $url   = AA_Validate::factoryByString('url:30')->getHtmlInputAttr();
        $email = AA_Validate::factoryByString('e-mail:20')->getHtmlInputAttr();

        $this->assertSame(['url', 30], [$url['type'], $url['maxlength'] ?? null]);
        $this->assertSame('^http(s?):\/\/\S+\.\S+', $url['pattern']);
        $this->assertSame(['email', 20], [$email['type'], $email['maxlength'] ?? null]);
        $this->assertArrayNotHasKey('pattern', $email);
    }
}
