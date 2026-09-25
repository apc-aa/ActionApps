<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA_MbC;
use AA_Metabase;
use AA_Module_Site;
use LogicException;

/** The aa-app package format 1.x as THIS AA knows it: the XSD and the known child elements per parent,
 *  both generated from include/metabase_schema.php + the rules below, so a new AA column lands in both.
 *
 *  A table row is an element named after the table, a column its child element. A column missing in
 *  the XSD cannot travel: installation columns, the module column and AUTO_INCREMENT numbers.
 *  SchemaTest keeps FILE equal to xsd() - regenerate with `php dev/central/aa-app-xsd.php`.
 */
final class Schema
{
    public const NS = 'https://actionapps.org/ns/aa-app';

    /** the generated XSD, validated with DOMDocument::schemaValidateSource() (schemaValidate() would load a file) */
    public const FILE = __DIR__ . '/aa-app-1.xsd';

    /** object classes of format 1.0. Settings objects travel only as <settings>, subobjects only inside
     *  an AA_Set; AA_Form, AA_Plannedtask and tokens never. */
    public const TOP_CLASSES = ['AA_Aliasfunc', 'AA_Set'];
    public const SUB_CLASSES = ['AA_Condition', 'AA_Sortorder'];

    /** module wrapper => the class of its <settings> (fixed by the module type) */
    public const SETTINGS_CLASSES = ['site-module' => 'AA_Modulesettings_Site', 'slice-module' => 'AA_Modulesettings_Slice'];

    /** row element => [table, key columns written as attributes: column => [attribute, type, default],
     *  columns left out, note]. Also left out: the module column (the importer sets it) and AUTO_INCREMENT
     *  columns that are no attribute (the target numbers them). New columns of definition tables travel
     *  automatically; see CARRIED. */
    private const ROWS = [
        'module'                     => ['module', [], ['type', 'owner', 'created_by', 'created_at', 'slice_url', 'deleted', 'priority', 'app_id']],
        'slice'                      => ['slice', [], ['owner', 'created_by', 'created_at', 'slice_url', 'deleted', 'fileman_dir', 'reading_password', 'export_to_all']],
        'field'                      => ['field', ['id' => ['id', 'fieldId']], []],
        'view'                       => ['view', ['id' => ['id', 'xs:positiveInteger']], []],
        'email'                      => ['email', ['id' => ['id', 'xs:positiveInteger']], []],
        'profile'                    => ['profile', [], ['uid'], "only the default profile (uid='*'): input_view, ui_manager, ui_inputform, ..."],
        'constant_slice'             => ['constant_slice', [], ['group_id'], 'group_id = @name, slice_id = @owner of <constant-group>'],
        'constant'                   => ['constant', ['id' => ['id', 'longId']], ['group_id']],
        'site'                       => ['site', [], ['structure'], 'structure = <spot-tree>'],
        'site_spot'                  => ['site_spot', ['spot_id' => ['spot_id', 'xs:positiveInteger']], []],
        'uses'                       => ['relation', ['destination_id' => ['module', 'longId']], ['source_id', 'flag'], 'only flag REL_FLAG_MODULE_DEPEND ("uses modules")'],
        'alerts_collection'          => ['alerts_collection', ['id' => ['id', 'collectionId']], []],
        'alerts_collection_filter'   => ['alerts_collection_filter', ['collectionid' => ['collection', 'collectionId'], 'filterid' => ['filter', 'xs:positiveInteger'], 'myindex' => ['myindex', 'xs:integer', '0']], []],
        'alerts_collection_howoften' => ['alerts_collection_howoften', ['collectionid' => ['collection', 'collectionId'], 'howoften' => ['howoften', 'xs:string']], ['last']],
        'alerts_filter'              => ['alerts_filter', ['id' => ['id', 'xs:positiveInteger']], []],
    ];

    /** Tables of installation state: every column is classified by hand (here, or left out in ROWS).
     *  A new column stops the generator until someone decides - it may be a path, a password or a
     *  permission of the source AA. */
    private const CARRIED = [
        'module'            => ['name', 'lang_file', 'flag'],
        'slice'             => [
            'name', 'type', 'template', 'fulltext_format_top', 'fulltext_format', 'fulltext_format_bottom', 'odd_row_format',
            'even_row_format', 'even_odd_differ', 'compact_top', 'compact_bottom', 'category_top', 'category_format',
            'category_bottom', 'category_sort', 'd_listlen', 'lang_file', 'fulltext_remove', 'compact_remove',
            'email_sub_enable', 'exclude_from_dir', 'notify_sh_offer', 'notify_sh_accept', 'notify_sh_remove',
            'notify_holding_item_s', 'notify_holding_item_b', 'notify_holding_item_edit_s', 'notify_holding_item_edit_b',
            'notify_active_item_edit_s', 'notify_active_item_edit_b', 'notify_active_item_s', 'notify_active_item_b',
            'noitem_msg', 'admin_format_top', 'admin_format', 'admin_format_bottom', 'admin_remove', 'admin_noitem_msg',
            'permit_anonymous_post', 'permit_anonymous_edit', 'permit_offline_fill', 'aditional', 'flag', 'vid',
            'gb_direction', 'group_by', 'gb_header', 'gb_case', 'javascript', 'fileman_access', 'auth_field_group',
            'mailman_field_lists', 'mlxctrl',
        ],
        'site'              => ['state_file', 'flag'],
        'alerts_collection' => ['emailid_welcome', 'emailid_alert', 'slice_id'],
    ];

