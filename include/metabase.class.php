<?php

/**
 *
 * PHP version 7.2+
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @package   Include
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
 */

use AA\IO\DB\DB_AA;
use AA\Managed;
use AA\Managed_Table;
use AA\Manageraction\Manageractions;
use AA\Manageraction\Table_Row_Delete;
use AA\Util\Searchfields;

/** AA_Metabase holds the database structure of AA
 *  The database structure in metabase is used for
 *    1) creating database
 *    2) updating the database structure
 *    3) constructing queries to database with data type checking
 *
 *  Inner structure looks like (generated with getDefinition() method):
 *
 *       'central_conf' => array(
 *           'id' => array(
 *                0 => "id",                         // 'Field'
 *                1 => "int(10) unsigned",           // 'Type'
 *                2 => "NO",                         // 'Null'
 *                3 => "PRI",                        // 'Key'
 *                4 => "0",                          // 'Default'
 *                5 => "auto_increment",             // 'Extra'
 *                6 => "AA identifier",              // 'Comment'
 *           ),
 *           'dns_conf' => array(
 *               'Field'   => "dns_conf",
 *               'Type'    => "varbinary(255)",
 *               'Null'    => "NO",
 *           ),
 *           ...
 */


// AA_Metabase_Column - we shorted the names in order the serialized tables could be shorter
class AA_MbC
{
    /** Column definition array
     *             0     1    2     3      4       5       6
     *  array ( field, type, null, key, default, extra, comment )
     *
     *  The reason, why we store it in array is, that the metabase is here
     *  stored as serialized string and I want to keep it as short as possible
     */
    public $c;

    public function __construct($column = null)
    {
        if ($column !== null) {
            $this->c = [$column['Field'], $column['Type'], $column['Null'] === 'YES', $column['Key'], $column['Default'], $column['Extra'], $column['Comment']];
        }
    }

    /** Plain-array form of the column: the 7-element [field, type, null, key, default, extra, comment]. */
    public function toArray(): array
    {
        return $this->c;
    }

    /** Rebuild a column from its toArray() form (bypasses the DB-row constructor). */
    public static function fromArray(array $c): AA_MbC
    {
        $column = new self();
        $column->c = $c;
        return $column;
    }

    public function isKey(): bool
    {
        return str_contains($this->c[3], 'PRI');
    }

    public function isAutoIncrement(): bool
    {
        return str_contains((string) $this->c[5], 'auto_increment');
    }

    public function getName()
    {
        return $this->c[0];
    }

    private function _getBaseType(): string
    {
        $dbtype = strtolower($this->c[1]);
        return substr($dbtype, 0, strspn($dbtype, "abcdefghijklmnopqrstuvwxyz"));
    }

    /** @return int max length of the field if we know it */
    public function getMaxLength(): int
    {
        $part = [];
        return preg_match("/\\((\\d*)\\)/", $this->c[1], $part) ? (int) $part[1] : 0;
    }

    /** @return string - database field type - 'float' | 'int' | 'string' | 'text' */
    public function getFieldType(): string
    {
        // Used in AA database
        // bigint(20)
        // bigint(30)
        // binary(16)
        // char(100)
        // char(150)
        // char(16)
        // char(160)
        // char(20)
        // char(250)
        // char(255)
        // char(30)
        // char(40)
        // char(50)
        // char(6)
        // char(60)
        // char(80)
        // double
        // enum('hidden','highlight','visible')
        // enum('n','y')
        // float(10,2)
        // int(10)
        // int(10) unsigned
        // int(11)
        // int(14)
        // int(4)
        // longtext
        // longblob
        // mediumint(9)
        // mediumtext
        // smallint(1)
        // smallint(20)
        // smallint(5)
        // smallint(6)
        // text
        // timestamp
        // tinyint(1)
        // tinyint(10)
        // tinyint(2)
        // tinyint(3) unsigned
        // tinyint(4)
        // varbinary(10)
        // varbinary(15)
        // varbinary(16)
        // varbinary(255)
        // varbinary(30)
        // varbinary(32)
        // varbinary(40)
        // varbinary(6)
        // varchar(10)
        // varchar(100)
        // varchar(120)
        // varchar(128)
        // varchar(14)
        // varchar(15)
        // varchar(150)
        // varchar(16)
        // varchar(20)
        // varchar(200)
        // varchar(255)
        // varchar(30)
        // varchar(32)
        // varchar(40)
        // varchar(5)
        // varchar(50)
        // varchar(60)
        // varchar(80)

        return match ($this->_getBaseType()) {
            'float', 'double'                                                => 'float',
            'int', 'mediumint', 'bigint', 'smallint', 'tinyint', 'timestamp' => 'int',
            'binary', 'varbinary', 'char', 'varchar', 'enum'                 => 'string',
            default                                                          => 'text',
        };
    }

    /** @return bool - if the field type is binary or varbinary or *blob */
    public function isBinary(): bool
    {
        return (str_contains($this->_getBaseType(), 'binary') || str_contains($this->_getBaseType(), 'blob'));
    }

    private function _getSearchType(): string
    {
        return match ($this->_getBaseType()) {
            'float', 'double', 'int', 'mediumint', 'bigint', 'smallint', 'tinyint' => 'numeric',
            'timestamp'                                                            => 'date',
            default                                                                => 'text',
        };
    }

    public function getAsProperty(bool $edit = true): ?AA_Property
    {
        $type = $this->getFieldType();
        if ($this->isKey() || (strtolower($this->c[5]) === 'auto_increment')) {
            // for editing or int/long/packed ids do not allow editing - allow edit keys just for new text field (nodes table)
            if ($edit || ($type === 'int') || ($type === 'float') || $this->getMaxLength() <= 32) {
                return null;
            }
        }
        // $id,       $name,     $type, $multi, $persistent, $validator, $required, $input_help, $input_morehlp='', $example='', $show_content_type_switch=0, $content_type_switch_default=, $perms=null, $default=null) {
        $validator = ($len = $this->getMaxLength()) ? [$type,['maxlength' => $len]] : $type;
        $required  = false; //  !($this->c[2]) - nonsense
        return  new AA_Property($this->c[0], _mdelayed($this->c[0]), $type, false, true, $validator, $required, $this->c[6]);
    }

    public function getCreateSql(): string
    {
        $SQL  = '`' . $this->c[0] . '`';                     // column name
        $SQL .= ' ' . $this->c[1];                          // column definition
        if (!$this->c[2]) {                                 // NULL ?
            $SQL .= ' NOT NULL';
        }
        if (strlen($this->c[4] ?? '')) {
            // look for keywords for default
            if ($this->c[4] === 'CURRENT_TIMESTAMP') {
                // we ignore it, because DEFAULT CURRENT_TIMESTAMP is
                // not supported in MySQL < 4.1.x and it is not needed for
                // MySQL > 4.1.x, because standard settings of any timestamp
                // column is:
                //     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            } elseif (in_array(strtolower($this->c[4]), ['null','current_timestamp()'])) {
                $SQL .= " default " . $this->c[4];     // default
            } else {
                $SQL .= " default '" . $this->c[4] . "'";     // default
            }
        }
        $SQL .= ' ' . $this->c[5];                          // extra - like auto_increment
        return $SQL;
    }

