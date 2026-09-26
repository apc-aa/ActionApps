<?php

declare(strict_types=1);

/**
 * A transfer from another AA: the target pulls the batches through Get_App of the source (a fake remote
 * running the real AppRequest behind RemoteSource). The short token of the source's superadmin lives only
 * encrypted in the run, never in central_conf; a token the source refuses stops the run with a clear
 * code, and a new login continues it.
 */

namespace Tests\Unit\Central;

use AA\Central\AppExport;
use AA\Central\AppRequest;
use AA\Central\ExportRefused;
use AA\Central\InstallRun;
use AA\Central\Package\ImportConfirmation;
use AA\Central\Package\LintFinding;
use AA\Central\Package\Reader;
use AA\Central\RemoteAppSource;
use AA\Central\RemoteSource;
use AA\Central\ResponderPolicy;
use AA\Central\SourceFailed;
use AA\Central\TransferCheck;
use AA\Central\TransferProgress;
use AA_Request;
use AA_Response;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\FakeSource;
use Tests\Unit\Central\Package\Fixtures\ImportFixture;
use Tests\Unit\Central\Package\Fixtures\TableDb;

require_once __DIR__ . '/Package/fixtures/ImportFixture.php';
require_once __DIR__ . '/Package/fixtures/FakeSource.php';

final class RemoteTransferTest extends TestCase
{
    use ImportFixture {
        setUp as private fixtureSetUp;
    }

    private const ITEM_A    = '1234567890abcdef1234567890abcdef';
    private const ITEM_B    = '2234567890abcdef1234567890abcdef';
    private const TOKEN     = 'eyJhbGciOiJIUzI1NiJ9.first-short-token.sig';
    private const NEW_TOKEN = 'eyJhbGciOiJIUzI1NiJ9.second-short-token.sig';
    private const HASH      = '$2y$10$readerpasswordhashreaderpasswordhash';

    private FakeSource $source;
    /** the token the source accepts now */
    private string $accepted = self::TOKEN;
    /** @var list<string> the commands the source was asked */
    private array $asked = [];
    /** answers instead of the source's own: (request) => response, null = its own */
    private ?\Closure $answer = null;

    protected function setUp(): void
    {
        $this->fixtureSetUp();
        self::foreign();
        $entry        = static fn(string $id, string $headline): array => ['id' => $id, 'content' => [
            'status_code.....' => [['value' => '1', 'flag' => 0]],
            'headline........' => [['value' => $headline, 'flag' => 0]],
            'password........' => [['value' => self::HASH, 'flag' => 0]],
        ]];
        $this->source = new FakeSource(
            [
                'module' => [['id' => self::SLICE, 'type' => 'S', 'deleted' => 0, 'name' => 'Články', 'lang_file' => 'cz-utf8_news_lang.php']],
                'slice'  => [['id' => self::SLICE, 'name' => 'Články', 'type' => 'S', 'lang_file' => 'cz-utf8_news_lang.php', 'deleted' => 0]],
            ],
            [self::SLICE => [$entry(self::ITEM_A, 'A'), $entry(self::ITEM_B, 'B')]],
        );
    }

    /** the source AA: its responder.php answering Get_App for a superadmin with a Bearer token */
    private function remote(): RemoteSource
    {
        return new RemoteSource('https://source.example/aa/central/responder.php', open: function (string $url, $context): array {
            $options = stream_context_get_options($context)['http'];
            parse_str((string) $options['content'], $post);
            $request       = AA_Request::decode($post['request']);
            $this->asked[] = $request->getCommand();
            $bearer        = preg_match('/^Authorization: Bearer (\S+)\r$/m', (string) $options['header'], $m) ? $m[1] : '';
            $export        = new AppExport($this->source, ['url' => 'https://source.example/aa/', 'aa-version' => '2026.9'], static fn(): bool => true, true);
            $response      = match (true) {
                ($this->answer !== null) && (($own = ($this->answer)($request)) !== null) => $own,
                $request->getCommand() === 'Get_Jwt' => (($post['username'] ?? '') === 'source-admin') && (($post['password'] ?? '') === 'pw')
                    ? new AA_Response($this->accepted = self::jwtOf(['sub' => '7', 'iat' => time(), 'exp' => time() + 86400, 'pwd' => 'x', 'scp' => 'apps']))
                    : new AA_Response('A token needs a login', ResponderPolicy::ERR_TOKEN_NEEDS_PASSWORD),
                $bearer !== $this->accepted          => new AA_Response('Login token refused', ResponderPolicy::ERR_TOKEN_REFUSED),
                $request->getCommand() === 'Get_Modules' => new AA_Response(array_column(array_filter($this->source->tables['module'], static fn(array $m): bool => in_array($m['type'], $request->getParameters()['types'], true)), 'name', 'id')),
                $request->getCommand() !== 'Get_App' => new AA_Response('Bad request', ResponderPolicy::ERR_UNKNOWN_COMMAND),
                default                              => (new AppRequest($export, 'source-admin', static function (): void {}))->answer($request->getParameters()),
            };
            $stream = fopen('php://memory', 'w+b');
            fwrite($stream, serialize($response));
            rewind($stream);
            return [$stream, ['HTTP/1.1 200 OK']];
        });
    }