    /** value types other than by the column type: packed ids joined without a separator */
    private const VALUE_TYPES = ['constant.ancestors' => 'idsValue'];

    /** container => [attributes, children]; child suffix ? = optional, * = any number; a child
     *  missing in CHILD_TYPES is a row (ROWS) */
    private const CONTAINERS = [
        'site-module'    => [[['id', 'longId']], ['doc?', 'module', 'site', 'spot-tree?', 'site_spot*', 'uses*', 'settings?', 'object*']],
        'slice-module'   => [[['id', 'longId']], ['doc?', 'module', 'slice', 'settings?', 'field*', 'view*', 'email*', 'profile*', 'object*', 'item*']],
        'alerts-module'  => [[['id', 'longId']], ['doc?', 'module', 'email*', 'alerts_collection?', 'alerts_collection_filter*', 'alerts_collection_howoften*', 'alerts_filter*']],
        'constant-group' => [[['name', 'groupId'], ['owner', 'longIdOrEmpty', '', 'owning slice (constant_slice.slice_id) - a slice of the package, or empty']], ['constant_slice?', 'constant*']],
    ];

    /** settings: "<site|slice>Settings", the class fixed by the module type */
    private const CHILD_TYPES = ['doc' => 'xs:string', 'spot-tree' => 'spotTree', 'object' => 'topObject', 'item' => 'item'];

    /** children of the elements written out in TEMPLATE (SchemaTest checks them against the XSD);
     *  `signature` has any content and is not pruned */
    private const FIXED_CHILDREN = [
        'aa-app'    => ['meta', 'site-module', 'slice-module', 'alerts-module', 'constant-group', 'signature'],
        'meta'      => ['name', 'description', 'version', 'publish', 'source', 'requires', 'contents', 'tables', 'doc'],
        'tables'    => ['table'],
        'spot-tree' => ['spot'],
        'spot'      => ['var', 'cond', 'positions', 'choices'],
        'settings'  => ['p'],
        'object'    => ['p', 'object'],
        'item'      => ['v'],
    ];

    /** attributes of the elements written out in TEMPLATE (SchemaTest checks them against the XSD) */
    private const FIXED_ATTRIBUTES = [
        'aa-app'    => ['format'],
        'source'    => ['url', 'aa-version', 'aa-build', 'site', 'exported'],
        'requires'  => ['aa-version'],
        'contents'  => ['items'],
        'table'     => ['name', 'columns'],
        'spot-tree' => ['start'],
        'spot'      => ['id', 'name', 'parent', 'flag', 'empty'],
        'cond'      => ['var'],
        'settings'  => ['class'],
        'object'    => ['id', 'class', 'name', 'n', 'i'],
        'p'         => ['n', 'i', 'enc', 'crlf'],
        'item'      => ['id'],
        'v'         => ['f', 'i', 'flag', 'enc', 'crlf'],
    ];

    /** attributes of a column by its value type (TEMPLATE); the others have just `null` */
    private const VALUE_ATTRIBUTES = ['textValue' => ['null', 'enc', 'crlf']];

    private readonly AA_Metabase $metabase;

    /** @param AA_Metabase|null $metabase the AA schema; null = include/metabase_schema.php */
    public function __construct(?AA_Metabase $metabase = null)
    {
        $this->metabase = $metabase ?? AA_Metabase::singleton();
    }

    public function xsd(): string
    {
        $containers = '';
        foreach (self::CONTAINERS as $element => [$attributes, $children]) {
            $containers .= $this->container($element, $attributes, $children);
        }
        $rows = '';
        foreach (array_keys(self::ROWS) as $element) {
            $rows .= $this->row($element);
        }
        return strtr(self::TEMPLATE, [
            '{{ns}}'         => self::NS,
            '{{containers}}' => $containers,
            '{{classes}}'    => self::enumeration('topObjectClass', self::TOP_CLASSES) . self::enumeration('subObjectClass', self::SUB_CLASSES),
            '{{rows}}'       => rtrim($rows),
            '{{class_site}}'  => self::SETTINGS_CLASSES['site-module'],
            '{{class_slice}}' => self::SETTINGS_CLASSES['slice-module'],
        ]);
    }

    /** Known child elements per parent element name (declarations of one name merged), in XSD order.
     *  The reader prunes unknown elements only for a higher minor format version. Columns are checked
     *  against the local AA schema instead: unknown ones are always pruned, known but left-out ones
     *  (reading_password) stay for the XSD to reject.
     *  @return array<string, list<string>> */
    public function knownElements(): array
    {
        $known = self::FIXED_CHILDREN;
        foreach (self::CONTAINERS as $element => [, $children]) {
            $known[$element] = array_map(static fn(string $child): string => rtrim($child, '?*'), $children);
        }
        foreach (array_keys(self::ROWS) as $element) {
            $known[$element] = array_keys($this->columns($element)[0]);
        }
        return $known;
    }

