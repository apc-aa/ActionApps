<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Optimize\AbstractOptimize;
use AA\Optimize\Finding;
use PHPUnit\Framework\TestCase;

/** test() and findings() default to each other - a legacy class writes test(),
 *  a checker writes findings(); neither may recurse into the other. */
final class AbstractOptimizeFindingsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // _m(), get_class_name()
    }

    public function test_legacy_class_yields_one_error_finding_with_its_report(): void
    {
        $legacy = new class extends AbstractOptimize {
            public static function name(): string
            {
                return 'Legacy';
            }
            public function test(): bool
            {
                $this->message('something is off');
                return false;
            }
        };
        $findings = $legacy->findings();
        $this->assertCount(1, $findings);
        $this->assertSame(Finding::ERROR, $findings[0]->severity);
        $this->assertSame('Legacy', $findings[0]->title);
        $this->assertSame([['txt' => 'something is off']], $findings[0]->evidence);
        $this->assertTrue($findings[0]->repairable, 'the default actions() include repair');
        $this->assertFalse($legacy->test());
    }

    public function test_legacy_class_passing_yields_ok_without_repair(): void
    {
        $legacy = new class extends AbstractOptimize {
            public function test(): bool
            {
                return true;
            }
        };
        $findings = $legacy->findings();
        $this->assertSame(Finding::OK, $findings[0]->severity);
        $this->assertFalse($findings[0]->repairable);
    }

    public function test_legacy_class_can_declare_a_failed_test_as_advice(): void
    {
        $advisory = new class extends AbstractOptimize {
            public static function failSeverity(): string
            {
                return Finding::INFO;
            }
            public function test(): bool
            {
                return false;
            }
        };
        $this->assertSame(Finding::INFO, $advisory->findings()[0]->severity);
        $this->assertFalse($advisory->test(), 'test() itself still reports the failure to Optimize');
    }

    public function test_checker_derives_test_from_its_findings(): void
    {
        $failing = new class extends AbstractOptimize {
            public function findings(): array
            {
                return [new Finding(Finding::WARNING, 'slow'), new Finding(Finding::ERROR, 'dead')];
            }
        };
        $this->assertFalse($failing->test());

        $warning_only = new class extends AbstractOptimize {
            public function findings(): array
            {
                return [new Finding(Finding::WARNING, 'slow')];
            }
        };
        $this->assertTrue($warning_only->test(), 'a warning is not an error');
    }

    public function test_runner_gives_a_keyless_finding_the_check_as_key(): void
    {
        $legacy = new class extends AbstractOptimize {
            public function findings(): array
            {
                return [new Finding(Finding::WARNING, 'a'), new Finding(Finding::WARNING, 'b', key: 'own')];
            }
        };
        $findings = \AA\Optimize\CheckRunner::runClass('Legacy', get_class($legacy));
        $this->assertSame(['Legacy', 'own'], [$findings[0]->key, $findings[1]->key]);
        $this->assertSame('Legacy', $findings[1]->check);
    }

    public function test_class_writing_neither_is_ok(): void
    {
        $empty = new class extends AbstractOptimize {};
        $this->assertTrue($empty->test());
        $this->assertSame(Finding::OK, $empty->findings()[0]->severity);
    }
}
