<?php

declare(strict_types=1);

/**
 * TransferText: the progress page names a finding by its title, what it means and what to do, a slice by
 * the name of the slice its items go into and an item by its id - one note per code, counted - and gets no
 * raw finding: no ref unmasked, no package path, not even the run's own form of it.
 */

namespace Tests\Unit\Central;

use AA\Central\InstallRun;
use AA\Central\SourceFailed;
use AA\Central\TransferText;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../include/mgettext.php';

final class TransferTextTest extends TestCase
{
    private const NEWS   = 'aa11bb22cc33dd44ee55ff6677889900';
    private const EVENTS = 'bb11bb22cc33dd44ee55ff6677889900';
    private const ITEM   = '0123456789abcdef0123456789abcdef';

    public static function setUpBeforeClass(): void
    {
        // InstallRun loads AA_Object (include/statestore.php), which needs what include/config.php gives
        foreach (['AA_INSTAL_URL' => 'http://localhost/aa/', 'AA_INSTAL_PATH' => '/aa/', 'IMG_UPLOAD_PATH' => sys_get_temp_dir() . '/', 'AA_BASE_PATH' => dirname(__DIR__, 3) . '/'] as $k => $v) {
            if (!defined($k)) {
                define($k, $v);
            }
        }
    }

    private static function text(): TransferText
    {
        return new TransferText([self::NEWS => 'Články', strtoupper(self::EVENTS) => 'Akce']);
    }

    /** a TransferProgress::of() of these parts @param array<string, mixed> $parts */
    private static function state(array $parts): array
    {
        return $parts + ['status' => '', 'state' => InstallRun::ITEMS, 'running' => true, 'waiting' => false, 'stuck' => false, 'nextAt' => 0, 'failures' => 0,
            'moved' => 1_900_000_000, 'done' => 5, 'total' => 9, 'written' => [self::NEWS => 5], 'warnings' => [], 'error' => null, 'findings' => [],
            'afterClose' => false, 'mayContinue' => false, 'mayEnd' => true, 'login' => false];
    }

    /** @param array<string, mixed> $parts @return array<string, mixed> */
    private static function page(array $parts): array
    {
        return self::text()->page(self::state($parts));
    }

    /** the error of the run in words, its place as the run keeps it (InstallRun::where()) */
    private static function error(string $code, string $path): string
    {
        return self::page(['state' => InstallRun::ERROR, 'error' => ['code' => $code, 'where' => InstallRun::where($path)]])['error'];
    }

    public function test_the_page_gets_the_facts_of_the_run_but_no_raw_finding(): void
    {
        $item = 'slice-module[' . self::NEWS . ']/item[' . self::ITEM . ']';
        $page = self::page([
            'login'    => true,
            'warnings' => [self::NEWS => ['item-field' => 2]],
            'findings' => [['level' => 'E', 'code' => 'charset', 'path' => "$item/headline........[0]", 'ref' => 'x']],
            'error'    => ['code' => 'CHARSET', 'where' => InstallRun::where("$item/headline........[0]")],
        ]);

        $keys = array_keys($page);
        sort($keys);
        self::assertSame(['done', 'error', 'failures', 'found', 'login', 'mayContinue', 'mayEnd', 'moved', 'nextAt', 'notes', 'running', 'state', 'status', 'stuck', 'total', 'waiting', 'written'], $keys);
        self::assertSame([InstallRun::ITEMS, true, 5, 9, [self::NEWS => 5], true], [$page['state'], $page['running'], $page['done'], $page['total'], $page['written'], $page['login']]);
        $json = (string) json_encode($page);
        self::assertStringNotContainsString('slice-module[', $json);
        self::assertStringNotContainsString('slice-module:', $json);
    }

    public function test_a_warning_of_two_slices_is_one_note_by_the_names_of_the_slices(): void
    {
        $notes = self::page(['warnings' => [self::NEWS => ['item-field' => 89], self::EVENTS => ['item-field' => 1]]])['notes'];

        self::assertCount(1, $notes);
        self::assertSame(['key' => 'W item-field', 'level' => 'W', 'title' => 'Warnings (W): Item values left out', 'count' => 90, 'places' => [['Články', 89], ['Akce', 1]], 'lines' => [
            'Items have values of a field the slice does not have - they are left out.',
            'What to do: Usually nothing to do.',
        ]], $notes[0]);
    }

    /** a step that died leaves its warning to the run, not to a slice */
    public function test_a_warning_of_no_slice_counts_without_a_place(): void
    {
        $notes = self::page(['warnings' => ['' => ['AFTER_DEAD_STEP' => 1], self::NEWS => ['AFTER_DEAD_STEP' => 2]]])['notes'];

        self::assertSame([['key' => 'W AFTER_DEAD_STEP', 'level' => 'W', 'title' => 'Warnings (W): AFTER_DEAD_STEP', 'count' => 3, 'places' => [['Články', 2]], 'lines' => []]], $notes);
    }

    public function test_an_unknown_code_and_slice_are_shown_as_they_are(): void
    {
        $other = 'cc11bb22cc33dd44ee55ff6677889900';
        $notes = self::page(['warnings' => [$other => ['brand-new-code' => 2]]])['notes'];

        self::assertSame([['key' => 'W brand-new-code', 'level' => 'W', 'title' => 'Warnings (W): brand-new-code', 'count' => 2, 'places' => [[$other, 2]], 'lines' => []]], $notes);
    }

    public function test_a_slice_named_by_a_number_is_named(): void
    {
        $notes = (new TransferText([self::NEWS => '2024']))->page(self::state(['warnings' => [self::NEWS => ['item-field' => 3]]]))['notes'];

        self::assertSame([['2024', 3]], $notes[0]['places']);
    }

