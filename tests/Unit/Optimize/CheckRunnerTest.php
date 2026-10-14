<?php

declare(strict_types=1);

/**
 * CheckRunner::classes() is the one way to list optimizers by type. src/Optimize/ also holds
 * helper classes (CodePoints) that are not optimizers and have no types() - service/sql_update.php
 * called ::types() on every class in the directory and died on a fresh install (2026-09-19).
 */

namespace {
    // AA_Components pulls include/constants.php, which needs these config.php constants.
    foreach ([
        'AA_INSTAL_URL'   => 'http://localhost/aa/',
        'AA_INSTAL_PATH'  => '/aa/',
        'IMG_UPLOAD_PATH' => sys_get_temp_dir() . '/',
        'AA_BASE_PATH'    => dirname(__DIR__, 3) . '/',
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
}

namespace Tests\Unit\Optimize {

    use AA\Optimize\AbstractOptimize;
    use AA\Optimize\CheckRunner;
    use AA\Optimize\CodePoints;
    use AA\Optimize\Finding;
    use PHPUnit\Framework\TestCase;

    final class CheckRunnerTest extends TestCase
    {
        public function test_classes_sqlUpdate_returnsOnlyOptimizers(): void
        {
            $classes = CheckRunner::classes(['sql_update']);

            self::assertNotEmpty($classes);
            self::assertNotContains(CodePoints::class, $classes);
            foreach ($classes as $class) {
                self::assertTrue(is_subclass_of($class, AbstractOptimize::class), $class);
                self::assertContains('sql_update', $class::types(), $class);
            }
        }

        /** a finding without a key is rebuilt with the class name as key - every field must survive it */
        public function test_runClass_keyless_finding_keeps_every_field(): void
        {
            $check = new class extends AbstractOptimize {
                public function findings(): array
                {
                    return [new Finding(Finding::WARNING, 'title', count: 3, since: 10, evidence: [['a' => 'b']], advice: 'advice', links: ['page' => 'https://x/'], repairable: true, last: 20, checked: 30, recheckable: false, verify: 'next run', muted: 'known', kind: 'Kind', place: '/page')];
                }
            };

            $finding = CheckRunner::runClass('CheckX', $check::class)[0];

            self::assertSame('CheckX', $finding->key);
            self::assertSame('CheckX', $finding->check);
            self::assertSame(
                [Finding::WARNING, 'title', 3, 10, [['a' => 'b']], 'advice', ['page' => 'https://x/'], true, 20, 30, false, 'next run', 'known', 'Kind', '/page'],
                [$finding->severity, $finding->title, $finding->count, $finding->since, $finding->evidence, $finding->advice, $finding->links, $finding->repairable, $finding->last, $finding->checked, $finding->recheckable, $finding->verify, $finding->muted, $finding->kind, $finding->place],
            );
        }
    }
}