    /** Known attributes per element name (declarations of one name merged: a column `name` has
     *  null/enc/crlf, so <meta><name> "knows" them too - the XSD then decides).
     *  @return array<string, list<string>> */
    public function knownAttributes(): array
    {
        $known = self::FIXED_ATTRIBUTES;
        $add   = static function (string $element, array $names) use (&$known): void {
            $known[$element] = array_values(array_unique(array_merge($known[$element] ?? [], $names)));
        };
        foreach (self::CONTAINERS as $element => [$attributes]) {
            $add($element, array_column($attributes, 0));
        }
        foreach (self::ROWS as $element => [, $attributes]) {
            if ($attributes) {
                $add($element, array_column($attributes, 0));
            }
            foreach ($this->columns($element)[0] as $column => $type) {
                $add($column, self::VALUE_ATTRIBUTES[$type] ?? ['null']);
            }
        }
        return $known;
    }

    /** the row elements of a container (Validator): 1 = exactly one row, ? = at most one, * = any
     *  @return array<string, string> */
    public static function rowCounts(string $container): array
    {
        $counts = [];
        foreach (self::CONTAINERS[$container][1] ?? [] as $child) {
            $element = rtrim($child, '?*');
            if (isset(self::ROWS[$element])) {
                $counts[$element] = ($element === $child) ? '1' : substr($child, -1);
            }
        }
        return $counts;
    }

    /** true: site.state_file names a control file of the source AA, which site.php would include -
     *  never code of an app @param array<string, mixed> $site a site row */
    public static function controlFile(array $site): bool
    {
        return ((int) ValueCodec::bytes($site['flag'] ?? '') !== 1) && (AA_Module_Site::stateFileName(ValueCodec::bytes($site['state_file'] ?? '')) !== '');
    }

    /** @return array<string, string> row element => its table in metabase_schema.php */
    public function rowTables(): array
    {
        return array_map(static fn(array $row): string => $row[0], self::ROWS);
    }

    /** Every column of the row element's table in this AA - carried or not
     *  @return list<string> */
    public function tableColumns(string $element): array
    {
        return array_keys($this->metabase->getColumns(self::ROWS[$element][0]));
    }

    /** Columns a row element carries as child elements, in metabase_schema.php order
     *  @return array<string, string> column => value type: textValue | intValue | floatValue | idValue | idsValue */
    public function rowColumns(string $element): array
    {
        return $this->columns($element)[0];
    }

    /** Key columns a row element carries as attributes, in XSD order.
     *  @return array<string, array<int, string>> column => [attribute, XSD type, default] - no default = required */
    public function rowAttributes(string $element): array
    {
        return self::ROWS[$element][1];
    }

    /** Integer columns of a row element (key attributes included) and their ranges in MySQL
     *  @return array<string, array{int, int}> column => [min, max] */
    public function intRanges(string $element): array
    {
        return array_filter(array_map(static function (AA_MbC $column): ?array {
            if (!preg_match('/^(tiny|small|medium|big|)int\b(.*unsigned)?/i', (string) $column->c[1], $m)) {
                return null;
            }
            $bits = ['tiny' => 8, 'small' => 16, 'medium' => 24, '' => 32, 'big' => 64][strtolower($m[1])];
            return match (true) {
                $bits === 64 => [empty($m[2]) ? PHP_INT_MIN : 0, PHP_INT_MAX],
                empty($m[2]) => [-(2 ** ($bits - 1)), 2 ** ($bits - 1) - 1],
                default      => [0, 2 ** $bits - 1],
            };
        }, $this->metabase->getColumns(self::ROWS[$element][0])));
    }

    /** Length limits of a row element's table: [N, true] = N characters (char, varchar), [N, false] =
     *  N bytes (binary, *text, *blob). A packed id column holds 2N hex digits of the unpacked ids.
     *  AA_MbT::getVarset4Data() cuts every (N) column at N bytes too (TargetCharset::tooLong()).
     *  @return array<string, array{int, bool}> column => [limit, counts characters] */
    public function lengthLimits(string $element): array
    {
        return $this->tableLimits(self::ROWS[$element][0]);
    }

    /** lengthLimits() of any AA table - the ones outside the row elements too (item, content, object_*)
     *  @return array<string, array{int, bool}> column => [limit, counts characters] */
    public function tableLimits(string $table): array
    {
        $limits = [];
        foreach ($this->metabase->getColumns($table) as $name => $column) {
            $type  = strtolower((string) $column->c[1]);
            $limit = match (true) {
                (bool) preg_match('/^(var)?(char|binary)\((\d+)\)/', $type, $m) => [(int) $m[3], $m[2] === 'char'],
                (bool) preg_match('/^tiny(?:text|blob)\b/', $type)               => [255, false],
                (bool) preg_match('/^(?:text|blob)\b/', $type)                   => [65535, false],
                (bool) preg_match('/^medium(?:text|blob)\b/', $type)             => [16777215, false],
                default                                                          => null,
            };
            if ($limit !== null) {
                $limits[(string) $name] = AA_Metabase::isPacked($table, (string) $name) ? [2 * $limit[0], false] : $limit;
            }
        }
        return $limits;
    }

