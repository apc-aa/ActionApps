<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Optimize\Finding;
use AA\Optimize\StateCheckItem;
use AA\Optimize\StateCheckModel;
use AA\Util\Note;
use PHPUnit\Framework\TestCase;

/** The sections, states and anchors of State Check and the ?format=json shape -
 *  everything fed in as data, no DB (StateCheckModel::load() is the DB part). */
final class StateCheckModelTest extends TestCase
{
    private const SNAPSHOT = 1_000_000;
    private const NOW = 1_050_000;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // _m(), safe()
    }

    /** @param array<string, mixed> $args Finding constructor arguments */
    private static function finding(string $check, string $severity, string $key = '', array $args = [], string $category = 'errors'): Finding
    {
        $finding           = new Finding($severity, $args['title'] ?? "$check $key", ...array_diff_key($args, ['title' => 1]), key: $key ?: $check);
        $finding->check    = $check;
        $finding->category = $category;
        return $finding;
    }

    /** @return array{time: int, check: string, severity: string, count: int, title: string} */
    private static function row(int $time, string $check, string $severity, string $title = 'from the snapshot', int $count = 0): array
    {
        return ['time' => $time, 'check' => $check, 'severity' => $severity, 'count' => $count, 'title' => $title];
    }

    /** @param array<string, mixed> $args */
    private static function model(array $findings, array $args = []): StateCheckModel
    {
        return new StateCheckModel($findings, ...($args + ['snapshot' => self::SNAPSHOT, 'now' => self::NOW, 'install_url' => 'https://example.org/aa/', 'recheckable' => ['CheckA', 'CheckB', 'CheckX', 'CheckSlow']]));
    }

    /** @return array<string, string> item id => state, per section */
    private static function states(StateCheckModel $model, string $section): array
    {
        return array_column(array_map(static fn(StateCheckItem $i) => [$i->id, $i->state], $model->sections[$section]), 1, 0);
    }

    private static function item(StateCheckModel $model, string $section, int $i = 0): StateCheckItem
    {
        return $model->sections[$section][$i];
    }

    public function test_anchor_is_stable_safe_and_differs_by_key(): void
    {
        $id = StateCheckModel::anchor('CheckPhpFatals', 'a.php:1');
        $this->assertMatchesRegularExpression('/^f-CheckPhpFatals-[0-9a-f]{8}$/', $id);
        $this->assertSame($id, StateCheckModel::anchor('CheckPhpFatals', 'a.php:1'));
        $this->assertNotSame($id, StateCheckModel::anchor('CheckPhpFatals', 'b.php:2'));
        $this->assertMatchesRegularExpression('/^f-unexpanded-alias-[0-9a-f]{8}$/', StateCheckModel::anchor('unexpanded-alias"<>', 'k'), 'only id-safe characters reach the anchor');
    }

    public function test_absent_at_the_snapshot_is_new_present_is_ongoing(): void
    {
        $model = self::model(
            [self::finding('CheckA', Finding::ERROR), self::finding('CheckB', Finding::WARNING)],
            ['history' => [self::row(self::SNAPSHOT - 86400 * 3, 'CheckB', Finding::WARNING), self::row(self::SNAPSHOT - 86400, 'CheckB', Finding::ERROR)]],
        );
        $this->assertSame([StateCheckModel::anchor('CheckA', 'CheckA') => StateCheckModel::NEW], self::states($model, StateCheckModel::NEW));
        $this->assertSame([StateCheckModel::anchor('CheckB', 'CheckB') => StateCheckModel::ONGOING], self::states($model, StateCheckModel::ONGOING));
        $this->assertSame(self::SNAPSHOT - 86400 * 3, self::item($model, StateCheckModel::ONGOING)->first_seen, 'without its own time, an ongoing finding is as old as the run of problem rows of its check');
        $this->assertSame(self::NOW, self::item($model, StateCheckModel::ONGOING)->last_seen, 'a state finding holds now');
    }

    public function test_a_finding_first_seen_after_the_snapshot_is_new_even_in_an_ongoing_check(): void
    {
        $model = self::model(
            [
                self::finding('CheckA', Finding::ERROR, 'old.php:1', ['since' => self::SNAPSHOT - 500, 'last' => self::NOW - 10, 'count' => 4]),
                self::finding('CheckA', Finding::ERROR, 'new.php:2', ['since' => self::SNAPSHOT + 500, 'last' => self::SNAPSHOT + 600]),
            ],
            ['history' => [self::row(self::SNAPSHOT - 300, 'CheckA', Finding::ERROR)]],
        );
        $this->assertSame([StateCheckModel::anchor('CheckA', 'new.php:2') => StateCheckModel::NEW], self::states($model, StateCheckModel::NEW));
        $ongoing = self::item($model, StateCheckModel::ONGOING);
        $this->assertSame([self::SNAPSHOT - 500, self::NOW - 10, 4], [$ongoing->first_seen, $ongoing->last_seen, $ongoing->count], 'its own times win over the check history');
    }

    public function test_a_check_that_passed_at_the_snapshot_after_a_problem_is_back_again(): void
    {
        $model = self::model(
            [self::finding('CheckA', Finding::WARNING), self::finding('CheckB', Finding::WARNING)],
            ['history' => [
                self::row(self::SNAPSHOT - 900, 'CheckA', Finding::WARNING),
                self::row(self::SNAPSHOT - 100, 'CheckA', Finding::OK),
                self::row(self::SNAPSHOT - 100, 'CheckB', Finding::OK),   // passed from the start: simply new
            ]],
        );
        $this->assertSame([
            StateCheckModel::anchor('CheckA', 'CheckA') => StateCheckModel::REGRESSED,
            StateCheckModel::anchor('CheckB', 'CheckB') => StateCheckModel::NEW,
        ], self::states($model, StateCheckModel::NEW));
    }

    public function test_no_snapshot_yet_makes_every_problem_new(): void
    {
        $model = self::model([self::finding('CheckA', Finding::ERROR, '', ['since' => 5])], ['snapshot' => 0]);
        $this->assertSame(StateCheckModel::NEW, self::item($model, StateCheckModel::NEW)->state);
        $this->assertSame([], $model->sections[StateCheckModel::ONGOING]);
    }

    public function test_back_again_after_its_resolution_ended(): void
    {
        $note = static fn(int $created, int $until): Note => new Note(['created' => $created, 'until' => $until, 'state' => Note::STATE_RESOLVED]);
        $history = [self::row(self::SNAPSHOT - 86400, 'CheckA', Finding::ERROR), self::row(self::SNAPSHOT - 86400, 'CheckB', Finding::ERROR)];
        $model = self::model([self::finding('CheckA', Finding::ERROR), self::finding('CheckB', Finding::ERROR)], ['history' => $history, 'notes' => [
            'finding:CheckA:CheckA' => $note(self::SNAPSHOT - 5000, self::SNAPSHOT + 100),   // still hidden at the snapshot, back since
            'finding:CheckB:CheckB' => $note(self::SNAPSHOT - 5000, self::SNAPSHOT - 4000),  // back before the snapshot: ongoing
        ]]);
        $this->assertSame([StateCheckModel::anchor('CheckA', 'CheckA') => StateCheckModel::REGRESSED], self::states($model, StateCheckModel::NEW));
        $this->assertSame([StateCheckModel::anchor('CheckB', 'CheckB') => StateCheckModel::ONGOING], self::states($model, StateCheckModel::ONGOING));
    }

    public function test_an_event_after_the_resolution_brings_it_back(): void
    {
        $note  = new Note(['created' => self::SNAPSHOT - 5000, 'until' => self::SNAPSHOT - 4000, 'state' => Note::STATE_RESOLVED]);
        $model = self::model(
            [
                self::finding('CheckA', Finding::ERROR, 'x', ['since' => self::SNAPSHOT - 9000, 'last' => self::SNAPSHOT + 50]),    // first occurrence after the resolution unknown: the newest
                self::finding('CheckB', Finding::ERROR, 'y', ['since' => self::SNAPSHOT - 3000, 'last' => self::SNAPSHOT + 50]),    // back at since, before the snapshot
            ],
            ['history' => [self::row(self::SNAPSHOT - 86400, 'CheckA', Finding::ERROR), self::row(self::SNAPSHOT - 86400, 'CheckB', Finding::ERROR)], 'notes' => ['finding:CheckA:x' => $note, 'finding:CheckB:y' => $note]],
        );
        $this->assertSame([StateCheckModel::anchor('CheckA', 'x') => StateCheckModel::REGRESSED], self::states($model, StateCheckModel::NEW));
        $this->assertSame([StateCheckModel::anchor('CheckB', 'y') => StateCheckModel::ONGOING], self::states($model, StateCheckModel::ONGOING));
    }

    public function test_resolved_findings_are_muted_with_their_reason(): void
    {
        $note  = new Note(['author' => 'u1', 'created' => self::SNAPSHOT, 'until' => (int) strtotime('2026-10-20 00:00:00'), 'text' => '<p>Known, <b>fixed</b> on Friday</p>', 'state' => Note::STATE_RESOLVED]);
        $model = self::model([], ['resolved' => [[self::finding('CheckA', Finding::ERROR), $note]], 'user_name' => static fn(string $uid): string => "name of $uid"]);
        $item  = self::item($model, StateCheckModel::MUTED);
        $this->assertSame(StateCheckModel::MUTED, $item->state);
        $this->assertSame('Marked resolved by name of u1 until 2026-10-20. Known, fixed on Friday', $item->reason, 'plain text, no markup');
        $this->assertSame([], $item->actions, 'unresolving is the note\'s own button');
        $this->assertSame($note, $item->note);
    }

    public function test_not_verified_lists_problems_and_puts_the_quiet_checks_on_a_line(): void
    {
        $model = self::model([], [
            'history'    => [self::row(self::SNAPSHOT - 86400 * 2, 'CheckX', Finding::WARNING, '3 SEO duplicates', 3), self::row(self::SNAPSHOT - 86400, 'CheckX', Finding::WARNING, '5 SEO duplicates', 5), self::row(self::SNAPSHOT - 86400 * 9, 'CheckSlow', Finding::ERROR, 'from when it was extended')],
            'unverified' => [
                'CheckX'    => ['name' => 'SEO duplicates', 'slow' => false, 'category' => 'db'],
                'CheckY'    => ['name' => 'Multivalue duplicates', 'slow' => false, 'category' => ''],
                'CheckZ'    => ['name' => 'Relations', 'slow' => false, 'category' => ''],
                'CheckSlow' => ['name' => 'Content column', 'slow' => true, 'category' => 'db'],
            ],
        ]);
        [$x] = $model->sections[StateCheckModel::NOT_VERIFIED];
        $this->assertCount(1, $model->sections[StateCheckModel::NOT_VERIFIED], 'only what was a problem gets a row');
        $this->assertSame([Finding::WARNING, '5 SEO duplicates', 5, self::SNAPSHOT - 86400 * 2, self::SNAPSHOT], [$x->severity, $x->title, $x->count, $x->first_seen, $x->last_seen]);
        $this->assertSame('As of ' . date('Y-m-d H:i', self::SNAPSHOT) . '.', $x->reason, 'why it is not current is said once, in the intro');
        $this->assertStringContainsString('as the daily snapshot of ' . date('Y-m-d H:i', self::SNAPSHOT) . ' found them', $model->sectionIntro(StateCheckModel::NOT_VERIFIED));
        $this->assertSame([StateCheckModel::ACTION_RECHECK], $x->actions);
        $this->assertSame(StateCheckModel::anchor('CheckX', 'CheckX'), $x->id);
        $this->assertSame([
            ['text' => '2 extended checks did not run now; they passed at the last daily snapshot, ' . date('Y-m-d H:i', self::SNAPSHOT) . ': Multivalue duplicates, Relations.', 'count' => 2],
            ['text' => '1 slow checks did not run now; they are never part of the daily snapshot: Content column.', 'count' => 1],
        ], $model->lines[StateCheckModel::NOT_VERIFIED]);
        $this->assertSame(4, $model->count(StateCheckModel::NOT_VERIFIED), 'the head counts the checks on the lines too');
    }

    public function test_near_duplicates_of_one_check_collapse_into_a_group_per_kind(): void
    {
        $form = static fn(string $where, int $since, int $last): Finding => self::finding('CheckSecurity', Finding::WARNING, "form:$where:$since", ['title' => "A form on $where sent an unsigned expression; it was refused", 'advice' => 'Sign the forms.', 'since' => $since, 'last' => $last, 'kind' => 'Unsigned expression refused', 'place' => $where]);
        $model = self::model([
            $form('', self::SNAPSHOT - 900, self::SNAPSHOT - 800),
            $form('https://example.org/aa/claude-test-page', self::SNAPSHOT - 500, self::SNAPSHOT - 100),
            $form('https://example.org/aa/claude-test-page', self::SNAPSHOT - 700, self::SNAPSHOT - 600),
            self::finding('CheckSecurity', Finding::WARNING, 'link:a', ['title' => 'A link to console.php sent an expression in als[A]', 'advice' => 'Sign the forms.', 'since' => self::SNAPSHOT - 50, 'kind' => 'Expression in a link shown as text']),
            self::finding('CheckSecurity', Finding::ERROR, 'off:x', ['title' => 'Protection "x" is switched off', 'advice' => 'Switch it on.', 'since' => self::SNAPSHOT - 40]),
            self::finding('CheckSession', Finding::INFO, 'pw', ['title' => 'Get_Sessionid called from 1.2.3.4 (password login)', 'advice' => 'A script.', 'since' => self::SNAPSHOT - 30, 'place' => '1.2.3.4']),
            self::finding('CheckSession', Finding::INFO, 'ses', ['title' => 'Get_Sessionid called from 1.2.3.4 (session login)', 'advice' => 'A site.', 'since' => self::SNAPSHOT - 30, 'place' => '1.2.3.4']),
            self::finding('CheckA', Finding::WARNING),
        ], ['history' => [self::row(self::SNAPSHOT - 86400, 'CheckSecurity', Finding::WARNING), self::row(self::SNAPSHOT - 86400, 'CheckSession', Finding::INFO), self::row(self::SNAPSHOT - 86400, 'CheckA', Finding::WARNING)], 'names' => ['CheckSecurity' => 'Security protections', 'CheckSession' => 'Get_Sessionid use'], 'recheckable' => ['CheckSecurity']]);

        $rows = $model->sections[StateCheckModel::ONGOING];
        $this->assertSame([
            'Security protections — 2 findings',
            'Unsigned expression refused — 3 findings, most often on claude-test-page',
            'CheckA ',
            'Get_Sessionid use — 2 findings, most often on 1.2.3.4',
        ], array_map(static fn(StateCheckItem $i) => $i->title, $rows), 'worst first; a kind names its group, the odd ones of a check go under its name; the own address is left out of a place');

        $forms = $rows[1];
        $this->assertSame('g' . substr(StateCheckModel::anchor('CheckSecurity', 'form::' . (self::SNAPSHOT - 900)), 1), $forms->id, 'the id of its oldest member');
        $this->assertCount(3, $forms->members);
        $this->assertSame([Finding::WARNING, StateCheckModel::ONGOING, self::SNAPSHOT - 900, self::SNAPSHOT - 100, 'Sign the forms.', []], [$forms->severity, $forms->state, $forms->first_seen, $forms->last_seen, $forms->advice, $forms->actions]);
        $this->assertSame('', $forms->chip(StateCheckModel::ONGOING), 'no chip: the section says it, the Since column has the date');
        $this->assertSame('After the fix, open a finding below and press its Recheck.', $forms->verify, 'the group row has no Recheck of its own');

        $rest = $rows[0];
        $this->assertSame([Finding::ERROR, 2, ''], [$rest->severity, count($rest->members), $rest->advice], 'the odd ones of a check: one group, worst severity, no shared advice');
    }

    public function test_a_place_that_is_an_id_is_no_place(): void
    {
        $member = static fn(string $key, string $place): Finding => self::finding('CheckObjects', Finding::WARNING, $key, ['kind' => 'Write to an object of another module refused', 'place' => $place, 'count' => 3]);
        $model  = self::model([$member('a', '9014a323aa77acf3818a49681c8eafec'), $member('b', '9014a323aa77acf3818a49681c8eafec')]);
        $this->assertSame('Write to an object of another module refused — 2 findings', self::item($model, StateCheckModel::NEW)->title);
    }

    public function test_the_summary_says_which_sections_are_empty(): void
    {
        $model = self::model([self::finding('CheckA', Finding::OK)]);
        $this->assertSame('Nothing new, nothing ongoing; every check verified now; nothing muted; 1 checks passed.', $model->summary());
        $this->assertSame('Ran now and found nothing.', $model->sectionIntro(StateCheckModel::PASSED));
        $this->assertStringContainsString(date('Y-m-d H:i', self::SNAPSHOT), $model->sectionIntro(StateCheckModel::NEW));
    }

    public function test_a_source_that_did_not_verify_now_files_not_verified(): void
    {
        $finding = new Finding(Finding::WARNING, 'mojibake on /x', key: 'mojibake:/x', checked: self::SNAPSHOT - 10, recheckable: false, verify: 'next nightly run');
        $finding->check = 'CheckNightly';
        $item = self::item(self::model([$finding]), StateCheckModel::NOT_VERIFIED);
        $this->assertSame([StateCheckModel::NOT_VERIFIED, StateCheckModel::SOURCE_NIGHTLY, self::SNAPSHOT - 10, 'next nightly run'], [$item->state, $item->source, $item->last_seen, $item->verify]);
        $this->assertNotContains(StateCheckModel::ACTION_RECHECK, $item->actions);
    }

    public function test_a_nightly_finding_is_new_when_the_latest_run_saw_it_first(): void
    {
        $nightly = static function (string $type, int $since, int $last): Finding {   // one finding per type of the nightly test
            $finding        = new Finding(Finding::WARNING, $type, since: $since, key: $type, last: $last, recheckable: false, verify: 'next nightly run');
            $finding->check = 'CheckNightly';
            return $finding;
        };
        // the daily snapshot says nothing about it: the comparison point is the previous nightly run
        $model = self::model([$nightly('mojibake', self::NOW - 100, self::NOW - 100), $nightly('php-error', self::NOW - 86400, self::NOW - 100), $nightly('soft-404', self::NOW - 86400, self::NOW - 100)]);
        $this->assertSame([[], []], array_map(static fn(StateCheckItem $i) => $i->members, $model->sections[StateCheckModel::ONGOING]), 'two types of the nightly test are two rows, not a group');
        $this->assertSame([StateCheckModel::anchor('CheckNightly', 'mojibake') => StateCheckModel::NEW], self::states($model, StateCheckModel::NEW));
        $this->assertSame([StateCheckModel::anchor('CheckNightly', 'php-error') => StateCheckModel::ONGOING, StateCheckModel::anchor('CheckNightly', 'soft-404') => StateCheckModel::ONGOING], self::states($model, StateCheckModel::ONGOING));
        $item = self::item($model, StateCheckModel::NEW);
        $this->assertSame([StateCheckModel::SOURCE_NIGHTLY, 'next nightly run', [StateCheckModel::ACTION_RESOLVE]], [$item->source, $item->verify, $item->actions], 'no Recheck: the page cannot run the nightly test');
    }

    public function test_a_known_issue_of_the_source_is_muted(): void
    {
        $finding = new Finding(Finding::WARNING, 'soft 404 on /y', key: 'soft-404:/y', muted: 'known: the page is a stub');
        $finding->check = 'CheckNightly';
        $item = self::item(self::model([$finding]), StateCheckModel::MUTED);
        $this->assertSame('known: the page is a stub', $item->reason);
    }

    public function test_actions_appear_only_when_they_can_be_done(): void
    {
        $model = self::model([
            self::finding('CheckA', Finding::ERROR, 'r', ['repairable' => true]),
            self::finding('CheckZ', Finding::WARNING),                  // not a check the page can run again
            self::finding('CheckB', Finding::OK),
            self::finding('CheckEnv', Finding::INFO, '', [], 'environment'),
        ]);
        $this->assertSame([StateCheckModel::ACTION_REPAIR, StateCheckModel::ACTION_RESOLVE, StateCheckModel::ACTION_RECHECK], self::item($model, StateCheckModel::NEW, 0)->actions);
        $this->assertSame([StateCheckModel::ACTION_RESOLVE], self::item($model, StateCheckModel::NEW, 1)->actions);
        $this->assertSame('', self::item($model, StateCheckModel::NEW, 1)->verify);
        $this->assertStringContainsString('Recheck', self::item($model, StateCheckModel::NEW, 0)->verify);
        $this->assertSame([], self::item($model, StateCheckModel::PASSED)->actions);
        $this->assertSame([], self::item($model, StateCheckModel::ENVIRONMENT)->actions, 'the environment is information');
    }

    public function test_a_problem_of_the_environment_category_is_not_hidden_among_the_versions(): void
    {
        $model = self::model([self::finding('CheckEnv', Finding::INFO, '', [], 'environment'), self::finding('CheckSiteFiles', Finding::ERROR, '', [], 'environment')]);
        $this->assertSame(['CheckSiteFiles'], array_map(static fn(StateCheckItem $i) => $i->check, $model->sections[StateCheckModel::NEW]));
        $this->assertSame(['CheckEnv'], array_map(static fn(StateCheckItem $i) => $i->check, $model->sections[StateCheckModel::ENVIRONMENT]));
        $this->assertContains(StateCheckModel::ACTION_RESOLVE, self::item($model, StateCheckModel::NEW)->actions);
    }

    public function test_sections_are_sorted_worst_first_and_a_finding_run_twice_shows_once(): void
    {
        $model = self::model([
            self::finding('CheckA', Finding::INFO),
            self::finding('CheckB', Finding::WARNING, '', ['count' => 2]),
            self::finding('CheckB', Finding::WARNING, '', ['count' => 2]),
            self::finding('CheckC', Finding::ERROR),
            self::finding('CheckD', Finding::WARNING, '', ['count' => 9]),
        ]);
        $this->assertSame(['CheckC', 'CheckD', 'CheckB', 'CheckA'], array_map(static fn(StateCheckItem $i) => $i->check, $model->sections[StateCheckModel::NEW]));
    }

    public function test_where_links_become_absolute(): void
    {
        $model = self::model([self::finding('CheckA', Finding::ERROR, '', ['links' => ['Log rows' => '/aa/admin/aa_log.php?x=1', 'Open page' => 'https://other.org/p', 'Cron' => 'aa_cron.php']])]);
        $this->assertSame([
            ['label' => 'Log rows', 'url' => 'https://example.org/aa/admin/aa_log.php?x=1'],
            ['label' => 'Open page', 'url' => 'https://other.org/p'],
            ['label' => 'Cron', 'url' => 'https://example.org/aa/admin/aa_cron.php'],
        ], self::item($model, StateCheckModel::NEW)->where);
    }

    public function test_json_has_the_shape_the_nightly_mail_reads(): void
    {
        $model = self::model(
            [self::finding('CheckA', Finding::ERROR, 'a.php:1', ['since' => self::SNAPSHOT + 1, 'count' => 4, 'advice' => 'Open the file']), self::finding('CheckB', Finding::OK)],
            ['lang' => 'cz', 'nightly' => ['run' => '20261014-0330', 'time' => self::SNAPSHOT + 7]],
        );
        $json = json_decode($model->json(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['install', 'page', 'generated', 'lang', 'snapshot', 'nightly', 'summary', 'labels', 'sections'], array_keys($json));
        $this->assertSame(['last_seen' => 'Last', 'since' => 'Since', 'count' => 'Count'], $json['labels'], 'the heads of the time columns, as on the page');
        $this->assertSame(['https://example.org/aa', 'https://example.org/aa/admin/aa_statecheck.php', self::NOW, 'cz', self::SNAPSHOT], [$json['install'], $json['page'], $json['generated'], $json['lang'], $json['snapshot']]);
        $this->assertSame(['run' => '20261014-0330', 'time' => self::SNAPSHOT + 7], $json['nightly']);
        $this->assertSame([StateCheckModel::NEW, StateCheckModel::PASSED], array_column($json['sections'], 'id'), 'only sections with content, in order');
        $this->assertSame(['id', 'title', 'intro', 'count', 'findings', 'severities', 'items', 'lines'], array_keys($json['sections'][0]));

        $item = $json['sections'][0]['items'][0];
        $this->assertSame(['id', 'url', 'state', 'state_label', 'severity', 'severity_label', 'source', 'check', 'category', 'title', 'advice', 'count', 'first_seen', 'last_seen', 'last_seen_label', 'since_label', 'where', 'verify', 'reason', 'member_count', 'members'], array_keys($item));
        $this->assertSame(['', 0, []], [$item['state_label'], $item['member_count'], $item['members']], 'New in the new section needs no chip');
        $this->assertSame($model->count(StateCheckModel::NEW), $json['sections'][0]['count'], 'the number in the head of the section on the page');
        $this->assertSame('https://example.org/aa/admin/aa_statecheck.php#' . $item['id'], $item['url']);
        $this->assertSame([StateCheckModel::NEW, Finding::ERROR, 'Problem', 'statecheck', 'CheckA', 'errors', 4, self::SNAPSHOT + 1], [$item['state'], $item['severity'], $item['severity_label'], $item['source'], $item['check'], $item['category'], $item['count'], $item['first_seen']]);
        $this->assertSame('1 new, nothing ongoing (1 problems, 0 warnings, 0 recommendations); every check verified now; nothing muted; 1 checks passed.', $json['summary']);
    }

    public function test_json_carries_a_group_with_its_members_in_the_item_shape(): void
    {
        $model = self::model([self::finding('CheckA', Finding::WARNING, 'a', ['title' => 'CKEditor 4 loaded on x']), self::finding('CheckA', Finding::WARNING, 'b', ['title' => 'CKEditor 4 loaded on y'])], ['names' => ['CheckA' => 'CKEditor 4 use']]);
        $group = json_decode($model->json(), true)['sections'][0]['items'][0];
        $this->assertSame('CKEditor 4 use — 2 findings', $group['title']);
        $this->assertSame(2, $group['member_count']);
        $this->assertCount(2, $group['members']);
        $this->assertSame(['', ''], [$group['state_label'], $group['members'][0]['state_label']], 'neither says New in the new section');
        $this->assertSame('https://example.org/aa/admin/aa_statecheck.php#' . $group['id'], $group['url']);
        $this->assertSame(array_keys($group), array_keys($group['members'][0]), 'a member is a normal item');
        $this->assertSame('https://example.org/aa/admin/aa_statecheck.php#' . StateCheckModel::anchor('CheckA', 'a'), $group['members'][0]['url']);
    }

    public function test_group_headline_names_the_kind_the_count_and_the_place_that_stands_out(): void
    {
        $this->assertSame('Unsigned expression refused — 4 findings, most often on claude-test-page', StateCheckModel::headline('Unsigned expression refused', ['claude-test-page', '', 'claude-test-page', 'x'], [1, 1, 1, 1]));
        $this->assertSame('CKEditor 4 use — 2 findings, most often on zz test site', StateCheckModel::headline('CKEditor 4 use', ['zz test site', 'Sandbox'], [5, 1]), 'a member weighs as many as it occurred');
        $this->assertSame('Links — 2 findings', StateCheckModel::headline('Links', ['a', 'b'], [1, 1]), 'nothing stands out');
        $this->assertSame('Links — 3 findings', StateCheckModel::headline('Links', ['a', '', ''], [1, 0, 0]), 'one occurrence is not "most often"');
    }

    public function test_time_reads_like_an_age(): void
    {
        $now  = 1_800_000_000;
        $ages = [0 => '', $now - 20 => 'just now', $now - 300 => '5 min', $now - 3 * 3600 => '3 h', $now - 5 * 86400 => '5 d', $now - 21 * 86400 => '3 w', $now - 120 * 86400 => '4 mo', $now - 800 * 86400 => '2 y'];
        foreach ($ages as $time => $label) {
            $this->assertSame($label, StateCheckModel::ago($time, $now), (string) $time);
        }

        $model = self::model([
            self::finding('CheckA', Finding::ERROR),                                                                   // cron is dead: holds now
            self::finding('CheckB', Finding::WARNING, 'x', ['since' => self::NOW - 2 * 86400, 'last' => self::NOW - 3 * 3600, 'count' => 4]),
        ]);
        $json = array_column(json_decode($model->json(), true)['sections'][0]['items'], null, 'check');
        $this->assertSame(['now', ''], [$json['CheckA']['last_seen_label'], $json['CheckA']['since_label']], 'a state that holds now has no time of its own');
        $this->assertSame(self::NOW, $json['CheckA']['last_seen'], 'the absolute times stay');
        $this->assertSame(['3 h', '2 d', self::NOW - 3 * 3600], [$json['CheckB']['last_seen_label'], $json['CheckB']['since_label'], $json['CheckB']['last_seen']]);
    }

    public function test_a_group_keeps_its_id_in_another_language_and_section(): void
    {
        $member = static fn(string $key, int $since, string $advice): Finding => self::finding('CheckA', Finding::WARNING, $key, ['title' => "Form $key refused", 'advice' => $advice, 'since' => $since]);
        $ongoing = self::model([$member('b', self::SNAPSHOT - 500, 'Sign the forms.'), $member('a', self::SNAPSHOT - 900, 'Sign the forms.')], ['history' => [self::row(self::SNAPSHOT - 86400, 'CheckA', Finding::WARNING)]]);
        $new     = self::model([$member('a', self::SNAPSHOT - 900, 'Podepište formuláře.'), $member('b', self::SNAPSHOT - 500, 'Podepište formuláře.'), $member('c', self::SNAPSHOT + 10, 'Podepište formuláře.')]);

        $id = 'g' . substr(StateCheckModel::anchor('CheckA', 'a'), 1);
        $this->assertSame($id, self::item($ongoing, StateCheckModel::ONGOING)->id);
        $this->assertSame($id, self::item($new, StateCheckModel::NEW)->id, 'a newer member joining does not change it');
    }

    public function test_recheck_answers_about_its_own_finding(): void
    {
        $keyless  = self::finding('CheckX', Finding::WARNING);      // CheckRunner keys it by the class name
        $other    = self::finding('CheckX', Finding::WARNING, 'a.php:1');
        $fine     = self::finding('CheckY', Finding::OK);
        $this->assertNull(StateCheckModel::stillThere([$other, $fine], 'CheckX', 'CheckX'), 'the keyless finding is gone - another one of its check is not it');
        $this->assertSame($keyless, StateCheckModel::stillThere([$other, $keyless], 'CheckX', 'CheckX'));
        $this->assertSame($other, StateCheckModel::stillThere([$fine, $other], 'CheckX', StateCheckModel::ANY_KEY), 'a row known only from the snapshot asks for any problem');
        $this->assertNull(StateCheckModel::stillThere([$fine], 'CheckY', StateCheckModel::ANY_KEY), 'a passing finding is no problem');

        $model = self::model([], ['history' => [self::row(self::SNAPSHOT - 10, 'CheckX', Finding::WARNING)], 'unverified' => ['CheckX' => ['name' => 'X', 'slow' => false, 'category' => 'db']]]);
        $this->assertSame(StateCheckModel::ANY_KEY, self::item($model, StateCheckModel::NOT_VERIFIED)->recheckKey());
        $this->assertSame('a.php:1', self::item(self::model([$other]), StateCheckModel::NEW)->recheckKey());
    }

    public function test_no_problems_only_when_nothing_is_new_ongoing_or_not_verified(): void
    {
        $this->assertFalse(self::model([self::finding('CheckA', Finding::OK)])->hasProblems());
        $unverified = self::model([self::finding('CheckA', Finding::OK)], ['history' => [self::row(self::SNAPSHOT - 10, 'CheckX', Finding::ERROR)], 'unverified' => ['CheckX' => ['name' => 'X', 'slow' => false, 'category' => 'db']]]);
        $this->assertTrue($unverified->hasProblems(), 'a problem when last known is not "no problems"');
    }

    public function test_not_verified_says_once_why_and_each_row_only_its_date(): void
    {
        $nightly = static function (string $type, string $severity, int $checked): Finding {
            $finding        = new Finding($severity, $type, key: $type, last: $checked, checked: $checked, recheckable: false);
            $finding->check = 'CheckNightly';
            return $finding;
        };
        $ran      = self::NOW - 3 * 86400;
        $findings = [$nightly('mojibake', Finding::WARNING, $ran), $nightly('overflow', Finding::OK, $ran)];
        $night    = ['run' => 'r1', 'time' => $ran];

        $stale = self::model($findings, ['nightly' => $night, 'nightly_full' => $ran]);
        $this->assertStringContainsString('its latest full run is from ' . date('Y-m-d H:i', $ran) . ', older than 36 h', $stale->sectionIntro(StateCheckModel::NOT_VERIFIED));
        $this->assertSame('As of ' . date('Y-m-d H:i', $ran) . '.', self::item($stale, StateCheckModel::NOT_VERIFIED)->reason);
        $this->assertSame('1 checks of the nightly test found nothing when they last ran: ' . \AA\Optimize\CheckNightly::describe('overflow')[0] . '.', $stale->lines[StateCheckModel::NOT_VERIFIED][0]['text']);

        $current = self::model($findings, ['nightly' => $night, 'nightly_full' => self::NOW - 3600]);
        $this->assertStringContainsString('the types missing from its latest full run', $current->sectionIntro(StateCheckModel::NOT_VERIFIED));
        $this->assertStringNotContainsString('Nightly test', self::model([])->sectionIntro(StateCheckModel::NOT_VERIFIED), 'no nightly test, no word about it');
    }

    public function test_chips_appear_only_where_they_add_something(): void
    {
        $model = self::model(
            [self::finding('CheckA', Finding::ERROR), self::finding('CheckB', Finding::WARNING), self::finding('CheckR', Finding::WARNING), self::finding('CheckEnv', Finding::INFO, '', [], 'environment'), self::finding('CheckC', Finding::OK)],
            ['history' => [self::row(self::SNAPSHOT - 86400, 'CheckB', Finding::WARNING), self::row(self::SNAPSHOT - 900, 'CheckR', Finding::WARNING), self::row(self::SNAPSHOT - 100, 'CheckR', Finding::OK)]],
        );
        $chips = [];
        foreach (json_decode($model->json(), true)['sections'] as $section) {
            $chips[$section['id']] = array_column($section['items'], 'state_label', 'check');
        }
        $this->assertSame([
            StateCheckModel::NEW         => ['CheckA' => '', 'CheckR' => 'Back again'],
            StateCheckModel::ONGOING     => ['CheckB' => ''],
            StateCheckModel::PASSED      => ['CheckC' => ''],
            StateCheckModel::ENVIRONMENT => ['CheckEnv' => ''],
        ], $chips, 'a chip only where it differs from its section: "New" in new says nothing');
    }

    public function test_counts_are_rows_everywhere_and_findings_on_the_side(): void
    {
        $member = static fn(string $key): Finding => self::finding('CheckA', Finding::WARNING, $key, ['kind' => 'Form refused']);
        $model  = self::model([$member('a'), $member('b'), $member('c'), self::finding('CheckB', Finding::ERROR)]);
        $this->assertSame([2, 4], [$model->count(StateCheckModel::NEW), $model->findingsCount(StateCheckModel::NEW)]);
        $this->assertStringStartsWith('2 new, nothing ongoing (1 problems, 1 warnings, 0 recommendations)', $model->summary());
        $section = json_decode($model->json(), true)['sections'][0];
        $this->assertSame([2, 4, ['error' => 1, 'warning' => 1, 'info' => 0]], [$section['count'], $section['findings'], $section['severities']], 'the head of the section shows count, as the summary does');
    }

    public function test_json_survives_a_title_cut_inside_a_character(): void
    {
        $model = self::model([self::finding('CheckA', Finding::WARNING, '', ['title' => "half \xC5"])]);
        $this->assertSame("half \u{FFFD}", json_decode($model->json(), true)['sections'][0]['items'][0]['title']);
    }

    public function test_history_keeps_the_check_rows_only(): void
    {
        $history = StateCheckModel::parseHistory([
            ['time' => '100', 'selector' => 'summary', 'params' => 'Scs:3:31:6:49'],
            ['time' => '100', 'selector' => 'CheckPhpFatals', 'params' => 'Sc:error:4:PHP fatal error in doc/a.php#:50'],
            ['time' => '200', 'selector' => 'CheckOdd', 'params' => 'Sc:weird:1:not a severity'],
            ['time' => '200', 'selector' => 'CheckCron', 'params' => 'Sc:ok:0:'],
        ]);
        $this->assertSame([
            ['time' => 100, 'check' => 'CheckPhpFatals', 'severity' => Finding::ERROR, 'count' => 4, 'title' => 'PHP fatal error in doc/a.php:50'],
            ['time' => 200, 'check' => 'CheckCron', 'severity' => Finding::OK, 'count' => 0, 'title' => ''],
        ], $history);
    }
}
