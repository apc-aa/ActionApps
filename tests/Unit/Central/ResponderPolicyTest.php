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
            'Do_Import_Module_Chunk' => ['Do_Import_Module_Chunk', true, false],
            'Get_Widget'             => ['Get_Widget', false, false],
            'Tags'                   => ['Tags', false, false],
            'Widget_Selection'       => ['Widget_Selection', false, false],
            'Formatted_By_Widget'    => ['Formatted_By_Widget', false, false],
            'Get_Fields'             => ['Get_Fields', false, false],
            'Item_History_Diff'      => ['Item_History_Diff', false, false],
            'Optimize4id'            => ['Optimize4id', false, true],
            'Alertitem'              => ['Alertitem', false, true],
            'unknown'                => ['Foo_Bar', false, false],
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
}