    private static function jwtOf(array $claims): string
    {
        $b64 = static fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        return $b64('{"typ":"JWT","alg":"HS256"}') . '.' . $b64(json_encode($claims)) . '.' . $b64('signature');
    }

    /** the source of a run as the install page builds it: the sealed token opened for the source's address */
    private function sourceOf(string $run): RemoteAppSource
    {
        return new RemoteAppSource($this->remote(), $this->remote()->unseal(InstallRun::get($run)->token()));
    }

    /** a transfer run from the source AA of central_conf row 7, its definitions written */
    private function transferred(): string
    {
        [$next, TableDb::$next['object_text']] = [TableDb::$next['object_text'], 900_000];
        $run                                   = InstallRun::preview(InstallRun::TRANSFER, false, '7', self::USER, $this->remote()->seal(self::TOKEN), self::NOW)->getId();
        TableDb::$next['object_text']          = $next;
        $confirmation                          = new ImportConfirmation('preview-session');
        $plan                                  = $this->plan(self::example(), true);
        $this->import($plan, $this->importer(null, $confirmation), $confirmation->token($plan, self::USER), $run);
        $this->after->calls = [];
        return $run;
    }

    private function progress(string $run): TransferProgress
    {
        return new TransferProgress($this->importer(), fn(): RemoteAppSource => $this->sourceOf($run), $this->after, self::USER);
    }

    /** @return list<string> ids of the items of the new slice */
    private static function itemsOfTheSlice(): array
    {
        return array_values(array_column(array_filter(TableDb::rows('item'), static fn(array $r): bool => $r['slice_id'] === self::id(2)), 'id'));
    }

    /** the stored value of the run's token property */
    private static function storedToken(string $run): string
    {
        foreach (TableDb::rows('object_text') as $row) {
            if (($row['object_id'] === $run) && ($row['property'] === 'token')) {
                return (string) $row['value'];
            }
        }
        return '';
    }

    public function test_the_token_is_kept_only_encrypted_in_the_run(): void
    {
        $run = $this->transferred();

        self::assertSame(self::TOKEN, $this->remote()->unseal(InstallRun::get($run)->token()));
        self::assertNotSame('', self::storedToken($run));
        self::assertStringNotContainsString(self::TOKEN, json_encode(TableDb::$tables));
        self::assertStringNotContainsString('first-short-token', json_encode(TableDb::$tables));
        self::assertArrayNotHasKey('central_conf', TableDb::$tables);
    }

    public function test_the_batches_come_through_get_app_and_the_finish_forgets_the_token(): void
    {
        $run = $this->transferred();

        $state = $this->progress($run)->next($run, [self::SLICE, '']);

        self::assertSame([TransferProgress::DONE, InstallRun::DONE], [$state['status'], $state['state']]);
        self::assertSame([self::ITEM_A, self::ITEM_B], self::itemsOfTheSlice());
        self::assertSame(['Get_App', 'Get_App', 'Get_App'], $this->asked, 'the batch and the two counts of the check');
        self::assertSame(2, InstallRun::get($run)->report()['slices'][0]['sourceCount']);
        self::assertSame(['', ''], [InstallRun::get($run)->token(), self::storedToken($run)]);
        self::assertStringNotContainsString(self::HASH, json_encode(array_map(static fn(array $w): mixed => $w, TableDb::rows('object_text'))), 'no secret in the run');
    }

    public function test_a_refused_token_stops_the_run_and_a_new_login_continues_it(): void
    {
        $run            = $this->transferred();
        $this->accepted = self::NEW_TOKEN;          // the token expired or the password changed on the source

        $log = ini_set('error_log', '/dev/null');
        try {
            $state = $this->progress($run)->next($run, [self::SLICE, '']);
        } finally {
            ini_set('error_log', (string) $log);
        }

        self::assertSame([InstallRun::ERROR, ['code' => SourceFailed::LOGIN, 'path' => 'source']], [$state['state'], $state['error']]);
        self::assertSame(['slice' => self::SLICE, 'cursor' => ''], $state['position']);
        self::assertSame([], self::itemsOfTheSlice());

        $resumed = $this->progress($run)->resume($run, $this->remote()->seal(self::NEW_TOKEN));

        self::assertSame([TransferProgress::RESUMED, InstallRun::ITEMS], [$resumed['status'], $resumed['state']]);
        self::assertSame(self::NEW_TOKEN, $this->remote()->unseal(InstallRun::get($run)->token()));
        self::assertStringNotContainsString('second-short-token', json_encode(TableDb::$tables));
        self::assertSame(TransferProgress::DONE, $this->progress($run)->next($run, [self::SLICE, ''])['status']);
    }

