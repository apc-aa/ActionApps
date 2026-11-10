<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use AA\Util\Paramwizard;
use AA\Widget\EdtWidget;
use AA\Widget\SelWidget;
use PHPUnit\Framework\TestCase;

/**
 * Definitions of types and their parameters the field page sends to <aa-params> as one script.
 */
final class ParamwizardTest extends TestCase
{
    private const FIXTURE = ['name' => 'x', 'hint' => 'H', 'items' => ['a' => ['name' => 'A', 'desc' => 'D']]];

    /** @var array<string, mixed> globals the tests replace, as they were */
    private array $saved = [];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
    }

    protected function setUp(): void
    {
        foreach (['TRANS_ACTIONS', 'VALIDATE_TYPES'] as $name) {
            if (array_key_exists($name, $GLOBALS)) {
                $this->saved[$name] = $GLOBALS[$name];
            }
        }
    }

    protected function tearDown(): void
    {
        foreach (['TRANS_ACTIONS', 'VALIDATE_TYPES'] as $name) {
            if (array_key_exists($name, $this->saved)) {
                $GLOBALS[$name] = $this->saved[$name];
            } else {
                unset($GLOBALS[$name]);
            }
        }
    }

    public function test_fromClasses_describesParameters(): void
    {
        $items = Paramwizard::fromClasses(['sel' => SelWidget::class, 'edt' => EdtWidget::class]);

        $this->assertSame(array_keys(SelWidget::getClassProperties()), array_column($items['sel']['params'], 'id'));
        $types = array_column($items['sel']['params'], 'type', 'id');
        $this->assertSame('string', $types['slice_field']);
        $this->assertSame('text', $types['const_arr']);
        $this->assertSame('bool', $types['use_name']);
        $this->assertSame('int', $types['bin_filter']);

        $area = array_column($items['edt']['params'], null, 'id')['area_type'];
        $this->assertSame('enum', $area['type']);
        $this->assertSame(['class' => 'class', 'iframe' => 'iframe'], $area['options']);

        foreach ([...$items['sel']['params'], ...$items['edt']['params']] as $param) {
            foreach (['id', 'name', 'type', 'help', 'example'] as $key) {
                $this->assertArrayHasKey($key, $param);
            }
        }
    }

    /** The form fields take plain values; the examples are written as in the stored string. */
    public function test_fromClasses_examplesArePlainValues(): void
    {
        $params = array_column(Paramwizard::fromClasses(['sel' => SelWidget::class])['sel']['params'], null, 'id');

        $this->assertSame('{"1":"Yes","0":"No"}', $params['const_arr']['example']);
    }

    /** The validators read the string by their own properties, the old array has them in another order. */
    public function test_definitions_validateTypesTakeParametersFromTheValidators(): void
    {
        $GLOBALS['VALIDATE_TYPES'] = ['name' => 'v', 'items' => [
            'regexp' => ['name' => 'R', 'desc' => 'D', 'params' => [['name' => 'Regular Expressions'], ['name' => 'Error text']]],
            'e-mail' => ['name' => 'E'],
            'xyz'    => ['name' => 'X', 'params' => [['name' => 'p']]],
        ]];

        $items = Paramwizard::definitions('VALIDATE_TYPES')['items'];

        $this->assertSame(['pattern', 'empty_expression', 'maxlength'], array_column($items['regexp']['params'], 'id'));
        $this->assertSame('D', $items['regexp']['desc']);
        $this->assertSame(array_keys(\AA_Validate_Email::getClassProperties()), array_column($items['e-mail']['params'], 'id'));
        $this->assertSame(['p'], array_column($items['xyz']['params'], 'id'));
    }

    public function test_fromArray_mapsNamesTypesAndEscapes(): void
    {
        $items = Paramwizard::fromArray([
            'name'  => 'Fn',
            'items' => [
                'f_a' => [
                    'name'     => 'abstract',
                    'desc'     => 'a \\<BR\\> b',
                    'params'   => [
                        ['name' => 'length', 'desc' => 'd', 'type' => 'INT', 'example' => '80'],
                        ['name' => 'paragraph', 'type' => 'BOOL'],
                        ['name' => 'x', 'type' => 'STRID'],
                    ],
                    'examples' => [['desc' => 'e', 'params' => '80:x']],
                ],
                'f_0' => ['name' => 'null'],
            ],
        ]);

        $this->assertSame('a &lt;BR&gt; b', $items['f_a']['desc']);
        $this->assertSame(['id' => 'length', 'name' => 'length', 'type' => 'int', 'help' => 'd', 'example' => '80'], $items['f_a']['params'][0]);
        $this->assertSame('bool', $items['f_a']['params'][1]['type']);
        $this->assertSame('string', $items['f_a']['params'][2]['type']);
        $this->assertSame('80:x', $items['f_a']['examples'][0]['params']);
        $this->assertSame(['desc' => '', 'params' => []], $items['f_0']);
    }

    public function test_definitions_readsTheLegacyArrayWithItsHint(): void
    {
        $GLOBALS['TRANS_ACTIONS'] = self::FIXTURE;

        $this->assertSame(['items' => ['a' => ['desc' => 'D', 'params' => []]], 'hint' => 'H'], Paramwizard::definitions('TRANS_ACTIONS'));
    }

    public function test_script_isScriptSafe(): void
    {
        $desc                     = "</script><b>'\"&ř";
        $GLOBALS['TRANS_ACTIONS'] = self::FIXTURE;
        $GLOBALS['TRANS_ACTIONS']['items']['a']['desc'] = $desc;

        $script = Paramwizard::script(['TRANS_ACTIONS']);

        $this->assertStringStartsWith('window.AA_PARAMS = ', $script);
        $this->assertStringEndsWith(';', $script);
        $this->assertStringNotContainsString('</script', $script);
        $this->assertStringNotContainsString('<', $script);

        $data = json_decode(substr($script, strlen('window.AA_PARAMS = '), -1), true);
        $this->assertSame($desc, $data['lists']['TRANS_ACTIONS']['items']['a']['desc']);
        $this->assertSame('Parameters', $data['labels']['params']);
    }
}
