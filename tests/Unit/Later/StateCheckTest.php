<?php

declare(strict_types=1);

namespace Tests\Unit\Later;

use AA\Later\StateCheck;
use AA\Optimize\Finding;
use AA\Util\Note;
use PHPUnit\Framework\TestCase;

final class StateCheckTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // AA_Logentry_Sc properties use _m()
    }

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

    public function test_a_passing_check_is_a_bare_ok_row_so_its_title_cannot_flap(): void
    {
        $this->assertSame('Sc:ok:0:', StateCheck::entry(['severity' => Finding::OK, 'count' => 3, 'title' => 'Queue ran at 12:01'])->serializeToString());
        $this->assertSame('Sc:error:4:PHP fatal in a.php#:1', StateCheck::entry(['severity' => Finding::ERROR, 'count' => 4, 'title' => 'PHP fatal in a.php:1'])->serializeToString());
    }

    public function test_a_check_whose_problems_are_all_resolved_writes_an_ok_row(): void
    {
        $note = new Note(['state' => Note::STATE_RESOLVED]);
        $rows = StateCheck::rows(
            [$this->finding('CheckQueue', Finding::WARNING, 'cron dead')],
            [[$this->finding('CheckSecret', Finding::ERROR, 'no secret'), $note], [$this->finding('CheckQueue', Finding::INFO, 'waiting'), $note]],
        );
        $this->assertSame([
            'CheckQueue'  => ['severity' => Finding::WARNING, 'count' => 0, 'title' => 'cron dead'],
            'CheckSecret' => ['severity' => Finding::OK, 'count' => 0, 'title' => ''],
        ], $rows, 'otherwise its last problem row would still be the current one');
    }

    public function test_passed_checks_are_counted_only_on_request(): void
    {
        $findings = [$this->finding('CheckCron', Finding::OK, 'fine'), $this->finding('CheckQueue', Finding::INFO, 'waiting')];
        $this->assertSame(['CheckQueue'], array_keys(StateCheck::aggregate($findings)));
        $this->assertSame(['CheckQueue', 'CheckCron'], array_keys(StateCheck::aggregate($findings, true)));
    }
}