    /** returns database structure definition as PHP code (array)
     *  not used, yet (and question is, if ever) */
    public function getDefinition(): string
    {
        $ret  = "\n        '" . $this->c[0] . "' => array(";
        if ($this->c[0]) {
            $ret .= "\n            'Field'   => \"" . $this->c[0] . '",';
        }
        if ($this->c[1]) {
            $ret .= "\n            'Type'    => \"" . $this->c[1] . '",';
        }
        if ($this->c[2]) {
            $ret .= "\n            'Null'    => \"" . ($this->c[2] ? 'YES' : 'NO') . '",';
        }
        if ($this->c[3]) {
            $ret .= "\n            'Key'     => \"" . $this->c[3] . '",';
        }
        if ($this->c[4]) {
            $ret .= "\n            'Default' => \"" . $this->c[4] . '",';
        }
        if ($this->c[5]) {
            $ret .= "\n            'Extra'   => \"" . $this->c[5] . '",';
        }
        if ($this->c[6]) {
            $ret .= "\n            'Comment' => \"" . $this->c[6] . '"';
        }
        $ret .= "\n        )";
        return $ret;
    }

    /** generate alias from column */
    public function getAlias(string $name): AA_Alias
    {
        switch ($this->c[0]) {
            case 'created':
            case 'execute_after':
            case 'time':
                return new AA_Alias($name, "f_d:Y-m-d H:i:s", $this->c[0], $this->c[0]);
        }
        return match ($this->_getBaseType()) {
            'timestamp'                                        => new AA_Alias($name, "f_d:Y-m-d H:i:s", $this->c[0], $this->c[0]),
            'mediumtext', 'mediumblob', 'longblob', 'longtext' => new AA_Alias($name, "f__:{safe:{shorten:_#this:250:3}}", $this->c[0], $this->c[0]),
            default                                            => new AA_Alias($name, "f_t::safe", $this->c[0], $this->c[0]),
        };
        // was f_1 but aa_log.php then showed messed HTML
        // return GetAliasDef( "f_t:{expandable:_#this:30:...:&raquo;:&laquo;}", $this->c[0], $this->c[0]);
    }

    public function getFieldDef()
    {
        return GetFieldDef($this->c[0], $this->c[0], $this->_getSearchType(), false, 1, 1);
    }

    public function getEmptyValue(): int|string
    {
        return in_array($this->_getSearchType(), ['numeric','date']) ? 0 : '';
    }
}

class AA_MbI
{
    /**
     *  The reason, why we use this short variables is, that the metabase is here
     *  stored as serialized string and I want to keep it as short as possible
     */
    public $t; // table name
    public $n; // index name
    public $s; // sort of index P|U|I  (= PRIMARY|UNIQUE|INDEX)
    public $c = []; // columns array (array of column names of array (name,part) for partial column index (like text(10))

    public function __construct($table = null, $name = null, $sort = null)
    {
        $this->t = $table;
        $this->n = $name;
        $this->s = $sort;
    }

    /** Plain-array form of the index: [t, n, s, c]. */
    public function toArray(): array
    {
        return ['t' => $this->t, 'n' => $this->n, 's' => $this->s, 'c' => $this->c];
    }

    /** Rebuild an index from its toArray() form (bypasses addColumn()). */
    public static function fromArray(array $a): AA_MbI
    {
        $index = new self();
        $index->t = $a['t'];
        $index->n = $a['n'];
        $index->s = $a['s'];
        $index->c = $a['c'];
        return $index;
    }

    public function addColumn($position, $column, $sub_part = ''): void
    {
        $this->c[(int) $position] = $sub_part ? [$column, $sub_part] : $column;
    }

    public function getCreateSql(): string
    {
        $cols = [];
        foreach ($this->c as $col) {
            $cols[] = is_array($col) ? '`' . $col[0] . '`(' . $col[1] . ')' : "`$col`";
        }
        $cols_list = implode(',', $cols);
        return match ($this->s) {
            'P'     => "PRIMARY KEY ($cols_list)",
            'U'     => 'UNIQUE KEY ' . $this->n . " ($cols_list)",
            default => 'KEY ' . $this->n . " ($cols_list)",
        };
    }
}



class AA_MbT implements IteratorAggregate, ArrayAccess, Countable
{
    /** @var string Name of the table */
    public $t;

    /** @var string[] of PRIMARY KEY columns */
    public $k = [];

    /** @var AA_MbI[] array of INDEXES: array(index_name => AA_MbI) */
    public $i = [];

    /** @var AA_MbC[] array of table columns */
    public $c = [];

    /** @var string[] array of table flags - like ENGINE=InnoDB, DEFAULT CHARSET=cp1250 */
    public $f;

    // This is temporary solution - we will use some better structure (MDB2?)
    // for table definition in order we can check the field type,
    // the indexes, generate sql_update script, ...
    public function __construct($tablename = null, $columns = null, $indexes = '')
    {
        $this->t = $tablename;
        foreach (($columns ?? []) as $column) {
            $aa_column  = new AA_MbC($column);
            $this->c[$column['Field']] = new AA_MbC($column);
            if ($aa_column->isKey()) {
                $this->k[$column['Field']] = true;
            }
        }
        if (!empty($indexes)) {
            // indexes array looks like:
            // [0] => Array (
            //      [Table] => email [Non_unique] => 0 [Key_name] => PRIMARY [Seq_in_index] => 1 [Column_name] => id [Collation] => A [Cardinality] => 71 [Sub_part] => [Packed] => [Null] => [Index_type] => BTREE [Comment] => )
            foreach ($indexes as $index_part) {
                if (!isset($this->i[$index_part['Key_name']])) {
                    $index_type = ($index_part['Key_name'] === 'PRIMARY') ? 'P' : (($index_part['Non_unique']) ? 'I' : 'U');
                    $this->i[$index_part['Key_name']] = new AA_MbI($index_part['Table'], $index_part['Key_name'], $index_type);
                }
                $idx = &$this->i[$index_part['Key_name']]; // to work in php4
                $idx->addColumn((int) $index_part['Seq_in_index'], $index_part['Column_name'], $index_part['Sub_part']);
            }
        }
    }

    /** Plain-array form: t, k, i (=> AA_MbI.toArray()), c (=> AA_MbC.toArray()), f. */
    public function toArray(): array
    {
        $indexes = [];
        foreach ($this->i as $name => $index) {
            $indexes[$name] = $index->toArray();
        }
        $columns = [];
        foreach ($this->c as $name => $column) {
            $columns[$name] = $column->toArray();
        }
        return ['t' => $this->t, 'k' => $this->k, 'i' => $indexes, 'c' => $columns, 'f' => $this->f];
    }

    /** Rebuild a table (and its nested indexes/columns) from its toArray() form. */
    public static function fromArray(array $a): AA_MbT
    {
        $table = new self();
        $table->t = $a['t'];
        $table->k = $a['k'];
        $table->f = $a['f'];
        foreach ($a['i'] as $name => $index) {
            $table->i[$name] = AA_MbI::fromArray($index);
        }
        foreach ($a['c'] as $name => $column) {
            $table->c[$name] = AA_MbC::fromArray($column);
        }
        return $table;
    }

    public static function factoryFromDb($tablename): AA_MbT
    {
        $columns = DB_AA::select(['Field' => []], "SHOW FULL COLUMNS FROM `$tablename`");
        $indexes = DB_AA::select([], "SHOW INDEX FROM `$tablename`");
        return new AA_MbT($tablename, $columns, $indexes);
    }

    /** get Table name */
    public function getName(): string
    {
        return $this->t;
    }

    public function getColumnNames(): array
    {
        return array_keys($this->c);
    }

