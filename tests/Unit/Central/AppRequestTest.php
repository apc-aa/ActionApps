<?php

declare(strict_types=1);

/**
 * Get_App on the source AA (AppRequest over AppExport): an install needs PS_EDIT on every module, a
 * transfer a superadmin; the parts answer plain strings; the logins of a Reader Management slice are
 * refused when a login cannot be checked; every transfer export is logged without its secrets; errors
 * are a code and a short text.
 */

namespace Tests\Unit\Central;

use AA\Central\AppExport;
use AA\Central\AppRequest;
use AA\Central\Package\Reader;
use AA\Central\Package\Source;
use AA\Central\ResponderPolicy;
use AA_Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Unit\Central\Package\Fixtures\FakeSource;
use Tests\Unit\Central\Package\Fixtures\ImportFixture;

require_once __DIR__ . '/Package/fixtures/ImportFixture.php';
require_once __DIR__ . '/Package/fixtures/FakeSource.php';

final class AppRequestTest extends TestCase
{
    use ImportFixture;

    private const SLICE_A = 'b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2';
    private const READERS = 'c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3';
    private const ITEM_1  = '0123456789abcdef0123456789abcdef';
    private const ITEM_2  = '1123456789abcdef0123456789abcdef';
    private const HASH    = '$2y$10$readerpasswordhashreaderpasswordhash';
    private const SECRET  = 'JBSWY3DPEHPK3PXP';
    private const STARTED = 1_790_000_000;

    private FakeSource|FailingSource $source;
    /** @var list<string> modules the user may edit (PS_EDIT) */
    private array $editable = [self::SLICE_A, self::READERS];
    private bool $superadmin = true;
    /** @var list<array{string, string}> the log lines: [selector, params] */
    private array $log = [];

    protected function setUp(): void
    {
        $module = static fn(string $id, string $name): array => ['id' => $id, 'type' => 'S', 'name' => $name, 'lang_file' => 'cz-utf8_news_lang.php', 'flag' => 0, 'deleted' => 0,
            'owner' => '', 'created_by' => 'uid-17', 'created_at' => 1700000000, 'slice_url' => '', 'priority' => 5, 'app_id' => null];
        $slice  = static fn(string $id, string $name, string $type): array => ['id' => $id, 'name' => $name, 'type' => $type, 'lang_file' => 'cz-utf8_news_lang.php',
            'deleted' => 0, 'reading_password' => 'slice-reading-password'];
        $reader = static fn(string $id, string $login): array => ['id' => $id, 'content' => [
            'status_code.....' => [['value' => '1', 'flag' => 0]],
            'headline........' => [['value' => $login, 'flag' => 0]],
            'password........' => [['value' => self::HASH, 'flag' => 0]],
            'secret..........' => [['value' => self::SECRET, 'flag' => 0]],
        ]];
        $this->source = new FakeSource(
            [
                'module' => [$module(self::SLICE_A, 'Articles'), $module(self::READERS, 'Readers')],
                'slice'  => [$slice(self::SLICE_A, 'Articles', 'S'), $slice(self::READERS, 'Readers', 'ReaderManagement')],
            ],
            [self::READERS => [$reader(self::ITEM_1, 'alice'), $reader(self::ITEM_2, 'bob')]],
        );
    }

    private function answer(array $param, string $user = 'source-uid'): AA_Response
    {
        $export = new AppExport($this->source, ['url' => 'https://source.example/aa/', 'aa-version' => '2026.9'], fn(string $module): bool => in_array($module, $this->editable, true), $this->superadmin);
        return (new AppRequest($export, $user, function (string $selector, string $params): void {
            $this->log[] = [$selector, $params];
        }))->answer($param);
    }

    private static function definitions(string $mode, array $modules = [self::SLICE_A]): array
    {
        return ['part' => 'definitions', 'modules' => $modules, 'mode' => $mode, 'bins' => 'active', 'started' => self::STARTED];
    }

    private static function batch(): array
    {
        return ['part' => 'batch', 'mode' => 'transfer', 'bins' => 'active', 'slice' => self::READERS, 'after' => '', 'started' => self::STARTED];
    }

