<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use MLX;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * MLX::getparamstring() (include/mlx.php) makes the query of the language links above the item form,
 * which are written into an href; the parameters it gets come from the request.
 */
final class MlxParamStringTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../../include/mlx.php';
    }

    private static function paramString(array $params): string
    {
        return (new ReflectionClass(MLX::class))->newInstanceWithoutConstructor()->getparamstring($params);
    }

    public function test_getparamstring_ordinaryValues_keepTheirForm(): void
    {
        self::assertSame('encap=false&', self::paramString(['encap' => false]));
        self::assertSame('encap=true&', self::paramString(['encap' => true]));
        self::assertSame('encap=true&a=1&', self::paramString(['encap' => 'true', 'a' => 1]));
    }

    public function test_getparamstring_requestText_isUrlEncoded(): void
    {
        self::assertSame("encap=x%27%3E%3Cb%3E&", self::paramString(['encap' => "x'><b>"]));
    }
}