    /** Is the $columnname the column in this table? */
    public function isColumn($columnname): bool
    {
        return isset($this->c[$columnname]);
    }

    /** get array of Column objects
     *  @return AA_MbC[]
     */
    public function getColumns(): array
    {
        return $this->c;
    }

    /** get Column objects
     *  @return AA_MbC
     */
    public function getColumn($columnname): AA_MbC
    {
        return $this->c[$columnname];
    }

    public function getKeys(): array
    {
        return array_keys($this->k);
    }

    public function isKey($columnname): bool
    {
        return ((bool) $this->k[$columnname]);
    }

    /** get type of this key for zids object - 's' | 'w' | 'p' | 'l'
     * @param string $key
     */
    public function getZidsType(string $key): string
    {
        $column = $this->c[$key];
        if ($column->getFieldType() === 'int') {
            return 's';   // short int
        }
        if ($column->getMaxLength() > 32) {
            return 'w';   // sting
        }
        return AA_Metabase::isPacked($this->t, $key) ? 'p' : 'l';
    }


    /** getCreateSql() with the indexes by name: their order means nothing, and an index dropped
     *  and added again goes last on the live table */
    public function getCompareSql(): string
    {
        $table = clone $this;
        ksort($table->i);
        return $table->getCreateSql();
    }

    public function getCreateSql(string $prefix = '', string $engine_template_table = ''): string
    {
        $sql_parts = [];
        foreach ($this->c as $column) {
            $sql_parts[] = $column->getCreateSql();
        }
        foreach ($this->i as $index) {
            $sql_parts[] = $index->getCreateSql();
        }

        $table_details = [];
        if ($engine_template_table) {
            ['ENGINE' => $engine, 'TABLE_COLLATION' => $collation] = \AA\IO\DB\DB_AA::select1(['ENGINE','TABLE_COLLATION'], 'SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES', [['TABLE_NAME',$engine_template_table]]);
            if ($engine) {
                $table_details[] = "ENGINE=$engine";
            }
            if ($collation) {
                $table_details[] = "COLLATE='$collation'";
            }
        }
        return "CREATE TABLE IF NOT EXISTS `$prefix" . $this->t . "` (\n" . implode(",\n", $sql_parts) . "\n) " . implode(',', $table_details);
    }

    /** returns database structure definition as PHP code (array) */
    public function getDefinition(): string
    {
        $defs = [];
        foreach ($this->c as $column) {
            $defs[] = $column->getDefinition();
        }
        $ret  = "\n    '" . $this->t . "' => array(";
        $ret .= implode(",", $defs);
        $ret .= "\n    )";
        return $ret;
    }


    /** setFromSql function
     *  Fills AA_MbT structure from the result of SQL command:
     *     SHOW CREATE TABLE $tablename
     * @param $tablename
     * @param $create_SQL
     */
    //    function setFromSql($tablename, $create_SQL) {
    //        $this->t = $tablename;
    //        foreach (explode("\n", $create_SQL) as $row) {
    //            $row = trim($row);
    //            // first row - CREATE TABLE - no need to grab anything from it
    //            if ( strpos($row, 'CREATE TABLE') === 0 ) {
    //                continue;
    //            }
    //            // field definition row - grab it
    //            if ( (strpos($row, 'KEY') === 0) OR
    //                (strpos($row, 'UNIQUE KEY') === 0) OR
    //                (strpos($row, 'PRIMARY KEY') === 0) ) {
    //                $this->_setIndexFromSql($row);
    //                continue;
    //            }
    //            if ( strpos($row, ')') === 0 ) {
    //                $this->_setFlagFromSql($row);
    //                continue;
    //            }
    //            // else urecognized row
    //            echo $row;
    //        }
    //    }

    /**   */
    public function generateAliases(): AA_Aliases
    {
        $aliases = new AA_Aliases();
        foreach ($this->c as $column_name => $column) {
            $aliasname = "_#" . substr(str_pad(strtoupper($column_name), 8, '_'), 0, 8);
            $i = 2;
            while ($aliases->getAlias($aliasname)) { // already defined
                $aliasname = substr($aliasname, 0, -strlen((string) $i)) . (string) $i;
                ++$i;
            }
            $aliases->addAlias($column->getAlias($aliasname));
        }
        return $aliases;
    }

    /**  */
    public function getSearchArray(): array
    {
        $fieldarr = [];
        foreach ($this->c as $column_name => $column) {
            // @todo - make alias field type aware
            $fieldarr[$column_name] = $column->getFieldDef();
        }
        return $fieldarr;
    }

    /**
     *  @return Searchfields
     */
    public function getSearchfields(): Searchfields
    {
        $searchfields = new AA\Util\Searchfields();
        foreach ($this->c as $column_name => $column) {
            $searchfields->addArray($column_name, $column->getFieldDef());
        }
        return $searchfields;
    }

    /** returns associative array of table fields filled with '' or 0 (based on column type) */
    public function getEmptyRowArray(): array
    {
        $fieldarr = [];
        foreach ($this->c as $column_name => $column) {
            $fieldarr[$column_name] = $column->getEmptyValue();
        }
        return $fieldarr;
    }

    /** Prepare Cvarset for given data array (set right types/values/...)
     *
     * @param  array<string, mixed> $data    data array - all data unpacked. The values are converted to correct ones
     * @return Cvarset|null
     */
    public function getVarset4Data(array $data): ?Cvarset
    {
        if (!($cols = $this->getColumns())) {
            return null;
        }
        $varset = new Cvarset();

        foreach ($cols as $colname => $coldef) {
            if (isset($data[$colname])) {
                $value = $data[$colname];
                $iskey = $coldef->isKey();
                if ($coldef->getFieldType() === 'int') {
                    $varset->add($colname, 'number', (int) $value, $iskey);
                } elseif (AA_Metabase::isPacked($this->t, $colname) && is_long_ids($value)) {
                    $varset->add($colname, 'unpacked', $value, $iskey);
                } elseif (AA_Metabase::isPacked($this->t, $colname)) {
                    // '0' or a shorter id of old data: written as the quoted pack_id() text
                    $varset->add($colname, 'text', pack_id((string) $value), $iskey);
                } else {
                    // no substr() to the column length: MySQL (non-strict sql_mode) cuts varchar by characters, not bytes
                    $varset->add($colname, 'text', $value, $iskey);
                }
            }
        }
        return $varset;
    }

    /** Prepare data for insert (set right types/values/...)
     *
     * @param  array<string, mixed> $data    data array - all data unpacked. The values are converted to correct ones
     * @return array ['colname' => 'value', ...] - SQL literals of the schema columns $data sets (not null), in schema order
     */
    public function getSQLData(array $data): array
    {
        if (!($cols = $this->getColumns())) {
            return [];
        }
        $ret = [];

        foreach ($cols as $colname => $coldef) {
            if (isset($data[$colname])) {
                $value = $data[$colname];
                // $iskey = $coldef->isKey();
                $type = $coldef->getFieldType();
                if ($type === 'int') {
                    $ret[$colname] = (int) $value;
                } elseif ($type === 'float') {
                    $ret[$colname] = (float) $value;
                } elseif (AA_Metabase::isPacked($this->t, $colname)) {
                    $ret[$colname] = is_long_ids($value) ? '0x' . $value : qquote(pack_id((string) $value));
                } else {
                    $ret[$colname] = qquote($value);
                }
            }
        }
        return $ret;
    }

