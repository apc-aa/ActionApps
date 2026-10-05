<?php

declare(strict_types=1);

/**
 * A transfer from another AA: the steps of the run in the queue pull the batches through Get_App of the
 * source (a fake remote running the real AppRequest behind RemoteSource). The short token of the source's
 * superadmin lives only sealed in the run, never in central_conf; a token the source refuses stops the run
 * with a clear code, and a new login continues it.
 */

namespace Tests\Unit\Central;

use AA\Central\AppExport;
use AA\Central\AppRequest;
use AA\Central\AppSource;
use AA\Central\ItemIdCheck;
use AA\Central\Package\DbImportTarget;
use AA\Central\ExportRefused;
use AA\Central\InstallRun;
use AA\Central\Package\ImportChoices;
use AA\Central\Package\ImportConfirmation;
use AA\Central\Package\ItemFilter;
use AA\Central\Package\LintFinding;
use AA\Central\Package\Reader;
use AA\Central\RemoteAppSource;
use AA\Central\RemoteSource;
use AA\Central\ResponderPolicy;
use AA\Central\SourceFailed;
use AA\Central\TransferCheck;
use AA\Central\TransferProgress;
use AA\Later\BatchFailed;
use AA_Request;
use AA_Response;
use ArrayObject;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
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
    /** answers instead of the source's own: (request) => response, a MockResponse as it is, null = its own */
    private ?Closure $answer = null;
    /** the AA_ID the source reports - another AA by default */
    private string $sourceAa = '5ource000000000000000000000000aa';
    /** @var list<int> the seconds each source built for a step may wait for an answer */
    private array $timeouts = [];

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
    private function remote(int $timeout = RemoteSource::TIMEOUT): RemoteSource
    {
        return new RemoteSource('https://source.example/aa/central/responder.php', timeout: $timeout, transport: new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            parse_str((string) $options['body'], $post);
            $request       = AA_Request::decode($post['request']);
            $this->asked[] = $request->getCommand();
            $bearer        = preg_match('/^Authorization: Bearer (\S+)$/m', implode("\n", $options['headers']), $m) ? $m[1] : '';
            $export        = new AppExport($this->source, ['url' => 'https://source.example/aa/', 'aa-version' => '2026.9'], static fn(): bool => true, true);
            $response      = match (true) {
                ($this->answer !== null) && (($own = ($this->answer)($request)) !== null) => $own,
                $request->getCommand() === 'Get_Jwt' => (($post['username'] ?? '') === 'source-admin') && (($post['password'] ?? '') === 'pw')
                    ? new AA_Response($this->accepted = self::jwtOf(['sub' => '7', 'iat' => time(), 'exp' => time() + 86400, 'pwd' => 'x', 'scp' => 'apps']))
                    : new AA_Response('A token needs a login', ResponderPolicy::ERR_TOKEN_NEEDS_PASSWORD),
                $bearer !== $this->accepted          => new AA_Response('Login token refused', ResponderPolicy::ERR_TOKEN_REFUSED),
                $request->getCommand() === 'Get_Modules' => new AA_Response(array_column(array_filter($this->source->tables['module'], static fn(array $m): bool => in_array($m['type'], $request->getParameters()['types'], true)), 'name', 'id')),
                $request->getCommand() !== 'Get_App' => new AA_Response('Bad request', ResponderPolicy::ERR_UNKNOWN_COMMAND),
                default                              => (new AppRequest($export, 'source-admin', static function (): void {}, $this->sourceAa))->answer($request->getParameters()),
            };
            return ($response instanceof MockResponse) ? $response : new MockResponse(serialize($response));
        }));
    }

    /** an answer that never comes - the mock's inactivity timeout */
    private static function silent(): MockResponse
    {
        return new MockResponse((static function (): \Generator {
            yield '';
        })());
    }

    private static function jwtOf(array $claims): string
    {
        $b64 = static fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        return $b64('{"typ":"JWT","alg":"HS256"}') . '.' . $b64(json_encode($claims)) . '.' . $b64('signature');
    }

    /** a transfer run from the source AA of central_conf row 7, its definitions written and its first step queued
     *  @param array<string, string> $filters source slice => the condition of its items */
    private function transferred(array $filters = []): string
    {
        [$next, TableDb::$next['object_text']] = [TableDb::$next['object_text'], 900_000];
        $choices                               = $filters ? new ImportChoices(filters: $filters) : null;
        $run                                   = InstallRun::preview(InstallRun::TRANSFER, false, '7', self::USER, $this->remote()->seal(self::TOKEN), self::NOW, '', [], $choices)->getId();
        TableDb::$next['object_text']          = $next;
        $confirmation                          = new ImportConfirmation('preview-session');
        $plan                                  = $this->plan(self::example(), true);
        $this->import($plan, $this->importer(null, $confirmation), $confirmation->token($plan, self::USER), $run);
        $this->after->calls = [];
        $this->steps();
        return $run;
    }

    /** The steps get the source as InstallRun builds it for a remote run: the sealed token opened for the
     *  source's address, each answer within the timeout the step gives; $from (called once per source
     *  built) may replace it.
     *  @param ?Closure(InstallRun, int): AppSource $from */
    private function steps(?Closure $from = null): void
    {
        InstallRun::setTestDoubles(function (InstallRun $run, int $timeout) use ($from): AppSource {
            $this->timeouts[] = $timeout;
            return $from ? $from($run, $timeout) : new RemoteAppSource($this->remote($timeout), $this->remote()->unseal($run->token()));
        }, $this->after);
    }

    /** runs the queued steps - $max at most - the source's complaints kept out of the test output
     *  @return list<string> */
    private static function quietly(int $max = 50): array
    {
        $log = ini_set('error_log', '/dev/null');
        try {
            return self::runAll($max);
        } finally {
            ini_set('error_log', (string) $log);
        }
    }

    /** @return list<string> ids of the items of the new slice */
    private static function itemsOfTheSlice(): array
    {
        return array_values(array_column(array_filter(TableDb::rows('item'), static fn(array $r): bool => $r['slice_id'] === self::id(2)), 'id'));
    }

    /** the stored value of a property of the run */
    private static function stored(string $run, string $property): string
    {
        foreach (TableDb::rows('object_text') as $row) {
            if (($row['object_id'] === $run) && ($row['property'] === $property)) {
                return (string) $row['value'];
            }
        }
        return '';
    }

    /** the Get_App requests of the source kept, the source answering as before */
    private function kept(): ArrayObject
    {
        $requests     = new ArrayObject();
        $this->answer = static function (AA_Request $r) use ($requests): ?AA_Response {
            $requests[] = $r->getParameters();
            return null;
        };
        return $requests;
    }

    /** @param list<string> $ids @return list<array<string, mixed>> items of the source with these ids */
    private static function entries(array $ids): array
    {
        return array_map(static fn(string $id): array => ['id' => $id, 'content' => ['status_code.....' => [['value' => '1', 'flag' => 0]], 'headline........' => [['value' => $id, 'flag' => 0]]]], $ids);
    }

    public function test_the_token_is_kept_only_sealed_in_the_run(): void
    {
        $run = $this->transferred();

        self::assertSame(self::TOKEN, $this->remote()->unseal(InstallRun::get($run)->token()));
        self::assertNotSame('', self::stored($run, 'sealed'));
        self::assertGreaterThan(time(), (int) self::stored($run, 'sealed_until'));
        self::assertStringNotContainsString(self::TOKEN, json_encode(TableDb::$tables));
        self::assertStringNotContainsString('first-short-token', json_encode(TableDb::$tables));
        self::assertArrayNotHasKey('central_conf', TableDb::$tables);
    }

    public function test_the_steps_bring_the_batches_through_get_app_and_the_finish_forgets_the_token(): void
    {
        $run = $this->transferred();

        $lines = self::runAll();

        self::assertSame([InstallRun::DONE, 'stale'], $lines, 'the batch and the check in one step; its safety row finds the run done');
        self::assertSame(InstallRun::DONE, InstallRun::get($run)->state());
        self::assertSame([self::ITEM_A, self::ITEM_B], self::itemsOfTheSlice());
        self::assertSame(['Get_App', 'Get_App', 'Get_App'], $this->asked, 'the batch and the two counts of the check');
        self::assertSame(2, InstallRun::get($run)->report()['slices'][0]['sourceCount']);
        self::assertSame([self::SLICE => 2], InstallRun::get($run)->written());
        self::assertSame(['', '', ''], [InstallRun::get($run)->token(), self::stored($run, 'sealed'), self::stored($run, 'sealed_until')]);
        self::assertStringNotContainsString(self::HASH, json_encode(TableDb::rows('object_text')), 'no secret in the run');
        self::assertContains('invalidatePagecache ' . self::id(2), $this->after->calls, 'the pages of the new slice');
        self::assertContains('constantsChanged', $this->after->calls);
    }

    /** an answer of the source may take only what is left of the step - the check, with no budget of its own, a short while */
    public function test_each_source_of_a_step_waits_at_most_for_the_rest_of_the_step(): void
    {
        $this->transferred();

        self::runAll();

        self::assertCount(2, $this->timeouts, 'the batch, then the check');
        self::assertGreaterThanOrEqual(1, $this->timeouts[0]);
        self::assertLessThanOrEqual(30, $this->timeouts[0], 'a queue round');
        self::assertLessThanOrEqual(30, $this->timeouts[1]);
    }

    public function test_a_refused_token_stops_the_run_and_a_new_login_continues_it(): void
    {
        $run            = $this->transferred();
        $this->accepted = self::NEW_TOKEN;          // the token expired or the password changed on the source

        self::assertSame(['error ' . SourceFailed::LOGIN], self::quietly(), 'no retry - the same token would be refused again');

        $stopped = InstallRun::get($run);
        self::assertSame([InstallRun::ERROR, [SourceFailed::LOGIN, 'source']], [$stopped->state(), $stopped->error()]);
        self::assertSame(InstallRun::at(self::SLICE, ''), $stopped->position());
        self::assertSame([], self::itemsOfTheSlice());
        self::assertTrue(TransferProgress::needsLogin($stopped));
        $page = TransferProgress::state($run, self::USER);
        self::assertSame([true, true], [$page['login'], $page['mayContinue']]);

        self::assertSame(TransferProgress::RESUMED, TransferProgress::resume($run, self::USER, $this->remote()->seal(self::NEW_TOKEN)));

        self::assertSame(InstallRun::ITEMS, InstallRun::get($run)->state());
        self::assertSame(self::NEW_TOKEN, $this->remote()->unseal(InstallRun::get($run)->token()));
        self::assertFalse(TransferProgress::needsLogin(InstallRun::get($run)));
        self::assertStringNotContainsString('second-short-token', json_encode(TableDb::$tables));
        self::runAll();
        self::assertSame(InstallRun::DONE, InstallRun::get($run)->state());
        self::assertSame([self::ITEM_A, self::ITEM_B], self::itemsOfTheSlice());
    }

    /** what Continue of the page does after the source refused the token: log in, seal, resume */
    public function test_continue_logs_in_again_and_the_transfer_goes_on(): void
    {
        $run            = $this->transferred();
        $this->accepted = self::NEW_TOKEN;
        self::quietly();
        self::assertSame(SourceFailed::LOGIN, InstallRun::get($run)->error()[0]);

        $remote = $this->remote();
        self::assertSame(TransferProgress::RESUMED, TransferProgress::resume($run, self::USER, $remote->seal($remote->jwt('source-admin', 'pw'))));
        $this->asked = [];
        self::runAll();

        self::assertSame(InstallRun::DONE, InstallRun::get($run)->state());
        self::assertSame([self::ITEM_A, self::ITEM_B], self::itemsOfTheSlice());
        self::assertSame(['Get_App', 'Get_App', 'Get_App'], $this->asked, 'the new token is accepted for the batch and the check');
    }

    /** Continue is the user's who started the run - a new login would put another user's rights in it */
    public function test_another_superadmin_may_not_continue_with_a_login_of_his_own(): void
    {
        $run            = $this->transferred();
        $this->accepted = self::NEW_TOKEN;
        self::quietly();
        $this->superadmins->superadmins[] = 'other-admin';

        self::assertFalse(TransferProgress::state($run, 'other-admin')['mayContinue']);
        try {
            TransferProgress::resume($run, 'other-admin', $this->remote()->seal(self::NEW_TOKEN));
            self::fail('another user continued the run');
        } catch (BatchFailed $e) {
            self::assertSame('FORBIDDEN', $e->reason);
        }
        self::assertSame([InstallRun::ERROR, self::TOKEN], [InstallRun::get($run)->state(), $this->remote()->unseal(InstallRun::get($run)->token())]);
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
        $remote = new RemoteSource('https://source.example/aa/central/responder.php', transport: new MockHttpClient(new MockResponse(serialize(new AA_Response(['not-an-id' => ['nested']])))));

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

    public function test_the_cursor_goes_over_the_wire(): void
    {
        $ids                              = array_map(static fn(int $i): string => sprintf('%032x', 0xb0000 + $i), range(1, 501));
        $this->source->items[self::SLICE] = self::entries($ids);
        $run                              = $this->transferred();
        $requests                         = $this->kept();

        self::assertSame('step 2', self::runQueued(), 'the definitions queued step 1');
        self::assertSame(InstallRun::at(self::SLICE, $ids[499]), InstallRun::get($run)->position());

        self::runAll();

        self::assertSame(InstallRun::DONE, InstallRun::get($run)->state());
        self::assertSame([self::SLICE => 501], InstallRun::get($run)->written());
        self::assertCount(501, self::itemsOfTheSlice());
        self::assertSame(['', $ids[499]], array_column(array_filter((array) $requests, static fn(array $r): bool => $r['part'] === 'batch'), 'after'));
        self::assertSame([], array_filter(array_column((array) $requests, 'items')), 'full batches ask for no limit');
    }

    /** a step out of time asks at once for half as many items - and only then names a limit at all; a quick
     *  answer asks for twice as many again */
    public function test_after_a_step_out_of_time_the_source_is_asked_for_fewer_items(): void
    {
        $ids                              = array_map(static fn(int $i): string => sprintf('%032x', 0xb0000 + $i), range(1, 501));
        $this->source->items[self::SLICE] = self::entries($ids);
        $run                              = $this->transferred();
        $requests                         = new ArrayObject();
        $failed                           = false;
        $this->answer                     = static function (AA_Request $r) use ($requests, &$failed): AA_Response|MockResponse|null {
            $requests[] = $r->getParameters();
            if (!$failed && (($r->getParameters()['part'] ?? '') === 'batch')) {
                $failed = true;
                return self::silent();
            }
            return null;
        };

        self::quietly(1);
        self::assertSame([1, InstallRun::at(self::SLICE, ''), false], [(int) self::stored($run, 'failures'), InstallRun::get($run)->position(), InstallRun::get($run)->isWaiting(InstallRun::clock())], 'no wait');

        self::quietly();

        $batches = array_values(array_filter((array) $requests, static fn(array $r): bool => $r['part'] === 'batch'));
        self::assertSame([null, 250, null], array_map(static fn(array $r): ?int => $r['items'] ?? null, $batches), 'the half after the timeout, a full one after a quick answer');
        self::assertSame(['', '', $ids[249]], array_column($batches, 'after'));
        self::assertSame(InstallRun::DONE, InstallRun::get($run)->state());
        self::assertCount(501, self::itemsOfTheSlice());
    }

    /** a source that knows no limit gives a full batch - the run takes it */
    public function test_a_source_ignoring_the_limit_still_transfers_everything(): void
    {
        $ids                              = array_map(static fn(int $i): string => sprintf('%032x', 0xb0000 + $i), range(1, 501));
        $this->source->items[self::SLICE] = self::entries($ids);
        $run                              = $this->transferred();
        $export                           = new AppExport($this->source, ['url' => 'https://source.example/aa/', 'aa-version' => '2026.9'], static fn(): bool => true, true);
        $failed                           = false;
        $asked                            = new ArrayObject();
        $this->answer                     = function (AA_Request $r) use ($export, &$failed, $asked): AA_Response|MockResponse|null {
            $params = $r->getParameters();
            if (($params['part'] ?? '') !== 'batch') {
                return null;
            }
            if (!$failed) {
                $failed = true;
                return self::silent();
            }
            $asked[] = $params['items'] ?? null;
            unset($params['items']);
            return (new AppRequest($export, 'source-admin', static function (): void {}, $this->sourceAa))->answer($params);
        };

        self::quietly();

        self::assertSame(250, $asked[0], 'asked for fewer');
        self::assertSame(InstallRun::DONE, InstallRun::get($run)->state());
        self::assertSame([self::SLICE => 501], InstallRun::get($run)->written());
        self::assertCount(501, self::itemsOfTheSlice());
    }

    /** the condition goes with every batch and the check; an answer of a source that cannot filter would be all the items */
    public function test_a_filtered_transfer_takes_the_matching_items_only(): void
    {
        $this->source->tables['field'] = [['id' => 'headline........', 'slice_id' => self::SLICE, 'input_show_func' => 'fld:']];
        $run                           = $this->transferred([self::SLICE => self::ONLY_B]);
        $requests                      = $this->kept();

        self::runAll();

        self::assertSame(InstallRun::DONE, InstallRun::get($run)->state());
        self::assertSame([self::ITEM_B], self::itemsOfTheSlice());
        self::assertSame(['batch', 'counts', 'counts'], array_column((array) $requests, 'part'));
        self::assertSame(array_fill(0, 3, [self::SLICE => self::ONLY_B]), array_column((array) $requests, 'filters'));
        $report = InstallRun::get($run)->report();
        self::assertSame([1, 1], [$report['slices'][0]['sourceCount'], $report['slices'][0]['targetCount']]);
        self::assertSame([], $report['findings'], 'the matching items only are compared');
    }

    public function test_a_batch_without_the_echo_of_the_filters_is_not_written(): void
    {
        $this->source->tables['field'] = [['id' => 'headline........', 'slice_id' => self::SLICE, 'input_show_func' => 'fld:']];
        $run                           = $this->transferred([self::SLICE => self::ONLY_B]);
        $export                        = new AppExport($this->source, ['url' => 'https://source.example/aa/', 'aa-version' => '2026.9'], static fn(): bool => true, true);
        // an older source: all the items, no echo
        $this->answer = function (AA_Request $r) use ($export): ?AA_Response {
            $params = $r->getParameters();
            unset($params['filters']);
            return (($r->getParameters()['part'] ?? '') === 'batch') ? (new AppRequest($export, 'source-admin', static function (): void {}, $this->sourceAa))->answer($params) : null;
        };

        self::quietly();

        self::assertSame([InstallRun::ERROR, [SourceFailed::FAILED, '']], [InstallRun::get($run)->state(), InstallRun::get($run)->error()], 'asked three times, then stopped');
        self::assertSame([], self::itemsOfTheSlice());
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

    #[DataProvider('foreignCounts')]
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

        self::runAll();

        self::assertSame(InstallRun::DONE, InstallRun::get($run)->state());
        $report = InstallRun::get($run)->report();
        self::assertNull($report['slices'][0]['sourceCount']);
        self::assertSame(['W', 'source-count-unknown'], [$report['findings'][0]['level'], $report['findings'][0]['code']]);
    }

    /** End and the page of a run never need the source - its Central row may be gone */
    public function test_end_works_after_the_central_row_is_deleted(): void
    {
        $run = $this->transferred();
        $this->steps(static fn(): never => throw new SourceFailed(SourceFailed::UNREACHABLE, 'No such AA in the Central list'));

        self::assertSame(InstallRun::ITEMS, TransferProgress::state($run, self::USER)['state']);
        self::assertSame(TransferProgress::ENDED, TransferProgress::end($run, self::USER));

        self::assertSame(InstallRun::ENDED, InstallRun::get($run)->state());
        self::assertSame(['', ''], [InstallRun::get($run)->token(), self::stored($run, 'sealed')]);
        self::runAll();
        self::assertSame([], $this->timeouts, 'nothing asked the source');
        self::assertSame([], self::itemsOfTheSlice());
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
        $run   = $this->transferred();
        $calls = 0;
        $this->steps(function (InstallRun $run, int $timeout) use (&$calls): AppSource {
            return (++$calls === 1) ? new RemoteAppSource($this->remote($timeout), $this->remote()->unseal($run->token())) : throw new SourceFailed(SourceFailed::UNREACHABLE, 'No such AA in the Central list');
        });

        self::runAll();

        self::assertSame(InstallRun::DONE, InstallRun::get($run)->state());
        $report = InstallRun::get($run)->report();
        self::assertSame([null, 2], [$report['slices'][0]['sourceCount'], $report['slices'][0]['targetCount']]);
        self::assertSame([TransferCheck::SOURCE_UNKNOWN, SourceFailed::UNREACHABLE], [$report['findings'][0]['code'], $report['findings'][0]['ref']]);
    }

    /** a Central row may name this very AA (over https as any other) - its identity says so */
    public function test_a_source_reporting_the_id_of_this_aa_is_the_same_aa(): void
    {
        $foreign = new RemoteAppSource($this->remote(), self::TOKEN);
        $foreign->definitions([self::SLICE], true, false, self::NOW);
        self::assertFalse($foreign->sameAa());

        $this->sourceAa = (string) constant('AA_ID');
        $self           = new RemoteAppSource($this->remote(), self::TOKEN);
        $self->definitions([self::SLICE], true, false, self::NOW);
        self::assertTrue($self->sameAa());
    }

    /** another AA keeps the item ids: the ones this AA has already refuse the preview, before anything is written */
    public function test_item_ids_existing_here_refuse_the_preview(): void
    {
        $ids = array_map(static fn(int $i): string => sprintf('%032x', 0xd0000 + $i), range(1, AppSource::ID_PAGE + 2));
        $this->source->items[self::SLICE] = array_map(static fn(string $id): array => ['id' => $id, 'content' => ['status_code.....' => [['value' => '1', 'flag' => 0]]]], $ids);
        TableDb::$tables['item'][] = ['id' => $ids[3], 'short_id' => 50, 'slice_id' => self::FS, 'status_code' => 1];
        TableDb::$tables['item'][] = ['id' => end($ids), 'short_id' => 51, 'slice_id' => self::FS, 'status_code' => 1];
        [TableDb::$log, $before] = [[], TableDb::$tables];

        $findings = (new ItemIdCheck(new RemoteAppSource($this->remote(), self::TOKEN), new DbImportTarget()))->check([self::SLICE], false);

        self::assertSame(['E ' . ItemIdCheck::ITEM_ID_EXISTS . ' slice-module[' . self::SLICE . '] 2'], array_map(static fn(array $f): string => "{$f[1]->level} {$f[1]->code} $f[0] {$f[1]->ref}", $findings), 'the second page too');
        self::assertSame(['Get_App', 'Get_App', 'Get_App'], $this->asked, 'the count, then a page of ids a request');
        self::assertSame($before, TableDb::$tables, 'nothing written');
    }

    public function test_a_source_without_the_items_here_passes_the_check(): void
    {
        self::assertSame([], (new ItemIdCheck(new RemoteAppSource($this->remote(), self::TOKEN), new DbImportTarget()))->check([self::SLICE], false));
    }

    private const ONLY_B = 'd-headline........-=-B';

    /** the field the filters name, and every Get_App request of the source kept */
    private function filterable(): ArrayObject
    {
        $this->source->tables['field'] = [['id' => 'headline........', 'slice_id' => self::SLICE, 'input_show_func' => 'fld:']];
        $requests                      = new ArrayObject();
        $this->answer                  = static function (AA_Request $r) use ($requests): ?AA_Response {
            $requests[] = $r->getParameters();
            return null;
        };
        return $requests;
    }

    public function test_filtered_requests_come_over_the_wire_and_the_echo_is_checked(): void
    {
        $requests = $this->filterable();
        $remote   = new RemoteAppSource($this->remote(), self::TOKEN);
        $filters  = [self::SLICE => self::ONLY_B];

        self::assertSame([self::ITEM_B], $remote->itemIds(self::SLICE, false, '', $filters));
        self::assertSame([self::SLICE => 1], $remote->itemCounts([self::SLICE], false, 0, $filters));
        self::assertSame([self::ITEM_B], array_column((new Reader())->read($remote->batch(self::SLICE, '', false, self::NOW, $filters)[0])->package->modules[0]->items, 'id'));
        self::assertSame([self::SLICE => 0], $remote->leftOut($filters, [self::SLICE], false));
        self::assertSame([self::SLICE => 2], $remote->itemCounts([self::SLICE], false), 'without filters as before');
        self::assertSame([$filters, null], [$requests[1]['filters'], $requests[4]['filters'] ?? null], 'no filters, no parameter - an older source gets the request it knows');
    }

    /** an item a filter leaves out stays at the source - its id here stops nothing */
    public function test_the_item_id_check_of_a_filtered_slice_looks_at_the_matching_items_only(): void
    {
        $this->filterable();
        TableDb::$tables['item'][] = ['id' => self::ITEM_A, 'short_id' => 50, 'slice_id' => self::FS, 'status_code' => 1];
        $check = new ItemIdCheck(new RemoteAppSource($this->remote(), self::TOKEN), new DbImportTarget());

        self::assertSame([], $check->check([self::SLICE], false, [self::SLICE => self::ONLY_B]));
        self::assertCount(1, $check->check([self::SLICE], false));
    }

    /** a call takes the filters of its own slices only - the local source and the remote one alike */
    public function test_filters_of_other_slices_are_left_out(): void
    {
        $requests = $this->filterable();
        $other    = 'f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0';
        $filters  = [$other => 'd-no.field.......-=-x', strtoupper(self::SLICE) => self::ONLY_B];
        $local    = new AppExport($this->source, ['url' => 'https://source.example/aa/', 'aa-version' => '2026.9'], static fn(): bool => true, true);
        $remote   = new RemoteAppSource($this->remote(), self::TOKEN);

        self::assertSame([self::ITEM_B], $local->itemIds(self::SLICE, false, '', $filters));
        self::assertSame([self::ITEM_B], $remote->itemIds(self::SLICE, false, '', $filters));
        self::assertSame([self::SLICE => self::ONLY_B], $requests[0]['filters']);
        self::assertSame([self::SLICE => 0], $local->leftOut([$other => self::ONLY_B], [self::SLICE], false));
        self::assertSame([self::SLICE => 0], $remote->leftOut([$other => self::ONLY_B], [self::SLICE], false));
        self::assertCount(1, $requests, 'no filter of its slices, no question');
    }

    /** @return array<string, array{Closure(array<string, string>): mixed}> */
    public static function noEcho(): array
    {
        return [
            'an older source'   => [static fn(array $filters): mixed => [self::ITEM_A, self::ITEM_B]],
            'another digest'    => [static fn(array $filters): mixed => ['filters' => ItemFilter::digest([self::SLICE => 'd-headline........-=-A']), 'answer' => [self::ITEM_B]]],
            'no answer'         => [static fn(array $filters): mixed => ['filters' => ItemFilter::digest($filters)]],
            'a digest no text'  => [static fn(array $filters): mixed => ['filters' => 1, 'answer' => [self::ITEM_B]]],
        ];
    }

    /** a source that ignores the filters answers for all the items - every request on its own is refused */
    #[DataProvider('noEcho')]
    public function test_an_answer_without_the_echo_of_the_filters_is_refused(Closure $answer): void
    {
        $filters      = [self::SLICE => self::ONLY_B];
        $this->answer = static fn(AA_Request $r): AA_Response => new AA_Response($answer($filters));

        try {
            (new RemoteAppSource($this->remote(), self::TOKEN))->itemIds(self::SLICE, false, '', $filters);
            self::fail('an answer without the echo');
        } catch (SourceFailed $e) {
            self::assertSame([SourceFailed::FAILED, 'The source AA cannot filter items - update it first'], [$e->reason, $e->getMessage()]);
        }
    }

    public function test_left_out_of_a_source_that_knows_no_such_part(): void
    {
        $this->answer = static fn(AA_Request $r): AA_Response => new AA_Response('Get_App needs part, mode or started of the right form', ResponderPolicy::ERR_APP_REQUEST);

        try {
            (new RemoteAppSource($this->remote(), self::TOKEN))->leftOut([self::SLICE => self::ONLY_B], [self::SLICE], false);
            self::fail('an older source');
        } catch (SourceFailed $e) {
            self::assertSame('The source AA cannot filter items - update it first', $e->getMessage());
        }
    }

    public function test_left_out_without_filters_asks_nothing(): void
    {
        self::assertSame([self::SLICE => 0], (new RemoteAppSource($this->remote(), self::TOKEN))->leftOut([], [self::SLICE], false));
        self::assertSame([], $this->asked);
    }

    /** @return array<string, array{mixed}> */
    public static function foreignLeftOut(): array
    {
        return ['a negative count' => [[self::SLICE => -1]], 'a slice missing' => [[]], 'no map' => ['1']];
    }

    #[DataProvider('foreignLeftOut')]
    public function test_left_out_of_a_foreign_form_is_refused(mixed $counts): void
    {
        $filters      = [self::SLICE => self::ONLY_B];
        $this->answer = static fn(AA_Request $r): AA_Response => new AA_Response(['filters' => ItemFilter::digest($filters), 'answer' => $counts]);

        $this->expectException(SourceFailed::class);
        (new RemoteAppSource($this->remote(), self::TOKEN))->leftOut($filters, [self::SLICE], false);
    }

    /** a source may page on forever (ids ascending, the space is 2^128) - pages beyond its own count are its error */
    public function test_item_id_pages_beyond_the_count_of_the_source_are_refused(): void
    {
        $page   = 0;
        $source = new class ($page) implements AppSource {
            public function __construct(private int &$page) {}

            public function sameAa(): bool
            {
                return false;
            }

            public function itemIds(string $slice, bool $allBins, string $after, array $filters = []): array
            {
                $start = ++$this->page * AppSource::ID_PAGE;
                return array_map(static fn(int $i): string => sprintf('%032x', $start + $i), range(1, AppSource::ID_PAGE));
            }

            public function itemCounts(array $slices, bool $allBins, int $editedAfter = 0, array $filters = []): array
            {
                return array_fill_keys($slices, 3);
            }

            public function definitions(array $modules, bool $transfer, bool $allBins, int $started): array
            {
                throw new \LogicException('not asked');
            }

            public function batch(string $slice, string $after, bool $allBins, int $started, array $filters = [], int $limit = 500): array
            {
                throw new \LogicException('not asked');
            }

            public function logins(array $slices, bool $allBins, array $filters = []): array
            {
                throw new \LogicException('not asked');
            }

            public function leftOut(array $filters, array $slices, bool $allBins): array
            {
                throw new \LogicException('not asked');
            }
        };

        try {
            (new ItemIdCheck($source, new DbImportTarget()))->check([self::SLICE], false);
            self::fail('endless pages');
        } catch (SourceFailed $e) {
            self::assertSame(SourceFailed::FAILED, $e->reason);
        }
        self::assertSame(2, $page, 'ceil(3 / page) + 1 pages at most');
    }
}