    /** A row in a finding path: by its key attributes (`view[12]`), a keyless row or one of an element
     *  unknown here by its position (`profile[#0]`) @param array<mixed> $row */
    public function rowPath(string $path, string $element, array $row, int $i): string
    {
        $keys = array_keys(self::ROWS[$element][1] ?? []);
        return "{$path}/{$element}[" . ($keys ? implode(',', array_map(static fn(string $k): string => ValueCodec::bytes($row[$k] ?? ''), $keys)) : "#$i") . ']';
    }

    /** @return array{array<string, string>, list<string>} [column => value type, columns left out] */
    private function columns(string $element): array
    {
        [$table, $attributes, $left_out] = self::ROWS[$element];
        if (!isset($this->metabase->tables[$table])) {
            throw new LogicException("Table $table is not in the AA schema");
        }
        $columns = $this->metabase->getColumns($table);
        // a renamed or mistyped name would silently carry the real column
        if ($missing = array_diff(array_merge(array_keys($attributes), $left_out), array_keys($columns))) {
            throw new LogicException("Columns $table." . implode(", $table.", $missing) . ' of Schema::ROWS are not in include/metabase_schema.php - renamed?');
        }
        $left_out[] = AA_Metabase::getModuleField($table);
        $in         = $out = [];
        foreach ($columns as $name => $column) {
            if (isset($attributes[$name])) {
                continue;
            }
            if (in_array($name, $left_out, true) || $column->isAutoIncrement()) {
                $out[] = $name;
                continue;
            }
            if (isset(self::CARRIED[$element]) && !in_array($name, self::CARRIED[$element], true)) {
                throw new LogicException("New column $table.$name: `$table` holds installation state - decide whether an app package may carry it and add it to Schema::CARRIED or to the left-out columns in Schema::ROWS, then regenerate (dev/README.md)");
            }
            $in[$name] = self::VALUE_TYPES["$table.$name"] ?? (AA_Metabase::isPacked($table, $name) ? 'idValue' : match ($column->getFieldType()) {
                'int'   => 'intValue',
                'float' => 'floatValue',
                default => 'textValue',
            });
        }
        return [$in, $out];
    }

    private function row(string $element): string
    {
        [$table, $attributes, , $note] = self::ROWS[$element] + [3 => ''];
        [$columns, $left_out]          = $this->columns($element);
        $xsd                           = "  <!-- table `$table`" . ($note === '' ? '' : ", $note")
            . ($left_out ? '; not in the package: ' . implode(', ', $left_out) : '') . " -->\n"
            . "  <xs:complexType name=\"row_$element\">\n";
        if ($columns) {
            $xsd .= "    <xs:all>\n";
            foreach ($columns as $name => $type) {
                $xsd .= "      <xs:element name=\"$name\" type=\"$type\" minOccurs=\"0\"/>\n";
            }
            $xsd .= "    </xs:all>\n";
        }
        foreach ($attributes as $attribute) {
            $xsd .= self::attribute($attribute);
        }
        return "$xsd  </xs:complexType>\n\n";
    }

    /** @param list<array<int, string>> $attributes @param list<string> $children */
    private function container(string $element, array $attributes, array $children): string
    {
        [$prefix, $kind] = explode('-', $element);
        $xsd             = '  <xs:complexType name="' . $prefix . ucfirst($kind) . "\">\n    <xs:sequence>\n";
        foreach ($children as $child) {
            $name   = rtrim($child, '?*');
            $type   = $name === 'settings' ? "{$prefix}Settings" : (self::CHILD_TYPES[$name] ?? "row_$name");
            $occurs = ['?' => ' minOccurs="0"', '*' => ' minOccurs="0" maxOccurs="unbounded"'][substr($child, -1)] ?? '';
            $xsd   .= "      <xs:element name=\"$name\" type=\"$type\"$occurs";
            if ($name !== 'settings' && $name !== 'object') {
                $xsd .= "/>\n";
                continue;
            }
            // each property value (name, index) at most once - scalar or subobject
            $xsd .= ">\n        <xs:unique name=\"unique" . ucfirst($prefix) . ucfirst($name) . "Prop\">\n"
                . '          <xs:selector xpath="' . ($name === 'object' ? 'a:p|a:object' : 'a:p') . "\"/>\n"
                . "          <xs:field xpath=\"@n\"/>\n          <xs:field xpath=\"@i\"/>\n"
                . "        </xs:unique>\n      </xs:element>\n";
        }
        $xsd .= "    </xs:sequence>\n";
        foreach ($attributes as $attribute) {
            $xsd .= self::attribute($attribute);
        }
        return "$xsd  </xs:complexType>\n\n";
    }

    /** @param array<int, string> $attribute [name, type, default, comment] - no default = required */
    private static function attribute(array $attribute): string
    {
        [$name, $type] = $attribute;
        return (isset($attribute[3]) ? "    <!-- $attribute[3] -->\n" : '')
            . "    <xs:attribute name=\"$name\" type=\"$type\" "
            . (isset($attribute[2]) ? "default=\"$attribute[2]\"" : 'use="required"') . "/>\n";
    }

    /** @param list<string> $values */
    private static function enumeration(string $name, array $values): string
    {
        $xsd = "  <xs:simpleType name=\"$name\">\n    <xs:restriction base=\"xs:string\">\n";
        foreach ($values as $value) {
            $xsd .= "      <xs:enumeration value=\"$value\"/>\n";
        }
        return "$xsd    </xs:restriction>\n  </xs:simpleType>\n\n";
    }