    /** implements IteratorAggregate */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->c);
    }

    /** Countable interface */
    public function count(): int
    {
        return count($this->c);
    }

    /** ArrayAccess interface */
    public function offsetSet($offset, $value): void
    {
        $this->c[$offset] = $value;
    }
    public function offsetExists($offset): bool
    {
        return array_key_exists($offset, $this->c);
    }
    public function offsetUnset($offset): void
    {
        unset($this->c[$offset]);
    }
    public function offsetGet($offset): mixed
    {
        return $this->c[$offset] ?? null;
    }
}

/** @todo convert to static class variables after move to PHP5 */
class AA_Metabase
{
    /**
     * @var AA_MbT[]
     * we can change it to protected, but then we need to regenerate the serialized string
     */
    public $tables = [];

    /** AA_Metabase function - constructor
     *  Do not use it in most cases - use $metabase = AA::Metabase() instead
     */
    public function __construct() {}

    /** Readable plain-array form of the whole schema: [tablename => AA_MbT.toArray()]. */
    public function toArray(): array
    {
        $tables = [];
        foreach ($this->tables as $name => $table) {
            $tables[$name] = $table->toArray();
        }
        return $tables;
    }

    /** Rebuild the metabase (and all nested objects) from a toArray()/schema-file array. */
    public static function fromArray(array $tables): AA_Metabase
    {
        $metabase = new self();
        foreach ($tables as $name => $table) {
            $metabase->tables[$name] = AA_MbT::fromArray($table);
        }
        return $metabase;
    }

    /** Render the schema as the readable, diffable content of include/metabase_schema.php.
     *  One line per column / per index → clean blame and grep. The two "Generate metabase"
     *  optimize actions emit exactly this, so regeneration yields minimal diffs. Loaded back
     *  by singleton() via fromArray(require ...). */
    public function renderSchemaPhp(): string
    {
        $php = "<?php\n"
            . "/* The expected AA database schema (" . count($this->tables) . " tables) as a readable PHP array.\n"
            . " * Hand-edit for small schema changes (one line per column), or regenerate from a reference DB\n"
            . " * via the \"Generate metabase\" optimize action. Loaded by AA_Metabase::singleton() via fromArray(require). */\n\n"
            . "return [\n";
        foreach ($this->toArray() as $tname => $t) {
            $php .= '    ' . self::exportInline($tname) . " => [\n";
            $php .= "        't' => " . self::exportInline($t['t']) . ",\n";
            $php .= "        'k' => " . self::exportInline($t['k']) . ",\n";
            $php .= "        'i' => " . self::exportBlock($t['i'], 8) . ",\n";
            $php .= "        'c' => " . self::exportBlock($t['c'], 8) . ",\n";
            $php .= "        'f' => " . self::exportInline($t['f']) . ",\n";
            $php .= "    ],\n";
        }
        return $php . "];\n";
    }

    /** Export a scalar or (recursively) an array onto a single line, short [] syntax. */
    private static function exportInline($value): string
    {
        if (!is_array($value)) {
            return var_export($value, true);
        }
        $is_list = array_is_list($value);
        $parts   = [];
        foreach ($value as $k => $v) {
            $parts[] = ($is_list ? '' : self::exportInline($k) . ' => ') . self::exportInline($v);
        }
        return '[' . implode(', ', $parts) . ']';
    }

    /** Export an assoc array as one entry per line (each value inlined), indented; [] if empty. */
    private static function exportBlock(array $a, int $indent): string
    {
        if ($a === []) {
            return '[]';
        }
        $pad = str_repeat(' ', $indent + 4);
        $out = "[\n";
        foreach ($a as $k => $v) {
            $out .= $pad . self::exportInline($k) . ' => ' . self::exportInline($v) . ",\n";
        }
        return $out . str_repeat(' ', $indent) . ']';
    }

    /** Static function called like $metabase = AA::Metabase() */
    public static function singleton()
    {
        static $instance = null;
        if ($instance === null) {
            // The expected DB schema is stored as a readable, diffable PHP array in
            // include/metabase_schema.php (hand-edit for small changes, or regenerate via the
            // "Generate metabase" optimize action). It is opcache-compiled, so loading it is
            // ~4-5x cheaper than the previous unserialize() of a 109 KB blob.
            $instance = self::fromArray(require __DIR__ . "/metabase_schema.php");
        }
        return $instance;
    }

    public function addTable(AA_MbT $table): void
    {
        $this->tables[$table->getName()] = $table;
    }

    /** Returns array of all table names */
    public function getTableNames(): array
    {
        return array_keys($this->tables);
    }

    /** Returns array of keys for given table */
    public function getKeys($tablename): array
    {
        $table = $this->tables[$tablename];
        return is_object($table) ? $table->getKeys() : [];
    }

    /** is the $columnname the key for given table */
    public function isKey($tablename, $columnname): bool
    {
        $table = $this->tables[$tablename];
        return is_object($table) ? $table->isKey($columnname) : false;
    }

    /** Returns array of all columns */
    public function getColumnNames($tablename): array
    {
        $table = $this->tables[$tablename];
        return is_object($table) ? $table->getColumnNames() : [];
    }

    /** Is the $columnname the column in the $tablename? */
    public function isColumn($tablename, $columnname): bool
    {
        $table = $this->tables[$tablename];
        return is_object($table) ? $table->isColumn($columnname) : false;
    }

    /** returns asociative array of table columns as object, so we can work with it (in varset INSERT, ...)
     * @param $tablename
     * @return AA_MbC[]
     */
    public function getColumns($tablename): array
    {
        return $this->tables[$tablename]->getColumns();
    }

    /** Sets the key columns (and the module column) of $data to the row (and module) of $identifier.
     *  The values are UNPACKED ids, as doUpdate()/doInsert()/doDelete() expect - they pack them.
     * @param array         $data
     * @param AA_Identifier $identifier
     */
    public function fillKeys(&$data, $identifier): void
    {

        $module_id  = $identifier->getModuleId();
        $tablename  = $identifier->getTable();
        $row        = $identifier->getRow();

        if (!self::isTableKeysSupported($tablename)) {
            // you can't use this function for that the table - this is programmers mistake - correct the code
            echo "table $tablename not supported in AA_Metabase::fillKeys()";
            exit;
        }
        $table      = $this->tables[$tablename];
        $keys       = $table->getKeys();

        // make sense just for single-keys or two-key, where second key
        // is replaced by $module_field below (for field table)
        $module_field = self::getModuleField($tablename);
        foreach ($keys as $key) {
            if ($key == $module_field) {
                // we will assign it in next step - reassignModule()
                continue;
            }
            $data[$key] = $row;
        }
        $this->reassignModule($data, $tablename, $module_id);
    }

    /** changes the column of the table which identifies to which module it
     *  belongs. That way you just move the data to another module
     *  It modifies $data parameter - sets the UNPACKED $module_id (the do*() methods pack it)
     */
    public function reassignModule(&$data, $tablename, $module_id): void
    {
        if ($module_field = self::getModuleField($tablename)) {
            $data[$module_field] = $module_id;
        }
    }

