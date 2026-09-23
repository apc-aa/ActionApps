<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA\Manager;
use AA\Manageraction\Central_OptimizeType;
use AA_Actionapps;
use AA_Request;
use AA_Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use zids;

// class declarations only
require_once dirname(__DIR__, 3) . '/central/include/actionapps.class.php';

/** An AA that answers every request with a prepared AA_Response and keeps the requests. */
class CentralOptimizeTypeFakeAa extends AA_Actionapps
{
    /** @var AA_Request[] */
    public array $requests = [];

    public function __construct(private AA_Response $answer, private string $name = 'Remote AA') {}

    public function getResponse($request)
    {
        $this->requests[] = $request;
        return $this->answer;
    }

    public function getName(): string
    {
        return $this->name;
    }
}

/**
 * Central "Update DB (sql_update)" runs the remote AA's sql_update chain through the
 * Do_Optimize_Type responder (the stored Central login), not service/sql_update.php over a URL.
 */
final class CentralOptimizeTypeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // _m(), safe()
    }

    public function test_doOptimizeType_sends_type_and_method(): void
    {
        $aa = new CentralOptimizeTypeFakeAa(new AA_Response('OK <table></table>'));

        $this->assertSame('OK <table></table>', $aa->doOptimizeType('sql_update', 'repair'));
        $this->assertCount(1, $aa->requests);
        $this->assertSame('Do_Optimize_Type', $aa->requests[0]->getCommand());
        $this->assertSame(['type' => 'sql_update', 'method' => 'repair'], $aa->requests[0]->getParameters());
    }

    public function test_doOptimizeType_old_aa_without_the_command_says_update_it(): void
    {
        // responder.php of an AA before Do_Optimize_Type: unknown command
        $aa = new CentralOptimizeTypeFakeAa(new AA_Response('Bad request sent for responder.php - Do_Optimize_Type', 103));

        $report = $aa->doOptimizeType('sql_update', 'test');

        $this->assertStringContainsString('does not support', $report);
        $this->assertStringContainsString('update it first', $report);
    }

    public function test_doOptimize_old_aa_says_update_it_too(): void
    {
        $aa = new CentralOptimizeTypeFakeAa(new AA_Response('Bad request sent for responder.php - Do_Optimize', 103));

        $this->assertStringContainsString('update it first', $aa->doOptimize('AA\\Optimize\\UpdateDbStructure', 'test'));
    }

    public function test_doOptimizeType_no_response_warns_it_may_still_run(): void
    {
        // AA_Request::ask(): timed out or unreadable - the remote run may go on
        $aa = new CentralOptimizeTypeFakeAa(new AA_Response('No response recieved (2 - timeout)', 3));

        $report = $aa->doOptimizeType('sql_update', 'repair');

        $this->assertStringStartsWith('3 No response recieved (2 - timeout)', $report);
        $this->assertStringContainsString('may still be running', $report);
        $this->assertStringContainsString('run Test', $report);
    }

    /** @return array<string, array{AA_Response, string}> */
    public static function errorAnswers(): array
    {
        return [
            'unknown type'     => [new AA_Response('Unknown optimize type: foo', 9), '9 Unknown optimize type: foo'],
            'no permission'    => [new AA_Response('You (x) don\'t have permissions to run Do_Optimize_Type', 101), '101 You (x) don\'t have permissions to run Do_Optimize_Type'],
            'already running'  => [new AA_Response('sql_update is already running on this AA', 11), '11 sql_update is already running on this AA'],
            'non-scalar error' => [new AA_Response(['x'], 5), '5'],
        ];
    }

    #[DataProvider('errorAnswers')]
    public function test_doOptimizeType_other_errors_show_code_and_detail(AA_Response $answer, string $expected): void
    {
        $this->assertSame($expected, (new CentralOptimizeTypeFakeAa($answer))->doOptimizeType('sql_update', 'test'));
    }

    public function test_doOptimizeType_non_scalar_report_does_not_throw(): void
    {
        $report = (new CentralOptimizeTypeFakeAa(new AA_Response(['unexpected'])))->doOptimizeType('sql_update', 'test');

        $this->assertStringContainsString('Unexpected response', $report);
    }

    /** the action with the AAs given instead of read from central_conf
     *  @param array<int|string, AA_Actionapps> $aas  central_conf id => AA */
    private static function action(string $method, array $aas = []): Central_OptimizeType
    {
        return new class ('Sqlupdate_Test', 'sql_update', $method, $aas) extends Central_OptimizeType {
            public function __construct($id, $type, $method, private array $aas)
            {
                parent::__construct($id, $type, $method);
            }

            protected function actionapps($aa_id): AA_Actionapps
            {
                return $this->aas[$aa_id];
            }
        };
    }

    public function test_perform_old_aa_error_is_shown_sandboxed_next_to_the_other_reports(): void
    {
        $new = new CentralOptimizeTypeFakeAa(new AA_Response('OK <table><tr><td>UpdateDbStructure</td></tr></table>'), 'New AA');
        $old = new CentralOptimizeTypeFakeAa(new AA_Response('Bad request sent for responder.php - Do_Optimize_Type', 103), 'Old AA');

        $out = self::action('test', ['11' => $new, '12' => $old])->perform($this->createStub(Manager::class), new zids(['11', '12'], 's'), '');

        $this->assertSame(2, substr_count($out, '<iframe sandbox'));
        $this->assertStringContainsString('<h3>New AA</h3>', $out);
        $this->assertStringContainsString('<h3>Error: Old AA</h3>', $out);
        $this->assertStringContainsString('does not support', $out);
        // the remote report is in the inert srcdoc, never in the page itself
        $this->assertStringNotContainsString('<table>', $out);
        $this->assertStringContainsString('&lt;td&gt;UpdateDbStructure', $out);
    }

    /** @return array<string, array{AA_Response, string}> */
    public static function headings(): array
    {
        return [
            'chain OK'        => [new AA_Response('OK <table></table>'), '<h3>Remote AA</h3>'],
            'chain failed'    => [new AA_Response('Err <table></table>'), '<h3>Error: Remote AA</h3>'],
            'already running' => [new AA_Response('sql_update is already running on this AA', 11), '<h3>Error: Remote AA</h3>'],
            'old AA'          => [new AA_Response('Bad request sent for responder.php - Do_Optimize_Type', 103), '<h3>Error: Remote AA</h3>'],
        ];
    }

    #[DataProvider('headings')]
    public function test_perform_heading_says_error_unless_the_chain_passed(AA_Response $answer, string $heading): void
    {
        $out = self::action('repair', ['11' => new CentralOptimizeTypeFakeAa($answer)])->perform($this->createStub(Manager::class), new zids(['11'], 's'), '');

        $this->assertStringStartsWith($heading, $out);
    }

    public function test_perform_sends_the_actions_method(): void
    {
        $aa = new CentralOptimizeTypeFakeAa(new AA_Response('OK'));

        self::action('repair', ['11' => $aa])->perform($this->createStub(Manager::class), new zids(['11'], 's'), '');

        $this->assertSame(['type' => 'sql_update', 'method' => 'repair'], $aa->requests[0]->getParameters());
    }

    public function test_perform_nothing_selected_is_no_error(): void
    {
        $this->assertFalse(self::action('test')->perform($this->createStub(Manager::class), new zids(), ''));
    }

    public function test_getName_says_test_or_update_as_sql_update_php(): void
    {
        $this->assertStringEndsWith(' - test', (new Central_OptimizeType('Sqlupdate_Test', 'sql_update', 'test'))->getName());
        $this->assertStringEndsWith(' - update', (new Central_OptimizeType('Sqlupdate_Update', 'sql_update', 'repair'))->getName());
        // the translated label of the former Central_Sqlupdate
        $this->assertStringStartsWith(_m('Update DB (sql_update)'), (new Central_OptimizeType('Sqlupdate_Test', 'sql_update', 'test'))->getName());
    }
}
