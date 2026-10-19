<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Isds\DatovkaConf;
use AA\Optimize\CheckIsdsAccounts;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Where CheckIsdsAccounts looks for the directory of AA: AA_BASE_PATH - as config.php writes it, maybe
 * through a link - and the directory PHP resolved. Each test in a process of its own: a constant is
 * defined once.
 */
final class CheckIsdsAccountsBasesTest extends TestCase
{
    /** @return list<string> */
    private static function bases(): array
    {
        $check = (new \ReflectionClass(CheckIsdsAccounts::class))->newInstanceWithoutConstructor();

        return (new \ReflectionMethod(CheckIsdsAccounts::class, 'bases'))->invoke($check);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_bases_asWrittenAndAsResolved(): void
    {
        define('AA_BASE_PATH', '/srv/www/aa-link/');

        self::assertSame(['/srv/www/aa-link/', DatovkaConf::base()], self::bases());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_bases_onceWhenTheSame(): void
    {
        define('AA_BASE_PATH', DatovkaConf::base());

        self::assertSame([DatovkaConf::base()], self::bases());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_bases_resolvedWithoutTheConstant(): void
    {
        self::assertFalse(defined('AA_BASE_PATH'));
        self::assertSame([DatovkaConf::base()], self::bases());
    }
}