    /** static method */
    public static function isTableKeysSupported($tablename): bool
    {
        static $SUPPORTED_TABLES = [
            // single keys
            'alerts_collection',
            'alerts_filter',
            // 'auth_log',  // removed table HM 2025
            'auth_user',
            'change',
            'change_record',
            'central_conf',
            'constant',
            'constant_slice',
            'cron',
            'db_sequence',
            'discussion',
            'email',
            'event',
            'external_feeds',
            'item',
            'jump',
            'links',
            'links_cat_cat',
            'links_categories',
            'links_languages',
            'links_link_cat',
            'links_links',
            'links_regions',
            'log',
            'module',
            'nodes',
            'offline',
            'object_float',
            'object_integer',
            'object_text',
            'pagecache',
            'pagecache_str2find',
            'polls',
            'polls_answer',
            'polls_design',
            'polls_log',
            'post2shtml',
            'profile',
            'rssfeeds',
            'searchlog',
            'site',
            'site_spot',
            'slice',
            'slice_owner',
            'toexecute',
            'users',
            'view',
            'wizard_template',
            // supported table using double keys (slice_id,id)
            'field',
            // unsupported table using triple keys (slice_id,uid,`function`)
            // 'email_notify'
        ];
        // search and replace should be done here
        // feeds from_id to_id
        // feedmap   from_slice_id, to_slice_id
        // feedperms from_id,       to_id
        // relation  source_id,     destination_id
        return in_array($tablename, $SUPPORTED_TABLES);
    }

    /** static method
     * @param $tablename
     * @param $column
     * @return bool
     */
    public static function isPacked($tablename, $column): bool
    {
        return in_array($column, self::getPacked($tablename));
    }

    /** get type of the column in the table */
    public function getFieldType($tablename, $column): string
    {
        return $this->tables[$tablename]->getColumn($column)->getFieldType();
    }

    /** auto generated fields */
    public const AUTO_KEYS = ['polls_design' => 'id'];

    /** static method */
    public static function getModuleFields(): array
    {
        static $MODULE_KEYS = [
            'alerts_collection'   => 'module_id',
            'constant_slice'      => 'slice_id',
            'ef_permissions'      => 'slice_id',
            'email'               => 'owner_module_id',
            'email_notify'        => 'slice_id',
            'external_feeds'      => 'slice_id',
            'field'               => 'slice_id',
            'item'                => 'slice_id',
            'links'               => 'id',
            'module'              => 'id',
            'polls'               => 'module_id',
            'polls_design'        => 'module_id',
            'profile'             => 'slice_id',
            'rssfeeds'            => 'slice_id',
            'site'                => 'id',
            'site_spot'           => 'site_id',
            'slice'               => 'id',
            'view'                => 'slice_id',
        ];
        return $MODULE_KEYS;
    }

    /** static method */
    public static function getModuleField($tablename): ?string
    {
        return self::getModuleFields()[$tablename] ?? null;   // null for tables without it (constant)
    }

    /** static method
     *  @todo - would be probably better to move it to AA_MbT
     *  @todo - convert to static class members for PHP5
     */
    public static function getPacked($tablename): array
    {
        static $PACKED = [
            'alerts_collection'   => ['module_id','slice_id'],
            'constant'            => ['id','ancestors'], // ancestors are multiple - joined!
            'constant_slice'      => ['slice_id'],
            'content'             => ['item_id'],
            'discussion'          => ['id','item_id'],
            'ef_categories'       => ['category_id','target_category_id'],
            'ef_permissions'      => ['slice_id'],
            'email'               => ['owner_module_id'],
            'email_notify'        => ['slice_id'],
            'external_feeds'      => ['slice_id', 'remote_slice_id'],
            'feedmap'             => ['from_slice_id', 'to_slice_id'],
            'feedperms'           => ['from_id','to_id'],
            'feeds'               => ['from_id','to_id','category_id','to_category_id'],
            'field'               => ['slice_id'],
            'hit_long_id'         => ['id'],
            'item'                => ['id','slice_id'],  // slice_id is not part of key, here
            'jump'                => ['slice_id', 'dest_slice_id'],
            'links'               => ['id'],             // special meaning of first characters - category!!!
            'module'              => ['id', 'owner'],
            'offline'             => ['id'],
            'polls'               => ['module_id'],
            'polls_design'        => ['module_id'],
            'profile'             => ['slice_id'],
            'relation'            => ['source_id', 'destination_id'],
            'rssfeeds'            => ['slice_id'],
            'site'                => ['id'],
            'site_spot'           => ['site_id'],
            'slice'               => ['id', 'owner', 'mlxctrl'],
            'slice_owner'         => ['id'],
            'view'                => ['slice_id'],
        ];
        return $PACKED[$tablename] ?? [];
    }

    /** The best (most safe) low-level approach to update data in the AA table - the data are checked before storing against database schema
     *  - the packed data are packed automatically, the integer data are typecast to numbers, ...
     *  For now, the table keys must be provided - we are not able to update multiple records. Could be changed in future
     *  usage: AA::Metabase()->doUpdate(...);
     * @param  string   $tablename table, where to store data
     * @param  array<string, mixed> $data    data array - all data unpacked. The values are converted to correct ones
     * @param  string|null $nohalt  'nohalt' - do not halt on database error
     * @param  string   $history_key resource id the change is written to the history under ('' = no history)
     * @return bool|int   0|false on error
     */
    public function doUpdate($tablename, $data, ?string $nohalt = null, string $history_key = ''): bool|int
    {
        if (($varset = $this->getVarset4Data($tablename, $data)) && $this->isKeysFilled($tablename, $varset)) {
            return $varset->doUpdate($tablename, $nohalt, $history_key);
        }
        return false;
    }

    /** The best (most safe) low-level approach to store data in the AA table - the data are checked before storing against database schema
     *  - the packed data are packed automatically, the integer data are typecast to numbers, ...
     *
     * @usage AA::Metabase()->doInsert('log', ['time'=>time(),'user'=>$uid, 'type'=>$event, 'selector'=>$selector, 'params'=>$params]);
     *
     * @param  string       $tablename  - table, where to store data
     * @param  array<string, mixed> $data       - data array - all data unpacked. The values are converted to correct ones
     * @param  string|null  $nohalt     'nohalt' - do not halt on database error
     *
     * @return bool|int   0|false on error, int on success. If INSERT, returns last inserted id or PHP_INT_MAX if not provided
     */
    public function doInsert(string $tablename, array $data, ?string $nohalt = null): bool|int
    {
        if ($varset = $this->getVarset4Data($tablename, $data)) {
            return $varset->doInsert($tablename, $nohalt);
        }
        return false;
    }

    /** use with doInsert - generates auto key for new records for supported tables
     *  maybe we can do it automaticaly in doInsert
     *  maybe we can do it based on getKey() method and using the getMaxLength() method
     */
    public function autoInsertKey($tablename, array &$data): array
    {
        if ($fld = self::AUTO_KEYS[$tablename] ?? false) {
            $data[$fld] = new_id();
        }
        return $data;
    }

    /** The best (most safe) low-level approach to store data in the AA table - the data are checked before storing against database schema
     *  - the packed data are packed automatically, the integer data are typecast to numbers, ...
     * @usage AA::Metabase()->doReplace('log', ['time'=>time(),'user'=>$uid, 'type'=>$event, 'selector'=>$selector, 'params'=>$params]);
     * @param  string   $tablename - table, where to store data
     * @param  array<string, mixed> $data      - data array - all data unpacked. The values are converted to correct ones
     * @param  string   $nohalt  'nohalt' - do not halt on database error
     * @return bool|int   0|false on error
     */
    public function doReplace($tablename, $data, $nohalt = null): bool|int
    {
        if (($varset = $this->getVarset4Data($tablename, $data)) && $this->isKeysFilled($tablename, $varset)) {
            return $varset->doTrueReplace($tablename, $nohalt);
        }
        return false;
    }

