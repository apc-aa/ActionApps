<?php

declare(strict_types=1);

/**
 * TransferText: the progress page names a finding by its title, what it means and what to do, a slice by
 * the name of the slice its items go into and an item by its id - one note per code, counted - and gets no
 * raw finding: no ref unmasked, no package path.
 */

namespace Tests\Unit\Central;

use AA\Central\SourceFailed;
use AA\Central\TransferText;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../include/mgettext.php';

final class TransferTextTest extends TestCase
{
    private const NEWS   = 'aa11bb22cc33dd44ee55ff6677889900';
    private const EVENTS = 'bb11bb22cc33dd44ee55ff6677889900';
    private const ITEM   = '0123456789abcdef0123456789abcdef';

    private static function text(): TransferText
    {
        return new TransferText([self::NEWS => 'Články', strtoupper(self::EVENTS) => 'Akce']);
    }

    /** a TransferProgress::state() of these parts @param array<string, mixed> $parts */
    private static function state(array $parts): array
    {
        return $parts + ['status' => 'written', 'state' => 'items', 'position' => ['slice' => self::NEWS, 'cursor' => ''], 'written' => [self::NEWS => 5],
            'warnings' => [], 'error' => null, 'findings' => [], 'cleanup' => []];
    }

    /** @param array<string, mixed> $parts @return array<string, mixed> */
    private static function page(array $parts): array
    {
        return self::text()->page(self::state($parts));
    }

    public function test_the_page_gets_the_position_and_the_counts_but_no_raw_finding(): void
    {
        $page = self::page([
            'warnings' => [self::NEWS => ['item-field' => 2]],
            'findings' => [['level' => 'E', 'code' => 'charset', 'path' => 'slice-module[' . self::NEWS . ']/item[' . self::ITEM . ']/headline........[0]', 'ref' => 'x']],
        ]);

        self::assertSame(['status', 'state', 'position', 'written', 'login', 'cleanupFailed', 'notes', 'found', 'error'], array_keys($page));
        self::assertSame(['slice' => self::NEWS, 'cursor' => ''], $page['position']);
        self::assertSame([self::NEWS => 5], $page['written']);
        self::assertStringNotContainsString('slice-module[', (string) json_encode($page));
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

    public function test_the_findings_of_the_request_name_the_item_and_come_by_their_danger(): void
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

        self::assertSame(['E', 'I'], array_column($page['found'], 'level'), 'a warning of the request is counted in the run already');
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

    public function test_a_cache_not_cleared_after_the_finish_is_a_note(): void
    {
        $page = self::page(['state' => 'done', 'cleanup' => [['pagecache[' . self::NEWS . ']', 'RuntimeException']]]);

        self::assertTrue($page['cleanupFailed']);
        self::assertSame(['Warnings (W): A cache was not cleared after the transfer', 1], [$page['notes'][0]['title'], $page['notes'][0]['count']]);
        self::assertSame('pagecache[' . self::NEWS . '] (RuntimeException)', $page['notes'][0]['lines'][0]);
    }

    public function test_the_error_of_the_run_in_words(): void
    {
        $error = static fn(string $code, string $path): string => self::page(['state' => 'error', 'error' => ['code' => $code, 'path' => $path]])['error'];

        self::assertSame('The source AA refused the login (SOURCE_LOGIN)', $error(SourceFailed::LOGIN, 'source'));
        self::assertSame('The source gives no items of the slice (SOURCE_DENIED) - Akce', $error('SOURCE_DENIED', 'slice-module[' . self::EVENTS . ']'));
        self::assertSame('An item too big to transfer (ITEM_TOO_BIG) - Články › item ' . self::ITEM, $error('ITEM_TOO_BIG', 'slice-module[' . self::NEWS . ']/item[' . self::ITEM . ']'));
        self::assertSame('The items are here already (ITEM_ID_IN_USE) - Akce › item ' . self::ITEM, $error('ITEM_ID_IN_USE', 'slice-module[' . self::EVENTS . ']/item[' . strtoupper(self::ITEM) . ']'));
        self::assertStringStartsWith('Writing the items failed', $error('WRITE_FAILED', 'batch'));
        self::assertStringEndsWith('the PHP error log of this AA (DB) - Články', $error('DB', 'slice-module[' . self::NEWS . ']'));
        self::assertSame('UNKNOWN_CODE', $error('UNKNOWN_CODE', 'batch'));
        self::assertSame('', self::page([])['error']);
    }

    public function test_a_refused_login_asks_for_the_login_again(): void
    {
        self::assertTrue(self::page(['state' => 'error', 'error' => ['code' => SourceFailed::LOGIN, 'path' => 'source']])['login']);
        self::assertFalse(self::page(['state' => 'error', 'error' => ['code' => SourceFailed::UNREACHABLE, 'path' => 'source']])['login']);
    }
}
