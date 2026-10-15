<?php

declare(strict_types=1);

namespace Tests\Unit;

use AA\FormArray;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Formatted_By_Widget responder takes a form id from an anonymous request and writes it into
 * HTML attributes and onclick JS, so it accepts only what formName2Id(getName4Form()) can build.
 */
final class FormArrayTest extends TestCase
{
    #[DataProvider('formIds')]
    public function test_isFormId_idsAaBuilds(string $id): void
    {
        self::assertTrue(FormArray::isFormId($id));
    }

    #[DataProvider('foreignIds')]
    public function test_isFormId_markupAndScript(string $id): void
    {
        self::assertFalse(FormArray::isFormId($id));
    }

    public static function formIds(): array
    {
        return [
            'item'            => [FormArray::formName2Id('aa[ud359b5b4e82a6cd9e40750ca6771b077][relation_______4]')],
            'new item'        => [FormArray::formName2Id('aa[n1_d359b5b4e82a6cd9e40750ca6771b077][post_date______1]')],
            'universal'       => [FormArray::formName2Id('aa[ud359b5b4e82a6cd9e40750ca6771b077][headline________]', true)],
            'urlencoded oid'  => [FormArray::formName2Id('aa[u' . rawurlencode('a.b~c d') . '][x]')],
        ];
    }

    public static function foreignIds(): array
    {
        return [
            'empty'      => [''],
            'quote'      => ['aa-x" onmouseover="alert(1)'],
            'apostrophe' => ["aa-x');alert(1);('"],
            'tag'        => ['aa-x><script>'],
            'newline'    => ["aa-x\n"],
            'space'      => ['aa-x y'],
        ];
    }
}