    public function test_the_definitions_and_the_logins_come_as_the_local_ones(): void
    {
        $remote = new RemoteAppSource($this->remote(), self::TOKEN);
        $local  = new AppExport($this->source, ['url' => 'https://source.example/aa/', 'aa-version' => '2026.9'], static fn(): bool => true, true);

        [$xml, , $now] = $remote->definitions([self::SLICE], true, false, self::NOW);

        self::assertEqualsWithDelta(time(), $now, 5, 'the clock of the source');

        self::assertSame($local->definitions([self::SLICE], true, false, self::NOW)[0], $xml, 'the same XML - the same fingerprint');
        self::assertSame([self::SLICE], array_map(static fn($m): string => $m->id, (new Reader())->read($xml)->package->modules));
        self::assertSame([self::SLICE => 2], $remote->itemCounts([self::SLICE], false));
    }

    public function test_the_module_list_comes_by_the_short_token(): void
    {
        self::assertSame([self::SLICE => 'Články'], (new RemoteAppSource($this->remote(), self::TOKEN))->modules(['S', 'Alerts']));
        self::assertSame(['Get_Modules'], $this->asked);

        $this->accepted = self::NEW_TOKEN;
        $this->expectException(SourceFailed::class);
        (new RemoteAppSource($this->remote(), self::TOKEN))->modules(['W']);
    }

    public function test_a_module_list_of_a_foreign_form_is_refused(): void
    {
        $remote = new RemoteSource('https://source.example/aa/central/responder.php', open: static function (): array {
            $stream = fopen('php://memory', 'w+b');
            fwrite($stream, serialize(new AA_Response(['not-an-id' => ['nested']])));
            rewind($stream);
            return [$stream, ['HTTP/1.1 200 OK']];
        });

        $this->expectException(SourceFailed::class);
        (new RemoteAppSource($remote, self::TOKEN))->modules(['S']);
    }

    public function test_an_export_error_of_the_source_comes_back_as_findings(): void
    {
        $this->source->tables['slice'][0]['type'] = 'ReaderManagement';
        $this->source->items[self::SLICE][1]['content']['headline........'][0]['value'] = "b\xffb";

        try {
            (new RemoteAppSource($this->remote(), self::TOKEN))->logins([self::SLICE], false);
            self::fail('a login that cannot be checked');
        } catch (ExportRefused $e) {
            self::assertSame([['slice-module[' . self::SLICE . ']/item', LintFinding::ERROR, 'login-charset', '1']], array_map(static fn(array $f): array => [$f[0], $f[1]->level, $f[1]->code, $f[1]->ref], $e->findings));
        }
    }

    public function test_a_refused_token_on_the_preview_is_a_login_failure(): void
    {
        $this->accepted = self::NEW_TOKEN;

        $this->expectException(SourceFailed::class);
        (new RemoteAppSource($this->remote(), self::TOKEN))->definitions([self::SLICE], true, false, self::NOW);
    }

    /** what Continue of the page does after the source refused the token: log in, seal, resume */
    public function test_continue_logs_in_again_and_the_transfer_goes_on(): void
    {
        $run            = $this->transferred();
        $this->accepted = self::NEW_TOKEN;
        $log            = ini_set('error_log', '/dev/null');
        try {
            self::assertSame(SourceFailed::LOGIN, $this->progress($run)->next($run, [self::SLICE, ''])['error']['code']);
        } finally {
            ini_set('error_log', (string) $log);
        }

        $remote  = $this->remote();
        $resumed = $this->progress($run)->resume($run, $remote->seal($remote->jwt('source-admin', 'pw')));

        self::assertSame(InstallRun::ITEMS, $resumed['state']);
        self::assertSame(TransferProgress::DONE, $this->progress($run)->next($run, [self::SLICE, ''])['status']);
        self::assertSame([self::ITEM_A, self::ITEM_B], self::itemsOfTheSlice());
    }

