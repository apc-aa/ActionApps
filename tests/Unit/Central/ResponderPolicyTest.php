<?php

declare(strict_types=1);

namespace Tests\Unit\Central;

use AA\Central\ResponderPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResponderPolicyTest extends TestCase
{
    /** [name, isRemoteCommand, isPostOnlyCommand]
     *  @return array<string, array{string, bool, bool}> */
    public static function commands(): array
    {
        return [
            'Get_Sessionid'          => ['Get_Sessionid', true, false],
            'Get_Jwt'                => ['Get_Jwt', true, false],
            'Logout'                 => ['Logout', true, false],
            'Get_Aaname'             => ['Get_Aaname', true, false],
            'Get_Modules'            => ['Get_Modules', true, false],
            'Get_Module_Defs'        => ['Get_Module_Defs', true, false],
            'Do_Synchronize'         => ['Do_Synchronize', true, false],
            'Do_Optimize'            => ['Do_Optimize', true, false],
            'Do_Optimize_Type'       => ['Do_Optimize_Type', true, false],
            'Get_App'                => ['Get_App', true, false],
            'Get_Widget'             => ['Get_Widget', false, false],
            'Tags'                   => ['Tags', false, false],
            'Widget_Selection'       => ['Widget_Selection', false, false],
            'Formatted_By_Widget'    => ['Formatted_By_Widget', false, false],
            'Get_Fields'             => ['Get_Fields', false, false],
            'Item_History_Diff'      => ['Item_History_Diff', false, false],
            'Optimize4id'            => ['Optimize4id', false, true],
            'Alertitem'              => ['Alertitem', false, true],
            'unknown'                => ['Foo_Bar', false, false],
            'removed'                => ['Do_Import_Module_Chunk', false, false],
            'empty'                  => ['', false, false],
        ];
    }

    #[DataProvider('commands')]
    public function test_isRemoteCommand_matches_table(string $name, bool $remote, bool $post_only): void
    {
        $this->assertSame($remote, ResponderPolicy::isRemoteCommand($name));
    }

    #[DataProvider('commands')]
    public function test_isPostOnlyCommand_matches_table(string $name, bool $remote, bool $post_only): void
    {
        $this->assertSame($post_only, ResponderPolicy::isPostOnlyCommand($name));
    }

    /** [type, method, expected error code or null]
     *  @return array<string, array{string, string, ?int}> */
    public static function optimizeTypeRequests(): array
    {
        return [
            'sql_update test'        => ['sql_update', 'test', null],
            'sql_update repair'      => ['sql_update', 'repair', null],
            'unknown type'           => ['foo', 'test', 9],
            'empty type'             => ['', 'test', 9],
            'type other case'        => ['SQL_UPDATE', 'test', 9],
            'check type, no chain'   => ['check_basic', 'test', 9],
            'site specific fixes'    => ['private_fix', 'repair', 9],
            'optional features'      => ['opt_feature', 'repair', 9],
            'method revert'          => ['sql_update', 'revert', 10],
            'method repair4ids'      => ['sql_update', 'repair4ids', 10],
            'method sql_update name' => ['sql_update', 'update', 10],
            'empty method'           => ['sql_update', '', 10],
            'both unknown, type 1st' => ['foo', 'bar', 9],
        ];
    }

    #[DataProvider('optimizeTypeRequests')]
    public function test_optimizeTypeRefusal_allows_only_known_type_and_method(string $type, string $method, ?int $code): void
    {
        $refusal = ResponderPolicy::optimizeTypeRefusal($type, $method);
        if ($code === null) {
            $this->assertNull($refusal);
            return;
        }
        $this->assertNotNull($refusal);
        [$message, $error] = $refusal;
        $this->assertSame($code, $error);
        $this->assertNotSame('', $message);
    }

    public function test_unknown_command_code_is_what_every_older_aa_sends(): void
    {
        // Central tells "update it first" by this code, also from AAs that predate the constant
        $this->assertSame(103, ResponderPolicy::ERR_UNKNOWN_COMMAND);
    }

    public function test_unknown_item_code_is_not_taken_by_other_responders(): void
    {
        // responder.php uses 1-11 and 101-106; live editing shows "Error <code>: ..." in place of the widget
        $this->assertSame(12, ResponderPolicy::ERR_UNKNOWN_ITEM);
    }

    /** @return array<string, array{array<string, string>, bool}> */
    public static function browserRequests(): array
    {
        $host = ['HTTP_HOST' => 'toolkit.ecn.cz'];
        return [
            'no headers (server-to-server)' => [$host, false],
            'sec-fetch same-origin'         => [$host + ['HTTP_SEC_FETCH_SITE' => 'same-origin'], false],
            'sec-fetch none (typed URL)'    => [$host + ['HTTP_SEC_FETCH_SITE' => 'none'], false],
            'sec-fetch same-site'           => [$host + ['HTTP_SEC_FETCH_SITE' => 'same-site'], true],
            'sec-fetch cross-site'          => [$host + ['HTTP_SEC_FETCH_SITE' => 'cross-site'], true],
            'sec-fetch wins over origin'    => [$host + ['HTTP_SEC_FETCH_SITE' => 'same-site', 'HTTP_ORIGIN' => 'https://toolkit.ecn.cz'], true],
            'origin equal host'             => [$host + ['HTTP_ORIGIN' => 'https://toolkit.ecn.cz'], false],
            'origin equal, other case'      => [$host + ['HTTP_ORIGIN' => 'https://Toolkit.ECN.cz'], false],
            'origin sibling subdomain'      => [$host + ['HTTP_ORIGIN' => 'https://geoinformace.ecn.cz'], true],
            'origin different port'         => [$host + ['HTTP_ORIGIN' => 'https://toolkit.ecn.cz:8443'], true],
            'origin same port'              => [['HTTP_HOST' => 'toolkit.ecn.cz:8443', 'HTTP_ORIGIN' => 'https://toolkit.ecn.cz:8443'], false],
            'origin null'                   => [$host + ['HTTP_ORIGIN' => 'null'], true],
            'origin without HTTP_HOST'      => [['HTTP_ORIGIN' => 'https://toolkit.ecn.cz'], true],
        ];
    }

    #[DataProvider('browserRequests')]
    public function test_isForeignBrowserRequest_matches_table(array $server, bool $expected): void
    {
        $this->assertSame($expected, ResponderPolicy::isForeignBrowserRequest($server));
    }

    /** @return array<string, array{string, ?string}> */
    public static function moduleTypes(): array
    {
        return [
            'Slice'     => ['Slice', 'AA_Definition_Module_Slice'],
            'Site'      => ['Site', 'AA_Definition_Module_Site'],
            'Alerts'    => ['Alerts', 'AA_Definition_Module_Alerts'],
            'unknown'   => ['Foo', null],
            'traversal' => ['../x', null],
            'empty'     => ['', null],
            'Chunk'     => ['Chunk', null],
        ];
    }

    #[DataProvider('moduleTypes')]
    public function test_moduleDefinitionClass_maps_only_known_types(string $type, ?string $expected): void
    {
        $this->assertSame($expected, ResponderPolicy::moduleDefinitionClass($type));
    }

    public function test_syncAllowedClasses_keeps_real_action_and_neutralises_foreign_object(): void
    {
        // class declarations only - no side effect on load
        require_once dirname(__DIR__, 3) . '/central/include/actionapps.class.php';

        $payload = serialize(new \AA_Sync_Action('UPDATE_VALUE', new \AA_Identifier('abc', 'field', 'x', 'name'), ['k' => new \ArrayObject()]));
        $cmd     = unserialize($payload, ['allowed_classes' => ResponderPolicy::syncAllowedClasses()]);

        $this->assertInstanceOf(\AA_Sync_Action::class, $cmd);
        $identifier = (new \ReflectionProperty($cmd, 'identifier'))->getValue($cmd);
        $this->assertSame('abc->field->x->name', $identifier->toString());
        $params = (new \ReflectionProperty($cmd, 'params'))->getValue($cmd);
        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $params['k']);
    }

    /** @return array<string, array{string, string, bool}> scope, command, allowed */
    public static function scopes(): array
    {
        return [
            'admin: anything'          => ['admin', 'Do_Optimize', true],
            'admin: Get_App'           => ['admin', 'Get_App', true],
            'apps: Get_App'            => ['apps', 'Get_App', true],
            'apps: Get_Modules'        => ['apps', 'Get_Modules', true],
            'apps: Do_Optimize'        => ['apps', 'Do_Optimize', false],
            'apps: Do_Synchronize'     => ['apps', 'Do_Synchronize', false],
            'apps: Get_Jwt'            => ['apps', 'Get_Jwt', false],
            'apps: an AJAX command'    => ['apps', 'Get_Widget', false],
            'apps: a case variant'     => ['apps', 'get_app', false],
            'an unknown scope'         => ['root', 'Get_App', false],
        ];
    }

    #[DataProvider('scopes')]
    public function test_a_token_scope_allows_its_commands_only(string $scope, string $name, bool $allowed): void
    {
        $this->assertSame($allowed, ResponderPolicy::scopeAllows($scope, $name));
    }

    /** @return array<string, array{mixed, ?array{ttl: ?int, scope: string}}> */
    public static function jwtParams(): array
    {
        return [
            'none'                => [null, ['ttl' => null, 'scope' => 'admin']],
            'empty'               => [[], ['ttl' => null, 'scope' => 'admin']],
            'ttl int'             => [['ttl' => 3600], ['ttl' => 3600, 'scope' => 'admin']],
            'ttl string'          => [['ttl' => '86400'], ['ttl' => 86400, 'scope' => 'admin']],
            'ttl negative'        => [['ttl' => -5], ['ttl' => -5, 'scope' => 'admin']],
            'ttl negative string' => [['ttl' => '-5'], ['ttl' => -5, 'scope' => 'admin']],
            'ttl word'            => [['ttl' => 'day'], null],
            'ttl float'           => [['ttl' => 1.5], null],
            'ttl array'           => [['ttl' => [1]], null],
            'scope apps'          => [['ttl' => 86400, 'scope' => 'apps'], ['ttl' => 86400, 'scope' => 'apps']],
            'scope admin'         => [['scope' => 'admin'], ['ttl' => null, 'scope' => 'admin']],
            'scope unknown'       => [['scope' => 'root'], null],
            'scope array'         => [['scope' => ['apps']], null],
        ];
    }

    /** a ttl or scope the AA cannot read is an error - never a silent 1-year admin token */
    #[DataProvider('jwtParams')]
    public function test_get_jwt_reads_ttl_and_scope(mixed $param, ?array $expected): void
    {
        $this->assertSame($expected, ResponderPolicy::jwtParams($param));
    }
}
