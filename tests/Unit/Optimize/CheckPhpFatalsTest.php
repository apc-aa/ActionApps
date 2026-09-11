<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Optimize\CheckPhpFatals;
use PHPUnit\Framework\TestCase;

final class CheckPhpFatalsTest extends TestCase
{
    public function test_last_deployment_is_the_newest_change_or_zero(): void
    {
        $this->assertSame(0, CheckPhpFatals::lastDeployment([]));
        $this->assertSame(300, CheckPhpFatals::lastDeployment([['time' => 100], ['time' => 300]]));
    }

    public function test_page_of_selector(): void
    {
        $this->assertSame('admin/se_fields.php', CheckPhpFatals::pageOf('admin/se_fields.php:274'));
        $this->assertSame('modules/site/index.php', CheckPhpFatals::pageOf('modules/site/index.php:69'));
        $this->assertSame('', CheckPhpFatals::pageOf('src/Util/VersionProgress.php:460'), 'a class file is not a page');
        $this->assertSame('', CheckPhpFatals::pageOf('include/util.php:12'));
        $this->assertSame('', CheckPhpFatals::pageOf('/data/www/x/view.php:3'), 'an absolute path never leaves the install');
        $this->assertSame('', CheckPhpFatals::pageOf('../etc/x.php:3'));
    }

    public function test_new_since_deployment(): void
    {
        $this->assertTrue(CheckPhpFatals::isNewSince(400, 300));
        $this->assertTrue(CheckPhpFatals::isNewSince(300, 300));
        $this->assertFalse(CheckPhpFatals::isNewSince(200, 300));
        $this->assertFalse(CheckPhpFatals::isNewSince(200, 0), 'no deployment on record - nothing to compare with');
    }
}