    public function test_the_cursor_goes_over_the_wire(): void
    {
        $run = $this->transferred();
        $ids = array_map(static fn(int $i): string => sprintf('%032x', 0xb0000 + $i), range(1, 501));
        $this->source->items[self::SLICE] = array_map(static fn(string $id): array => ['id' => $id, 'content' => ['status_code.....' => [['value' => '1', 'flag' => 0]], 'headline........' => [['value' => $id, 'flag' => 0]]]], $ids);

        $first = $this->progress($run)->next($run, [self::SLICE, '']);
        self::assertSame(['slice' => self::SLICE, 'cursor' => $ids[499]], $first['position']);

        $last = $this->progress($run)->next($run, [self::SLICE, $ids[499]]);

        self::assertSame(TransferProgress::DONE, $last['status']);
        self::assertSame([self::SLICE => 501], $last['written']);
        self::assertCount(501, self::itemsOfTheSlice());
    }

    /** @return array<string, array{mixed}> */
    public static function foreignCounts(): array
    {
        return [
            'a slice missing'   => [[]],
            'a slice too many'  => [[self::SLICE => 2, 'f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0' => 1]],
            'a count no number' => [[self::SLICE => '2']],
            'a negative count'  => [[self::SLICE => -1]],
            'no list'           => ['2'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('foreignCounts')]
    public function test_item_counts_of_a_foreign_form_are_refused(mixed $counts): void
    {
        $this->answer = static fn(AA_Request $r): ?AA_Response => (($r->getParameters()['part'] ?? '') === 'counts') ? new AA_Response($counts) : null;

        $this->expectException(SourceFailed::class);
        (new RemoteAppSource($this->remote(), self::TOKEN))->itemCounts([self::SLICE], false);
    }

    /** the check at the end of a transfer asks the source for its counts - an unanswered ask still finishes the run */
    public function test_a_source_that_cannot_count_at_the_end_still_finishes_the_run(): void
    {
        $run          = $this->transferred();
        $this->answer = static fn(AA_Request $r): ?AA_Response => (($r->getParameters()['part'] ?? '') === 'counts') ? new AA_Response('down', ResponderPolicy::ERR_APP_FAILED) : null;

        $state = $this->progress($run)->next($run, [self::SLICE, '']);

        self::assertSame([TransferProgress::DONE, InstallRun::DONE], [$state['status'], $state['state']]);
        $report = InstallRun::get($run)->report();
        self::assertNull($report['slices'][0]['sourceCount']);
        self::assertSame(['W', 'source-count-unknown'], [$report['findings'][0]['level'], $report['findings'][0]['code']]);
    }

    /** End and the page of a run never need the source - its Central row may be gone */
    public function test_end_works_after_the_central_row_is_deleted(): void
    {
        $run      = $this->transferred();
        $progress = new TransferProgress($this->importer(), static fn(): never => throw new SourceFailed(SourceFailed::UNREACHABLE, 'No such AA in the Central list'), $this->after, self::USER);

        $state = $progress->end($run);

        self::assertSame([TransferProgress::ENDED, InstallRun::ENDED], [$state['status'], $state['state']]);
        self::assertSame('', InstallRun::get($run)->token());
    }

    /** the errors of an answer are source text: a few, and short */
    public function test_export_errors_of_an_answer_are_capped(): void
    {
        $lines        = array_map(static fn(int $i): string => 'item-too-big ' . str_repeat('p', 400) . " ref $i " . str_repeat('r', 400), range(1, 80));
        $this->answer = static fn(AA_Request $r): AA_Response => new AA_Response([...$lines, '9starts-with-a-digit x', 'UPPER x'], ResponderPolicy::ERR_APP_EXPORT);

        try {
            (new RemoteAppSource($this->remote(), self::TOKEN))->batch(self::SLICE, '', false, self::NOW);
            self::fail('an export error');
        } catch (ExportRefused $e) {
            self::assertCount(50, $e->findings);
            self::assertSame(255, strlen($e->findings[0][0]));
            self::assertSame(255, strlen($e->findings[0][1]->ref));
        }
    }

    public function test_a_finish_without_the_central_row_reports_the_target_counts(): void
    {
        $run    = $this->transferred();
        $source = $this->sourceOf($run);
        $calls  = 0;
        $from   = static function () use ($source, &$calls): RemoteAppSource {
            return (++$calls === 1) ? $source : throw new SourceFailed(SourceFailed::UNREACHABLE, 'No such AA in the Central list');
        };

        $state = (new TransferProgress($this->importer(), $from, $this->after, self::USER))->next($run, [self::SLICE, '']);

        self::assertSame(InstallRun::DONE, $state['state']);
        $report = InstallRun::get($run)->report();
        self::assertSame([null, 2], [$report['slices'][0]['sourceCount'], $report['slices'][0]['targetCount']]);
        self::assertSame([TransferCheck::SOURCE_UNKNOWN, SourceFailed::UNREACHABLE], [$report['findings'][0]['code'], $report['findings'][0]['ref']]);
    }
}
