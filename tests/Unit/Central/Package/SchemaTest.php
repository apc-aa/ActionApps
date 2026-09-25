<?php

declare(strict_types=1);

namespace Tests\Unit\Central\Package;

use AA\Central\Package\Schema;
use AA_Metabase;
use DOMDocument;
use DOMElement;
use DOMXPath;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase
{
    private const XS = 'http://www.w3.org/2001/XMLSchema';

    /** a valid package */
    private static function example(): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/aa-app-example.xml');
    }

    /** Validates as Reader does: schema as a string, no external entity may load.
     *  @return list<string> libxml errors, [] = valid */
    private static function validate(string $xml, string $xsd): array
    {
        $errors = libxml_use_internal_errors(true);
        libxml_set_external_entity_loader(static fn() => null);
        libxml_clear_errors();
        try {
            $dom = new DOMDocument();
            $ok  = $dom->loadXML($xml, LIBXML_NONET) && $dom->schemaValidateSource($xsd);
            $out = array_map(static fn($e) => trim($e->message), libxml_get_errors());
            return $ok ? [] : ($out ?: ['invalid']);
        } finally {
            libxml_clear_errors();
            libxml_set_external_entity_loader(null);
            libxml_use_internal_errors($errors);
        }
    }

    /** @param callable(array): array $edit changes the metabase_schema.php array */
    private static function schemaWith(callable $edit): Schema
    {
        return new Schema(AA_Metabase::fromArray($edit(AA_Metabase::singleton()->toArray())));
    }

    /** the schema with extra columns in `view` and `field` - as a newer AA would have it */
    private static function schemaWithNewColumns(): Schema
    {
        $tables                              = AA_Metabase::singleton()->toArray();
        $tables['view']['c']['new_template'] = ['new_template', 'longtext', true, '', null, '', ''];
        $tables['field']['c']['new_level']   = ['new_level', 'smallint(5)', true, '', null, '', ''];
        return new Schema(AA_Metabase::fromArray($tables));
    }

    public function test_xsd_equals_the_repository_copy(): void
    {
        $this->assertSame((string) file_get_contents(Schema::FILE), (new Schema())->xsd(), 'The AA schema changed - regenerate: php dev/central/aa-app-xsd.php');
    }

    public function test_example_package_is_valid(): void
    {
        $this->assertSame([], self::validate(self::example(), (new Schema())->xsd()));
    }

    /** One edit of the valid example each - the package must stop validating, for the right reason.
     *  @return array<string, array{string, string, string}> [search (exactly once in the example), replacement, part of the error] */
    public static function invalidPackages(): array
    {
        $collection = 'collection="AbC12" filter="3"';
        $filter     = '<alerts_collection_filter collection="AbC12" filter="3"/>';
        $howoften   = '<alerts_collection_howoften collection="AbC12" howoften="daily"/>';
        $leaf_spot  = '<spot id="2" name="clanek" parent="1"/>';
        return [
            'duplicate view id'                  => ['<view id="13">', '<view id="12">', 'uniqueView'],
            'reading_password column'            => ['<type>S</type>', '<type>S</type><reading_password>x</reading_password>', 'reading_password'],
            'AUTO_INCREMENT id column'           => ['<name>Detail</name>', '<name>Detail</name><id>13</id>', '}id'],
            'module column'                      => ['<name>Rubrika</name>', '<name>Rubrika</name><slice_id>0f1e2d3c4b5a69788796a5b4c3d2e1f0</slice_id>', 'slice_id'],
            'installation column'                => ['<lang_file>cz-utf8_news_lang.php</lang_file>', '<lang_file>cz-utf8_news_lang.php</lang_file><created_by>1</created_by>', 'created_by'],
            'AA_Plannedtask object'              => ['class="AA_Aliasfunc"', 'class="AA_Plannedtask"', "}object', attribute 'class'"],
            'Modulesettings in <object>'         => ['class="AA_Aliasfunc"', 'class="AA_Modulesettings_Site"', "}object', attribute 'class'"],
            'subobject class at the top'         => ['class="AA_Aliasfunc"', 'class="AA_Condition"', "}object', attribute 'class'"],
            'site settings in a slice module'    => ['<settings class="AA_Modulesettings_Slice">', '<settings class="AA_Modulesettings_Site">', "}settings', attribute 'class'"],
            'duplicate property index'           => ['<p n="page404">2</p>', '<p n="page404">2</p><p n="page404" i="0">3</p>', 'uniqueSiteSettingsProp'],
            'duplicate constant id across groups' => ['</constant-group>', '</constant-group><constant-group name="ct_jina"><constant id="aa11bb22cc33dd44ee55ff6677889900"><name>X</name></constant></constant-group>', 'uniqueConstant'],
            'duplicate uses'                     => ['<uses module="0f1e2d3c4b5a69788796a5b4c3d2e1f0"/>', '<uses module="0f1e2d3c4b5a69788796a5b4c3d2e1f0"/><uses module="0f1e2d3c4b5a69788796a5b4c3d2e1f0"/>', 'uniqueUses'],
            'collection filter, no collection'   => [$collection, 'collection="XyZ98" filter="3"', 'refCollectionFilter'],
            'collection filter, no filter'       => [$collection, 'collection="AbC12" filter="4"', 'refFilter'],
            'two alerts collections'             => ['<alerts_collection_filter ', '<alerts_collection id="XyZ98"/><alerts_collection_filter ', '}alerts_collection'],
            'site table in a slice module'       => ['<view id="12">', '<site_spot spot_id="9"/><view id="12">', 'site_spot'],
            'view in an alerts module'           => ['<email id="7">', '<view id="99"/><email id="7">', '}view'],
            'duplicate collection filter'        => [$filter, $filter . $filter, 'uniqueCollectionFilter'],
            'duplicate collection howoften'      => [$howoften, $howoften . $howoften, 'uniqueCollectionHowoften'],
            'collection id in two modules'       => ['</alerts-module>', '</alerts-module><alerts-module id="6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b"><module><name>B</name></module><alerts_collection id="AbC12"/></alerts-module>', 'uniqueCollectionId'],
            'duplicate spot id'                  => [$leaf_spot, '<spot id="3" name="clanek" parent="1"/>', 'keySpot'],
            'spot parent missing'                => [$leaf_spot, '<spot id="2" name="clanek" parent="9"/>', 'refSpotParent'],
            'start spot missing'                 => ['<spot-tree start="1">', '<spot-tree start="9">', 'refSpotStart'],
            'property and subobject clash'       => ['<p n="slices">0f1e2d3c4b5a69788796a5b4c3d2e1f0</p>', '<p n="slices">0f1e2d3c4b5a69788796a5b4c3d2e1f0</p><p n="conds">x</p>', 'uniqueSliceObjectProp'],
            'format 2'                           => ['format="1.0"', 'format="2.0"', "attribute 'format'"],
        ];
    }

    #[DataProvider('invalidPackages')]
    public function test_invalid_package_is_rejected(string $search, string $replace, string $error): void
    {
        $this->assertSame(1, substr_count(self::example(), $search), 'the edit must hit the example exactly once');
        $this->assertStringContainsString($error, implode("\n", self::validate(str_replace($search, $replace, self::example()), (new Schema())->xsd())));
    }

    /** @return array<string, array{string, string}> edits that keep the package valid */
    public static function validEdits(): array
    {
        return [
            'router expression in state_file' => ['<state_file>/cz/home</state_file>', '<state_file>{ifin:{server:SERVER_NAME}:czechweb.cz:/cz/home:/en/home}</state_file>'],
            'base64 value'                    => ['<name>Seznam článků</name>', '<name enc="base64">U2V6bmFt</name>'],
            'empty spot arrays'               => ['<spot id="2" name="clanek" parent="1"/>', '<spot id="2" name="clanek" parent="1" empty="v c"/>'],
            'minor version 1.1'               => ['format="1.0"', 'format="1.1"'],
        ];
    }

    #[DataProvider('validEdits')]
    public function test_valid_edit_is_accepted(string $search, string $replace): void
    {
        $this->assertSame(1, substr_count(self::example(), $search));
        $this->assertSame([], self::validate(str_replace($search, $replace, self::example()), (new Schema())->xsd()));
    }

    /** Every element the XSD declares, with its child elements (declarations of one name merged) -
     *  what knownElements() must say.
     *  @return array<string, list<string>> */
    private static function xsdChildren(string $xsd): array
    {
        $dom = new DOMDocument();
        $dom->loadXML($xsd);
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('xs', self::XS);
        $collect = static function (DOMElement $node, array &$names) use (&$collect): void {
            foreach ($node->childNodes as $child) {
                if (!$child instanceof DOMElement) {
                    continue;
                }
                if ($child->localName === 'element') {
                    $names[] = $child->getAttribute('name');
                } else {
                    $collect($child, $names);
                }
            }
        };
        $children = [];
        foreach ($xp->query('//xs:element[@name]') as $element) {
            assert($element instanceof DOMElement);
            $type  = $element->getAttribute('type');
            $types = $type === '' ? $xp->query('xs:complexType', $element) : $xp->query("/xs:schema/xs:complexType[@name='$type']");
            $names = [];
            foreach ($types as $ct) {
                assert($ct instanceof DOMElement);
                $collect($ct, $names);
            }
            $name            = $element->getAttribute('name');
            $children[$name] = array_values(array_unique(array_merge($children[$name] ?? [], $names)));
        }
        return array_filter($children);
    }

    public function test_knownElements_cover_exactly_what_the_xsd_declares(): void
    {
        $schema = new Schema();
        $known  = $schema->knownElements();
        foreach (self::xsdChildren($schema->xsd()) as $parent => $children) {
            $this->assertArrayHasKey($parent, $known);
            $this->assertEqualsCanonicalizing($children, $known[$parent], "children of <$parent>");
            unset($known[$parent]);
        }
        // left over: rows whose every column is an attribute
        $this->assertSame([], array_filter($known), 'knownElements() lists elements the XSD does not declare');
        $this->assertSame([], array_diff_key($known, $schema->rowTables()));
    }

    public function test_knownElements_rows_follow_the_metabase_order(): void
    {
        $columns = AA_Metabase::singleton()->getColumnNames('view');
        $this->assertSame(array_values(array_diff($columns, ['id', 'slice_id'])), (new Schema())->knownElements()['view']);
    }

    /** @return array<string, array{string, string}> [element, column] */
    public static function scrubbedColumns(): array
    {
        $rows = [];
        // columns a package leaves out; slice.export_to_all is a feeding permission
        foreach (['id', 'type', 'owner', 'created_by', 'created_at', 'slice_url', 'deleted', 'priority', 'app_id'] as $column) {
            $rows["module $column"] = ['module', $column];
        }
        foreach (['id', 'owner', 'created_by', 'created_at', 'slice_url', 'deleted', 'fileman_dir', 'reading_password', 'export_to_all'] as $column) {
            $rows["slice $column"] = ['slice', $column];
        }
        return $rows + [
            'view AUTO_INCREMENT id' => ['view', 'id'],
            'view slice_id'          => ['view', 'slice_id'],
            'field slice_id'         => ['field', 'slice_id'],
            'email owner'            => ['email', 'owner_module_id'],
            'email id (attribute)'   => ['email', 'id'],
            'profile uid'            => ['profile', 'uid'],
            'profile id'             => ['profile', 'id'],
            'constant short_id'      => ['constant', 'short_id'],
            'constant group'         => ['constant', 'group_id'],
            'constant_slice group'   => ['constant_slice', 'group_id'],
            'site id'                => ['site', 'id'],
            'site structure'         => ['site', 'structure'],
            'site_spot id'           => ['site_spot', 'id'],
            'site_spot site'         => ['site_spot', 'site_id'],
            'collection module'      => ['alerts_collection', 'module_id'],
            'howoften last'          => ['alerts_collection_howoften', 'last'],
            'relation flag'          => ['uses', 'flag'],
            'relation source'        => ['uses', 'source_id'],
            'relation destination'   => ['uses', 'destination_id'],
            'collection id (attr)'   => ['alerts_collection', 'id'],
            'filter id (attr)'       => ['alerts_filter', 'id'],
            'constant id (attr)'     => ['constant', 'id'],
            'field id (attr)'        => ['field', 'id'],
            'spot id (attr)'         => ['site_spot', 'spot_id'],
        ];
    }

    #[DataProvider('scrubbedColumns')]
    public function test_scrubbed_column_is_not_known(string $element, string $column): void
    {
        $schema = new Schema();
        $this->assertTrue(AA_Metabase::singleton()->isColumn($schema->rowTables()[$element], $column), 'the column exists in the AA schema');
        $this->assertNotContains($column, $schema->knownElements()[$element]);
    }

    public function test_rowTables_name_the_table_of_each_row_element(): void
    {
        $tables = (new Schema())->rowTables();
        $this->assertSame('relation', $tables['uses']);
        $this->assertSame('view', $tables['view']);
        $this->assertArrayNotHasKey('slice-module', $tables);
    }

    public function test_lengthLimits_of_the_columns(): void
    {
        $schema = new Schema();
        $field  = $schema->lengthLimits('field');
        $this->assertSame([255, true], $field['name'], 'varchar(255): characters');
        $this->assertSame([16, false], $field['type'], 'varbinary(16): bytes');
        $this->assertSame([10, true], $field['alias1'], 'varchar(10)');
        $this->assertSame([16777215, false], $field['input_show_func'], 'mediumtext: bytes');
        $this->assertArrayNotHasKey('input_pri', $field, 'no limit for an integer');
        $this->assertSame([100, true], $schema->lengthLimits('module')['name'], 'char(100)');
        $this->assertSame([4000, false], $schema->lengthLimits('email')['attachments']);
        $this->assertArrayNotHasKey('structure', $schema->lengthLimits('site'), 'longblob');
        $this->assertSame([255, true], $schema->lengthLimits('constant')['value'], 'char(255)');
        $this->assertSame([320, false], $schema->lengthLimits('constant')['ancestors'], 'packed varbinary(160): 320 hex digits');
        $this->assertSame([32, false], $schema->lengthLimits('alerts_collection')['slice_id'], 'packed varbinary(16)');
    }

    public function test_new_metabase_column_appears_in_xsd_and_knownElements(): void
    {
        $schema = self::schemaWithNewColumns();
        $xsd    = $schema->xsd();

        $this->assertContains('new_template', $schema->knownElements()['view']);
        $this->assertContains('new_level', $schema->knownElements()['field']);
        $this->assertStringContainsString('<xs:element name="new_template" type="textValue" minOccurs="0"/>', $xsd);
        $this->assertStringContainsString('<xs:element name="new_level" type="intValue" minOccurs="0"/>', $xsd);

        $package = str_replace('<name>Detail</name>', '<name>Detail</name><new_template>x</new_template>', self::example());
        $this->assertSame([], self::validate($package, $xsd));
        $this->assertNotSame([], self::validate($package, (new Schema())->xsd()), 'this AA does not know the column');
    }

    public function test_left_out_column_missing_in_the_metabase_throws(): void
    {
        $schema = self::schemaWith(static function (array $tables): array {
            unset($tables['slice']['c']['reading_password']);
            return $tables;
        });
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('slice.reading_password');
        $schema->xsd();
    }

    /** @return array<string, array{string}> tables of installation state - every column classified by hand */
    public static function installationTables(): array
    {
        return ['module' => ['module'], 'slice' => ['slice'], 'site' => ['site'], 'alerts_collection' => ['alerts_collection']];
    }

    #[DataProvider('installationTables')]
    public function test_new_column_in_an_installation_table_throws(string $table): void
    {
        $schema = self::schemaWith(static function (array $tables) use ($table): array {
            $tables[$table]['c']['new_secret'] = ['new_secret', 'varchar(100)', false, '', '', '', ''];
            return $tables;
        });
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("$table.new_secret");
        $schema->knownElements();
    }

    /** Every element the XSD declares, with its attribute names (declarations of one name merged) -
     *  what knownAttributes() must say.
     *  @return array<string, list<string>> */
    private static function xsdAttributes(string $xsd): array
    {
        $dom = new DOMDocument();
        $dom->loadXML($xsd);
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('xs', self::XS);
        $collect = static function (DOMElement $node, array &$names) use (&$collect, $xp): void {
            foreach ($node->childNodes as $child) {
                if (!$child instanceof DOMElement || $child->localName === 'element') {
                    continue;
                }
                if ($child->localName === 'attribute') {
                    $names[] = $child->getAttribute('name');
                } elseif ($child->localName === 'attributeGroup') {
                    $ref = $child->getAttribute('ref');
                    foreach ($xp->query("/xs:schema/xs:attributeGroup[@name='$ref']") as $group) {
                        assert($group instanceof DOMElement);
                        $collect($group, $names);
                    }
                } else {
                    $collect($child, $names);
                }
            }
        };
        $attributes = [];
        foreach ($xp->query('//xs:element[@name]') as $element) {
            assert($element instanceof DOMElement);
            $type  = $element->getAttribute('type');
            $types = $type === '' ? $xp->query('xs:complexType', $element) : $xp->query("/xs:schema/xs:complexType[@name='$type']");
            $names = [];
            foreach ($types as $ct) {
                assert($ct instanceof DOMElement);
                $collect($ct, $names);
            }
            $name              = $element->getAttribute('name');
            $attributes[$name] = array_values(array_unique(array_merge($attributes[$name] ?? [], $names)));
        }
        return array_filter($attributes);
    }

    public function test_knownAttributes_cover_exactly_what_the_xsd_declares(): void
    {
        $schema = new Schema();
        $known  = $schema->knownAttributes();
        $xsd    = self::xsdAttributes($schema->xsd());
        ksort($known);
        ksort($xsd);
        $this->assertEqualsCanonicalizing(array_keys($xsd), array_keys($known));
        foreach ($xsd as $element => $attributes) {
            $this->assertEqualsCanonicalizing($attributes, $known[$element], "attributes of <$element>");
        }
    }

    public function test_knownAttributes_of_a_new_column(): void
    {
        $this->assertSame(['null', 'enc', 'crlf'], self::schemaWithNewColumns()->knownAttributes()['new_template']);
        $this->assertSame(['null'], self::schemaWithNewColumns()->knownAttributes()['new_level']);
    }

    public function test_xsd_is_deterministic(): void
    {
        $this->assertSame((new Schema())->xsd(), (new Schema(AA_Metabase::fromArray(AA_Metabase::singleton()->toArray())))->xsd());
    }
}