    private static function logins(): array
    {
        return ['part' => 'logins', 'mode' => 'transfer', 'bins' => 'active', 'slices' => [self::READERS]];
    }

    /** the definitions carry the clock of the source: the final check compares its item times with it */
    public function test_an_install_answers_the_package_as_a_plain_string_and_the_source_time(): void
    {
        $before   = time();
        $response = $this->answer(self::definitions('install'));

        self::assertFalse($response->isError(), (string) json_encode($response->getResponse()));
        self::assertSame(['package', 'now'], array_keys($response->getResponse()));
        self::assertIsString($response->getResponse()['package']);
        self::assertGreaterThanOrEqual($before, $response->getResponse()['now']);
        self::assertSame([self::SLICE_A], array_map(static fn($m): string => $m->id, (new Reader())->read($response->getResponse()['package'])->package->modules));
    }

    public function test_an_install_needs_ps_edit_on_every_module(): void
    {
        $this->superadmin = false;
        $this->editable   = [self::SLICE_A];

        self::assertFalse($this->answer(self::definitions('install'))->isError(), 'an editor, no superadmin');
        self::assertSame(ResponderPolicy::ERR_APP_DENIED, $this->answer(self::definitions('install', [self::SLICE_A, self::READERS]))->getError());
    }

    public function test_the_same_package_for_the_same_start(): void
    {
        self::assertSame($this->answer(self::definitions('install'))->getResponse()['package'], $this->answer(self::definitions('install'))->getResponse()['package'], 'the target compares the preview with the write');
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function transferParts(): array
    {
        return [
            'definitions' => [self::definitions('transfer')],
            'batch'       => [self::batch()],
            'logins'      => [self::logins()],
        ];
    }

    #[DataProvider('transferParts')]
    public function test_a_transfer_needs_a_superadmin_of_the_source(array $param): void
    {
        $this->superadmin = false;

        $response = $this->answer($param);

        self::assertSame(ResponderPolicy::ERR_APP_DENIED, $response->getError(), 'PS_EDIT on every module is not enough');
        self::assertSame([], $this->log);
    }

    #[DataProvider('transferParts')]
    public function test_a_transfer_needs_ps_edit_too(array $param): void
    {
        $this->editable = [];

        self::assertSame(ResponderPolicy::ERR_APP_DENIED, $this->answer($param)->getError());
    }

    public function test_a_batch_carries_the_items_as_package_xml(): void
    {
        $response = $this->answer(self::batch());

        self::assertIsString($response->getResponse());
        $package = (new Reader())->read($response->getResponse())->package;
        self::assertSame([self::ITEM_1, self::ITEM_2], array_column($package->modules[0]->items, 'id'));
        self::assertTrue($package->batch->finished);
    }

    public function test_logins_are_a_list_of_strings(): void
    {
        self::assertSame(['alice', 'bob'], $this->answer(self::logins())->getResponse());
    }

    public function test_logins_that_cannot_be_checked_are_refused(): void
    {
        $this->source->items[self::READERS][1]['content']['headline........'][0]['value'] = "b\xffb";

        $response = $this->answer(self::logins());

        self::assertSame(ResponderPolicy::ERR_APP_EXPORT, $response->getError(), 'the target would miss a login');
        self::assertSame(['login-charset slice-module[' . self::READERS . ']/item 1'], $response->getResponse());
    }

    public function test_every_transfer_export_is_logged_without_its_secrets(): void
    {
        $this->answer(self::definitions('transfer', [self::SLICE_A, self::READERS]));
        $this->answer(self::batch());
        $this->answer(self::logins());
        $this->answer(self::definitions('install'));

        self::assertSame([
            ['transfer definitions ' . self::SLICE_A . ',' . self::READERS, 'user=source-uid modules=2'],
            ['transfer batch ' . self::READERS, 'user=source-uid items=2'],
            ['transfer logins ' . self::READERS, 'user=source-uid logins=2'],
        ], $this->log, 'an install is not logged');
        $all = json_encode($this->log);
        foreach ([self::HASH, self::SECRET, 'alice', 'slice-reading-password'] as $secret) {
            self::assertStringNotContainsString($secret, $all);
        }
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function badRequests(): array
    {
        return [
            'unknown part'       => [['part' => 'everything', 'mode' => 'install']],
            'unknown mode'       => [['part' => 'definitions', 'mode' => 'copy', 'modules' => [self::SLICE_A]]],
            'no module'          => [['part' => 'definitions', 'mode' => 'install', 'modules' => []]],
            'a module no id'     => [['part' => 'definitions', 'mode' => 'install', 'modules' => ['x']]],
            'modules no list'    => [['part' => 'definitions', 'mode' => 'install', 'modules' => self::SLICE_A]],
            'a cursor no id'     => [['part' => 'batch', 'mode' => 'transfer', 'slice' => self::READERS, 'after' => "1' OR 1"]],
            'started not a time' => [['part' => 'definitions', 'mode' => 'install', 'modules' => [self::SLICE_A], 'started' => 'now']],
        ];
    }

    #[DataProvider('badRequests')]
    public function test_a_bad_request_is_a_code_and_a_short_text(array $param): void
    {
        $response = $this->answer($param);

        self::assertSame(ResponderPolicy::ERR_APP_REQUEST, $response->getError());
        self::assertIsString($response->getResponse());
    }

    public function test_counts_are_integers_per_slice(): void
    {
        $response = $this->answer(['part' => 'counts', 'mode' => 'install', 'bins' => 'all', 'slices' => [self::READERS, self::SLICE_A], 'edited_after' => 0]);

        self::assertSame([self::READERS => 2, self::SLICE_A => 0], $response->getResponse());
    }

    /** @return array<string, array{mixed}> */
    public static function badEditedAfter(): array
    {
        return ['a word' => ['yesterday'], 'negative' => [-1], 'a float' => [1.5], 'a list' => [[1]]];
    }

    #[DataProvider('badEditedAfter')]
    public function test_counts_with_a_bad_edited_after_are_a_bad_request(mixed $after): void
    {
        self::assertSame(ResponderPolicy::ERR_APP_REQUEST, $this->answer(['part' => 'counts', 'mode' => 'install', 'slices' => [self::SLICE_A], 'edited_after' => $after])->getError());
    }

    public function test_counts_of_a_slice_the_user_may_not_edit_are_denied(): void
    {
        $this->editable = [self::SLICE_A];

        self::assertSame(ResponderPolicy::ERR_APP_DENIED, $this->answer(['part' => 'counts', 'mode' => 'install', 'slices' => [self::SLICE_A, self::READERS]])->getError());
    }

    public function test_an_unknown_module_is_answered_as_a_denied_one(): void
    {
        self::assertSame(ResponderPolicy::ERR_APP_DENIED, $this->answer(self::definitions('install', ['0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f']))->getError());
    }

    public function test_a_failure_of_the_source_never_shows_its_message(): void
    {
        $this->source = new FailingSource();

        $log = ini_set('error_log', '/dev/null');
        try {
            $response = $this->answer(self::definitions('install'));
        } finally {
            ini_set('error_log', (string) $log);
        }

        self::assertSame(ResponderPolicy::ERR_APP_FAILED, $response->getError());
        self::assertStringNotContainsString('SQLSTATE', (string) $response->getResponse());
        self::assertStringNotContainsString('aadb', (string) $response->getResponse());
    }
}

/** a source whose database fails - its message names the table */
final class FailingSource implements Source
{
    private static function fail(string $table): never
    {
        throw new RuntimeException("SQLSTATE[42S02]: Base table or view not found: `aadb`.`$table`");
    }

    public function select(string $table, array $where): array
    {
        self::fail($table);
    }

    public function itemIds(string $slice_id, bool $all_bins, string $after, int $limit): array
    {
        self::fail('item');
    }

    public function itemCount(string $slice_id, bool $all_bins, int $edited_after = 0): int
    {
        self::fail('item');
    }

    public function items(array $ids, bool $with_secrets): iterable
    {
        self::fail('content');
    }

    public function logins(string $slice_id, bool $all_bins): array
    {
        self::fail('content');
    }

    public function objectIds(string $owner_id): array
    {
        self::fail('object_text');
    }

    public function objectRows(array $ids, array $properties = []): array
    {
        self::fail('object_text');
    }
}
