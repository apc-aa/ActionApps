<?php

declare(strict_types=1);

namespace Tests\Unit\Later;

use AA\Later\StateCheck;
use AA\Optimize\Finding;
use PHPUnit\Framework\TestCase;

final class StateCheckTest extends TestCase
{
    private function finding(string $check, string $severity, string $title, int $count = 0): Finding
    {
        $f = new Finding($severity, $title, count: $count);
        $f->check = $check;
        return $f;
    }

    public function test_one_row_per_check_with_worst_severity_and_summed_count(): void
    {
        $rows = StateCheck::aggregate([
            $this->finding('CheckCron', Finding::OK, 'fine'),
            $this->finding('CheckPhpFatals', Finding::ERROR, 'fatal in a.php:1', 3),
            $this->finding('CheckQueue', Finding::WARNING, 'cron dead'),
            $this->finding('CheckPhpFatals', Finding::ERROR, 'fatal in b.php:2', 1),
            $this->finding('CheckQueue', Finding::INFO, 'waiting', 4),
        ]);
        $this->assertSame([
            'CheckPhpFatals' => ['severity' => Finding::ERROR, 'count' => 4, 'title' => 'fatal in a.php:1'],
            'CheckQueue'     => ['severity' => Finding::WARNING, 'count' => 4, 'title' => 'cron dead'],
        ], $rows);
    }

    public function test_passed_checks_are_counted_only_on_request(): void
    {
        $findings = [$this->finding('CheckCron', Finding::OK, 'fine'), $this->finding('CheckQueue', Finding::INFO, 'waiting')];
        $this->assertSame(['CheckQueue'], array_keys(StateCheck::aggregate($findings)));
        $this->assertSame(['CheckQueue', 'CheckCron'], array_keys(StateCheck::aggregate($findings, true)));
    }
}