    /** The same as doInsert, but for multiple similar inserts
     * @usage AA::Metabase()->doInsertMulti('log', [['time'=>time(),'user'=>$uid1, 'type'=>$event, 'selector'=>$selector, 'params'=>$params],
     *                                              ['time'=>time(),'user'=>$uid2, 'type'=>$event, 'selector'=>$selector, 'params'=>$params]]);
     * @see AA_Metabase::doInsert()
     * @param  string     $tablename table, where to store data
     * @param  array<array<string, mixed>> $data  data rows - all data unpacked. The values are converted to correct ones
     * @param  string     $nohalt  unused - an error halts unless the caller switched halting off on the handle (Logs)
     * @return bool|int   number of inserted rows, false on error
     */
    public function doInsertMulti($tablename, $data, $nohalt = null): bool|int
    {
        $table    = $this->tables[$tablename];
        $inserted = 0;
        foreach (array_chunk($data, 1000) as $chunk) {
            $rows = array_map(static fn($row) => $table->getSQLData($row), $chunk);
            // only the columns the rows set, as doInsert() does: the live table decides the rest - it may lack
            // a schema column (hit_archive.duration) or have another default. DEFAULT, not NULL, for a row without
            // one of them: NULL into a NOT NULL column stores 0/'' (and fails in a one-row INSERT)
            $columns = array_intersect($table->getColumnNames(), array_keys(array_merge(...$rows)));
            $values  = [];
            foreach ($rows as $row) {
                $values[] = '(' . implode(',', array_map(static fn($col) => $row[$col] ?? 'DEFAULT', $columns)) . ')';
            }
            $num_rows = DB_AA::sql("INSERT INTO `$tablename` (" . implode(',', array_map(static fn($col) => "`$col`", $columns)) . ') VALUES ' . implode(', ', $values));
            if ($num_rows === false) {
                return false;
            }
            $inserted += $num_rows;
        }
        return $inserted;
    }

    /** The best (most safe) low-level approach to delete data in the AA table
     *  - the packed keys are packed automatically, the integer data are typecast to numbers, ...
     *  usage: AA::Metabase()->doDelete(...);
     * @param string   $tablename
     * @param array<string, mixed> $keydata
     * @param string|null $nohalt  'nohalt' - do not halt on database error
     * @return bool|int   0|false on error
     */
    public function doDelete(string $tablename, array $keydata, ?string $nohalt = null): bool|int
    {
        if (($varset = $this->getVarset4Data($tablename, $keydata)) && $this->isKeysFilled($tablename, $varset)) {
            return $varset->doDelete($tablename, $nohalt);
        }
        return false;
    }

    /** Prepare Cvarset for given data array (set right types/values/...)
     *
     * @param  string   $tablename table to work with
     * @param  array<string, mixed> $data    data array - all data unpacked. The values are converted to correct ones
     * @return Cvarset|null
     */
    private function getVarset4Data(string $tablename, array $data): ?Cvarset
    {
        return $this->tables[$tablename]->getVarset4Data($data);
    }

    /** Check is all keys for current table are filled in Cvarset
     * @param string  $tablename
     * @param Cvarset $varset
     * @return bool
     */
    private function isKeysFilled($tablename, $varset): bool
    {
        if ($table_keys = $this->getKeys($tablename)) {
            foreach ($table_keys as $key) {
                if (!$varset->get((string) $key)) {  // 0 and null is probably not allowed for key
                    return false;
                }
            }
            return true;
        }
        return false;
    }

    /** analyzeStructure - reads table and column definitions from database
     * @param bool  $regular
     * @param array $only_tables
     */
    public function loadFromDb(bool $regular = false, array $only_tables = []): void
    {
        $db     = getDb();
        $tables = $db->table_names();
        foreach ($tables as $table) {
            if ($only_tables && !in_array($table['table_name'], $only_tables)) {
                continue;
            }
            if (!$regular || !in_array(substr($table['table_name'], 0, 4), ['bck_', 'tmp_'])) {
                $this->tables[$table['table_name']] = AA_MbT::factoryFromDb($table['table_name']);
            }
        }
        freeDb($db);
    }

    public function getCreateSql(string $tablename = '', string $prefix = '', string $engine_template_table = ''): string
    {
        $sql_parts = [];
        $tables = $tablename ? [$this->tables[$tablename]] : $this->tables;
        foreach ($tables as $table) {
            if (is_object($table)) {
                $sql_parts[] = $table->getCreateSql($prefix, $engine_template_table);
            }
        }
        return implode("\n", $sql_parts);
    }

    /** returns database structure definition as PHP code (array) */
    public function getDefinition(): string
    {
        $defs = [];
        foreach ($this->tables as $table) {
            $defs[] = $table->getDefinition();
        }
        $ret = "array(";
        $ret .= implode(",", $defs);
        $ret .= "\n);\n";
        return $ret;
    }

    /** addTableFromSql function
     * @param $tablename
     * @param $create_SQL
     */
    //    function addTableFromSql($tablename, $create_SQL) {
    //        $this->tables[$tablename] = new AA_MbT;
    //        $this->tables[$tablename]->setFromSQL($tablename, $create_SQL);
    //    }

    /** returns associative array of table fields filled with '' or 0 (based on column type)  */
    public function getEmptyRowArray($tablename): array
    {
        return $this->tables[$tablename]->getEmptyRowArray();
    }

    /** getSearchArray function
     * @deprecated - use getSearchfields
     */
    public function getSearchArray($tablename): array
    {
        return $this->tables[$tablename]->getSearchArray();
    }

    /** getSearchfields function
     */
    public function getSearchfields($tablename): Searchfields
    {
        return $this->tables[$tablename]->getSearchfields();
    }

    /** get zid-object type for key of this table - 's' | 'w' | 'p' | 'l' | '' (error) */
    public function getZidsType($tablename): string
    {
        $keys     = $this->getKeys($tablename);
        if (count($keys) !== 1) {
            warn("you can't use this function for [$tablename] table - " . count($keys) . " keys");
            return '';
        }
        return $this->tables[$tablename]->getZidsType($keys[0]);
    }