    /** The fixed part of the format; {{…}} are generated from the constants above */
    private const TEMPLATE = <<<'XSD'
        <?xml version="1.0" encoding="UTF-8"?>
        <!--
          aa-app 1.x - schema of the ActionApps app package, as THIS AA knows it.
          GENERATED by AA\Central\Package\Schema::xsd() from include/metabase_schema.php - do not edit;
          regenerate with: php dev/central/aa-app-xsd.php

          - XSD 1.0 (libxml2). Validate with DOMDocument::schemaValidateSource(<this file as string>)
            while the external entity loader returns null - schemaValidate(path) would need the loader.
          - The row types (row_*) follow the AA version, not the format version; Schema::knownElements()
            lists the known children per parent for the reader.
          - Before validation the reader prunes (a) columns unknown to the local metabase_schema - always,
            (b) elements/attributes/object classes unknown to this format version - only for a higher
            minor version; both are reported. Then this schema validates strictly.
          - No imports, no includes, no DTD: validation must never load anything.
        -->
        <xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"
                   xmlns="{{ns}}"
                   xmlns:a="{{ns}}"
                   targetNamespace="{{ns}}"
                   elementFormDefault="qualified"
                   attributeFormDefault="unqualified">

          <!-- ================================================================ simple types -->

          <!-- format version: major.minor; this schema = major 1 -->
          <xs:simpleType name="formatVersion">
            <xs:restriction base="xs:string">
              <xs:pattern value="1\.(0|[1-9][0-9]{0,3})"/>
            </xs:restriction>
          </xs:simpleType>

          <!-- AA long id, unpacked: 32 lowercase hex -->
          <xs:simpleType name="longId">
            <xs:restriction base="xs:string">
              <xs:pattern value="[0-9a-f]{32}"/>
            </xs:restriction>
          </xs:simpleType>

          <xs:simpleType name="longIdOrEmpty">
            <xs:restriction base="xs:string">
              <xs:pattern value="([0-9a-f]{32})?"/>
            </xs:restriction>
          </xs:simpleType>

          <!-- constant.ancestors: long ids joined without a separator -->
          <xs:simpleType name="longIds">
            <xs:restriction base="xs:string">
              <xs:pattern value="([0-9a-f]{32})*"/>
            </xs:restriction>
          </xs:simpleType>

          <!-- field id: exactly 16 bytes, e.g. headline........ , _text..........1 , alerts1....AbC12 -->
          <xs:simpleType name="fieldId">
            <xs:restriction base="xs:string">
              <xs:pattern value="[A-Za-z0-9_.]{16}"/>
            </xs:restriction>
          </xs:simpleType>

          <!-- constant group id; XSD counts characters, not bytes - the reader must also run
               constant_group_id_valid() (1-16 BYTES) -->
          <xs:simpleType name="groupId">
            <xs:restriction base="xs:string">
              <xs:pattern value="[^\p{Cc}:'&quot;`\\%]{1,16}"/>
            </xs:restriction>
          </xs:simpleType>

          <!-- alerts collection id: gensalt(5) -->
          <xs:simpleType name="collectionId">
            <xs:restriction base="xs:string">
              <xs:pattern value="[A-Za-z0-9]{5}"/>
            </xs:restriction>
          </xs:simpleType>

          <xs:simpleType name="intOrEmpty">
            <xs:restriction base="xs:string">
              <xs:pattern value="(-?[0-9]{1,20})?"/>
            </xs:restriction>
          </xs:simpleType>

          <xs:simpleType name="floatOrEmpty">
            <xs:restriction base="xs:string">
              <xs:pattern value="(-?[0-9]+(\.[0-9]+)?([eE][-+]?[0-9]+)?)?"/>
            </xs:restriction>
          </xs:simpleType>

          <xs:simpleType name="intList">
            <xs:list itemType="xs:integer"/>
          </xs:simpleType>

          <!-- spot properties that are an EMPTY array (not null) - kept on the spot-tree round trip -->
          <xs:simpleType name="spotArrayList">
            <xs:list>
              <xs:simpleType>
                <xs:restriction base="xs:string">
                  <xs:enumeration value="v"/>
                  <xs:enumeration value="c"/>
                  <xs:enumeration value="po"/>
                  <xs:enumeration value="ch"/>
                </xs:restriction>
              </xs:simpleType>
            </xs:list>
          </xs:simpleType>

          <xs:simpleType name="nameList">
            <xs:list itemType="xs:NCName"/>
          </xs:simpleType>

          <!-- how a value is encoded in the element text -->
          <xs:simpleType name="encoding">
            <xs:restriction base="xs:string">
              <!-- text: the value as is (default); base64: the same UTF-8-side bytes, when XML 1.0
                   cannot carry them (control chars, lone CR, exceptionally invalid UTF-8) -->
              <xs:enumeration value="text"/>
              <xs:enumeration value="base64"/>
            </xs:restriction>
          </xs:simpleType>

          <xs:simpleType name="publish">
            <xs:restriction base="xs:string">
              <xs:enumeration value="no"/>
              <xs:enumeration value="users"/>
              <xs:enumeration value="all"/>
            </xs:restriction>
          </xs:simpleType>