    public function test_the_kept_findings_name_the_item_and_come_by_their_danger(): void
    {
        $item  = 'slice-module[' . self::NEWS . ']/item[' . self::ITEM . ']';
        $page  = self::page([
            'warnings' => [self::NEWS => ['item-field' => 3]],
            'findings' => [
                ['level' => 'W', 'code' => 'item-field', 'path' => $item, 'ref' => 'x'],
                ['level' => 'I', 'code' => 'lang-file', 'path' => 'slice-module[' . self::NEWS . ']', 'ref' => ''],
                ['level' => 'E', 'code' => 'charset', 'path' => "$item/headline........[0]", 'ref' => 'Ahoj 😀'],
                ['level' => 'E', 'code' => 'charset', 'path' => "$item/headline........[0]", 'ref' => 'Ahoj 😀'],
            ],
        ]);

        self::assertSame(['E', 'I'], array_column($page['found'], 'level'), 'a warning is counted in the run already');
        self::assertSame(['Errors (E): A text this AA cannot store', 2], [$page['found'][0]['title'], $page['found'][0]['count']]);
        self::assertSame([['Články › item ' . self::ITEM . ' › headline........: Ahoj 😀', 2]], $page['found'][0]['places']);
        self::assertSame(3, $page['notes'][0]['count']);
    }

    public function test_the_findings_a_batch_only_counted_are_in_the_count(): void
    {
        $item  = 'slice-module[' . self::NEWS . ']/item[' . self::ITEM . ']';
        $found = self::page(['findings' => [
            ['level' => 'I', 'code' => 'reader-accounts', 'path' => $item, 'ref' => ''],
            ['level' => 'I', 'code' => 'reader-accounts', 'path' => '', 'ref' => '+449 more (reader-accounts)'],
        ]])['found'];

        self::assertSame(450, $found[0]['count']);
        self::assertSame([['Články › item ' . self::ITEM, 1]], $found[0]['places'], 'the rest has no place');
    }

    public function test_a_secret_never_shows_its_value(): void
    {
        $page = self::page(['findings' => [
            ['level' => 'E', 'code' => 'too-long', 'path' => 'slice-module[' . self::NEWS . ']/setting-values/_pwd[0]', 'ref' => 'hunter2'],
            ['level' => 'S', 'code' => 'credentials', 'path' => 'slice-module[' . self::NEWS . ']/view[3]/odd', 'ref' => '{credentials:x:hunter3}'],
        ]]);

        $json = (string) json_encode($page, JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('hunter2', $json);
        self::assertStringNotContainsString('hunter3', $json);
    }

    public function test_a_cache_not_cleared_after_the_end_is_a_note(): void
    {
        $page = self::page(['state' => InstallRun::DONE, 'afterClose' => true, 'warnings' => [self::NEWS => ['item-field' => 1]]]);

        self::assertSame(['W item-field', 'after-close'], array_column($page['notes'], 'key'));
        self::assertSame(['key' => 'after-close', 'level' => 'W', 'title' => 'Warnings (W): A cache was not cleared after the transfer', 'count' => 1, 'places' => [],
            'lines' => ['Pages of the new modules may show old content until their cache expires; the details are in the PHP error log.']], $page['notes'][1]);
        self::assertSame([], self::page(['state' => InstallRun::DONE])['notes']);
    }

    public function test_the_error_of_the_run_in_words_at_its_place(): void
    {
        $item = 'slice-module[' . self::NEWS . ']/item[' . self::ITEM . ']';

        self::assertSame('The source AA refused the login (SOURCE_LOGIN)', self::error(SourceFailed::LOGIN, 'source'));
        self::assertSame('The source gives no items of the slice (SOURCE_DENIED) - Akce', self::error(InstallRun::SOURCE_DENIED, 'slice-module[' . self::EVENTS . ']'));
        self::assertSame('An item too big to transfer (ITEM_TOO_BIG) - Články › item ' . self::ITEM, self::error('ITEM_TOO_BIG', $item));
        self::assertSame('The items are here already (ITEM_ID_IN_USE) - Akce › item ' . self::ITEM, self::error('ITEM_ID_IN_USE', 'slice-module[' . self::EVENTS . ']/item[' . strtoupper(self::ITEM) . ']'));
        self::assertSame('A text this AA cannot store (CHARSET) - Články › item ' . self::ITEM . ' › headline........', self::error('CHARSET', "$item/headline........[0]"));
        self::assertStringStartsWith('Writing the items failed', self::error(InstallRun::WRITE_FAILED, 'batch'));
        self::assertStringEndsWith('the PHP error log of this AA (DB) - Články', self::error('DB', 'slice-module[' . self::NEWS . ']'));
        self::assertSame('UNKNOWN_CODE', self::error('UNKNOWN_CODE', 'batch'));
        self::assertSame('', self::page([])['error']);
    }

    /** the codes of the queue's steps tell the admin what happened, not only their name */
    public function test_every_code_of_a_step_has_words_and_no_place_for_the_run_s_own_places(): void
    {
        foreach ([SourceFailed::UNREACHABLE, SourceFailed::TIMEOUT, SourceFailed::FAILED, InstallRun::COLLECT_FAILED, InstallRun::DENIED, InstallRun::WRITE_FAILED, 'NO_PROGRESS', 'STEP_DIED', 'FINAL_CHECK', 'INTERNAL', 'GONE', 'ENGINE'] as $code) {
            foreach (['source', 'batch', 'user', ''] as $where) {
                $text = self::error($code, $where);
                self::assertMatchesRegularExpression('/^\S.* \(' . $code . '\)$/u', $text, "$code at '$where'");
                self::assertGreaterThan(strlen($code) + 12, strlen($text), "$code in words");
            }
        }
    }
}