    /** @return array rows from $tablename for given $module_id in form
     *          ret[id] = array('column'=>value, ...)
     *          ids are always UNPACKED (as keys as well as in values)
     */
    public function getModuleRows($tablename, $module_id): array
    {
        $JOIN = [
            // @todo - do the 'constant' better - check the fields setting, and get all the constants used
            'constant'   => ['scr_field' => 'group_id', 'dest_table' => 'constant_slice', 'dest_field' => 'group_id'],

            'content'    => ['scr_field' => 'item_id',  'dest_table' => 'item',           'dest_field' => 'id'],
            'discussion' => ['scr_field' => 'item_id',  'dest_table' => 'item',           'dest_field' => 'id'],

            'alerts_collection_filter'   => ['scr_field' => 'collectionid',  'dest_table' => 'alerts_collection', 'dest_field' => 'id'],
            'alerts_collection_howoften' => ['scr_field' => 'collectionid',  'dest_table' => 'alerts_collection', 'dest_field' => 'id'],
        ];

        $module_table = $tablename;
        $join_sql     = '';

        if (isset($JOIN[$tablename])) {
            $j = $JOIN[$tablename];
            $module_table = $j['dest_table'];
            $join_sql     = "INNER JOIN $module_table ON $tablename." . $j['scr_field'] . "=$module_table." . $j['dest_field'];
        }

        $module_field = self::getModuleField($module_table);
        if (!$module_field) {
            // you can't use this function for that table - this is programmers mistake - correct the code
            echo "table $tablename not supported in AA_Metabase::getModuleRows() - no module field";
            exit;
        }

        $table_keys   = $this->getKeys($tablename);
        if (count($table_keys) === 1) {
            $table_key = $table_keys[0];
        } elseif (count($table_keys) === 2 && in_array($module_field, $table_keys)) {
            // two keys, but one of them is module_id, which is OK
            $table_key = ($table_keys[0] == $module_field) ? $table_keys[1] : $table_keys[0];
        } else {
            // you can't use this function for that table - this is programmers mistake - correct the code
            //echo "table $tablename not supported in AA_Metabase::getModuleRows() - too much keys";
            //exit;
            $table_key = '';  // we do not use the key
        }

        $module_val   = self::isPacked($module_table, $module_field) ? q_pack_id($module_id) : $module_id;
        $key_used     = $table_key ? (self::isPacked($tablename, $table_key) ? "unpack:$table_key" : $table_key) : ('');

        $SQL = "SELECT $tablename.* FROM $tablename $join_sql WHERE $module_table.$module_field = '$module_val'";

        $ret          = GetTable2Array($SQL, $key_used, 'aa_fields');
        if (!is_array($ret)) {
            $ret = [];
        }

        $this->unpackIds($tablename, $ret);
        return $ret;
    }

    public function unpackIds($tablename, &$data): void
    {
        $packed_columns = self::getPacked($tablename);
        foreach ($packed_columns as $column) {
            foreach ($data as $k => $v) {
                $data[$k][$column] = unpack_id($v[$column]);
            }
        }
    }

    public function packIds($tablename, &$data): void
    {
        $packed_columns = self::getPacked($tablename);
        foreach ($packed_columns as $column) {
            foreach ($data as $k => $v) {
                $data[$k][$column] = pack_id($v[$column]);
            }
        }
    }


    /** Compares two metabases - this and the $metabase supplied by the parameter
     *  You can use it to check, which tables should be updated
     * @param AA_Metabase $metabase - the second metabase which will be compared to $this
     *
     * @return array
     */
    public function compare(AA_Metabase $metabase): array
    {
        $diffs = [];

        // for us are varchar and char the same - some tables are never converted
        // to char in some versions of MySQL, so the test is always false
        $eq_vars   = ['varchar', " default '                '", " default '                                '", " default '0'", "timestamp NOT NULL", 'varbinary'];
        $eq_novars = ['char'   , '', '', '', 'timestamp', 'binary'];

        foreach ($this->tables as $tablename => $table) {
            $table_sql_1 = $table->getCompareSql();
            $table_sql_2 = isset($metabase->tables[$tablename]) ? $metabase->tables[$tablename]->getCompareSql() : '';

            $diffs[$tablename] = [
                'equal'  => (str_replace($eq_vars, $eq_novars, $table_sql_1) === str_replace($eq_vars, $eq_novars, $table_sql_2)),
                'table1' => $table_sql_1,
                'table2' => $table_sql_2,
            ];
        }

        // all the tables not pressent in the template
        $uncompared = array_diff($metabase->getTableNames(), $this->getTableNames());
        foreach ($uncompared as $tablename) {
            if (str_starts_with($tablename, 'bck_')) {
                continue;
            }
            $diffs[$tablename] = [
                'equal'  => false,
                'table1' => '',
                'table2' => $metabase->getCreateSql($tablename),
            ];
        }
        return $diffs;
    }

    /** getContent function for loading content of specified table for manager
     *  class
     *
     * Loads data from database for given table ids (called in itemview class)
     * and stores it in the 'Abstract Data Structure' for use with 'item' class
     *
     * @param zids $zids     array if ids to get from database
     * @param array $settings array - just one parameter: table (or owner), where to search
     * @return AA_Content[] - Abstract Data Structure containing the links data
     *                        {@link https://actionapps.org/faq/#1337}
     * @static
     * @see GetItemContent(), itemview class, item class
     */
    public static function getContent($zids, $settings): array
    {
        $ret = [];

        $tablename = $settings['table'];
        $metabase = AA::Metabase();
        $keys = $metabase->getKeys($tablename);
        if (count($keys) !== 1) {
            // you can't use this function for that table - this is programmers mistake - correct the code
            echo "Missing key for table $tablename in AA_Metabase::getContent()";
            exit;
        }
        $key = $keys[0];


        $content = AA_Content::StoreTable2AaContent("SELECT * FROM $tablename", '', $key, [$zids->whereIn($key, true)]);

        // AA::$debug&2 && AA::$dbg->group('meta');

        // it is unordered, so we have to sort it (ids with no row in the table are skipped):
        for ($i = 0, $ino = $zids->count(); $i < $ino; ++$i) {
            $id = $zids->id($i);
            if (isset($content[$id])) {
                $ret[(string) $id] = $content[$id];
            }
        }
        // tried to replace the for () with foreach (), but it is two times slower (PHP 5.3.6 - eAccelerator 0.9.6.1 )
        // reset($zids); foreach($zids as $id) { $ret[(string)$id] = $content[$id]; }
        // iterations  | for time  | foreach time
        // 20          | 2.8E-5    | 4.2E-5
        // 2000        | 0.0018    | 0.0031

        //AA::$debug&2 && AA::$dbg->groupend('meta');

        // unpack packed fields, if there are some
        $packed_columns = self::getPacked($tablename);
        foreach ($packed_columns as $column) {
            foreach ($ret as $k => $v) {
                $ret[$k]->setValue($column, unpack_id($v->getValue($column)));
            }
        }
        return $ret;
    }

    /** AA::Metabase()->queryZids - Finds link IDs for links according to given  conditions
     * @param array  $settings - array - just one parameter: table, where to search
     * @param AA_Set $set - AA_Set object which specifies Sortorder and Conditions
     *                          - there we store also BINs conditions, since each
     *                            table/module can use different Bins AND for other
     *                            the idea of BINs makes no sense at all
     * @global bool  $debug =1       - many debug messages
     * @return zids
     */
    public function queryZids($settings, $set): zids
    {
        global $debug;                 // displays debug messages

        $tablename = $settings['table'];

        if ($this->isColumn($tablename, 'status_code')) {
            $bin  = $set->getBinsNumber();
            $set->addCondition(new AA_Condition('status_code', '==', $bin));
        }

        $conds     = $set->getConds();
        $sort      = $set->getSort();

        if ($debug) {
            huhl("<br>Conds:", $conds, "<br>--<br>Sort:", $sort, "<br>--");
        }

        $fields      = $this->getSearchArray($tablename);
        $join_tables = [];   // not used in this function

        $keys        = $this->getKeys($tablename);
        if (count($keys) !== 1) {
            // you can't use this function for that table - this is programmers mistake - correct the code
            echo "Missing key for table $tablename in AA::Metabase()->queryZids()";
            exit;
        }
        $key = $keys[0];

        $SQL    = "SELECT DISTINCT $key FROM $tablename ";
        //        $SQL .= CreateBinCondition($type, $tablename);
        $where  = MakeSQLConditions($fields, $conds, $fields, $join_tables);
        $SQL   .= ($where ? "WHERE (1=1) $where" : '');
        $SQL   .= MakeSQLOrderBy($fields, $sort, $join_tables);

        return GetZidsFromSQL($SQL, $this->tables[$tablename]->getZidsType($key));
    }