          <!-- ================================================================ column values -->
          <!-- Every column is an element named as the DB column. Text may be plain or CDATA (the
               reader sees no difference; "]]>" inside CDATA is split as "]]]]><![CDATA[>").
               null="true"  - SQL NULL (the text must be empty - checked in PHP, XSD 1.0 cannot)
               enc="base64" - see type "encoding"
               crlf="true"  - the value's line ends were CRLF; the writer stored LF, the reader restores CRLF
               A column missing in a row = empty value of its type ('' / 0), if meta/tables lists it for the
               table; a column meta/tables does not list = the importing AA's schema default. -->

          <xs:attributeGroup name="valueAttrs">
            <xs:attribute name="null" type="xs:boolean" default="false"/>
          </xs:attributeGroup>

          <xs:complexType name="textValue">
            <xs:simpleContent>
              <xs:extension base="xs:string">
                <xs:attributeGroup ref="valueAttrs"/>
                <xs:attribute name="enc" type="encoding" default="text"/>
                <xs:attribute name="crlf" type="xs:boolean" default="false"/>
              </xs:extension>
            </xs:simpleContent>
          </xs:complexType>

          <xs:complexType name="intValue">
            <xs:simpleContent>
              <xs:extension base="intOrEmpty">
                <xs:attributeGroup ref="valueAttrs"/>
              </xs:extension>
            </xs:simpleContent>
          </xs:complexType>

          <xs:complexType name="floatValue">
            <xs:simpleContent>
              <xs:extension base="floatOrEmpty">
                <xs:attributeGroup ref="valueAttrs"/>
              </xs:extension>
            </xs:simpleContent>
          </xs:complexType>

          <xs:complexType name="idValue">
            <xs:simpleContent>
              <xs:extension base="longIdOrEmpty">
                <xs:attributeGroup ref="valueAttrs"/>
              </xs:extension>
            </xs:simpleContent>
          </xs:complexType>

          <xs:complexType name="idsValue">
            <xs:simpleContent>
              <xs:extension base="longIds">
                <xs:attributeGroup ref="valueAttrs"/>
              </xs:extension>
            </xs:simpleContent>
          </xs:complexType>

          <!-- ================================================================ root -->

          <xs:element name="aa-app">
            <xs:complexType>
              <xs:sequence>
                <xs:element name="meta" type="meta"/>
                <xs:choice minOccurs="1" maxOccurs="unbounded">
                  <xs:element name="site-module" type="siteModule">
                    <xs:unique name="uniqueSpot">
                      <xs:selector xpath="a:site_spot"/>
                      <xs:field xpath="@spot_id"/>
                    </xs:unique>
                    <xs:unique name="uniqueUses">
                      <xs:selector xpath="a:uses"/>
                      <xs:field xpath="@module"/>
                    </xs:unique>
                    <xs:key name="keySpot">
                      <xs:selector xpath="a:spot-tree/a:spot"/>
                      <xs:field xpath="@id"/>
                    </xs:key>
                    <xs:keyref name="refSpotParent" refer="keySpot">
                      <xs:selector xpath="a:spot-tree/a:spot"/>
                      <xs:field xpath="@parent"/>
                    </xs:keyref>
                    <xs:keyref name="refSpotStart" refer="keySpot">
                      <xs:selector xpath="a:spot-tree"/>
                      <xs:field xpath="@start"/>
                    </xs:keyref>
                  </xs:element>
                  <xs:element name="slice-module" type="sliceModule">
                    <xs:unique name="uniqueField">
                      <xs:selector xpath="a:field"/>
                      <xs:field xpath="@id"/>
                    </xs:unique>
                  </xs:element>
                  <xs:element name="alerts-module" type="alertsModule">
                    <!-- alerts_collection.module_id is UNIQUE: at most one collection per module -->
                    <xs:key name="keyCollection">
                      <xs:selector xpath="a:alerts_collection"/>
                      <xs:field xpath="@id"/>
                    </xs:key>
                    <xs:keyref name="refCollectionFilter" refer="keyCollection">
                      <xs:selector xpath="a:alerts_collection_filter"/>
                      <xs:field xpath="@collection"/>
                    </xs:keyref>
                    <xs:keyref name="refCollectionHowoften" refer="keyCollection">
                      <xs:selector xpath="a:alerts_collection_howoften"/>
                      <xs:field xpath="@collection"/>
                    </xs:keyref>
                    <xs:key name="keyFilter">
                      <xs:selector xpath="a:alerts_filter"/>
                      <xs:field xpath="@id"/>
                    </xs:key>
                    <xs:keyref name="refFilter" refer="keyFilter">
                      <xs:selector xpath="a:alerts_collection_filter"/>
                      <xs:field xpath="@filter"/>
                    </xs:keyref>
                    <!-- primary keys of the two tables -->
                    <xs:unique name="uniqueCollectionFilter">
                      <xs:selector xpath="a:alerts_collection_filter"/>
                      <xs:field xpath="@collection"/>
                      <xs:field xpath="@filter"/>
                    </xs:unique>
                    <xs:unique name="uniqueCollectionHowoften">
                      <xs:selector xpath="a:alerts_collection_howoften"/>
                      <xs:field xpath="@collection"/>
                      <xs:field xpath="@howoften"/>
                    </xs:unique>
                  </xs:element>
                </xs:choice>
                <xs:element name="constant-group" type="constantGroup" minOccurs="0" maxOccurs="unbounded"/>
                <!-- placeholder: signature of the canonical document without this element (later: Ed25519) -->
                <xs:element name="signature" type="signature" minOccurs="0"/>
              </xs:sequence>
              <xs:attribute name="format" type="formatVersion" use="required"/>
            </xs:complexType>

