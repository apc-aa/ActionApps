<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which feeding node admin/getxml.php serves: one named node with its exact password.
 *
 * The script runs on being loaded, so the function is extracted from the real file and evaluated
 * with the nodes table replaced by an array.
 */
final class GetxmlNodeAuthenticatedTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (function_exists('aa_test_NodeAuthenticated')) {
            return;
        }
        $tokens = token_get_all((string) file_get_contents(__DIR__ . '/../../../admin/getxml.php'));
        $code   = '';
        $depth  = 0;
        foreach ($tokens as $i => $token) {
            if (($code === '') && !(is_array($token) && ($token[0] === T_FUNCTION) && (($tokens[$i + 2][1] ?? '') === 'NodeAuthenticated'))) {
                continue;
            }
            $text  = is_array($token) ? $token[1] : $token;
            $code .= $text;
            if ($text === '{') {
                $depth++;
            } elseif (($text === '}') && (--$depth === 0)) {
                break;
            }
        }
        self::assertNotSame('', $code, 'NodeAuthenticated() not found in admin/getxml.php');
        eval('function aa_test_nodes_password($column, $sql, $where) { $GLOBALS["aa_test_nodes_where"] = $where; return $GLOBALS["aa_test_nodes"][$where[0][1]] ?? false; }');
        eval(strtr($code, [
            'function NodeAuthenticated(' => 'function aa_test_NodeAuthenticated(',
            'DB_AA::select1('             => 'aa_test_nodes_password(',
        ]));
    }

    protected function setUp(): void
    {
        $GLOBALS['aa_test_nodes'] = ['Ecn' => 'secret', "Children's Fund" => "it's\\x", 'Open' => ''];
        $GLOBALS['aa_test_nodes_where'] = null;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['aa_test_nodes'], $GLOBALS['aa_test_nodes_where']);
    }

    /** @return array<string, array{mixed, mixed, bool}> */
    public static function requests(): array
    {
        return [
            'the right password'        => ['Ecn', 'secret', true],
            'quotes and a backslash'    => ["Children's Fund", "it's\\x", true],
            'a node without a password' => ['Open', '', true],
            'a wrong password'          => ['Ecn', 'Secret', false],
            'a longer password'         => ['Ecn', 'secret ', false],
            'an unknown node'           => ['Nobody', '', false],
            'no node'                   => ['', '', false],
            'a list of passwords'       => ['Ecn', ['x', 'secret'], false],
            'a list of nodes'           => [['Nobody', 'Ecn'], 'secret', false],
        ];
    }

    #[DataProvider('requests')]
    public function test_NodeAuthenticated(mixed $node_name, mixed $password, bool $expected): void
    {
        $this->assertSame($expected, aa_test_NodeAuthenticated($node_name, $password));
    }

    public function test_NodeAuthenticated_looks_the_node_up_by_its_name_only(): void
    {
        aa_test_NodeAuthenticated('Ecn', 'secret');

        $this->assertSame([['name', 'Ecn']], $GLOBALS['aa_test_nodes_where'], 'the password is compared in PHP, not in the query');
    }
}