    public static function queryCount($settings, $set)
    {
        $tablename = $settings['table'];
        $metabase = AA::Metabase();

        if ($metabase->isColumn($tablename, 'status_code')) {
            $bin  = $set->getBinsNumber();
            $set->addCondition(new AA_Condition('status_code', '==', $bin));
        }

        $conds = $set->getConds();

        $fields = $metabase->getSearchArray($tablename);
        $join_tables = [];   // not used in this function

        $SQL = "SELECT count(*) as count FROM $tablename ";
        $where = MakeSQLConditions($fields, $conds, $fields, $join_tables);
        $SQL .= ($where ? "WHERE (1=1) $where" : '');

        return (string) DB_AA::select1('count', $SQL);
    }


    /** Get tabledit cofiguration for easy edit and add to the table
     *  NO LONGER USED
     */
    //    function getTableditConf($tablename) {
    //        $ret = [
    //            "table"     => $tablename,
    //            "type"      => "edit",
    ////          "mainmenu"  => "modadmin",
    ////          "submenu"   => "design",
    //            "readonly"  => false,
    //            "addrecord" => false,
    ////          "cond"      => CheckPerms( AuthGetUid(), "slice", $slice_id, PS_MODP_EDIT_DESIGN),
    ////          "title"     => $title,
    ////          "caption"   => $title,
    //            "attrs"     => ["table"=>""],
    ////          "gotoview"  => "polls_designs_edit",
    //        ];
    //
    //        $table         = $this->tables[$tablename];
    //        $table_columns = $table->getColumnNames();
    //        foreach ($table_columns as $column_name) { // in priority order
    //            $field_type = 'text';    // @todo - get the type from field type
    //            $ret['fields'][$column_name] = [
    //                'caption' => $column_name,
    //                'view'    => ['type' => $field_type]
    //            ];
    //            // @todo - do better check - based on table setting
    //            if ($column_name = 'id') {
    //                $ret['fields'][$column_name]['view']['readonly'] = true;
    //            }
    //        }
    //        return $ret;
    //    }

    /** AA_Propery array - for form generation
     * @param string $tablename
     * @param bool   $edit
     * @return AA_Property[]
     */
    public function getColumnProperties(string $tablename, bool $edit = true): array
    {
        $table = $this->tables[$tablename];
        $ret   = [];
        foreach ($table as $column) { // in priority order
            if ($prop = $column->getAsProperty($edit)) {
                $ret[$column->getName()] = $prop;
            }
        }
        return $ret;
    }

    /** generate manager from database structure
     *
     * @param  string               $tablename
     * @param  Manageractions|null  $actions
     * @param  Manageractions|null  $switches
     * @param  array                $set  - like ['edit_url' => 'some_url.php?id=_#id']
     * @param  array                $addbuttons  - like ['Validate' => a_href('https://validator.w3.org/feed/...', _m('Validate')), but also negative 'Edit' => false, to remove the button, 'Delete' => false
     *
     * @return Managed
     */
    public function getManagerConf(string $tablename, Manageractions $actions = null, Manageractions $switches = null, array $set = [], array $addbuttons = []): Managed
    {
        $table         = $this->tables[$tablename];
        $aliases       = $table->generateAliases();
        $search_fields = $this->getSearchfields($tablename);

        $keys = $table->getKeys();

        if (!is_object($actions)) {
            $actions   = new Manageractions();
        }

        $buttons = [];
        $key_alias = '';
        // for now, we support only tables with 1-column key, but it could be extended to id|module type of id later
        if (count($keys) === 1) {
            if ($addbuttons['Delete'] ?? true) {    // not ['Edit'=>false]
                $actions->addAction(new Table_Row_Delete('DeleteTRow'));
            }
            // $key_alias = $aliases->getNamesForField($keys[0])[0];
            $key_alias = "{rawurlencode:{" . $keys[0] . "}}";
            if ($addbuttons['Edit'] ?? true) {
                $edit_url = array_key_exists('edit_url', $set) ? str_replace('_#id', "{rawurlencode:{" . $keys[0] . "}}", $set['edit_url']) : get_url('', ["id={rawurlencode:{" . $keys[0] . "}}"], true);  // can't be shorted - we need {} aroud the key_alias; $key_alias is sefe()d, which we not need
                $buttons['Edit'] = a_href($edit_url, _m('Edit'), ['class' => 'aa-button-edit']);
            }
        }
        $grouped   = $actions->getManagerButtonsGrouped('', $key_alias, AA::$module_id);
        $buttons   = array_merge($buttons, $grouped['primary'], $addbuttons);
        $secondary = $grouped['secondary'];

        $columns = [];
        if (count($actions)) {
            $columns['_aa_chb'] = ['',"<input name=\"chb[x$key_alias]\" value=\"\" type=checkbox>"];
        }
        if ($buttons || $secondary) {
            $cell = $buttons ? implode(' ', $buttons) : '';
            if ($secondary) {
                // Popover ID is per-row; key_alias evaluates to the row's id at render time.
                $popid = 'aa-rowmenu-' . $key_alias;
                $items = '';
                foreach ($secondary as $btn) {
                    $items .= '<div class="aa-overflow-item">' . $btn . '</div>';
                }
                $cell .= ' <button type="button" class="aa-overflow-trigger" popovertarget="' . $popid . '" aria-label="' . safe(_m('More')) . '">&hellip;</button>'
                       . '<div popover id="' . $popid . '" class="aa-overflow-menu">' . $items . '</div>';
                // The positioner JS for `.aa-overflow-menu` is loaded by adminpage.class.php
                // alongside aa-system.css, so it works for any reuse of the popover pattern.
            }
            $columns['_aa_action'] = [_m('Action'), $cell];
        }

        $aliasnames = $aliases->getNames();
        $i = 0;
        foreach ($search_fields as $fid => $fld) {
            $columns[$fid] = [$fld['name'], $aliasnames[$i++]];
        }

        $manager_settings = [
            'show'      =>  MGR_ALL & ~(MGR_SB_BOOKMARKS | MGR_SB_ALLNUM),
            'searchbar' => [
                'fields'               => $search_fields,
                'search_row_count_min' => 1,
                'order_row_count_min'  => 1,
                'add_empty_search_row' => true,
                'function'             => false,  // name of function for additional action hooked on standard filter action
            ],
            'scroller'  => [
                'listlen'              => 100,
            ],
            'itemview'  => [
                //'fields'               => $search_fields,
                'aliases'              => $aliases,
                //    static class method               , first parameter to the method
                'get_content_funct'    => [['AA_Metabase', 'getContent'], ['table' => $tablename]],
                'query_funct'          => [[AA::Metabase(), 'queryZids'], ['table' => $tablename]],
            ],
            'actions'   => $actions,
            'switches'  => $switches,
            'bin'       => 'app',
        ];

        $managed = (new Managed_Table($tablename))->setSettings($manager_settings)->setColumns($columns)->setTitle(_m('Manage %1', [$tablename]));
        if (array_key_exists('edit_url', $set)) {
            $managed->setUrl($set['edit_url']);
        }
        return $managed;
    }

    //    /** AA_iEditable method - adds Object's editable properties to the $form */
    //    public static function addFormrows(AA_Form $form) {
    //        $table = $form->getObjectOwner();
    //        return $form->addProperties(AA::Metabase()->getColumnProperties($table));
    //    }

}