            <!-- ids unique across the whole package -->
            <xs:unique name="uniqueModule">
              <xs:selector xpath="a:site-module|a:slice-module|a:alerts-module"/>
              <xs:field xpath="@id"/>
            </xs:unique>
            <xs:unique name="uniqueView">
              <xs:selector xpath="a:slice-module/a:view"/>
              <xs:field xpath="@id"/>
            </xs:unique>
            <xs:unique name="uniqueCollectionId">
              <xs:selector xpath="a:alerts-module/a:alerts_collection"/>
              <xs:field xpath="@id"/>
            </xs:unique>
            <xs:unique name="uniqueEmail">
              <xs:selector xpath="a:slice-module/a:email|a:alerts-module/a:email"/>
              <xs:field xpath="@id"/>
            </xs:unique>
            <xs:unique name="uniqueGroup">
              <xs:selector xpath="a:constant-group"/>
              <xs:field xpath="@name"/>
            </xs:unique>
            <xs:unique name="uniqueConstant">
              <xs:selector xpath="a:constant-group/a:constant"/>
              <xs:field xpath="@id"/>
            </xs:unique>
            <xs:unique name="uniqueObject">
              <xs:selector xpath="*/a:object|*/a:object/a:object"/>
              <xs:field xpath="@id"/>
            </xs:unique>
            <xs:unique name="uniqueItem">
              <xs:selector xpath="a:slice-module/a:item"/>
              <xs:field xpath="@id"/>
            </xs:unique>
          </xs:element>

          <!-- ================================================================ meta -->

          <xs:complexType name="meta">
            <xs:sequence>
              <xs:element name="name"        type="xs:string"/>
              <xs:element name="description" type="xs:string" minOccurs="0"/>
              <!-- app version as the author writes it, e.g. 1.2.0 -->
              <xs:element name="version"     type="xs:string" minOccurs="0"/>
              <!-- who may fetch the app at the source; the importer sets "users" on the new site -->
              <xs:element name="publish"     type="publish"   minOccurs="0"/>
              <xs:element name="source"      type="source"/>
              <xs:element name="requires"    type="requires"  minOccurs="0"/>
              <xs:element name="contents"    type="contents"/>
              <!-- columns the exporting AA knew per table (decides "missing = empty" vs "missing = unknown") -->
              <xs:element name="tables"      type="tables"/>
              <xs:element name="doc"         type="xs:string" minOccurs="0"/>
            </xs:sequence>
          </xs:complexType>

          <xs:complexType name="source">
            <!-- AA_INSTAL_URL of the exporting AA (never AA_ID) -->
            <xs:attribute name="url"        type="xs:anyURI"   use="required"/>
            <!-- aa_version('aa') and aa_version('build') -->
            <xs:attribute name="aa-version" type="xs:string"   use="required"/>
            <xs:attribute name="aa-build"   type="xs:string"/>
            <!-- the site the app was exported from (fromSite), empty for fromModules -->
            <xs:attribute name="site"       type="longIdOrEmpty"/>
            <xs:attribute name="exported"   type="xs:dateTime" use="required"/>
          </xs:complexType>

          <xs:complexType name="requires">
            <!-- oldest AA the package was tested with / needs -->
            <xs:attribute name="aa-version" type="xs:string"/>
          </xs:complexType>

          <xs:complexType name="contents">
            <xs:attribute name="items" type="xs:boolean" default="false"/>
          </xs:complexType>

          <xs:complexType name="tables">
            <xs:sequence>
              <xs:element name="table" maxOccurs="unbounded">
                <xs:complexType>
                  <xs:attribute name="name"    type="xs:NCName" use="required"/>
                  <xs:attribute name="columns" type="nameList"  use="required"/>
                </xs:complexType>
              </xs:element>
            </xs:sequence>
          </xs:complexType>

          <xs:complexType name="signature" mixed="true">
            <xs:sequence>
              <xs:any processContents="skip" minOccurs="0" maxOccurs="unbounded"/>
            </xs:sequence>
            <xs:anyAttribute processContents="skip"/>
          </xs:complexType>

          <!-- ================================================================ modules -->

        {{containers}}  <!-- ================================================================ site tree -->
          <!-- site.structure (serialized sitetree/spot) as data - the package never carries PHP serialize();
               the importer builds the tree with sitetree::fromArray(); ids are written as
               numbers, fromArray() restores the canonical PHP types -->

          <xs:complexType name="spotTree">
            <xs:sequence>
              <xs:element name="spot" maxOccurs="unbounded">
                <xs:complexType>
                  <xs:sequence>
                    <xs:element name="var"       type="xs:string" minOccurs="0" maxOccurs="unbounded"/>
                    <xs:element name="cond"      minOccurs="0" maxOccurs="unbounded">
                      <xs:complexType>
                        <xs:simpleContent>
                          <xs:extension base="xs:string">
                            <xs:attribute name="var" type="xs:string" use="required"/>
                          </xs:extension>
                        </xs:simpleContent>
                      </xs:complexType>
                    </xs:element>
                    <!-- as stored: a choice (option) spot has its own id here -->
                    <xs:element name="positions" type="intList" minOccurs="0"/>
                    <xs:element name="choices"   type="intList" minOccurs="0"/>
                  </xs:sequence>
                  <xs:attribute name="id"     type="xs:positiveInteger" use="required"/>
                  <xs:attribute name="name"   type="xs:string"          use="required"/>
                  <xs:attribute name="parent" type="xs:positiveInteger" use="required"/>
                  <xs:attribute name="flag"   type="xs:integer"         default="0"/>
                  <!-- var/cond/positions/choices absent = null, unless listed here as an empty array -->
                  <xs:attribute name="empty"  type="spotArrayList"/>
                </xs:complexType>
              </xs:element>
            </xs:sequence>
            <xs:attribute name="start" type="xs:positiveInteger" use="required"/>
          </xs:complexType>

          <!-- ================================================================ objects (AA_Object) -->

          <!-- object classes of format 1.0 (Schema::TOP_CLASSES, SUB_CLASSES); the reader prunes other
               classes of a higher minor version -->
        {{classes}}  <!-- one scalar property value; i = index of a multi-value property (object_*.priority) -->
          <xs:complexType name="property">
            <xs:simpleContent>
              <xs:extension base="xs:string">
                <xs:attribute name="n"    type="xs:NCName"  use="required"/>
                <xs:attribute name="i"    type="xs:nonNegativeInteger" default="0"/>
                <xs:attribute name="enc"  type="encoding"   default="text"/>
                <xs:attribute name="crlf" type="xs:boolean" default="false"/>
              </xs:extension>
            </xs:simpleContent>
          </xs:complexType>

          <!-- the module settings object; its id is derived: string2id(class . module_id);
               the class is fixed by the module type -->
          <xs:complexType name="siteSettings">
            <xs:sequence>
              <xs:element name="p" type="property" minOccurs="0" maxOccurs="unbounded"/>
            </xs:sequence>
            <xs:attribute name="class" type="xs:string" use="required" fixed="{{class_site}}"/>
          </xs:complexType>

          <xs:complexType name="sliceSettings">
            <xs:sequence>
              <xs:element name="p" type="property" minOccurs="0" maxOccurs="unbounded"/>
            </xs:sequence>
            <xs:attribute name="class" type="xs:string" use="required" fixed="{{class_slice}}"/>
          </xs:complexType>

          <!-- an object owned by the module (AA_Aliasfunc, AA_Set). An object-valued property (AA_Set.conds,
               AA_Set.sort) is a nested <object n="conds" i="0"> - the importer builds children first, puts
               them into the parent's property and the parent's save() saves them (AA_Property::save) -->
          <xs:complexType name="topObject">
            <xs:choice minOccurs="0" maxOccurs="unbounded">
              <xs:element name="p"      type="property"/>
              <xs:element name="object" type="subObject">
                <xs:unique name="uniqueSubObjectProp">
                  <xs:selector xpath="a:p"/>
                  <xs:field xpath="@n"/>
                  <xs:field xpath="@i"/>
                </xs:unique>
              </xs:element>
            </xs:choice>
            <xs:attribute name="id"    type="longId"         use="required"/>
            <xs:attribute name="class" type="topObjectClass" use="required"/>
            <xs:attribute name="name"  type="xs:string"/>
          </xs:complexType>

          <xs:complexType name="subObject">
            <xs:sequence>
              <xs:element name="p" type="property" minOccurs="0" maxOccurs="unbounded"/>
            </xs:sequence>
            <xs:attribute name="id"    type="longId"         use="required"/>
            <xs:attribute name="class" type="subObjectClass" use="required"/>
            <!-- the parent's property this object is a value of, and its index -->
            <xs:attribute name="n"     type="xs:NCName"      use="required"/>
            <xs:attribute name="i"     type="xs:nonNegativeInteger" default="0"/>
          </xs:complexType>

          <!-- ================================================================ items (explicit request only) -->

          <!-- only when meta/contents/@items = true -->
          <xs:complexType name="item">
            <xs:sequence>
              <xs:element name="v" maxOccurs="unbounded">
                <xs:complexType>
                  <xs:simpleContent>
                    <xs:extension base="xs:string">
                      <xs:attribute name="f"    type="fieldId"    use="required"/>
                      <!-- key of the value in GetItemContent(): the position of a multi-value, or
                           the translation number (>= AA_Value::MAX_INDEX) -->
                      <xs:attribute name="i"    type="xs:nonNegativeInteger" default="0"/>
                      <xs:attribute name="flag" type="xs:integer" default="0"/>
                      <xs:attribute name="enc"  type="encoding"   default="text"/>
                      <xs:attribute name="crlf" type="xs:boolean" default="false"/>
                    </xs:extension>
                  </xs:simpleContent>
                </xs:complexType>
              </xs:element>
            </xs:sequence>
            <xs:attribute name="id" type="longId" use="required"/>
          </xs:complexType>

          <!-- ================================================================ table rows (from metabase_schema.php) -->

        {{rows}}

        </xs:schema>

        XSD;
}
