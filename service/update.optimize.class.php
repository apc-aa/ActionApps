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
 * @package   Maintain
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
*/

use AA\IO\DB\DB_AA;

/** @todo this class should be abstract after we switch to PHP5 */
class AA_Optimize implements \AA\Util\NamedInterface {
    protected $messages = [];
    protected $db;

    /** \AA\Util\NamedInterface function */
    public function name(): string         {}
    /** \AA\Util\NamedInterface function */
    public function description(): string  {}

    public function test() : bool     {}
    public function repair() : bool   {}

    /** implemented actions within this class */
    function actions()      { return ['test','repair']; }

    /** checks if the this Optimize class belongs to specified type (like "sql_update") */
    function isType($type)  { return in_array($type, []); }

    /** the optimizers are ordered by priority when executed in chain (like for sql_update) */
    function priority()     { return 100; }

    /** Message function
    * @param string|array $row
    */
    function message($row) {
        $this->messages[] = is_array($row) ? $row : ['txt' => $row];
    }

    /** This function stores global state for all optimizations.
     *  You can call it with bool parameter which will switch the state
     *  If you call it without parameters, then you get current stete
     *  true means, the DB queries are not written to the database, but just
     *  written to messages (report)
     *  Used by query($SQL) method
     *  Called as AA_Optimize::justPrint();
     *  @todo convert to static class variable (after migration to PHP5)
     */
    static function justPrint($state=null) {
        static $just_print = false;
        if (!is_null($state)) {
            $just_print = $state;
        }
        return $just_print;
    }

    /** $db->query wrapper - always use it to not execute the command and just
     *  display it, ...
     */
    function query($SQL) {
        $this->message($SQL);
        $just_print = AA_Optimize::justPrint();

        if ($just_print) {
            return;
        }
        if (!$this->db) {
            $this->db = getDb();
            $this->db->Halt_On_Error = "report";
        }
        $db = $this->db;
        $db->query($SQL);
    }

    /** Report function
    * @return string - messages in table
    */
    function report()       {
        return GetHtmlTable($this->messages, 'key');
    }

    /** Clear report function
    * unsets all current messages
    */
    function clear_report() {
        unset($this->messages);
        $this->messages = [];
    }
}


/** Fix field table duplicate keys */
class AA_Optimize_Field_Duplicates extends AA_Optimize {

    /** Name function
    * @return string - a name
    */
    public function name(): string {
        return _m("Fix field definitions duplicates");
    }

    /** Description function
    * @return string - a description
    */
    public function description(): string {
        return _m("There should be only one slice_id - field_id pair in all slices, but sometimes there are more than one (mainly because of error in former sql_update.php3 script, where more than one display_count... fields were added).");
    }

    /** checks if the this Optimize class belongs to specified type (like "sql_update") */
    function isType($type)  { return in_array($type, ['sql_update']); }

    /** the optimizers are ordered by priority (less first) when executed
     *  in chain (like for sql_update)
     *  Default value is 100
     *  Execute this first
     */
    function priority()     { return 10; }  // big priority

    /** Test function
    * @return bool
    */
    public function test() : bool {
        $duplicates = $this->_check_table();

        if (count($duplicates)==0) {
            $this->message(_m('No duplicates found'));
            return true;
        }
        foreach ($duplicates as $dup) {
            $this->message(_m('Duplicate in slice - field: %1 - %2', [unpack_id($dup[0]), $dup[1]]));
        }
        return false;
    }

    public function repair() : bool {
        $varset = new Cvarset;
        // $varset->setDebug();

        $duplicates = $this->_check_table();
        if (count($duplicates)==0) {
            $this->message(_m('No duplicates found'));
            return true;
        }
        $fixed = [];
        foreach ($duplicates as $dup) {
            if ( $fixed[$dup[0].$dup[1]] ) {
                // already fixed
                continue;
            }
            $fixed[$dup[0].$dup[1]] = true;

            $varset->doDeleteWhere('field', "slice_id='".quote($dup[0])."' AND id='".quote($dup[1])."'");
            $varset->resetFromRecord($dup[2]);
            $varset->doInsert('field');
            $this->message(_m('Field %2 in slice %1 fixed', [unpack_id($dup[0]), $dup[1]]));
        }
        return true;
    }

    function _check_table() {
        // does the table exist at all?
        $field_exists = GetTable2Array("SHOW TABLES LIKE 'field'", "aa_first");
        if (!$field_exists) {
            return [];
        }
        $fields = GetTable2Array("SELECT * FROM field ORDER BY slice_id, id", '');

        $field_table = [];
        $duplicates  = [];
        foreach ($fields as $field) {
            $sid = $field['slice_id'];
            $fid = $field['id'];
            if (!isset($field_table[$sid])) {
                $field_table[$sid] = [];
            }
            if ( isset($field_table[$sid][$fid])) {
                $duplicates[] = [$sid, $fid, $field_table[$sid][$fid]];
            } else {
                $field_table[$sid][$fid] = $field;
            }
        }
        return $duplicates;
    }
}

/** Updates the database structure for AA. It checks all the tables in current
 *  system and compare it with the newest database structure. The new table
 *  is created as tmp_*, then the content from old table is copied and if
 *  everything is OK, then the old table is renamed to bck_* and tmp_*
 *  is renamed to new table
 **/
class AA_Optimize_Update_Db_Structure extends AA_Optimize {

    /** Name function
    * @return string - a name
    */
    public function name(): string {
        return _m("Update database structure");
    }

    /** Description function
    * @return string - a description
    */
    public function description(): string {
        return _m("[experimental] "). _m("Updates the database structure for AA. It cheks all the tables in current system and compare it with the newest database structure. The new table is created as tmp_*, then the content from old table is copied and if everything is OK, then the old table is renamed to bck_* and tmp_* is renamed to new table. (new version based on the metabase structure)");
    }

    /** checks if the this Optimize class belongs to specified type (like "sql_update") */
    function isType($type)  { return in_array($type, ['sql_update']); }

    /** the optimizers are ordered by priority (less first) when executed
     *  in chain (like for sql_update)
     *  Default value is 100
     *  Execute this first
     */
    function priority()     { return 50; }  // quite big priority but not first

    /** Test function
    * @return string - a message
    */
    public function test() : bool {
        $template_metabase = AA::Metabase();
        $this_metabase     = new AA_Metabase;
        $this_metabase->loadFromDb();
        $diffs     = $template_metabase->compare($this_metabase);
        $different = false;

        $this->message(AA_Difftext::renderHtml(" Actual Database ", " AA Template "));

        foreach($diffs as $tablename => $diff) {
            if ($diff['equal']) {
                //huhl($diff);
                $this->message(_m('<em>Tables %1 are identical.</em><br>', [$tablename]));
            } else {
                $this->message(AA_Difftext::renderHtml($diff['table2'], $diff['table1']));
                $different = true;
            }
        }
        return !$different;
    }

    /** Main update function
     *  @return bool
     */
    public function repair() : bool {
        $template_metabase = AA::Metabase();
        $this_metabase     = new AA_Metabase;
        $this_metabase->loadFromDb();
        $diffs     = $template_metabase->compare($this_metabase);
        foreach($diffs as $tablename => $diff) {
            if ($diff['equal']) {
                $this->message(_m('Tables %1 are identical. Skipping.', [$tablename]));
                continue;
            }
            // create temporary table
            $this->message(_m('Deleting temporary table tmp_%1, if exist.', [$tablename]));
            $this->query("DROP TABLE IF EXISTS `tmp_$tablename`");
            $this->message(_m('Creating temporary table tmp_%1.', [$tablename]));
            $this->query($template_metabase->getCreateSql($tablename, 'tmp_'));

            // create new tables that do not exist in database
            // (we need it for next data copy, else it ends up with db error)
            $this->message(_m('Creating "old" data table %1 if not exists.', [$tablename]));
            $this->query($template_metabase->getCreateSql($tablename));


            // copy old data to tmp table
            $this->message(_m('copying old values to new table %1 -> tmp_%1', [$tablename]));

            $tmp_columns = $template_metabase->getColumnNames($tablename);
            $old_columns = $this_metabase->getColumnNames($tablename);

            $matches = array_intersect($tmp_columns, $old_columns);
            if ( count($matches) > 1 ) {
                $field_list = '`'. join('`, `', $matches) .'`';
                $this->query("INSERT INTO `tmp_$tablename` ($field_list) SELECT $field_list FROM `$tablename`");
            }

            // backup table and use the new one
            $this->message(_m('backup old table %1 -> bck_%1 and use new tables instead tmp_%1 -> %1', [$tablename]));
            $this->query("DROP TABLE IF EXISTS `bck_$tablename`");
            $this->query("ALTER TABLE `$tablename` RENAME `bck_$tablename");
            $this->query("ALTER TABLE `tmp_$tablename` RENAME `$tablename");
            $this->message(_m('%1 done.', [$tablename]));
        }
        return true;
    }
}

/** Recreates the "ActionApps Core" constants (delete and insert). **/
class AA_Optimize_Redefine_Core_Constants extends AA_Optimize {

    /** Name function
    * @return string - a name
    */
    public function name(): string {
        return _m("Redefine core constants");
    }

    /** Description function
    * @return string - a description
    */
    public function description(): string {
        return _m("Updates core constants - like Code-pages, Languge Constants and AA Bins");
    }

    /** checks if the this Optimize class belongs to specified type (like "sql_update") */
    function isType($type)  { return in_array($type, ['sql_update']); }

    /** Test function
    * @return bool
    */
    public function test() : bool {
        $ret = true;

        // Code-pages
        $row_count   = GetTable2Array("SELECT count(*) as count FROM constant WHERE group_id='lt_codepages'", "aa_first", 'count');
        if ($row_count < 9) {
            $this->message(_m('Code-pages constants (lt_codepages) are not up-to-date - found: %1 from 9', [$row_count]));
            $ret = false;
        }

        // Language
        $row_count   = GetTable2Array("SELECT count(*) as count FROM constant WHERE group_id='lt_languages'", "aa_first", 'count');
        if ($row_count < 46) {
            $this->message(_m('Language constants (lt_languages) are not up-to-date - found: %1 from 46', [$row_count]));
            $ret = false;
        }

        // AA Bins
        $row_count   = GetTable2Array("SELECT count(*) as count FROM constant WHERE group_id='AA_Core_Bins....'", "aa_first", 'count');
        if ($row_count < 3) {
            $this->message(_m('AA Bins constants (AA_Core_Bins....) are not up-to-date - found: %1 from 3', [$row_count]));
            $ret = false;
        }

        // APC-wide categories
        $row_count   = GetTable2Array("SELECT count(*) as count FROM constant WHERE group_id='lt_apcCategories'", "aa_first", 'count');
        if ($row_count < 82) {
            $this->message(_m('APC-wide categories (lt_apcCategories) are not up-to-date - found: %1 from 82', [$row_count]));
            $ret = false;
        }
        return $ret;
    }


    /** Main update function
     *  @return bool
     */
    public function repair() : bool {


        $this->message(_m('Deleting Code-pages, Languge Constants, AA Bins constants and APC-wide categories'));
        $this->query("DELETE FROM constant WHERE group_id IN ('lt_codepages', 'lt_languages', 'AA_Core_Bins....', 'lt_apcCategories')");

        $this->message(_m('Recreate Code-pages'));
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined000', 'lt_codepages', 'iso8859-1', 'iso8859-1', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined001', 'lt_codepages', 'iso8859-2', 'iso8859-2', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined002', 'lt_codepages', 'windows-1250', 'windows-1250', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined003', 'lt_codepages', 'windows-1253', 'windows-1253', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined004', 'lt_codepages', 'windows-1254', 'windows-1254', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined005', 'lt_codepages', 'koi8-r', 'koi8-r', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined006', 'lt_codepages', 'ISO-8859-8', 'ISO-8859-8', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined007', 'lt_codepages', 'windows-1258', 'windows-1258', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined061', 'lt_codepages', 'windows-1251', 'windows-1251', '', '100')");

        $this->message(_m('Recreate Languages'));
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined008', 'lt_languages', 'Afrikaans', 'AF', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined009', 'lt_languages', 'Arabic', 'AR', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined010', 'lt_languages', 'Basque', 'EU', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined011', 'lt_languages', 'Byelorussian', 'BE', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined012', 'lt_languages', 'Bulgarian', 'BG', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined013', 'lt_languages', 'Catalan', 'CA', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined014', 'lt_languages', 'Chinese (ZH-CN)', 'ZH', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined015', 'lt_languages', 'Chinese', 'ZH-TW', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined016', 'lt_languages', 'Croatian', 'HR', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined017', 'lt_languages', 'Czech', 'CS', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined018', 'lt_languages', 'Danish', 'DA', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined019', 'lt_languages', 'Dutch', 'NL', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined020', 'lt_languages', 'English', 'EN-GB', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined021', 'lt_languages', 'English (EN-US)', 'EN', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined022', 'lt_languages', 'Estonian', 'ET', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined023', 'lt_languages', 'Faeroese', 'FO', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined024', 'lt_languages', 'Finnish', 'FI', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined025', 'lt_languages', 'French (FR-FR)', 'FR', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined026', 'lt_languages', 'French', 'FR-CA', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined027', 'lt_languages', 'German', 'DE', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined028', 'lt_languages', 'Greek', 'EL', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined029', 'lt_languages', 'Hebrew (IW)', 'HE', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined030', 'lt_languages', 'Hungarian', 'HU', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined031', 'lt_languages', 'Icelandic', 'IS', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined032', 'lt_languages', 'Indonesian (IN)', 'ID', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined033', 'lt_languages', 'Italian', 'IT', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined034', 'lt_languages', 'Japanese', 'JA', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined035', 'lt_languages', 'Korean', 'KO', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined036', 'lt_languages', 'Latvian', 'LV', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined037', 'lt_languages', 'Lithuanian', 'LT', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined038', 'lt_languages', 'Neutral', 'NEUTRAL', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined039', 'lt_languages', 'Norwegian', 'NO', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined040', 'lt_languages', 'Polish', 'PL', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined041', 'lt_languages', 'Portuguese', 'PT', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined042', 'lt_languages', 'Portuguese', 'PT-BR', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined043', 'lt_languages', 'Romanian', 'RO', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined044', 'lt_languages', 'Russian', 'RU', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined045', 'lt_languages', 'Serbian', 'SR', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined046', 'lt_languages', 'Slovak', 'SK', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined047', 'lt_languages', 'Slovenian', 'SL', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined048', 'lt_languages', 'Spanish (ES-ES)', 'ES', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined049', 'lt_languages', 'Swedish', 'SV', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined050', 'lt_languages', 'Thai', 'TH', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined051', 'lt_languages', 'Turkish', 'TR', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined052', 'lt_languages', 'Ukrainian', 'UK', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined053', 'lt_languages', 'Vietnamese', 'VI', '', '100')");

        $this->message(_m('Recreate AA Bins'));
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined058', 'AA_Core_Bins....', 'Approved', '1', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined059', 'AA_Core_Bins....', 'Holding Bin', '2', '', '200')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined060', 'AA_Core_Bins....', 'Trash Bin', '3', '', '300')");

        $this->message(_m('APC wide categories'));
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined100', 'lt_apcCategories', 'Internet & ICT', 'Internet & ICT', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined101', 'lt_apcCategories', 'Internet & ICT - Free software & Open Source', 'Internet & ICT - Free software & Open Source', '', '110')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined102', 'lt_apcCategories', 'Internet & ICT - Access', 'Internet & ICT - Access', '', '120')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined103', 'lt_apcCategories', 'Internet & ICT - Connectivity', 'Internet & ICT - Connectivity', '', '130')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined104', 'lt_apcCategories', 'Internet & ICT - Women and ICT', 'Internet & ICT - Women and ICT', '', '140')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined105', 'lt_apcCategories', 'Internet & ICT - Rights', 'Internet & ICT - Rights', '', '150')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined106', 'lt_apcCategories', 'Internet & ICT - Governance', 'Internet & ICT - Governance', '', '160')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined107', 'lt_apcCategories', 'Development', 'Development', '', '200')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined108', 'lt_apcCategories', 'Development - Resources', 'Development - Resources', '', '210')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined109', 'lt_apcCategories', 'Development - Structural adjustment', 'Development - Structural adjustment', '', '220')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined110', 'lt_apcCategories', 'Development - Sustainability', 'Development - Sustainability', '', '230')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined111', 'lt_apcCategories', 'News and media', 'News and media', '', '300')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined112', 'lt_apcCategories', 'News and media - Alternative', 'News and media - Alternative', '', '310')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined113', 'lt_apcCategories', 'News and media - Internet', 'News and media - Internet', '', '320')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined114', 'lt_apcCategories', 'News and media - Training', 'News and media - Training', '', '330')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined115', 'lt_apcCategories', 'News and media - Traditional', 'News and media - Traditional', '', '340')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined116', 'lt_apcCategories', 'Environment', 'Environment', '', '400')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined117', 'lt_apcCategories', 'Environment - Agriculture', 'Environment - Agriculture', '', '410')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined118', 'lt_apcCategories', 'Environment - Animal rights/protection', 'Environment - Animal rights/protection', '', '420')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined119', 'lt_apcCategories', 'Environment - Climate', 'Environment - Climate', '', '430')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined120', 'lt_apcCategories', 'Environment - Biodiversity/conservetion', 'Environment - Biodiversity/conservetion', '', '440')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined121', 'lt_apcCategories', 'Environment - Energy', 'Environment - Energy', '', '450')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined122', 'lt_apcCategories', 'Environment - Campaigns', 'Environment - Campaigns', '', '455')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined123', 'lt_apcCategories', 'Environment - Legislation', 'Environment - Legislation', '', '460')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined124', 'lt_apcCategories', 'Environment - Genetics', 'Environment - Genetics', '', '465')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined125', 'lt_apcCategories', 'Environment - Natural resources', 'Environment - Natural resources', '', '470')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined126', 'lt_apcCategories', 'Environment - Rural development', 'Environment - Rural development', '', '475')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined127', 'lt_apcCategories', 'Environment - Transport', 'Environment - Transport', '', '480')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined128', 'lt_apcCategories', 'Environment - Urban ecology', 'Environment - Urban ecology', '', '485')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined129', 'lt_apcCategories', 'Environment - Pollution & waste', 'Environment - Pollution & waste', '', '490')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined130', 'lt_apcCategories', 'NGOs', 'NGOs', '', '500')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined131', 'lt_apcCategories', 'NGOs - Fundraising', 'NGOs - Fundraising', '', '510')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined132', 'lt_apcCategories', 'NGOs - Funding agencies', 'NGOs - Funding agencies', '', '520')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined133', 'lt_apcCategories', 'NGOs - Grants/scholarships', 'NGOs - Grants/scholarships', '', '530')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined134', 'lt_apcCategories', 'NGOs - Jobs', 'NGOs - Jobs', '', '540')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined135', 'lt_apcCategories', 'NGOs - Management', 'NGOs - Management', '', '550')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined136', 'lt_apcCategories', 'NGOs - Volunteers', 'NGOs - Volunteers', '', '560')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined137', 'lt_apcCategories', 'Society', 'Society', '', '600')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined138', 'lt_apcCategories', 'Society - Charities', 'Society - Charities', '', '610')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined139', 'lt_apcCategories', 'Society - Community', 'Society - Community', '', '620')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined140', 'lt_apcCategories', 'Society - Crime & rehabilitation', 'Society - Crime & rehabilitation', '', '630')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined141', 'lt_apcCategories', 'Society - Disabilities', 'Society - Disabilities', '', '640')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined142', 'lt_apcCategories', 'Society - Drugs', 'Society - Drugs', '', '650')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined143', 'lt_apcCategories', 'Society - Ethical business', 'Society - Ethical business', '', '660')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined144', 'lt_apcCategories', 'Society - Health', 'Society - Health', '', '670')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined145', 'lt_apcCategories', 'Society - Law and legislation', 'Society - Law and legislation', '', '675')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined146', 'lt_apcCategories', 'Society - Migration', 'Society - Migration', '', '680')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined147', 'lt_apcCategories', 'Society - Sexuality', 'Society - Sexuality', '', '685')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined148', 'lt_apcCategories', 'Society - Social services and welfare', 'Society - Social services and welfare', '', '690')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined149', 'lt_apcCategories', 'Economy & Work', 'Economy & Work', '', '700')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined150', 'lt_apcCategories', 'Economy & Work - Informal Sector', 'Economy & Work - Informal Sector', '', '710')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined151', 'lt_apcCategories', 'Economy & Work - Labour', 'Economy & Work - Labour', '', '720')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined152', 'lt_apcCategories', 'Culture', 'Culture', '', '800')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined153', 'lt_apcCategories', 'Culture - Arts and literature', 'Culture - Arts and literature', '', '810')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined154', 'lt_apcCategories', 'Culture - Heritage', 'Culture - Heritage', '', '820')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined155', 'lt_apcCategories', 'Culture - Philosophy', 'Culture - Philosophy', '', '830')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined156', 'lt_apcCategories', 'Culture - Religion', 'Culture - Religion', '', '840')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined157', 'lt_apcCategories', 'Culture - Ethics', 'Culture - Ethics', '', '850')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined158', 'lt_apcCategories', 'Culture - Leisure', 'Culture - Leisure', '', '860')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined159', 'lt_apcCategories', 'Human rights', 'Human rights', '', '900')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined160', 'lt_apcCategories', 'Human rights - Consumer Protection', 'Human rights - Consumer Protection', '', '910')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined161', 'lt_apcCategories', 'Human rights - Democracy', 'Human rights - Democracy', '', '920')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined162', 'lt_apcCategories', 'Human rights - Minorities', 'Human rights - Minorities', '', '930')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined163', 'lt_apcCategories', 'Human rights - Peace', 'Human rights - Peace', '', '940')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined164', 'lt_apcCategories', 'Education', 'Education', '', '1000')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined165', 'lt_apcCategories', 'Education - Distance learning', 'Education - Distance learning', '', '1010')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined166', 'lt_apcCategories', 'Education - Non-formal education', 'Education - Non-formal education', '', '1020')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined167', 'lt_apcCategories', 'Education - Schools', 'Education - Schools', '', '1030')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined168', 'lt_apcCategories', 'Politics & Government', 'Politics & Government', '', '1100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined169', 'lt_apcCategories', 'Politics & Government - Internet', 'Politics & Government - Internet', '', '1110')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined170', 'lt_apcCategories', 'Politics & Government - Local', 'Politics & Government - Local', '', '1120')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined171', 'lt_apcCategories', 'Politics & Government - Policies', 'Politics & Government - Policies', '', '1130')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined172', 'lt_apcCategories', 'Politics & Government - Administration', 'Politics & Government - Administration', '', '1140')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined173', 'lt_apcCategories', 'People', 'People', '', '1200')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined174', 'lt_apcCategories', 'People - Children', 'People - Children', '', '1210')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined175', 'lt_apcCategories', 'People - Adolescents/teenagers', 'People - Adolescents/teenagers', '', '1220')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined176', 'lt_apcCategories', 'People - Gender', 'People - Gender', '', '1230')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined177', 'lt_apcCategories', 'People - Older people', 'People - Older people', '', '1240')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined178', 'lt_apcCategories', 'People - Family', 'People - Family', '', '1250')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined179', 'lt_apcCategories', 'World', 'World', '', '1300')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined180', 'lt_apcCategories', 'World - Globalization', 'World - Globalization', '', '1310')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined181', 'lt_apcCategories', 'World - Debt', 'World - Debt', '', '1320')");

        $this->message(_m('Make sure, the constants are registered'));
        $this->query("REPLACE INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined054', 'lt_groupNames', 'Code Pages', 'lt_codepages', '', '0')");
        $this->query("REPLACE INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined055', 'lt_groupNames', 'Languages Shortcuts', 'lt_languages', '', '1000')");
        $this->query("REPLACE INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined057', 'lt_groupNames', 'AA Core Bins', 'AA_Core_Bins....', '', '10000')");
        $this->query("REPLACE INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined056', 'lt_groupNames', 'APC-wide Categories', 'lt_apcCategories', '', '1000')");

        $this->message(_m('Redefine core constants - done.'));
        return true;
    }
}

/** Recreates the template (example) slice - News EN **/
class AA_Optimize_Redefine_Slice_News_Templates extends AA_Optimize {

    /** Name function
    * @return string - a name
    */
    public function name(): string {
        return _m("Redefine News EN template slice");
    }

    /** Description function
    * @return string - a description
    */
    public function description(): string {
        return _m("Deletes and recreates the News EN slice, used as template or example slice");
    }

    /** checks if the this Optimize class belongs to specified type (like "sql_update") */
    function isType($type)  { return in_array($type, ['sql_update']); }


    /** Test function
    * @return bool
    */
    public function test() : bool {
        $ret = true;

        $row_count   = GetTable2Array("SELECT count(*) as count FROM module WHERE id='News_EN_tmpl....'", "aa_first", 'count');
        if ($row_count <> 1) {
            $this->message(_m('News EN template slice is not defined'));
            $ret = false;
        }
        return $ret;
    }

    /** Main update function
     *  @return bool
     */
    public function repair() : bool {

        $now         = time();
        $AA_IMG_URL  = '/'. AA_BASE_DIR .'images/';
        $AA_DOC_URL  = '/'. AA_BASE_DIR .'doc/';



        $this->message(_m('Deleting all fields form "ActionApps Core" slice'));
        $this->query("DELETE FROM field WHERE slice_id='News_EN_tmpl....'");

        $this->message(_m('Make sure "News EN" slice exists and reset to defaults'));
        $this->query("REPLACE INTO module (id, name, deleted, type, slice_url, lang_file, created_at, created_by, owner, flag) VALUES ('News_EN_tmpl....', 'News (EN) Template', 0, 'S', '', 'en_news_lang.php3', 975157733, '', 'AA_Core.........', 0)");
        $this->query("REPLACE INTO slice (id, name, owner, deleted, created_by, created_at, export_to_all, type, template, fulltext_format_top, fulltext_format, fulltext_format_bottom, odd_row_format, even_row_format, even_odd_differ, compact_top, compact_bottom, category_top, category_format, category_bottom, category_sort, slice_url, d_listlen, lang_file, fulltext_remove, compact_remove, email_sub_enable, exclude_from_dir, notify_sh_offer, notify_sh_accept, notify_sh_remove, notify_holding_item_s, notify_holding_item_b, notify_holding_item_edit_s, notify_holding_item_edit_b, notify_active_item_edit_s, notify_active_item_edit_b, notify_active_item_s, notify_active_item_b, noitem_msg, admin_format_top, admin_format, admin_format_bottom, admin_remove, permit_anonymous_post, permit_offline_fill, aditional, flag, vid, gb_direction, group_by, gb_header, gb_case, javascript) VALUES( 'News_EN_tmpl....', 'News (EN) Template', 'AA_Core.........', '0', '', '$now', '0', 'News_EN_tmpl....', '1', '', '<BR><FONT SIZE=+2 COLOR=blue>_#HEADLINE</FONT> <BR><B>_#PUB_DATE</B> <BR><img src=\"_#IMAGESRC\" width=\"_#IMGWIDTH\" height=\"_#IMG_HGHT\">_#FULLTEXT ', '','<font face=Arial color=#808080 size=-2>_#PUB_DATE - </font><font color=#FF0000><strong><a href=_#HDLN_URL>_#HEADLINE</a></strong></font><font color=#808080 size=-1><br>_#PLACE###(_#LINK_SRC) - </font><font color=black size=-1>_#ABSTRACT<br></font><br>', '', '0', '<br>', '<br>', '', '<p>_#CATEGORY</p>', '', '1', '". AA_HTTP_DOMAIN ."', '10000', 'en_news_lang.php3', '()', '()', '1', '0', '', '', '', '', '', '', '', '', '', '', '', 'No item found', '<tr class=tablename><td width=30>&nbsp;</td><td>Click on Headline to Edit</td><td>Date</td></tr>', '<tr class=tabtxt><td width=30><input type=checkbox name=\"chb[x_#ITEM_ID#]\" value=\"1\"></td><td><a href=\"_#EDITITEM\">_#HEADLINE</a></td><td>_#PUB_DATE</td></tr>', '', '', '1', '1', '', '0', '0', NULL, NULL, NULL, NULL,'')");

        $this->message(_m('Recreate field definitions for "News EN"'));
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'abstract........', '', 'News_EN_tmpl....', 'Abstract', '150', '', '', 'qte', '0', '0', '0', 'txt:8', '', '100', '', '', '', '', '0', '1', '1', '_#ABSTRACT', 'f_t', 'alias for abstract', '_#RSS_IT_D', 'f_r:256', 'Abstract for RSS', '', '', '', '', '', '0', '0', '1', '', 'text', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'category........', '', 'News_EN_tmpl....', 'Category', '500', '', '', 'txt:', '0', '0', '0', 'sel:lt_apcCategories', '', '100', '', '', '', '', '1', '1', '1', '_#CATEGORY', 'f_h', 'alias for Item Category', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '0', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'cp_code.........', '', 'News_EN_tmpl....', 'Code Page', '1800', 'Language Code Page', '', 'txt:iso8859-1', '0', '0', '0', 'sel:lt_codepages', '', '100', '', '', '', '', '0', '0', '0', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '0', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'created_by......', '', 'News_EN_tmpl....', 'Author', '470', 'Identification of creator', '', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#CREATED#', 'f_h', 'alias for Written By', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'edited_by.......', '', 'News_EN_tmpl....', 'Edited by', '5030', 'Identification of last editor', '', 'qte', '0', '0', '0', 'nul', '', '100', '', '', '', '', '0', '0', '0', '_#EDITEDBY', 'f_h', 'alias for Last edited By', '', '', '', '', '', '', '', '', '0', '0', '0', 'edited_by', 'text', 'uid', '0', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'edit_note.......', '', 'News_EN_tmpl....', 'Editor`s note', '2355', 'Here you can write your note (not displayed on the web)', '', 'qte', '0', '0', '0', 'txt', '', '100', '', '', '', '', '0', '0', '0', '_#EDITNOTE', 'f_h', 'alias for Editor`s note', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'expiry_date.....', '', 'News_EN_tmpl....', 'Expiry Date', '955', 'Date when the news expires', '', 'dte:2000', '1', '0', '0', 'dte:1:10:1', '', '100', '', '', '', '', '0', '0', '0', '_#EXP_DATE', 'f_d:m/d/Y', 'alias for Expiry Date', '', '', '', '', '', '', '', '', '0', '0', '0', 'expiry_date', 'date', 'dte', '1', '0')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'e_posted_by.....', '', 'News_EN_tmpl....', 'Author`s e-mail', '480', 'E-mail to author', '', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#E_POSTED', 'f_h', 'alias for Author`s e-mail', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'email', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'full_text.......', '', 'News_EN_tmpl....', 'Fulltext', '200', '', '', 'qte', '0', '0', '0', 'txt:8', '', '100', '', '', '', '', '0', '1', '1', '_#FULLTEXT', 'f_t', 'alias for Fulltext<br>(HTML tags are striped or not depending on HTML formated item setting)', '', '', '', '', '', '', '', '', '0', '0', '1', '', 'text', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'headline........', '', 'News_EN_tmpl....', 'Headline', '100', '', '', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#HEADLINE', 'f_h', 'alias for Item Headline', '_#RSS_IT_T', 'f_r:100', 'item title, for RSS', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'highlight.......', '', 'News_EN_tmpl....', 'Highlight', '450', 'Interesting news - shown on homepage', '', 'qte', '0', '0', '0', 'chb', '', '100', '', '', '', '', '0', '0', '0', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', 'highlight', 'bool', 'boo', '1', '0')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'hl_href.........', '', 'News_EN_tmpl....', 'Headline URL', '400', 'Link for the headline (for external links)', '', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#HDLN_URL', 'f_f:link_only.......', 'alias for News URL<br>(substituted by External news link URL(if External news is checked) or link to Fulltext)<div class=example><em>Example: </em>&lt;a href=_#HDLN_URL&gt;_#HEADLINE&lt;/a&gt;</div>', '_#RSS_IT_L', 'f_r:link_only.......', 'item link, for RSS', '', '', '', '', '', '0', '0', '0', '', 'url', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'img_height......', '', 'News_EN_tmpl....', 'Image height', '2300', 'Height of image (like: 100, 50%)', '', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#IMG_HGHT', 'f_g', 'alias for Image Height<br>(if no height defined, program tries to remove <em>height=</em> atribute from format string<div class=example><em>Example: </em>&lt;img src=\"_#IMAGESRC\" width=_#IMGWIDTH height=_#IMG_HGHT&gt;</div>', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'img_src.........', '', 'News_EN_tmpl....', 'Image URL', '2100', 'URL of the image', '', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#IMAGESRC', 'f_i', 'alias for Image URL<br>(if there is no image url defined in database, default url is used instead (see NO_PICTURE_URL constant in en_*_lang.php3 file))<div class=example><em>Example: </em>&lt;img src=\"_#IMAGESRC\"&gt;</div>', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'url', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'img_width.......', '', 'News_EN_tmpl....', 'Image width', '2200', 'Width of image (like: 100, 50%)', '', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#IMGWIDTH', 'f_w', 'alias for Image Width<br>(if no width defined, program tries to remove <em>width=</em> atribute from format string<div class=example><em>Example: </em>&lt;img src=\"_#IMAGESRC\" width=_#IMGWIDTH height=_#IMG_HGHT&gt;</div>', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'lang_code.......', '', 'News_EN_tmpl....', 'Language Code', '1700', 'Code of used language', '', 'txt:EN', '0', '0', '0', 'sel:lt_languages', '', '100', '', '', '', '', '0', '0', '0', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '0', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'last_edit.......', '', 'News_EN_tmpl....', 'Last Edit', '5040', 'Date of last edit', '', 'now:', '0', '0', '0', 'dte:1:10:1', '', '100', '', '', '', '', '0', '0', '0', '_#LASTEDIT', 'f_d:m/d/Y', 'alias for Last Edit', '', '', '', '', '', '', '', '', '0', '0', '0', 'last_edit', 'date', 'now', '0', '0')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'link_only.......', '', 'News_EN_tmpl....', 'External news', '300', 'Use External link instead of fulltext?', '', 'qte', '0', '0', '0', 'chb', '', '100', '', '', '', '', '0', '0', '1', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'bool', 'boo', '1', '0')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'place...........', '', 'News_EN_tmpl....', 'Locality', '630', 'News locality', '', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#PLACE###', 'f_h', 'alias for Locality', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'posted_by.......', '', 'News_EN_tmpl....', 'Posted by', '5035', 'Identification of author', '', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#POSTEDBY', 'f_h', 'alias for Author', '', '', '', '', '', '', '', '', '0', '0', '0', 'posted_by', 'text', 'uid', '0', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'post_date.......', '', 'News_EN_tmpl....', 'Post Date', '5005', 'Date of posting this news', '',              'now:', '1', '0', '0', 'nul', '', '100', '', '', '', '', '0', '0', '0', '_#POSTDATE', 'f_d:m/d/Y', 'alias for Post Date', '', '', '', '', '', '', '', '', '0', '0', '0', 'post_date', 'date', 'now', '0', '0')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'publish_date....', '', 'News_EN_tmpl....', 'Publish Date', '900', 'Date when the news will be published', '', 'now:', '1', '0', '0', 'dte:1:10:1', '', '100', '', '', '', '', '0', '0', '0', '_#PUB_DATE', 'f_d:m/d/Y', 'alias for Publish Date', '', '', '', '', '', '', '', '', '0', '0', '0', 'publish_date', 'date', 'dte', '1', '0')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'source..........', '', 'News_EN_tmpl....', 'Source', '600', 'Source of the news', '',                         'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#SOURCE##', 'f_h', 'alias for Source Name<br>(see _#LINK_SRC for text source link)', '_#SRC_URL#', 'f_l:source_href.....', 'alias for Source with URL<br>(if there is no source url defined in database, the source is displayed as link)', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'source_href.....', '', 'News_EN_tmpl....', 'Source URL', '610', 'URL of the source', '',                      'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#LINK_SRC', 'f_l', 'alias for Source Name with link.<br>(substituted by &lt;a href=\"_#SRC_URL#\"&gt;_#SOURCE##&lt;/a&gt; if Source URL defined, otherwise _#SOURCE## only)', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'url', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'status_code.....', '', 'News_EN_tmpl....', 'Status Code', '5020', 'Select in which bin should the news appear', '', 'qte:1', '1', '0', '0', 'sel:AA_Core_Bins....', '', '100', '', '', '', '', '0', '0', '0', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', 'status_code', 'number', 'num', '0', '0')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'slice_id........', '', 'News_EN_tmpl....', 'Slice', '5000', 'Internal field - do not change', '', 'qte:1', '1', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#SLICE_ID', 'f_n:slice_id........', 'alias for id of slice', '', '', '', '', '', '', '', '', '0', '0', '0', 'slice_id', '', 'nul', '0', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'display_count...', '', 'News_EN_tmpl....', 'Displayed Times', '5050', 'Internal field - do not change', '', 'qte:0', '1', '1', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#DISPL_NO', 'f_h', 'alias for number of displaying of this item', '', '', '', '', '', '', '', '', '0', '0', '0', 'display_count', '', 'nul', '0', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'disc_count......', '', 'News_EN_tmpl....', 'Comments Count', '5060', 'Internal field - do not change', '', 'qte:0', '1', '1', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#D_ALLCNT', 'f_h', 'alias for number of all discussion comments for this item', '', '', '', '', '', '', '', '', '0', '0', '0', 'disc_count', '', 'nul', '0', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'disc_app........', '', 'News_EN_tmpl....', 'Approved Comments Count', '5070', 'Internal field - do not change', '', 'qte:0', '1', '1', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#D_APPCNT', 'f_h', 'alias for number of approved discussion comments for this item', '', '', '', '', '', '', '', '', '0', '0', '0', 'disc_app', '', 'nul', '0', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'id..............', '', 'News_EN_tmpl....', 'Long ID', '5080', 'Internal field - do not change', '', 'txt:', 0, 0, 0, 'nul', '', 0, '', '', '', '', 1, 1, 1, '_#ITEM_ID_', 'f_n:', 'alias for Long Item ID', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, 'id', '', 'nul', 0, 1)");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'short_id........', '', 'News_EN_tmpl....', 'Short ID', '5090', 'Internal field - do not change', '', 'txt:', 0, 0, 0, 'nul', '', 100, '', '', '', '', 1, 1, 1, '_#SITEM_ID', 'f_t:', 'alias for Short Item ID', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, 'short_id', '', 'nul', 0, 0)");

        $this->message(_m('News EN template slice creation - done.'));
        return true;
    }
}


/** Recreates the template (example) slice - Reader Management **/
class AA_Optimize_Redefine_Slice_Reader_Templates extends AA_Optimize {

    /** Name function
    * @return string - a name
    */
    public function name(): string {
        return _m("Redefine Reader Management template slice");
    }

    /** Description function
    * @return string - a description
    */
    public function description(): string {
        return _m("Deletes and recreates the Reader Management slice, used as template or example slice");
    }

    /** checks if the this Optimize class belongs to specified type (like "sql_update") */
    function isType($type)  { return in_array($type, ['sql_update']); }


    /** Test function
    * @return bool
    */
    public function test() : bool {
        $ret = true;

        $row_count   = GetTable2Array("SELECT count(*) as count FROM module WHERE id='ReaderManagement'", "aa_first", 'count');
        if ($row_count <> 1) {
            $this->message(_m('"Reader Management" template slice is not defined'));
            $ret = false;
        }
        return $ret;
    }

    /** Main update function
     *  @return bool
     */
    public function repair() : bool {

        $now         = time();
        $AA_IMG_URL  = '/'. AA_BASE_DIR .'images/';
        $AA_DOC_URL  = '/'. AA_BASE_DIR .'doc/';

        $this->message(_m('Deleting all fields form "Reader Management" slice'));
        $this->query("DELETE FROM field WHERE slice_id='ReaderManagement'");

        $this->message(_m('Make sure "Reader Management" slice exists and reset to defaults'));
        $this->query("REPLACE INTO module (id, name, deleted, type, slice_url, lang_file, created_at, created_by, owner, flag) VALUES ('ReaderManagement', 'Reader Management Minimal', 0, 'S', '', 'en_news_lang.php3', 1043151515, '', 'AA_Core.........', 0)");
        $this->query("REPLACE INTO slice (id, name, owner, deleted, created_by, created_at, export_to_all, type, template, fulltext_format_top, fulltext_format, fulltext_format_bottom, odd_row_format, even_row_format, even_odd_differ, compact_top, compact_bottom, category_top, category_format, category_bottom, category_sort, slice_url, d_listlen, lang_file, fulltext_remove, compact_remove, email_sub_enable, exclude_from_dir, notify_sh_offer, notify_sh_accept, notify_sh_remove, notify_holding_item_s, notify_holding_item_b, notify_holding_item_edit_s, notify_holding_item_edit_b, notify_active_item_edit_s, notify_active_item_edit_b, notify_active_item_s, notify_active_item_b, noitem_msg, admin_format_top, admin_format, admin_format_bottom, admin_remove, permit_anonymous_post, permit_offline_fill, aditional, flag, vid, gb_direction, group_by, gb_header, gb_case, javascript, fileman_access, fileman_dir, auth_field_group, mailman_field_lists, permit_anonymous_edit) VALUES ('ReaderManagement', 'Reader Management Minimal', 'AA_Core.........', 0, '1', $now, 1, 'ReaderManagement', 1, '', '&nbsp;', '', '&nbsp;', '', 0, '', '', '', '', '', 0, '', 15, 'cz_news_lang.php3', '', '', 1, 0, '', '', '', '', '', '', '', '', '', '', '', ' ', '<table border=\"1\" bordercolor=\"white\" cellpadding=\"2\" cellspacing=\"0\">\r\n<tr align=\"center\">\r\n<td class=\"tabtit\">&nbsp;</td>\r\n<td class=\"tabtit\"><b>Username</b></td>\r\n<td class=\"tabtit\"><b>Email</b></td>\r\n<td class=\"tabtit\"><b>First</b></td>\r\n<td class=\"tabtit\"><b>Last</b></td>\r\n<td class=\"tabtit\"><b>Mail confirmed</b></td>\r\n</tr>', '<tr>\r\n<td><input type=checkbox name=\"chb[x_#ITEM_ID#]\" value=\"\"></td>\r\n<td class=\"tabtxt\">_#USERNAME</td>\r\n<td class=\"tabtxt\">_#EMAIL___</td>\r\n<td class=\"tabtxt\">_#FIRSTNAM</td>\r\n<td class=\"tabtxt\">_#LASTNAME</td>\r\n<td class=\"tabtxt\">_#MAILCONF</td>\r\n</tr>', '</table>', '', 2, 0, '', 0, 0, 2, '', 0, NULL, '', '0', '', '0', '0', 5);");

        $this->message(_m('Recreate field definitions for "Reader Management"'));
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('con_email.......', '', 'ReaderManagement', 'Email', 200, 'Reader\'s e-mail, unique in the scope of this slice', '', 'txt:', 0, 0, 0, 'fld:', '', 100, '', '', '', '', 1, 1, 1, '_#EMAIL___', 'f_c:!:<a href=\"_#EDITITEM\" class=iheadline>:</a>:&nbsp;::', 'Email', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, '', 'e-unique:con_email.......:1', 'qte:', 1, 1);");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('disc_app........', '', 'ReaderManagement', 'Approved Comments Count', 5070, 'Internal field - do not change', '', 'qte:0', 1, 1, 0, 'fld', '', 100, '', '', '', '', 0, 0, 0, '_#D_APPCNT', 'f_h', 'alias for number of approved discussion comments for this item', '', '', '', '', '', '', '', '', 0, 0, 0, 'disc_app', '', 'nul', 0, 1);");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('disc_count......', '', 'ReaderManagement', 'Comments Count', 5060, 'Internal field - do not change', '', 'txt:0', 1, 1, 0, 'fld:', '', 100, '', '', '', '', 0, 0, 0, '_#D_ALLCNT', 'f_h:', 'alias for number of all discussion comments for this item', '_#VIEW_165', 'f_v:vid=165&cmd[165]=x-165-_#short_id........', 'Zkraceny fulltex pohled pro diskuse', '', 'f_0:', '', '', '', 0, 0, 0, 'disc_count', 'text', 'qte', 0, 1);");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('display_count...', '', 'ReaderManagement', 'Displayed Times', 5050, 'Internal field - do not change', '', 'qte:0', 1, 1, 0, 'fld', '', 100, '', '', '', '', 0, 0, 0, '_#DISPL_NO', 'f_h', 'alias for number of displaying of this item', '', '', '', '', '', '', '', '', 0, 0, 0, 'display_count', '', 'nul', 0, 1);");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'id..............', '', 'ReaderManagement', 'Long ID', '5080', 'Internal field - do not change', '', 'txt:', 0, 0, 0, 'nul', '', 0, '', '', '', '', 1, 1, 1, '_#ITEM_ID_', 'f_n:', 'alias for Long Item ID', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, 'id', '', 'nul', 0, 1)");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'short_id........', '', 'ReaderManagement', 'Short ID', '5090', 'Internal field - do not change', '', 'txt:', 0, 0, 0, 'nul', '', 100, '', '', '', '', 1, 1, 1, '_#SITEM_ID', 'f_t:', 'alias for Short Item ID', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, 'short_id', '', 'nul', 0, 0)");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('edited_by.......', '', 'ReaderManagement', 'Edited by', 5030, 'Identification of last editor', '', 'qte', 0, 0, 0, 'nul', '', 100, '', '', '', '', 0, 0, 0, '_#EDITEDBY', 'f_h', 'alias for Last edited By', '', '', '', '', '', '', '', '', 0, 0, 0, 'edited_by', 'text', 'uid', 0, 0);");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('edit_note.......', '', 'ReaderManagement', 'Remark', 1000, '', '', 'txt:', 0, 0, 0, 'txt:4', '', 100, '', '', '', '', 0, 0, 0, '_#REMARK__', 'f_c:!:::&nbsp;::', 'Remark', '', 'f_a:', '', '', 'f_a:', '', '', '', 0, 0, 0, '', 'text', 'qte:', 1, 1);");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('expiry_date.....', '', 'ReaderManagement', 'Expiry date', 3100, 'Membership expiration', '', 'dte:2000', 0, 0, 0, 'dte:1\'10\'1', '', 100, '', '', '', '', 0, 0, 0, '_#EXP_DATE', 'f_d:j. n. Y', 'alias pro Datum Expirace', '', 'f_a:', '', '', 'f_a:', '', '', '', 0, 0, 0, 'expiry_date', 'date:', 'qte:', 1, 0);");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('flags...........', '', 'ReaderManagement', 'Flags', 5075, 'Internal field - do not change', '', 'qte:0', 0, 0, 0, 'fld', '', 100, '', '', '', '', 0, 0, 0, '', '', '', '', '', '', '', '', '', '', '', 0, 0, 0, 'flags', 'number', 'qte', 0, 1);");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('headline........', '', 'ReaderManagement', 'Username', 100, 'Reader\'s User Name, unique in the scope of the complete AA installation', '', 'txt:', 0, 0, 0, 'fld:', '', 100, '', '', '', '', 1, 1, 1, '_#USERNAME', 'f_c:!:<a href=\"_#EDITITEM\" class=iheadline>:</a>:&nbsp;::', 'Username', '', 'f_a:', '', '', 'f_a:', '', '', '', 0, 0, 0, '', 'unique:headline........:0', 'qte:', 1, 1);");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('highlight.......', '', 'ReaderManagement', 'Highlight', 5025, 'Interesting news - shown on homepage', '', 'qte', 0, 0, 0, 'chb', '', 100, '', '', '', '', 0, 0, 0, '', '', '', '', '', '', '', '', '', '', '', 0, 0, 0, 'highlight', 'bool', 'boo', 0, 0);");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('last_edit.......', '', 'ReaderManagement', 'Last Edit', 5040, 'Date of last edit', '', 'now:', 0, 0, 0, 'dte:1\'10\'1', '', 100, '', '', '', '', 0, 0, 0, '_#LASTEDIT', 'f_d:m/d/Y', 'alias for Last Edit', '', '', '', '', '', '', '', '', 0, 0, 0, 'last_edit', 'date', 'now', 0, 0);");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('password........', '', 'ReaderManagement', 'Password', 300, 'Your password. You must send it every time to confirm your changes.', '', 'txt:', 1, 0, 0, 'pwd:', '', 100, '', '', '', '', 0, 0, 0, '_#PASSWORD', 'f_c:!:*::&nbsp;::1', 'Password: Show * when set, nothing when not set', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, '', 'pwd:', 'pwd:', 1, 1);");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('posted_by.......', '', 'ReaderManagement', 'Posted by', 5000, 'Identification of author', '', 'qte', 0, 0, 0, 'fld', '', 100, '', '', '', '', 0, 0, 0, '_#POSTEDBY', 'f_h', 'alias for Author', '', '', '', '', '', '', '', '', 0, 0, 0, 'posted_by', 'text', 'uid', 0, 1);");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('post_date.......', '', 'ReaderManagement', 'Post Date', 5005, 'Date of posting this news', '', 'now:', 1, 0, 0, 'nul', '', 100, '', '', '', '', 0, 0, 0, '_#POSTDATE', 'f_d:m/d/Y', 'alias for Post Date', '', '', '', '', '', '', '', '', 0, 0, 0, 'post_date', 'date', 'now', 0, 0);");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('publish_date....', '', 'ReaderManagement', 'Start date', 3000, 'Membership start', '', 'now:', 0, 0, 0, 'dte:1:10:1', '', 100, '', '', '', '', 0, 0, 0, '_#PUB_DATE', 'f_d:j. n. Y', 'alias pro Datum Vystavení', '_#PUB_DAT#', 'f_d:j.n.y', 'alias pro Datum Vystavení pro admin stranky', '', 'f_a:', '', '', '', 0, 0, 0, 'publish_date', 'date:', 'qte:', 1, 0);");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('slice_id........', '', 'ReaderManagement', 'Slice', 5000, 'Internal field - do not change', '', 'qte:1', 1, 0, 0, 'fld', '', 100, '', '', '', '', 0, 0, 0, '_#SLICE_ID', 'f_n:slice_id', 'alias for id of slice', '', '', '', '', '', '', '', '', 0, 0, 0, 'slice_id', '', 'nul', 0, 0);");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('status_code.....', '', 'ReaderManagement', 'Status Code', 5020, 'Select in which bin should the news appear', '', 'qte:1', 1, 0, 0, 'sel:AA_Core_Bins....', '', 100, '', '', '', '', 0, 0, 0, '', '', '', '', '', '', '', '', '', '', '', 0, 0, 0, 'status_code', 'number', 'num', 0, 0);");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('switch..........', '', 'ReaderManagement', 'Email Confirmed', 600, 'Email is confirmed when the user clicks on the URL received in email', '', 'txt:', 0, 0, 0, 'chb', '', 100, '', '', '', '', 0, 0, 0, '_#MAILCONF', 'f_c:1:Yes::No::1', 'Email Confirmed', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, '', 'text', 'boo:', 1, 1);");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('text...........1', '', 'ReaderManagement', 'First name', 400, '', '', 'txt:', 0, 0, 0, 'fld:', '', 100, '', '', '', '', 1, 1, 1, '_#FIRSTNAM', 'f_c:!:::&nbsp;::', 'First name', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, '', 'text', 'qte:', 1, 1);");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('text...........2', '', 'ReaderManagement', 'Last name', 500, '', '', 'txt:', 0, 0, 0, 'fld:', '', 100, '', '', '', '', 1, 1, 1, '_#LASTNAME', 'f_c:!:::&nbsp;::', 'Last name', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, '', 'text', 'qte:', 1, 1);");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('text...........3', '', 'ReaderManagement', 'Access Code', 700, 'Access code is used to confirm email and when you do not use HTTP Authentification', '', 'rnd:5:text...........3:0', 0, 0, 0, 'fld:', '', 100, '', '', '', '', 1, 1, 1, '_#ACCECODE', 'f_h:', 'Access Code', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, '', 'text:', 'qte:', 1, 1);");

        $this->message(_m('Reader Management template slice creation - done.'));
        return true;
    }
}



/** Recreates the template (example) slice - Noticas - ES **/
class AA_Optimize_Redefine_Slice_Noticias_Templates extends AA_Optimize {

    /** Name function
    * @return string - a name
    */
    public function name(): string {
        return _m("Redefine Noticas - ES template slice");
    }

    /** Description function
    * @return string - a description
    */
    public function description(): string {
        return _m("Deletes and recreates the Noticas - ES slice, used as template or example slice");
    }

    /** checks if the this Optimize class belongs to specified type (like "sql_update") */
    function isType($type)  { return in_array($type, ['sql_update']); }


    /** Test function
    * @return bool
    */
    public function test() : bool {
        $ret = true;

        $row_count   = GetTable2Array("SELECT count(*) as count FROM module WHERE id='noticias-es.....'", "aa_first", 'count');
        if ($row_count <> 1) {
            $this->message(_m('"Noticas - ES" template slice is not defined'));
            $ret = false;
        }
        return $ret;
    }

    /** Main update function
     *  @return bool
     */
    public function repair() : bool {

        $now         = time();
        $AA_IMG_URL  = '/'. AA_BASE_DIR .'images/';
        $AA_DOC_URL  = '/'. AA_BASE_DIR .'doc/';

        $this->message(_m('Deleting all fields form "Noticas - ES" slice'));
        $this->query("DELETE FROM field WHERE slice_id='noticias-es.....'");

        $this->message(_m('Make sure "Noticas - ES" slice exists and reset to defaults'));
        $this->query("REPLACE INTO module (id, name, deleted, type, slice_url, lang_file, created_at, created_by, owner, flag) VALUES ('noticias-es.....', 'Noticias (ES) - Plantilla', 0, 'S', '', 'es_news_lang.php3', 1067835192, '', 'AA_Core.........', 0)");
        $this->query("REPLACE INTO slice (id, name, owner, deleted, created_by, created_at, export_to_all, type, template, fulltext_format_top, fulltext_format, fulltext_format_bottom, odd_row_format, even_row_format, even_odd_differ, compact_top, compact_bottom, category_top, category_format, category_bottom, category_sort, slice_url, d_listlen, lang_file, fulltext_remove, compact_remove, email_sub_enable, exclude_from_dir, notify_sh_offer, notify_sh_accept, notify_sh_remove, notify_holding_item_s, notify_holding_item_b, notify_holding_item_edit_s, notify_holding_item_edit_b, notify_active_item_edit_s, notify_active_item_edit_b, notify_active_item_s, notify_active_item_b, noitem_msg, admin_format_top, admin_format, admin_format_bottom, admin_remove, permit_anonymous_post, permit_anonymous_edit, permit_offline_fill, aditional, flag, vid, gb_direction, group_by, gb_header, gb_case, javascript, fileman_access, fileman_dir, auth_field_group, mailman_field_lists, reading_password) VALUES ('noticias-es.....', 'Noticias (ES) - Plantilla', 'AA_Core.........', 0, '8', 1067835192, 0, 'noticias-es.....', 1, '', '<h2>_#TITULAR_</h2>\r\n<B>_#AUTOR___, _#LUGAR___</B> <BR>\r\n<img src=\"_#IMAGESRC\" width=\"_#IMGWIDTH\" height=\"_#IMG_HGHT\" align=\"right\">\r\n_#TEXTO___', '', '<div class=\"item\">_#FECHAPUB:\r\n<strong><a href=_#ENLACE__>_#TITULAR_</a>\r\n</strong>\r\n<br>_#LUGAR___ [_#FTE_URL_]<br>\r\n_#RESUMEN_\r\n</div>\r\n<br>', '', 0, '', '<br>', '', '<p>_#CATEGORi</p>', '', 0, '', 10000, 'es_news_lang.php3', '()', '[]', 1, 0, '', '', '', '', '', '', '', '', '', '', '', 'No se encontraron datos', '<tr class=tablename><td width=30>&nbsp;</td><td>Haga clic para editar</td><td>Fecha</td></tr>', '<tr class=tabtxt><td width=30><input type=checkbox name=\"chb[x_#ITEM_ID#]\" value=\"1\"></td><td><a href=\"_#EDITITEM\">_#TITULAR_</a></td><td>_#FECHAPUB</td></tr>', '', '', 2, 0, 2, '', 0, 0, 2, 'category........', 0, '', '', '0', '', '', '', '')");

        $this->message(_m('Recreate field definitions for "Noticas - ES"'));
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('abstract........', '', 'noticias-es.....', 'Resumen', 150, '', '', 'txt:', 0, 0, 0, 'txt:8', '', 100, '', '', '', '', 0, 1, 1, '_#RESUMEN_', 'f_a:80:full_text.......:1', 'resumen del item', '_#RSS_IT_D', 'f_r:80:full_text.......:1', 'resumen del item para RSS', '', 'f_0:', '', '', '', 0, 0, 1, '', 'text:', 'qte:', 1, 1)");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('category........', '', 'noticias-es.....', 'Categoría', 500, '', '', 'txt:', 0, 0, 0, 'sel:lt_apcCategories:', '', 100, '', '', '', '', 1, 1, 1, '_#CATEGORI', 'f_h:', 'categoria del item', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, '', 'text:', 'qte:', 0, 1)");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('cp_code.........', '', 'noticias-es.....', 'Página de códigos', 1800, '', '', 'txt:iso8859-1', 0, 0, 0, 'sel:lt_codepages:', '', 100, '', '', '', '', 0, 0, 0, '', 'f_0:', '', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, '', 'text:', 'qte:', 0, 1)");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('created_by......', '', 'noticias-es.....', 'Autor', 470, '', '', 'txt:', 0, 0, 0, 'fld:', '', 100, '', '', '', '', 0, 0, 0, '_#AUTOR___', 'f_h:', 'autor del item', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, '', 'text:', 'qte:', 1, 1)");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('disc_app........', '', 'noticias-es.....', 'Comentarios aprobados', 5070, 'Internal field - do not change', '', 'txt:0', 1, 1, 0, 'fld:', '', 100, '', '', '', '', 0, 0, 0, '_#D_APPCNT', 'f_h:', 'número de comentarios aprobados para este ítem', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, 'disc_app', 'text:', 'qte:', 0, 1)");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('disc_count......', '', 'noticias-es.....', 'Comentarios', 5060, 'Internal field - do not change', '', 'txt:0', 1, 1, 0, 'fld:', '', 100, '', '', '', '', 0, 0, 0, '_#D_ALLCNT', 'f_h:', 'número total de comentarios sobre este ítem', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, 'disc_count', 'text:', 'qte:', 0, 1)");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('display_count...', '', 'noticias-es.....', 'Visualizaciones', 5050, 'Internal field - do not change', '', 'txt:0', 1, 1, 0, 'fld:', '', 100, '', '', '', '', 0, 0, 0, '_#VISUALIZ', 'f_h:', 'número de veces que este ítem ha sido visitado', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, 'display_count', 'text:', 'qte:', 0, 1)");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'id..............', '', 'noticias-es.....', 'Long ID', '5080', 'Internal field - do not change', '', 'txt:', 0, 0, 0, 'nul', '', 0, '', '', '', '', 1, 1, 1, '_#ITEM_ID_', 'f_n:', 'alias for Long Item ID', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, 'id', '', 'nul', 0, 1)");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'short_id........', '', 'noticias-es.....', 'Short ID', '5090', 'Internal field - do not change', '', 'txt:', 0, 0, 0, 'nul', '', 100, '', '', '', '', 1, 1, 1, '_#SITEM_ID', 'f_t:', 'alias for Short Item ID', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, 'short_id', '', 'nul', 0, 0)");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('edited_by.......', '', 'noticias-es.....', 'Editado por', 5030, '', '', 'txt:', 0, 0, 0, 'nul', '', 100, '', '', '', '', 0, 0, 0, '_#EDITADO_', 'f_h:', 'identificador del usuario que editó el ítem', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, 'edited_by', 'text:', 'uid:', 0, 1)");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('edit_note.......', '', 'noticias-es.....', 'Notas del editor', 2355, 'Estas notas no se publicarán en el sitio', '', 'txt:', 0, 0, 0, 'txt:', '', 100, '', '', '', '', 0, 0, 0, '_#EDITNOTE', 'f_h:', 'notas del editor', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, '', 'text:', 'qte:', 1, 1)");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('expiry_date.....', '', 'noticias-es.....', 'Fecha de caducidad', 955, 'Fecha en que el item expira (y se retira automáticamente del sitio)', '', 'dte:2000', 1, 0, 0, 'dte:1:10:1', '', 100, '', '', '', '', 0, 0, 0, '_#FECHACAD', 'f_d:d/m/Y', 'fecha de caducidad', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, 'expiry_date', 'date:', 'qte:', 1, 0)");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('e_posted_by.....', '', 'noticias-es.....', 'e-mail autor', 480, '', '', 'txt:', 0, 0, 0, 'fld:', '', 100, '', '', '', '', 0, 0, 0, '_#E_AUTOR_', 'f_h:', 'correo electrónico del autor', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, '', 'text:', 'qte:', 1, 1)");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('full_text.......', '', 'noticias-es.....', 'Texto completo', 200, '', '', 'txt:', 0, 0, 0, 'txt:8', '', 100, '', '', '', '', 0, 1, 1, '_#TEXTO___', 'f_t:', 'texto completo del item', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 1, '', 'text:', 'qte:', 1, 1)");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('headline........', '', 'noticias-es.....', 'Titular', 100, '', '', 'txt:', 1, 0, 0, 'fld:', '', 100, '', '', '', '', 1, 1, 1, '_#TITULAR_', 'f_h:', 'titular del item', '_#RSS_IT_T', 'f_r:100', 'titular del item para RSS', '', 'f_0:', '', '', '', 0, 0, 0, '', 'text:', 'qte:', 1, 1)");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('highlight.......', '', 'noticias-es.....', 'Resaltar', 450, '', '', 'txt:', 0, 0, 0, 'chb', '', 100, '', '', '', '', 0, 0, 0, '', 'f_0:', '', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, 'highlight', 'bool:', 'boo:', 1, 0)");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('hl_href.........', '', 'noticias-es.....', 'URL noticia externa', 400, '(para items externos) usar este URL para el enlace', '', 'txt:', 0, 0, 0, 'fld:', '', 100, '', '', '', '', 1, 1, 1, '_#ENLACE__', 'f_f:link_only.......', 'enlace al texto completo del item (se sustituye por el URL externo si está marcado como externo)', '_#RSS_IT_L', 'f_r:link_only.......', 'enlace para RSS', '', 'f_0:', '', '', '', 0, 0, 0, '', 'url:', 'qte:', 1, 1)");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('img_height......', '', 'noticias-es.....', 'alto de la imagen', 2300, 'puede ser en pixeles (ej: 100) o porcentaje (ej: 50%)', '', 'txt:', 0, 0, 0, 'fld:', '', 100, '', '', '', '', 0, 0, 0, '_#IMG_HGHT', 'f_g:', 'alto de la imagen<br>(si no está definido, se intenta eliminar el atributo <em>height=</em> del dise?o<div class=example><em>Ejemplo: </em>&lt;img src=\"_#IMAGESRC\" width=\"_#IMGWIDTH\" height=\"_#IMG_HGHT\"&gt;</div>', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, '', 'text:', 'qte:', 1, 1)");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('img_src.........', '', 'noticias-es.....', 'URL de imagen', 2100, 'URL de una imágen previamente publicada', '', 'txt:', 0, 0, 0, 'fld:', '', 100, '', '', '', '', 0, 0, 0, '_#IMAGESRC', 'f_i:', 'URL de la imagen<br>Si no está definido se usa el URL por defecto (ver NO_PICTURE_URL en en_*_lang.php3)<div class=example><em>Ejemplo: </em>&lt;img src=\"_#IMAGESRC\"&gt;</div>', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, '', 'url:', 'qte:', 1, 1)");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('img_width.......', '', 'noticias-es.....', 'ancho de la imagen', 2200, 'puede ser en pixeles (ej: 100) o porcentaje (ej: 50%)', '', 'txt:', 0, 0, 0, 'fld:', '', 100, '', '', '', '', 0, 0, 0, '_#IMGWIDTH', 'f_w:', 'ancho de la imagen<br>(si no está definido, se intenta eliminar el atributo <em>width=</em> del dise?o<div class=example><em>Ejemplo: </em>&lt;img src=\"_#IMAGESRC\" width=\"_#IMGWIDTH\" height=\"_#IMG_HGHT\"&gt;</div>', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, '', 'text:', 'qte:', 1, 1)");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('lang_code.......', '', 'noticias-es.....', 'Idioma', 1700, '', '', 'txt:EN', 0, 0, 0, 'sel:lt_languages:', '', 100, '', '', '', '', 0, 0, 0, '', 'f_0:', '', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, '', 'text:', 'qte:', 0, 1)");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('last_edit.......', '', 'noticias-es.....', 'Ultima modificación', 5040, '', '', 'now:', 0, 0, 0, 'dte:1:10:1', '', 100, '', '', '', '', 0, 0, 0, '_#ULTIMA_E', 'f_d:d/m/Y', 'fecha de la última edición', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, 'last_edit', 'date:', 'now:', 0, 0)");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('link_only.......', '', 'noticias-es.....', 'Noticia externa', 300, 'Usar un enlace externo en vez del texto completo', '', 'txt:', 0, 0, 0, 'chb', '', 100, '', '', '', '', 0, 0, 1, '', 'f_0:', '', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, '', 'bool:', 'boo:', 1, 0)");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('place...........', '', 'noticias-es.....', 'Localidad', 630, '', '', 'txt:', 0, 0, 0, 'fld:', '', 100, '', '', '', '', 0, 0, 0, '_#LUGAR___', 'f_h:', 'localidad', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, '', 'text:', 'qte:', 1, 1)");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('posted_by.......', '', 'noticias-es.....', 'Publicado por', 5035, '', '', 'txt:', 0, 0, 0, 'fld:', '', 100, '', '', '', '', 0, 0, 0, '_#PUBLICAD', 'f_h:', 'identificador del usuario que publicó el ítem', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, 'posted_by', 'text:', 'uid:', 0, 1)");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('post_date.......', '', 'noticias-es.....', 'Fecha de envío', 5005, '', '', 'now:', 1, 0, 0, 'nul', '', 100, '', '', '', '', 0, 0, 0, '_#FECHAENV', 'f_d:d/m/Y', 'fecha en que fué enviado el ítem', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, 'post_date', 'date:', 'now:', 0, 0)");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('publish_date....', '', 'noticias-es.....', 'Fecha de publicación', 900, 'Fecha en que el item debe aparecer publicado en el sitio', '', 'now:', 1, 0, 0, 'dte:1:10:1', '', 100, '', '', '', '', 0, 0, 0, '_#FECHAPUB', 'f_d:d/m/Y', 'fecha de publicación del item', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, 'publish_date', 'date:', 'qte:', 1, 0)");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'status_code.....', '', 'noticias-es.....', 'Estado', '5020', 'Seleccione en qué carpeta se almacena el item', '', 'qte:1', '1', '0', '0', 'sel:AA_Core_Bins....', '', '100', '', '', '', '', '0', '0', '0', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', 'status_code', 'number', 'num', '0', '0')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('slice_id........', '', 'noticias-es.....', 'Canal', 5000, 'Internal field - do not change', '', 'txt:1', 1, 0, 0, 'fld:', '', 100, '', '', '', '', 0, 0, 0, '_#ID_CANAL', 'f_n:slice_id........', 'identificador interno del canal', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, 'slice_id', 'text:', 'qte:', 0, 1)");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('source..........', '', 'noticias-es.....', 'Fuente', 600, '', '', 'txt:', 0, 0, 0, 'fld:', '', 100, '', '', '', '', 0, 0, 0, '_#FUENTE__', 'f_h:', 'fuente', '_#FTE_URL_', 'f_l:source_href.....', 'fuente mostrada como enlace al URL de la fuente (si está rellenado)', '', 'f_0:', '', '', '', 0, 0, 0, '', 'text:', 'qte:', 1, 1)");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('source_href.....', '', 'noticias-es.....', 'URL de la fuente', 610, '', '', 'txt:', 0, 0, 0, 'fld:', '', 100, '', '', '', '', 1, 1, 1, '_#URL_FTE_', 'f_h:', 'URL de la fuente', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, '', 'url:', 'qte:', 1, 1)");

        $this->message(_m('Noticas - ES template slice creation - done.'));
        return true;
    }
}

/** Recreates the template view - discus **/
class AA_Optimize_Redefine_View_Discus_Templates extends AA_Optimize {

    /** Name function @return a message */
    public function name(): string {
        return _m("Redefine discus template view");
    }

    /** Description function @return a message */
    public function description(): string {
        return _m("Deletes and recreates that view, which is used as template view of that type");
    }


    function _viewType(){
        return 'discus';
    }

    function _viewDefinition() {
        $AA_IMG_URL  = '/'. AA_BASE_DIR .'images/';
        return "INSERT INTO view SET slice_id='AA_Core_Fields..', name='Discussion ...', type='discus', `before`='<table bgcolor=#000000 cellspacing=0 cellpadding=1 border=0><tr><td><table width=100% bgcolor=#f5f0e7 cellspacing=0 cellpadding=0 border=0><tr><td colspan=8><big>Comments</big></td></tr>', even='<table  width=500 cellspacing=0 cellpadding=0 border=0><tr><td colspan=2><hr></td></tr><tr><td width=\"20%\"><b>Date:</b></td><td> _#DATE####</td></tr><tr><td><b>Comment:</b></td><td> _#SUBJECT#</td></tr><tr><td><b>Author:</b></td><td><A href=mailto:_#EMAIL###>_#AUTHOR##</a></td></tr><tr><td><b>WWW:</b></td><td><A href=_#WWW_URL#>_#WWW_DESC</a></td></tr><tr><td><b>IP:</b></td><td>_#IP_ADDR#</td></tr><tr><td colspan=2>&nbsp;</td></tr><tr><td colspan=2>_#BODY####</td></tr><tr><td colspan=2>&nbsp;</td></tr><tr><td colspan=2><a href=_#URLREPLY>Reply</a></td></tr></table><br>', odd='<tr><td width=\"10\">&nbsp;</td><td><font size=-1>_#CHECKBOX</font></td><td width=\"10\">&nbsp;</td><td align=center nowrap><SMALL>_#DATE####</SMALL></td><td width=\"20\">&nbsp;</td><td nowrap>_#AUTHOR## </td><td><table cellspacing=0 cellpadding=0 border=0><tr><td>_#TREEIMGS</td><td><img src=".$AA_IMG_URL."blank.gif width=2 height=21></td><td nowrap>_#SUBJECT#</td></tr></table></td><td width=\"20\">&nbsp;</td></tr>', even_odd_differ=1, after='</table></td></tr></table>_#BUTTONS#', remove_string='<script>function checkData() { var text=\"\"; if(!document.f.d_subject.value) { text+=\"subject \" } if (text!=\"\") { alert(\"Please, fill the field: \" + text);  return false; } return true; }</script><form name=f method=post action=\"/apc-aa/filldisc.php3\" onSubmit=\" return checkData()\"><p>Author<br><input type=text name=d_author > <p>Subject<br><input type=text name=d_subject value=\"_#SUBJECT#\"><p>E-mail<br><input type=text name=d_e_mail><p>Comment<br><textarea rows=\"5\" cols=\"40\" name=d_body ></textarea><p>WWW<br><input type=text name=d_url_address value=\"http://\"><p>WWW description<br><input type=text name=d_url_description><br><input type=submit value=Send align=center><input type=hidden name=d_parent value=\"_#DISC_ID#\"><input type=hidden name=d_item_id value=\"_#ITEM_ID#\"><input type=hidden name=url value=\"_#DISC_URL\"></FORM>', group_title=NULL, order1=NULL, o1_direction=0, order2=NULL, o2_direction=NULL, group_by1=NULL, g1_direction=NULL, group_by2=NULL, g2_direction=NULL, cond1field=NULL, cond1op=NULL, cond1cond=NULL, cond2field=NULL, cond2op=NULL, cond2cond=NULL, cond3field=NULL, cond3op=NULL, cond3cond=NULL, listlen=NULL, scroller=NULL, selected_item=0, modification=23, parameter=NULL, img1='<img src=${AA_IMG_URL}i.gif width=9 height=21>', img2='<img src=${AA_IMG_URL}l.gif width=9 height=21>', img3='<img src=${AA_IMG_URL}t.gif width=9 height=21>', img4='<img src=${AA_IMG_URL}blank.gif width=12 height=21>', flag=NULL, aditional=NULL, aditional2=NULL, aditional3=NULL, aditional4=NULL, aditional5=NULL, aditional6=NULL, noitem_msg='No item found', group_bottom=NULL, field1='', field2=NULL, field3=NULL, calendar_type='mon'";
    }


    /** checks if the this Optimize class belongs to specified type (like "sql_update") */
    function isType($type)  { return in_array($type, ['sql_update']); }


    /** Test function @return bool */
    public function test() : bool {
        $ret = true;

        $row_count   = GetTable2Array("SELECT count(*) as count FROM view WHERE slice_id='AA_Core_Fields..' AND type='".$this->_viewType()."'", "aa_first", 'count');
        if ($row_count <> 1) {
            $this->message(_m('"%1" template view is not defined', [$this->_viewType()]));
            $ret = false;
        }
        return $ret;
    }

    /** Main update function
     *  @return bool
     */
    public function repair() : bool {
        $this->message(_m('Deleting the view template "%1"', [$this->_viewType()]));
        $this->query("DELETE FROM view WHERE slice_id='AA_Core_Fields..' AND type='".$this->_viewType()."' ");

        $this->message(_m('Inserting the view "discus" template'));
        $this->query($this->_viewDefinition());

        $this->message(_m('view "%1" redefinition - done.', [$this->_viewType()]));
        return true;
    }
}

/** Recreates the template view - const**/
class AA_Optimize_Redefine_View_Const_Templates extends AA_Optimize_Redefine_View_Discus_Templates {

    public function name(): string { return _m("Redefine const template view"); }
    function _viewType()       { return 'const'; }
    function _viewDefinition() { return "INSERT INTO view SET slice_id='AA_Core_Fields..', name='Constant view ...', type='const', `before`='<table border=0 cellpadding=0 cellspacing=0>', even='', odd='<tr><td>_#VALUE###</td></tr>', even_odd_differ=0, after='</table>', remove_string=NULL, group_title=NULL, order1='value', o1_direction=0, order2=NULL, o2_direction=NULL, group_by1=NULL, g1_direction=NULL, group_by2=NULL, g2_direction=NULL, cond1field=NULL, cond1op=NULL, cond1cond=NULL, cond2field=NULL, cond2op=NULL, cond2cond=NULL, cond3field=NULL, cond3op=NULL, cond3cond=NULL, listlen=10, scroller=NULL, selected_item=0, modification=NULL, parameter='lt_languages', img1=NULL, img2=NULL, img3=NULL, img4=NULL, flag=NULL, aditional=NULL, aditional2=NULL, aditional3=NULL, aditional4=NULL, aditional5=NULL, aditional6=NULL, noitem_msg='No item found', group_bottom=NULL, field1='', field2=NULL, field3=NULL, calendar_type='mon'"; }

    /**
     * @inheritDoc
     */
    public function description(): string {
        return ''; // TODO: Implement description() method.
    }
}

/** Recreates the template view - javascript**/
class AA_Optimize_Redefine_View_Javascript_Templates extends AA_Optimize_Redefine_View_Discus_Templates {

    public function name(): string { return _m("Redefine javascript template view"); }
    function _viewType()       { return 'javascript'; }
    function _viewDefinition() { return "INSERT INTO view SET slice_id='AA_Core_Fields..', name='Javascript ...', type='javascript', `before`='/* output of this script can be included to any page on any server by adding:&lt;script type=\"text/javascript\" src=\"". AA_INSTAL_PATH ."view.php3?vid=3\"&gt; &lt;/script&lt; or such.*/', even=NULL, odd='document.write(\"_#HEADLINE\");', even_odd_differ=NULL, after='// script end ', remove_string=NULL, group_title=NULL, order1='', o1_direction=0, order2='', o2_direction=0, group_by1=NULL, g1_direction=NULL, group_by2=NULL, g2_direction=NULL, cond1field='', cond1op='<', cond1cond='', cond2field='', cond2op='<', cond2cond='', cond3field='', cond3op='<', cond3cond='', listlen=8, scroller=NULL, selected_item=NULL, modification=NULL, parameter=NULL, img1=NULL, img2=NULL, img3=NULL, img4=NULL, flag=NULL, aditional=NULL, aditional2=NULL, aditional3=NULL, aditional4=NULL, aditional5=NULL, aditional6=NULL, noitem_msg='No item found', group_bottom=NULL, field1='', field2=NULL, field3=NULL, calendar_type='mon'"; }
}

/** Recreates the template view - rss**/
class AA_Optimize_Redefine_View_Rss_Templates extends AA_Optimize_Redefine_View_Discus_Templates {

    public function name(): string { return _m("Redefine rss template view"); }
    function _viewType()       { return 'rss'; }
    function _viewDefinition() { return "INSERT INTO view SET slice_id='AA_Core_Fields..', name='rss', type='rss', `before`='<!DOCTYPE rss PUBLIC \"-//Netscape Communications//DTD RSS 0.91//EN\" \"http://my.netscape.com/publish/formats/rss-0.91.dtd\"> <rss version=\"0.91\"> <channel>  <title>_#RSS_TITL</title>  <link>_#RSS_LINK</link>  <description>_#RSS_DESC</description>  <lastBuildDate>_#RSS_DATE</lastBuildDate> <language></language>', even=NULL, odd=' <item> <title>_#RSS_IT_T</title> <link>_#RSS_IT_L</link> <description>_#RSS_IT_D</description> </item>', even_odd_differ=NULL, after='</channel></rss>', remove_string=NULL, group_title=NULL, order1='publish_date....', o1_direction=0, order2='headline........', o2_direction=0, group_by1=NULL, g1_direction=NULL, group_by2=NULL, g2_direction=NULL, cond1field='source..........', cond1op='', cond1cond='', cond2field='', cond2op='<', cond2cond='', cond3field='', cond3op='<', cond3cond='', listlen=15, scroller=NULL, selected_item=NULL, modification=NULL, parameter=NULL, img1=NULL, img2=NULL, img3=NULL, img4=NULL, flag=NULL, aditional='NULL', aditional2='NULL', aditional3='NULL', aditional4='NULL', aditional5='NULL', aditional6='NULL', noitem_msg='<!DOCTYPE rss PUBLIC \"-//Netscape Communications//DTD RSS 0.91//EN\" \"http://my.netscape.com/publish/formats/rss-0.91.dtd\"> <rss version=\"0.91\"> <title>_#RSS_TITL</title>  <link>_#RSS_LINK</link>  <description>_#RSS_DESC</description>  <lastBuildDate>_#RSS_DATE</lastBuildDate> <language></language><channel></channel></rss>', group_bottom=NULL, field1=NULL, field2=NULL, field3=NULL, calendar_type='mon'"; }
}

/** Recreates the template view - calendar**/
class AA_Optimize_Redefine_View_Calendar_Templates extends AA_Optimize_Redefine_View_Discus_Templates {

    public function name(): string { return _m("Redefine calendar template view"); }
    function _viewType()       { return 'calendar'; }
    function _viewDefinition() { return "INSERT INTO view SET slice_id='AA_Core_Fields..', name='Calendar', type='calendar', `before`='<table border=1>\r\n<tr><td>Mon</td><td>Tue</td><td>Wen</td><td>Thu</td><td>Fri</td><td>Sat</td><td>Sun</td></tr>', even=NULL, odd='_#STARTDAT-_#END_DATE <b>_#HEADLINE</b>', even_odd_differ=1, after='</table>', remove_string='', group_title='<td><font size=+2><a href=\"calendar.shtml?vid=319&cmd[319]=c-1-_#CV_TST_2-2-_#CV_TST_1&month=_#CV_NUM_M&year=_#CV_NUM_Y&day=_#CV_NUM_D\"><b>_#CV_NUM_D</b></a></font></td>', order1='', o1_direction=0, order2='', o2_direction=0, group_by1=NULL, g1_direction=NULL, group_by2=NULL, g2_direction=NULL, cond1field='publish_date....', cond1op='<', cond1cond='', cond2field='', cond2op='<', cond2cond='', cond3field='', cond3op='<', cond3cond='', listlen=5, scroller=NULL, selected_item=NULL, modification=NULL, parameter=NULL, img1=NULL, img2=NULL, img3=NULL, img4=NULL, flag=NULL, aditional='<td><font size=+2>_#CV_NUM_D</font></td>', aditional2='', aditional3='bgcolor=\"_#COLOR___\"', aditional4=NULL, aditional5=NULL, aditional6=NULL, noitem_msg='There are no events in this month.', group_bottom='', field1='start_date.....1', field2='end_date.......1', field3=NULL, calendar_type='mon_table'"; }
}

/** Recreates the template view - links**/
class AA_Optimize_Redefine_View_Links_Templates extends AA_Optimize_Redefine_View_Discus_Templates {

    public function name(): string { return _m("Redefine links template view"); }
    function _viewType()       { return 'links'; }
    function _viewDefinition() { return "INSERT INTO view SET slice_id='AA_Core_Fields..', name='Links', type='links', `before`='<br>\r\n', even='', odd='<p><a href=\"_#L_URL___\" class=\"link\">_#L_NAME__ (_#L_O_NAME)</a><br>\r\n          _#L_DESCRI<br>\r\n          <a href=\"_#L_URL___\" class=\"link2\">_#L_URL___</a>\r\n     </p>\r\n', even_odd_differ=0, after='', remove_string='()', group_title='', order1='', o1_direction=0, order2=NULL, o2_direction=0, group_by1=NULL, g1_direction=0, group_by2=NULL, g2_direction=0, cond1field=NULL, cond1op='<', cond1cond=NULL, cond2field=NULL, cond2op='<', cond2cond=NULL, cond3field=NULL, cond3op='<', cond3cond=NULL, listlen=1000, scroller=NULL, selected_item=NULL, modification=NULL, parameter=NULL, img1=NULL, img2=NULL, img3=NULL, img4=NULL, flag=NULL, aditional=NULL, aditional2=NULL, aditional3=NULL, aditional4=NULL, aditional5=NULL, aditional6=NULL, noitem_msg='<!-- no links in this category -->', group_bottom='', field1=NULL, field2=NULL, field3=NULL, calendar_type='mon'"; }
}

/** Recreates the template view - categories**/
class AA_Optimize_Redefine_View_Categories_Templates extends AA_Optimize_Redefine_View_Discus_Templates {

    public function name(): string { return _m("Redefine categories template view"); }
    function _viewType()       { return 'categories'; }
    function _viewDefinition() { return "INSERT INTO view SET slice_id='AA_Core_Fields..', name='Catategories', type='categories', `before`='     <br><b>_#C_PATH__</b><br><br>\r\n', even='', odd='<br>&#8226; <a href=\"?cat=_#CATEG_ID\" class=\"link\">_#C_NAME___#C_CROSS_</a>&nbsp;&nbsp;<b>(_#C_LCOUNT)</b>\r\n', even_odd_differ=0, after='', remove_string='', group_title='', order1='', o1_direction=0, order2='', o2_direction=0, group_by1='', g1_direction=0, group_by2='', g2_direction=0, cond1field='', cond1op='<', cond1cond='', cond2field='', cond2op='<', cond2cond='', cond3field='', cond3op='<', cond3cond='', listlen=1000, scroller=NULL, selected_item=NULL, modification=NULL, parameter=NULL, img1=NULL, img2=NULL, img3=NULL, img4=NULL, flag=NULL, aditional=NULL, aditional2=NULL, aditional3=NULL, aditional4=NULL, aditional5=NULL, aditional6=NULL, noitem_msg='<!-- no categories in this category -->', group_bottom='', field1=NULL, field2=NULL, field3=NULL, calendar_type='mon'"; }
}

/** Recreates the template view - urls**/
class AA_Optimize_Redefine_View_Urls_Templates extends AA_Optimize_Redefine_View_Discus_Templates {

    public function name(): string { return _m("Redefine urls template view"); }
    function _viewType()       { return 'urls'; }
    function _viewDefinition() { return "INSERT INTO view SET slice_id='AA_Core_Fields..', name='URLs listing', type='urls', `before`='<!-- view used for listing URLs of items -->', even=NULL, odd='<a href=\"http://www.example.org/index.stm?x=_#SITEM_ID\">_#SITEM_ID</a><br>\r\n', even_odd_differ=0, after='', remove_string='', group_title='', order1='', o1_direction=0, order2='', o2_direction=0, group_by1='', g1_direction=0, group_by2='', g2_direction=0, cond1field='', cond1op='<', cond1cond='', cond2field='', cond2op='<', cond2cond='', cond3field='', cond3op='<', cond3cond='', listlen=100000, scroller=NULL, selected_item=NULL, modification=NULL, parameter=NULL, img1=NULL, img2=NULL, img3=NULL, img4=NULL, flag=NULL, aditional=NULL, aditional2=NULL, aditional3=NULL, aditional4=NULL, aditional5=NULL, aditional6=NULL, noitem_msg='No item found', group_bottom=NULL, field1=NULL, field2=NULL, field3=NULL, calendar_type='mon'"; }
}

/** Recreates the template view - static**/
class AA_Optimize_Redefine_View_Static_Templates extends AA_Optimize_Redefine_View_Discus_Templates {

    public function name(): string { return _m("Redefine static template view"); }
    function _viewType()       { return 'static'; }
    function _viewDefinition() { return "INSERT INTO view SET slice_id='AA_Core_Fields..', name='Static page', type='static', `before`=NULL, even=NULL, odd='<!-- Static page view is used for creating and viewing static pages like Contacts or About us.', even_odd_differ=NULL, after=NULL, remove_string=NULL, group_title=NULL, order1=NULL, o1_direction=NULL, order2=NULL, o2_direction=NULL, group_by1=NULL, g1_direction=NULL, group_by2=NULL, g2_direction=NULL, cond1field=NULL, cond1op=NULL, cond1cond=NULL, cond2field=NULL, cond2op=NULL, cond2cond=NULL, cond3field=NULL, cond3op=NULL, cond3cond=NULL, listlen=NULL, scroller=NULL, selected_item=NULL, modification=NULL, parameter=NULL, img1=NULL, img2=NULL, img3=NULL, img4=NULL, flag=NULL, aditional=NULL, aditional2=NULL, aditional3=NULL, aditional4=NULL, aditional5=NULL, aditional6=NULL, noitem_msg=NULL, group_bottom=NULL, field1=NULL, field2=NULL, field3=NULL, calendar_type='mon'"; }
}

/** Recreates the template view - full**/
class AA_Optimize_Redefine_View_Full_Templates extends AA_Optimize_Redefine_View_Discus_Templates {

    public function name(): string { return _m("Redefine full template view"); }
    function _viewType()       { return 'full'; }
    function _viewDefinition() { return "INSERT INTO view SET slice_id='AA_Core_Fields..', name='Fulltext view', type='full', `before`='<!-- Fulltext view is for viewing long items. It shows only one selected item with abstract and fulltext. -->\r\n\r\n<!-- top of the page -->\r\n<br>', even=NULL, odd='<h2><b>_#HEADLINE</b></h2>\r\n_#PUB_DATE, _#AUTHOR__\r\n<br>\r\n_#FULLTEXT<br>\r\n<div align=\"right\"><a href=\"javascript:history.go(-1)\">Back</a></div>\r\n', even_odd_differ=NULL, after='', remove_string=NULL, group_title=NULL, order1=NULL, o1_direction=NULL, order2=NULL, o2_direction=NULL, group_by1=NULL, g1_direction=NULL, group_by2=NULL, g2_direction=NULL, cond1field='', cond1op='<', cond1cond='', cond2field='', cond2op='<', cond2cond='', cond3field='', cond3op='<', cond3cond='', listlen=NULL, scroller=NULL, selected_item=NULL, modification=NULL, parameter=NULL, img1=NULL, img2=NULL, img3=NULL, img4=NULL, flag=NULL, aditional=NULL, aditional2=NULL, aditional3=NULL, aditional4=NULL, aditional5=NULL, aditional6=NULL, noitem_msg='<p>No item found.</p>', group_bottom=NULL, field1=NULL, field2=NULL, field3=NULL, calendar_type='mon'"; }
}

/** Recreates the default site module template **/
class AA_Optimize_Redefine_Site_Templates extends AA_Optimize {

    /** Name function @return a message */
    public function name(): string {
        return _m("Recreate Site module template");
    }

    /** Description function @return a message */
    public function description(): string {
        return _m("Deletes and recreates the default template for Site module");
    }

    /** checks if the this Optimize class belongs to specified type (like "sql_update") */
    function isType($type)  { return in_array($type, ['sql_update']); }


    /** Test function @return bool */
    public function test() : bool {
        $ret = true;

        $row_count   = GetTable2Array("SELECT count(*) as count FROM module WHERE id='SiteTemplate....'", "aa_first", 'count');
        if ($row_count <> 1) {
            $this->message(_m('Site Module template should be recreated'));
            $ret = false;
        }
        return $ret;
    }

    /** Main update function @return bool */
    public function repair() : bool {

        $now = time();
        $this->message(_m('Replacing module definition'));
        $this->query("REPLACE INTO module  (id, name, deleted, type, slice_url, lang_file, created_at, created_by, owner, flag) VALUES ('SiteTemplate....', 'Site Template', 0, 'W', 'https://example.org', 'en-utf8_site_lang.php3', $now, '', '', 1)");
        $this->query("REPLACE INTO site    (id, state_file, structure, flag) VALUES ('SiteTemplate....', '/en/home', '".'O:8:"sitetree":2:{s:4:"tree";a:1:{i:1;O:4:"spot":8:{s:2:"id";s:1:"1";s:1:"n";s:5:"start";s:1:"c";N;s:1:"v";N;s:1:"p";s:1:"1";s:2:"po";a:1:{i:0;s:1:"1";}s:2:"ch";N;s:1:"f";i:0;}}s:8:"start_id";s:1:"1";}'."', 0)");

        $this->message(_m('Site Module template recreation - done.'));
        return true;
    }
}


// this is wrong! it deletes the priority field! Disabled for now.
/*
$SQL_update_modules[] = "REPLACE INTO module (id, name, deleted, type, slice_url, lang_file, created_at, created_by, owner, flag) SELECT id, name, deleted, 'S', slice_url, lang_file, created_at, created_by, owner, 0 FROM slice";
*/


/** Adds cron entry for Alerts Sending into AA cron **/
class AA_Optimize_Add_Alerts_Cron_Entry extends AA_Optimize {

    /** Name function @return a message */
    public function name(): string {
        return _m("Adds cron entry for Alerts Sending into AA cron");
    }

    /** Description function @return a message */
    public function description(): string {
        return _m("It however do not install the system cron for AA - you should do it by yourself (see /misc/aa-http-request script)");
    }

    /** checks if the this Optimize class belongs to specified type (like "sql_update") */
    function isType($type)  { return in_array($type, ['sql_update']); }


    /** Test function @return bool */
    public function test() : bool {
        $ret = true;

        $row_count   = GetTable2Array("SELECT count(*) as count FROM cron WHERE script='modules/alerts/alerts.php3'", "aa_first", 'count');
        if ($row_count < 1 ) {
            $this->message(_m('Cron entry for Alerts Sending is not installed in AA Cron'));
            $ret = false;
        }
        return $ret;
    }

    /** Main update function @return bool */
    public function repair() : bool {

        $this->message(_m('Deleting AA Cron entry for Alerts Sending'));
        $this->query("DELETE FROM cron WHERE script='modules/alerts/alerts.php3'");

        $this->message(_m('Adding AA cron entry for Alerts Sending'));
        $this->query("INSERT INTO cron (minutes, hours, mday, mon, wday, script, params, last_run) VALUES ('0-60/5', '*', '*', '*', '*', 'modules/alerts/alerts.php3', 'howoften=instant', NULL)");

        $this->message(_m('AA cron entry for Alerts Sending - done.'));
        return true;
    }
}

/** Adds cron entry for Feeding into AA cron **/
class AA_Optimize_Add_Feeding_Cron_Entry extends AA_Optimize {

    /** Name function @return a message */
    public function name(): string {
        return _m("Adds cron entry for Feeding into AA cron");
    }

    /** Description function @return a message */
    public function description(): string {
        return _m("It however do not install the system cron for AA - you should do it by yourself (see /misc/aa-http-request script)");
    }

    /** checks if the this Optimize class belongs to specified type (like "sql_update") */
    function isType($type)  { return in_array($type, ['sql_update']); }


    /** Test function @return bool */
    public function test() : bool {
        $ret = true;

        $row_count   = GetTable2Array("SELECT count(*) as count FROM cron WHERE script='admin/xmlclient.php3'", "aa_first", 'count');
        if ($row_count < 1) {
            $this->message(_m('Cron entry for Feeding is not installed in AA Cron'));
            $ret = false;
        }
        return $ret;
    }

    /** Main update function @return bool */
    public function repair() : bool {

        $this->message(_m('Deleting AA Cron entry for Feeding'));
        $this->query("DELETE FROM cron WHERE script='admin/xmlclient.php3'");

        $this->message(_m('Adding AA cron entry for Feeding'));
        $this->query("INSERT INTO cron (minutes, hours, mday, mon, wday, script, params, last_run) VALUES ('8,23,38,53', '*', '*', '*', '*', 'admin/xmlclient.php3', '', NULL)");

        $this->message(_m('AA cron entry for Feeding - done.'));
        return true;
    }
}

/** Adds cron entry for Database Optimalization into AA cron **/
class AA_Optimize_Add_Optimize_Cron_Entry extends AA_Optimize {

    /** Name function @return a message */
    public function name(): string {
        return _m("Adds cron entry for Database Optimalization into AA cron");
    }

    /** Description function @return a message */
    public function description(): string {
        return _m("It however do not install the system cron for AA - you should do it by yourself (see /misc/aa-http-request script)");
    }

    /** checks if the this Optimize class belongs to specified type (like "sql_update") */
    function isType($type)  { return in_array($type, ['sql_update']); }


    /** Test function @return bool */
    public function test() : bool {
        $ret = true;

        $row_count   = GetTable2Array("SELECT count(*) as count FROM cron WHERE script='misc/optimize.php3'", "aa_first", 'count');
        if ($row_count < 1) {
            $this->message(_m('Cron entry for Database Optimalization is not installed in AA Cron'));
            $ret = false;
        }
        return $ret;
    }

    /** Main update function @return bool */
    public function repair() : bool {

        $this->message(_m('Deleting AA Cron entry for Database Optimalization'));
        $this->query("DELETE FROM cron WHERE script='misc/optimize.php3'");

        $this->message(_m('Adding AA cron entry for Database Optimalization'));
        $this->query("INSERT INTO cron (minutes, hours, mday, mon, wday, script, params, last_run) VALUES ('38',     '2', '*', '*', '2', 'misc/optimize.php3', 'key=".substr( DB_PASSWORD, 0, 5 )."', NULL)");

        $this->message(_m('AA cron entry for Database Optimalization - done.'));
        return true;
    }
}

/** Adds cron entry for Checking link stutus in Links module into AA cron **/
class AA_Optimize_Add_Linkcheck_Cron_Entry extends AA_Optimize {

    /** Name function @return a message */
    public function name(): string {
        return _m("Adds cron entry for Link checking in Links Module into AA cron");
    }

    /** Description function @return a message */
    public function description(): string {
        return _m("It however do not install the system cron for AA - you should do it by yourself (see /misc/aa-http-request script)");
    }

    /** checks if the this Optimize class belongs to specified type (like "sql_update") */
    function isType($type)  { return in_array($type, ['sql_update']); }


    /** Test function @return bool */
    public function test() : bool {
        $ret = true;

        $row_count   = GetTable2Array("SELECT count(*) as count FROM cron WHERE script='modules/links/linkcheck.php3'", "aa_first", 'count');
        if ($row_count < 1) {
            $this->message(_m('Cron entry for "Checking link stutus in Links module" is not installed in AA Cron'));
            $ret = false;
        }
        return $ret;
    }

    /** Main update function @return bool */
    public function repair() : bool {

        $this->message(_m('Deleting AA Cron entry for Checking link stutus in Links module'));
        $this->query("DELETE FROM cron WHERE script='modules/links/linkcheck.php3'");

        $this->message(_m('Adding AA cron entry for Checking link stutus in Links module'));
        $this->query("INSERT INTO cron (minutes, hours, mday, mon, wday, script, params, last_run) VALUES ('35',     '*', '*', '*', '*', 'modules/links/linkcheck.php3', '', NULL)");

        $this->message(_m('AA cron entry for "Checking link stutus in Links module" - done.'));
        return true;
    }
}

/** Adds cron entry for Toexecute queue execution into AA cron **/
class AA_Optimize_Add_Toexecute_Cron_Entry extends AA_Optimize {

    /** Name function @return a message */
    public function name(): string {
        return _m("Adds cron entry for Toexecute queue execution into AA cron");
    }

    /** Description function @return a message */
    public function description(): string {
        return _m("It however do not install the system cron for AA - you should do it by yourself (see /misc/aa-http-request script)");
    }

    /** checks if the this Optimize class belongs to specified type (like "sql_update") */
    function isType($type)  { return in_array($type, ['sql_update']); }


    /** Test function @return bool */
    public function test() : bool {
        $ret = true;

        $row_count   = GetTable2Array("SELECT count(*) as count FROM cron WHERE script='misc/toexecute.php3'", "aa_first", 'count');
        if ($row_count < 1) {
            $this->message(_m('Cron entry for "Toexecute queue execution" is not installed in AA Cron'));
            $ret = false;
        }
        return $ret;
    }

    /** Main update function @return bool */
    public function repair() : bool {

        $this->message(_m('Deleting AA Cron entry for Toexecute queue execution'));
        $this->query("DELETE FROM cron WHERE script='misc/toexecute.php3'");

        $this->message(_m('Adding AA cron entry for Toexecute queue execution'));
        $this->query("INSERT INTO cron (minutes, hours, mday, mon, wday, script, params, last_run) VALUES ('0-60/2',     '*', '*', '*', '*', 'misc/toexecute.php3', '', NULL)");

        $this->message(_m('AA cron entry for Toexecute queue execution - done.'));
        return true;
    }
}



/** Adds email templates for Alerts module **/
class AA_Optimize_Add_Email_Templates_Cron_Entry extends AA_Optimize {

    /** Name function @return a message */
    public function name(): string {
        return _m("Adds email templates for Alerts module");
    }

    /** Description function @return a message */
    public function description(): string {
        return _m("");
    }

    /** checks if the this Optimize class belongs to specified type (like "sql_update") */
    function isType($type)  { return in_array($type, ['sql_update']); }


    /** Test function @return bool */
    public function test() : bool {
        $ret = true;

        $row_count   = GetTable2Array("SELECT count(*) as count FROM email WHERE type='alerts welcome'", "aa_first", 'count');
        if ($row_count < 1) {
            $this->message(_m('The e-mail templates are not complete'));
            $ret = false;
        }
        return $ret;
    }

    /** Main update function @return bool */
    public function repair() : bool {

        $this->message(_m('Replacing the e-mail templates'));
        $this->query("REPLACE INTO email (description, subject, body, header_from, reply_to, errors_to, sender, lang, html, type) VALUES ('Generic Alerts Welcome', 'Welcome to Econnect Alerts', 'Somebody requested to receive regularly new items from our web site \r\n<a href=\"http://www.ecn.cz\">www.ecn.cz</a>\r\n{switch({_#HOWOFTEN})instant:at the moment they are added\r\n:daily:once a day\r\n:weekly:once a week\r\n:monthly:once a month}.<br>\r\n<br>\r\nYou will not receive any emails until you confirm your subscription.\r\nTo confirm it or to change your personal info, please go to<br>\r\n<a href=\"_#COLLFORM\">_#COLLFORM</a>.<br><br>\r\nThank you for reading our alerts,<br>\r\nThe Econnect team\r\n', 'somebody@haha.cz', '', '', '', 'cz', 1, 'alerts welcome');");
        $this->query("REPLACE INTO email (description, subject, body, header_from, reply_to, errors_to, sender, lang, html, type) VALUES ('Generic Alerts Alert', '{switch({_#HOWOFTEN})instant:News from Econnect::_#HOWOFTEN digest from Econnect}', '_#FILTERS_\r\n<br><hr>\r\nTo change your personal info, please go to<br>\r\n<a href=\"_#COLLFORM\">_#COLLFORM</a>.<br><br>\r\nThank you for reading our alerts,<br>\r\nThe Econnect team\r\n', 'econnect@team.cz', '', '', '', 'cz', 1, 'alerts alert');");
        $this->query("REPLACE INTO email (description, subject, body, header_from, reply_to, errors_to, sender, lang, html, type) VALUES ('Generic Item Manager Welcome', 'Welcome, AA _#ROLE____', 'You have been assigned an Item Manager for the slice _#SLICNAME. Your username is _#LOGIN___. See <a href=\"http://apc-aa.sf.net/faq\">FAQ</a> for help.', '\"_#ME_NAME_\" <_#ME_MAIL_>', '', '', '', 'en', 1, 'slice wizard welcome');");

        $this->message(_m('The e-mail templates updated'));
        return true;
    }
}


/** Recreates the default Links module template **/
class AA_Optimize_Redefine_Links_Templates extends AA_Optimize {

    /** Name function @return a message */
    public function name(): string {
        return _m("Recreate Links module template");
    }

    /** Description function @return a message */
    public function description(): string {
        return _m("Deletes and recreates the default settings for Links module");
    }

    /** checks if the this Optimize class belongs to specified type (like "sql_update") */
    function isType($type)  { return in_array($type, ['sql_update']); }


    /** Test function @return bool */
    public function test() : bool {
        $ret = true;

        $row_count   = GetTable2Array("SELECT count(*) as count FROM module WHERE id LIKE '1Links%'", "aa_first", 'count');
        if ($row_count <> 1) {
            $this->message(_m('There is no root Link Module category - Links module should be recreted'));
            $ret = false;
        }
        return $ret;
    }

    /** Main update function @return bool */
    public function repair() : bool {

        $now = time();
        $this->message(_m('Replacing module definition'));
        $plinks_root_id = substr( '1Links'.q_pack_id(AA_ID), 0, 16 ); // the same as q_pack_id(Links_Category2SliceID(1)); see /modules/links/util.php3
        $plinks_test_id = substr( '2Links'.q_pack_id(AA_ID), 0, 16 ); // the same as q_pack_id(Links_Category2SliceID(2)); see /modules/links/util.php3
        $this->query("REPLACE INTO module (id, name, deleted, type, slice_url, lang_file, created_at, created_by, owner, flag) VALUES ('$plinks_root_id', 'Links root', 0, 'Links', 'https://example.org/index.shtml', 'en_links_lang.php3', $now, '', '', 0)");
        $this->query("REPLACE INTO module (id, name, deleted, type, slice_url, lang_file, created_at, created_by, owner, flag) VALUES ('$plinks_test_id', 'Links example', 0, 'Links', 'https://example.org/index.shtml', 'en_links_lang.php3', $now, '', '', 0)");
        $this->query("REPLACE INTO links (id, start_id, tree_start, select_start) VALUES ('$plinks_root_id', 1, 1, 1)");
        $this->query("REPLACE INTO links (id, start_id, tree_start, select_start) VALUES ('$plinks_test_id', 2, 2, 2)");

        $this->query("REPLACE INTO links_categories (id, name, html_template, deleted, path, inc_file1, link_count) VALUES (1, 'Root', '', 'n', '1', '', 0)");
        $this->query("REPLACE INTO links_categories (id, name, html_template, deleted, path, inc_file1, link_count) VALUES (2, 'Example', '', 'n', '1,2', '', 0)");

        $this->query("REPLACE INTO links_cat_cat (category_id, what_id, base, state, proposal, priority, proposal_delete, a_id) VALUES (1, 2, 'y', 'visible', 'n', '5.00', 'n', 1)");

        $this->query("REPLACE INTO links_languages (id, name, short_name) VALUES (100, 'Czech', 'Cz')");
        $this->query("REPLACE INTO links_languages (id, name, short_name) VALUES (200, 'Deutsch', 'De')");
        $this->query("REPLACE INTO links_languages (id, name, short_name) VALUES (300, 'English', 'En')");
        $this->query("REPLACE INTO links_languages (id, name, short_name) VALUES (400, 'French', 'Fr')");
        $this->query("REPLACE INTO links_languages (id, name, short_name) VALUES (500, 'Hungarian', 'Hu')");
        $this->query("REPLACE INTO links_languages (id, name, short_name) VALUES (530, 'Italian', 'It')");
        $this->query("REPLACE INTO links_languages (id, name, short_name) VALUES (550, 'Japan', 'It')");
        $this->query("REPLACE INTO links_languages (id, name, short_name) VALUES (600, 'Portugal', 'Po')");
        $this->query("REPLACE INTO links_languages (id, name, short_name) VALUES (700, 'Slovak', 'Sl')");
        $this->query("REPLACE INTO links_languages (id, name, short_name) VALUES (800, 'Spanish', 'Sp')");
        $this->query("REPLACE INTO links_languages (id, name, short_name) VALUES (900, 'Romanian', 'Ro')");
        $this->query("REPLACE INTO links_languages (id, name, short_name) VALUES (950, 'Russian', 'Ru')");
        $this->query("REPLACE INTO links_languages (id, name, short_name) VALUES (999, 'Other', 'other')");

        $this->query("REPLACE INTO links_regions (id, name, level) VALUES (1000, 'Africa', 1)");
        $this->query("REPLACE INTO links_regions (id, name, level) VALUES (1010, 'Kenya', 2)");
        $this->query("REPLACE INTO links_regions (id, name, level) VALUES (1020, 'Nigeria', 2)");
        $this->query("REPLACE INTO links_regions (id, name, level) VALUES (1030, 'Senegal', 2)");
        $this->query("REPLACE INTO links_regions (id, name, level) VALUES (1040, 'South Africa', 2)");
        $this->query("REPLACE INTO links_regions (id, name, level) VALUES (2000, 'Asia-Pacific', 1)");
        $this->query("REPLACE INTO links_regions (id, name, level) VALUES (2010, 'Australia', 2)");
        $this->query("REPLACE INTO links_regions (id, name, level) VALUES (2020, 'Japan', 2)");
        $this->query("REPLACE INTO links_regions (id, name, level) VALUES (2030, 'Philippines', 2)");
        $this->query("REPLACE INTO links_regions (id, name, level) VALUES (2040, 'South Korea', 2)");
        $this->query("REPLACE INTO links_regions (id, name, level) VALUES (3000, 'Central America', 1)");
        $this->query("REPLACE INTO links_regions (id, name, level) VALUES (3010, 'Nicaragua', 2)");
        $this->query("REPLACE INTO links_regions (id, name, level) VALUES (4000, 'Europe', 1)");
        $this->query("REPLACE INTO links_regions (id, name, level) VALUES (4010, 'Bulgaria', 2)");
        $this->query("REPLACE INTO links_regions (id, name, level) VALUES (4020, 'Czech Republic', 2)");
        $this->query("REPLACE INTO links_regions (id, name, level) VALUES (4030, 'Germany', 2)");
        $this->query("REPLACE INTO links_regions (id, name, level) VALUES (4040, 'Hungary', 2)");
        $this->query("REPLACE INTO links_regions (id, name, level) VALUES (4050, 'Romania', 2)");
        $this->query("REPLACE INTO links_regions (id, name, level) VALUES (4060, 'Slovakia', 2)");
        $this->query("REPLACE INTO links_regions (id, name, level) VALUES (4070, 'Spain', 2)");
        $this->query("REPLACE INTO links_regions (id, name, level) VALUES (4080, 'Ukraine', 2)");
        $this->query("REPLACE INTO links_regions (id, name, level) VALUES (4090, 'United Kingdom', 2)");
        $this->query("REPLACE INTO links_regions (id, name, level) VALUES (5000, 'North America', 1)");
        $this->query("REPLACE INTO links_regions (id, name, level) VALUES (5010, 'Canada', 2)");
        $this->query("REPLACE INTO links_regions (id, name, level) VALUES (5020, 'Mexico', 2)");
        $this->query("REPLACE INTO links_regions (id, name, level) VALUES (5030, 'USA', 2)");
        $this->query("REPLACE INTO links_regions (id, name, level) VALUES (6000, 'South America', 1)");
        $this->query("REPLACE INTO links_regions (id, name, level) VALUES (6010, 'Argentina', 2)");
        $this->query("REPLACE INTO links_regions (id, name, level) VALUES (6020, 'Brasil', 2)");
        $this->query("REPLACE INTO links_regions (id, name, level) VALUES (6030, 'Colombia', 2)");
        $this->query("REPLACE INTO links_regions (id, name, level) VALUES (6040, 'Ecuador', 2)");
        $this->query("REPLACE INTO links_regions (id, name, level) VALUES (6050, 'Uruguay', 2)");

        $this->message(_m('Links Module template recreation - done.'));
        return true;
    }
}

/** Add tables for new polls module */
class AA_Optimize_Add_Polls extends AA_Optimize {

    /** Name function
    * @return string - a name
    */
    public function name(): string {
        return _m("Add Polls tables");
    }

    /** Description function
    * @return string - a description
    */
    public function description(): string {
        return _m("Create tables for new Polls module and adds first - template polls. It removes all current polls!");
    }

    /** implemented actions within this class */
    function actions()      { return ['repair']; }

    /** checks if the this Optimize class belongs to specified type (like "sql_update") */
    function isType($type)  { return in_array($type, ['sql_update']); }

    /** Test function
    * @return string - a message
    */
    public function test() : bool {
        $ret = true;

        $row_count   = GetTable2Array("SELECT count(*) as count FROM module WHERE id='PollsTemplate...'", "aa_first", 'count');
        if ($row_count <> 1) {
            $this->message(_m('Poll module is not installed'));
            $ret = false;
        }
        return $ret;
    }

    /** Deletes the pagecache - the renaming and deleting is much, much quicker,
     *  than easy DELETE FROM ...
     * @return bool
     */
    public function repair() : bool {

        $metabase = AA::Metabase();
        $this->query("DROP TABLE IF EXISTS `polls`");
        $this->query($metabase->getCreateSql('polls'));

        $this->query("DROP TABLE IF EXISTS `polls_answer`");
        $this->query($metabase->getCreateSql('polls_answer'));

        $this->query("DROP TABLE IF EXISTS `polls_design`");
        $this->query($metabase->getCreateSql('polls_design'));

        $this->query("DROP TABLE IF EXISTS `polls_ip_lock`");
        $this->query($metabase->getCreateSql('polls_ip_lock'));

        $this->query("DROP TABLE IF EXISTS `polls_log`");
        $this->query($metabase->getCreateSql('polls_log'));

        $this->query("REPLACE INTO module (id, name, deleted, type, slice_url, lang_file, created_at, created_by, owner, flag) VALUES ('PollsTemplate...', 'Polls Template', 0, 'P', '', 'en_polls_lang.php3', 1043151515, '', 'AA_Core.........', 0)");
        $this->query("REPLACE INTO `polls` (`id`, `module_id`, `status_code`, `headline`, `publish_date`, `expiry_date`, `locked`, `logging`, `ip_locking`, `ip_lock_timeout`, `set_cookies`, `cookies_prefix`, `design_id`, `aftervote_design_id`, `params`) VALUES('506f6c6c73467273744578616d706c65', 'PollsTemplate...', 0, 'Do you like ActionApps', 1194217200, 1500000000, 0, 0, 0, 0, 0, 'AA_POLLS', '506f6c6c7344657369676e4578616d70', '', '')");
        $this->query("REPLACE INTO `polls_answer` (`id`, `poll_id`, `answer`, `votes`, `priority`) VALUES('506f6c6c73416e73776572312e2e2e2e', '506f6c6c73467273744578616d706c65', 'Yes', 0, 1)");
        $this->query("REPLACE INTO `polls_answer` (`id`, `poll_id`, `answer`, `votes`, `priority`) VALUES('506f6c6c73416e73776572322e2e2e2e', '506f6c6c73467273744578616d706c65', 'No', 0, 2)");
        $this->query("REPLACE INTO `polls_answer` (`id`, `poll_id`, `answer`, `votes`, `priority`) VALUES('506f6c6c73416e73776572332e2e2e2e', '506f6c6c73467273744578616d706c65', 'So-so', 0, 3)");
        $this->query("REPLACE INTO `polls_design` (`id`, `module_id`, `name`, `comment`, `top`, `answer`, `bottom`) VALUES('506f6c6c7344657369676e4578616d70', 'PollsTemplate...', 'Click for vote', 'Theme for more than 2 answers, vote for it by clicking on image', '<div id=\"poll__#POLL_ID_\" class=\"aa_poll\">\r\n<table width=100%>\r\n<tr><td colspan=\"2\"><b>_#QUESTION</b></td></tr>', '<tr>\r\n  <td width=40%>_#ANSWER__</td>\r\n  <td><a href=\"?poll_id=_#POLL_ID_&vote_id=_#ANS_ID__\"><div style=\"height:10px; width:{poll_share:500}px;background-color:#f00\">   </div>(_#ANS_PERC %)</a></td>\r\n</tr>', '</table>\r\n</div>')");

        $this->message(_m('Polls module created'));

        return true;
    }
}


/** Recreates the "ActionApps Core" slice fields (delete and insert). The fields
 *  form the "ActionApps Core" slice is used as template fields for other slices
 **/
class AA_Optimize_Redefine_Field_Templates extends AA_Optimize {

    /** Name function
    * @return string - a name
    */
    public function name(): string {
        return _m("Redefine field templates");
    }

    /** Description function
    * @return string - a description
    */
    public function description(): string {
        return _m("Updates field templates (in ActionApps Core slice), which is used when you adding new field to slice");
    }

    /** checks if the this Optimize class belongs to specified type (like "sql_update") */
    function isType($type)  { return in_array($type, ['sql_update']); }


    /** Test function
    * @return bool
    */
    public function test() : bool {
        $ret = true;

        $row_count   = GetTable2Array("SELECT count(*) as count FROM field WHERE id='headline' AND slice_id='AA_Core_Fields..'", "aa_first", 'count');
        if ($row_count <> 1) {
            $this->message(_m('teplate fields are not prenent'));
            $ret = false;
        }

        return $ret;
    }


    /** Main update function
     *  @return bool
     */
    public function repair() : bool {

        $now         = time();
        $AA_IMG_URL  = '/'. AA_BASE_DIR .'images/';
        $AA_DOC_URL  = '/'. AA_BASE_DIR .'doc/';

        $this->message(_m('Deleting all fields form "ActionApps Core" slice'));
        $this->query("DELETE FROM field WHERE slice_id='AA_Core_Fields..'");

        $this->message(_m('Make sure slice owner Action Aplications System exist and reset to defaults'));
        $this->query("REPLACE INTO slice_owner (id, name, email) VALUES ('AA_Core.........', 'Action Aplications System', '".ERROR_REPORTING_EMAIL."')");

        $this->message(_m('Make sure "ActionApps Core" slice exists and reset to defaults'));
        $this->query("REPLACE INTO slice (id, name, owner, deleted, created_by, created_at, export_to_all, type, template, fulltext_format_top, fulltext_format, fulltext_format_bottom, odd_row_format, even_row_format, even_odd_differ, compact_top, compact_bottom, category_top, category_format, category_bottom, category_sort, slice_url, d_listlen, lang_file, fulltext_remove, compact_remove, email_sub_enable, exclude_from_dir, notify_sh_offer, notify_sh_accept, notify_sh_remove, notify_holding_item_s, notify_holding_item_b, notify_holding_item_edit_s, notify_holding_item_edit_b, notify_active_item_edit_s, notify_active_item_edit_b, notify_active_item_s, notify_active_item_b, noitem_msg, admin_format_top, admin_format, admin_format_bottom, admin_remove, permit_anonymous_post, permit_offline_fill, aditional, flag, vid, gb_direction, group_by, gb_header, gb_case, javascript, auth_field_group, mailman_field_lists, reading_password, mlxctrl) VALUES ('AA_Core_Fields..', 'ActionApps Core', 'AA_Core_Fields..', 0, '', $now, 0, 'AA_Core_Fields..', 0, '', '',       '',                     '',             '',              0,               '',          '',             '',           '',              '',              1,             '". AA_HTTP_DOMAIN ."', 10000, 'en-utf8_news_lang.php3.php3', '()', '()', 1, 0, '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', 0, 0, '', 0, 0, NULL, NULL, NULL, NULL,'','','','','')");
        $this->query("REPLACE INTO module (id, name, deleted, type, slice_url, lang_file, created_at, created_by, owner, flag) VALUES ('AA_Core_Fields..', 'ActionApps Core', 0, 'S', '', 'en_news_lang.php3', $now, '', 'AA_Core.........', 0)");

        $this->message(_m('Recreate field definitions'));
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'headline',         '', 'AA_Core_Fields..', 'Headline',            '100', '', '', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'abstract',         '', 'AA_Core_Fields..', 'Abstract',            '189', '', '', 'txt', '0', '0', '0', 'txt:8', '', '100', '', '', '', '', '0', '1', '1', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '1', '', 'text', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'full_text',        '', 'AA_Core_Fields..', 'Fulltext',            '300', '', '', 'txt', '0', '0', '0', 'txt:8', '', '100', '', '', '', '', '0', '1', '1', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '1', '', 'text', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'hl_href',          '', 'AA_Core_Fields..', 'Headline URL',       '1655', 'Link for the headline (for external links)', '', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '', 'f_f:link_only.......', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'url', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'link_only',        '', 'AA_Core_Fields..', 'External item',      '1755', 'Use External link instead of fulltext?', '', 'txt', '0', '0', '0', 'chb', '', '100', '', '', '', '', '0', '0', '1', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'bool', 'boo', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'place',            '', 'AA_Core_Fields..', 'Place',              '2155', 'Item locality', '', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'source',           '', 'AA_Core_Fields..', 'Source',             '1955', 'Source of the item', '', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'source_href',      '', 'AA_Core_Fields..', 'Source URL',         '2055', 'URL of the source', '', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '', 'f_s:javascript: window.alert(\'No source url specified\')', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'url', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'lang_code',        '', 'AA_Core_Fields..', 'Language Code',      '1700', 'Code of used language', '', 'txt:EN', '0', '0', '0', 'sel:lt_languages', '', '100', '', '', '', '', '0', '0', '0', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'cp_code',          '', 'AA_Core_Fields..', 'Code Page',          '1800', 'Language Code Page', '', 'txt:iso8859-1', '0', '0', '0', 'sel:lt_codepages', '', '100', '', '', '', '', '0', '0', '0', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'category',         '', 'AA_Core_Fields..', 'Category',           '1000', '', '', 'txt:', '0', '0', '0', 'sel:lt_apcCategories', '', '100', '', '', '', '', '1', '1', '1', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'img_src',          '', 'AA_Core_Fields..', 'Image URL',          '2055', 'URL of the image', '', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '', 'f_i', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'url', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'img_width',        '', 'AA_Core_Fields..', 'Image width',        '2455', 'Width of image (like: 100, 50%)', '', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '', 'f_w', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'img_height',       '', 'AA_Core_Fields..', 'Image height',       '2555', 'Height of image (like: 100, 50%)', '', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '', 'f_g', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'e_posted_by',      '', 'AA_Core_Fields..', 'Author`s e-mail',    '2255', 'E-mail to author', '', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'email', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'created_by',       '', 'AA_Core_Fields..', 'Created By',         '2355', 'Identification of creator', '', 'txt', '0', '0', '0', 'nul', '', '100', '', '', '', '', '0', '0', '0', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'uid', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'edit_note',        '', 'AA_Core_Fields..', 'Editor`s note',      '2355', 'Here you can write your note (not displayed on the web)', '', 'txt', '0', '0', '0', 'txt', '', '100', '', '', '', '', '0', '0', '0', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'img_upload',       '', 'AA_Core_Fields..', 'Image upload',       '2222', 'Select Image for upload', '', 'txt', '1', '0', '0', 'fil:image/*', '', '100', '', '', '', '', '1', '1', '1', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'fil', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'source_desc',      '', 'AA_Core_Fields..', 'Source description',  '100', '', '', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'source_addr',      '', 'AA_Core_Fields..', 'Source address',      '100', '', '', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'source_city',      '', 'AA_Core_Fields..', 'Source city',         '100', '', '', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'source_prov',      '', 'AA_Core_Fields..', 'Source province',     '100', '', '', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'source_cntry',     '', 'AA_Core_Fields..', 'Source country',      '100', '', '', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'time',             '', 'AA_Core_Fields..', 'Time',                '100', '', '', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '0')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'con_name',         '', 'AA_Core_Fields..', 'Contact name',        '100', '', '', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'con_email',        '', 'AA_Core_Fields..', 'Contact e-mail',      '100', '', '', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'con_phone',        '', 'AA_Core_Fields..', 'Contact phone',       '100', '', '', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'con_fax',          '', 'AA_Core_Fields..', 'Contact fax',         '100', '', '', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'loc_name',         '', 'AA_Core_Fields..', 'Location name',       '100', '', '', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'loc_address',      '', 'AA_Core_Fields..', 'Location address',    '100', '', '', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'loc_city',         '', 'AA_Core_Fields..', 'Location city',       '100', '', '', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'loc_prov',         '', 'AA_Core_Fields..', 'Location province',   '100', '', '', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'loc_cntry',        '', 'AA_Core_Fields..', 'Location country',    '100', '', '', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'start_date',       '', 'AA_Core_Fields..', 'Start date',          '100', '', '', 'now', '1', '0', '0', 'dte:1:10:1', '', '100', '', '', '', '', '1', '1', '1', '', 'f_d:m/d/Y', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'date', 'dte', '1', '0')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'end_date',         '', 'AA_Core_Fields..', 'End date',            '100', '', '', 'now', '1', '0', '0', 'dte:1:10:1', '', '100', '', '', '', '', '1', '1', '1', '', 'f_d:m/d/Y', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'date', 'dte', '1', '0')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'keywords',         '', 'AA_Core_Fields..', 'Keywords',            '100', '', '', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'subtitle',         '', 'AA_Core_Fields..', 'Subtitle',            '100', '', '', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'year',             '', 'AA_Core_Fields..', 'Year',                '100', '', '', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'number',           '', 'AA_Core_Fields..', 'Number',              '100', '', '', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'number', 'num', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'page',             '', 'AA_Core_Fields..', 'Page',                '100', '', '', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'number', 'num', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'price',            '', 'AA_Core_Fields..', 'Price',               '100', '', '', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'number', 'num', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'organization',     '', 'AA_Core_Fields..', 'Organization',        '100', '', '', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'file',             '', 'AA_Core_Fields..', 'File upload',        '2222', 'Select file for upload', '', 'txt', '1', '0', '0', 'fil:*/*', '', '100', '', '', '', '', '1', '1', '1', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'fil', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'text',             '', 'AA_Core_Fields..', 'Text',                '100', '', '', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'unspecified',      '', 'AA_Core_Fields..', 'Unspecified',         '100', '', '', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'url',              '', 'AA_Core_Fields..', 'URL',                '2055', 'Internet URL address', '', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '', 'f_i', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'url', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'switch',           '', 'AA_Core_Fields..', 'Switch',             '2055', '', '', 'txt', '0', '0', '0', 'chb', '', '100', '', '', '', '', '0', '0', '0', '', 'f_i', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'boo', '1', '0')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'password',         '', 'AA_Core_Fields..', 'Password',           '2055', 'Password which user must know if (s)he want to edit item on public site', '', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '', 'f_i', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $this->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'relation',         '', 'AA_Core_Fields..', 'Relation',           '2055', '', '', 'txt:', '0', '0', '1', 'mse:#sLiCe-4e6577735f454e5f746d706c2e2e2e2e:', '', '100', '', '', '', '', '1', '1', '1', '', 'f_v:vid=243&cmd[243]=x-243-_#this', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        // Jakub added auth_group and mail_lists on 6.3.2003
        with (new AA_Optimize_Add_Auth_Group_Field_Template())->repair();
        with (new AA_Optimize_Add_Mail_Lists_Field_Template())->repair();
        // mimo added mlxctrl on 4.10.2004
          //with (new AA_Optimize_Add_Mlxctrl_Field_Template())->repair();   // MLX feature is not maintained, so we do not create the field as default. You can still add it individually
        // mimo added 2005-03-02
        with (new AA_Optimize_Add_Integer_Field_Template())->repair();
        // honzam added 2005-08-15 (based on Philip King and Antonin Slejska suggestions)
        with (new AA_Optimize_Add_Name_Field_Template())->repair();
        with (new AA_Optimize_Add_Phone_Field_Template())->repair();
        with (new AA_Optimize_Add_Fax_Field_Template())->repair();
        with (new AA_Optimize_Add_Address_Field_Template())->repair();
        with (new AA_Optimize_Add_Location_Field_Template())->repair();
        with (new AA_Optimize_Add_City_Field_Template())->repair();
        with (new AA_Optimize_Add_Country_Field_Template())->repair();
        with (new AA_Optimize_Add_Range_Field_Template())->repair();
        with (new AA_Optimize_Add_Real_Field_Template())->repair();
        // honzam added 2005-08-26 - computed fields templates
        with (new AA_Optimize_Add_Computed_Num_Field_Template())->repair();
        with (new AA_Optimize_Add_Computed_Txt_Field_Template())->repair();
        // honzam added 2007-11-21 - _upload_url..... - slice field for setting the name of upload directory
        with (new AA_Optimize_Add__Ip_Banned_Field_Template())->repair();
        with (new AA_Optimize_Add__Msg_Banned_Field_Template())->repair();
        with (new AA_Optimize_Add__Msg_Spam_Field_Template())->repair();
        with (new AA_Optimize_Add__Upload_Url_Field_Template())->repair();
        // honzam added 2008-07-15 - seo field for seo optimalization
        with (new AA_Optimize_Add_Seo_Field_Template())->repair();
        // honzam added 2008-11-20 - hit_1, hit_7, hit_30 - count hits
        with (new AA_Optimize_Add_Hit_1_Field_Template())->repair();
        with (new AA_Optimize_Add_Hit_7_Field_Template())->repair();
        with (new AA_Optimize_Add_Hit_30_Field_Template())->repair();
        // honzam added 2020-09-09 - mainly for 2FA, but also adding most missing fields
        with (new AA_Optimize_Add_Secret_Field_Template())->repair();
        with (new AA_Optimize_Add_Ip_Field_Template())->repair();
        with (new AA_Optimize_Add_Code_Field_Template())->repair();
        with (new AA_Optimize_Add_Date_Field_Template())->repair();


        // UPDATE ALSO AA_Optimize_Add_New_Field_Templates class

        $this->message(_m('Redefine field defaults - done.'));
        return true;
    }
}


/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AA_Optimize_Add_Seo_Field_Template extends AA_Optimize {

    /** Name function
    * @return string - a name
    */
    public function name(): string {
        return _m("Add seo fields template");
    }

    /** Description function
    * @return string - a description
    */
    public function description(): string {
        return _m("Updates field templates (in ActionApps Core slice) - redefine the field template");
    }

    /** the field identifier  */
    protected function fieldType(): string  { return 'seo'; }

    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array  {
        return [];
    }

    /** @return array - whole field definition - merged standard fields and special setting  */
    protected function fieldDefinition(): array {
        $field_standard = ['id'                => $this->fieldType(),
                           'type'              => '',
                           'slice_id'          => '41415f436f72655f4669656c64732e2e',   // AA_Core_Fields..
                           'name'              => mb_strtoupper(mb_substr($this->fieldType(), 0, 1)) . mb_substr($this->fieldType(), 1),
                           'input_pri'         => '100',
                           'input_help'        => '',
                           'input_morehlp'     => '',
                           'input_default'     => 'txt',
                           'required'          => '0',
                           'feed'              => '0',
                           'multiple'          => '0',
                           'input_show_func'   => 'fld',
                           'content_id'        => '',
                           'search_pri'        => '100',
                           'search_type'       => '',
                           'search_help'       => '',
                           'search_before'     => '',
                           'search_more_help'  => '',
                           'search_show'       => '1',
                           'search_ft_show'    => '1',
                           'search_ft_default' => '1',
                           'alias1'            => '',
                           'alias1_func'       => '',
                           'alias1_help'       => '',
                           'alias2'            => '',
                           'alias2_func'       => '',
                           'alias2_help'       => '',
                           'alias3'            => '',
                           'alias3_func'       => '',
                           'alias3_help'       => '',
                           'input_before'      => '',
                           'aditional'         => '',
                           'content_edit'      => '',
                           'html_default'      => '0',
                           'html_show'         => '0',
                           'in_item_tbl'       => '0',
                           'input_validate'    => 'text',
                           'input_insert_func' => 'qte',
                           'input_show'        => '1',
                           'text_stored'       => '1'
        ];
        return array_merge($field_standard, $this->fieldSpecialSetting());
    }
    /** checks if the this Optimize class belongs to specified type (like "sql_update") */
    function isType($type)  { return in_array($type, ['sql_update']); }


    /** Test function
    * @return bool
    */
    public function test() : bool {
        $ret = true;
        if ( !DB_AA::test('field', [['id', $this->fieldType()], ['slice_id', 'AA_Core_Fields..']]) ) {
            $this->message(_m('The "%1" field template missing', [$this->fieldType()]));
            $ret = false;
        } else {
            $this->message(_m('OK - The "%1" field template found', [$this->fieldType()]));
        }
        return $ret;
    }


    /** Main update function
     *  @return bool
     */
    public function repair() : bool {
        $this->message(_m('Recreate "%1" field definition', [$this->fieldType()]));
        AA::Metabase()->doReplace('field', $this->fieldDefinition());
        $this->message(_m('New "%1" field template added', [$this->fieldType()]));
        return true;
    }
}

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AA_Optimize_Add_Auth_Group_Field_Template extends AA_Optimize_Add_Seo_Field_Template {

    public function name(): string { return _m("Add auth_group...... field template"); }

    /** the field identifier  */
    protected function fieldType(): string { return 'auth_group'; }

    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return [
            'name'              => 'Auth Group',
            'input_pri'         => '350',
            'input_help'        => 'Sets permissions for web sections',
            'input_show_func'   => 'sel:',
            'alias1'            => '_#AUTGROUP',
            'alias1_func'       => 'f_h:',
            'alias1_help'       => 'Auth Group (membership type)'
        ];
    }
}

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AA_Optimize_Add_Mail_Lists_Field_Template extends AA_Optimize_Add_Seo_Field_Template {

    public function name(): string             { return _m("Add mail_lists field template"); }
    /** the field identifier  */
    protected function fieldType(): string       { return 'mail_lists'; }
    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return ['name'              => 'Mailing Lists',
                'input_pri'         => '1000',
                'input_help'        => 'Select mailing lists which you read',
                'multiple'          => '1',
                'input_show_func'   => 'mch::3:1',
                'alias1'            => '_#MAILLIST',
                'alias1_func'       => 'f_h:&nbsp;',
                'alias1_help'       => 'Mailing Lists'];
    }
}

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AA_Optimize_Add_Mlxctrl_Field_Template extends AA_Optimize_Add_Seo_Field_Template {

    public function name(): string             { return _m("Add mlxctrl field template"); }
    /** the field identifier  */
    protected function fieldType(): string       { return 'mlxctrl'; }
    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return ['name'              => 'MLX Control',
                'input_pri'         => '6000',
                'input_morehlp'     => 'http://mimo.gn.apc.org/mlx/',
                'required'          => '1',
                'multiple'          => '1',
                'input_show'        => '0'];
    }
}

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AA_Optimize_Add_Integer_Field_Template extends AA_Optimize_Add_Seo_Field_Template {

    public function name(): string             { return _m("Add integer field template"); }
    /** the field identifier  */
    protected function fieldType(): string       { return 'integer'; }
    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return ['html_default'      => '0',
                'input_validate'    => 'number',
                'input_insert_func' => 'num',
                'text_stored'       => '0'];
    }
}

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AA_Optimize_Add_Name_Field_Template extends AA_Optimize_Add_Seo_Field_Template {

    public function name(): string             { return _m("Add name field template"); }
    /** the field identifier  */
    protected function fieldType(): string       { return 'name'; }
    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return [];
    }
}

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AA_Optimize_Add_Phone_Field_Template extends AA_Optimize_Add_Seo_Field_Template {

    public function name(): string             { return _m("Add phone field template"); }
    /** the field identifier  */
    protected function fieldType(): string       { return 'phone'; }
    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return [];
    }
}

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AA_Optimize_Add_Fax_Field_Template extends AA_Optimize_Add_Seo_Field_Template {

    public function name(): string             { return _m("Add fax field template"); }
    /** the field identifier  */
    protected function fieldType(): string       { return 'fax'; }
    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return [];
    }
}

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AA_Optimize_Add_Address_Field_Template extends AA_Optimize_Add_Seo_Field_Template {

    public function name(): string             { return _m("Add address field template"); }
    /** the field identifier  */
    protected function fieldType(): string       { return 'address'; }
    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return [];
    }
}

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AA_Optimize_Add_Location_Field_Template extends AA_Optimize_Add_Seo_Field_Template {

    public function name(): string             { return _m("Add location field template"); }
    /** the field identifier  */
    protected function fieldType(): string       { return 'location'; }
    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return [];
    }
}

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AA_Optimize_Add_City_Field_Template extends AA_Optimize_Add_Seo_Field_Template {

    public function name(): string             { return _m("Add city field template"); }
    /** the field identifier  */
    protected function fieldType(): string       { return 'city'; }
    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return [];
    }
}

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AA_Optimize_Add_Country_Field_Template extends AA_Optimize_Add_Seo_Field_Template {

    public function name(): string             { return _m("Add country field template"); }
    /** the field identifier  */
    protected function fieldType(): string       { return 'country'; }
    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return [];
    }
}

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AA_Optimize_Add_Range_Field_Template extends AA_Optimize_Add_Seo_Field_Template {

    public function name(): string             { return _m("Add range field template"); }
    /** the field identifier  */
    protected function fieldType(): string       { return 'range'; }
    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return [];
    }
}

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AA_Optimize_Add_Real_Field_Template extends AA_Optimize_Add_Seo_Field_Template {

    public function name(): string             { return _m("Add real field template"); }
    /** the field identifier  */
    protected function fieldType(): string       { return 'real'; }
    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return ['name'              => 'Real number'];
    }
}

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AA_Optimize_Add_Computed_Num_Field_Template extends AA_Optimize_Add_Seo_Field_Template {

    public function name(): string             { return _m("Add computed_num field template"); }
    /** the field identifier  */
    protected function fieldType(): string       { return 'computed_num'; }
    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return ['name'              => 'Computed number',
                'input_show_func'   => 'nul',
                'html_default'      => '0',
                'input_validate'    => 'number',
                'input_insert_func' => 'com',
                'text_stored'       => '0'];
    }
}

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AA_Optimize_Add_Computed_Txt_Field_Template extends AA_Optimize_Add_Seo_Field_Template {

    public function name(): string             { return _m("Add computed_txt field template"); }
    /** the field identifier  */
    protected function fieldType(): string       { return 'computed_txt'; }
    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return ['name'              => 'Computed text',
                'input_show_func'   => 'nul'
        ];
    }
}

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AA_Optimize_Add__Upload_Url_Field_Template extends AA_Optimize_Add_Seo_Field_Template {

    public function name(): string             { return _m("Add _upload_url field template"); }
    /** the field identifier  */
    protected function fieldType(): string       { return '_upload_url'; }
    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return ['name'              => 'Upload URL',
                'input_pri'         => '100',
                'input_help'        => 'If you want to have your files stored in your domain, then you can create symbolic link from https://yourdomain.org/upload -> https://your.actionapps.org/IMG_UPLOAD_PATH and fill there "https://yourdomain.org/upload". The url stored in AA will be changed (The file is stored still on the same place).'
        ];
    }
}

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AA_Optimize_Add_Hit_1_Field_Template extends AA_Optimize_Add_Seo_Field_Template {

    public function name(): string             { return _m("Add hit_1 field template"); }
    /** the field identifier  */
    protected function fieldType(): string       { return 'hit_1'; }
    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return ['name'              => 'Hits last day',
                'input_default'     => 'qte',
                'required'          => '1',
                'html_default'      => '0',
                'input_validate'    => 'number',
                'input_insert_func' => 'num',
                'text_stored'       => '0'];
    }
}

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AA_Optimize_Add_Hit_7_Field_Template extends AA_Optimize_Add_Seo_Field_Template {

    public function name(): string             { return _m("Add hit_7 field template"); }
    /** the field identifier  */
    protected function fieldType(): string       { return 'hit_7'; }
    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return ['name'              => 'Hits last week',
                'input_default'     => 'qte',
                'required'          => '1',
                'html_default'      => '0',
                'input_validate'    => 'number',
                'input_insert_func' => 'num',
                'text_stored'       => '0'];
    }
}

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AA_Optimize_Add_Hit_30_Field_Template extends AA_Optimize_Add_Seo_Field_Template {

    public function name(): string             { return _m("Add hit_30 field template"); }
    /** the field identifier  */
    protected function fieldType(): string       { return 'hit_30'; }
    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return ['name'              => 'Hits last month',
                'input_default'     => 'qte',
                'required'          => '1',
                'html_default'      => '0',
                'input_validate'    => 'number',
                'input_insert_func' => 'num',
                'text_stored'       => '0'];
    }
}

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AA_Optimize_Add_Ip_Field_Template extends AA_Optimize_Add_Seo_Field_Template {

    public function name(): string             { return _m("Add ip field template"); }
    /** the field identifier  */
    protected function fieldType(): string       { return 'ip'; }
    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return ['name'              => 'IP address'];
    }
}

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AA_Optimize_Add__Ip_Banned_Field_Template extends AA_Optimize_Add_Seo_Field_Template {

    public function name(): string             { return _m("Add _ip_banned field template"); }
    /** the field identifier  */
    protected function fieldType(): string       { return '_ip_banned'; }
    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return ['name'              => 'Banned IPs slice ID'];
    }
}

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AA_Optimize_Add__Msg_Banned_Field_Template extends AA_Optimize_Add_Seo_Field_Template {

    public function name(): string             { return _m("Add _msg_banned field template"); }
    /** the field identifier  */
    protected function fieldType(): string       { return '_msg_banned'; }
    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return ['name'              => 'Message Banned',
                'input_help'        => 'Message shown to user, when the IP address of the comment sender is on list of banned IP addresses'
        ];
    }
}

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AA_Optimize_Add__Msg_Spam_Field_Template extends AA_Optimize_Add_Seo_Field_Template {

    public function name(): string             { return _m("Add _msg_spam field template"); }
    /** the field identifier  */
    protected function fieldType(): string       { return '_msg_spam'; }
    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return ['name'              => 'Message Spam',
                'input_help'        => 'Message shown to user, when the SPAM detection filter in the comments decides the post is spam'
        ];
    }
}

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AA_Optimize_Add__Url_Preview_Field_Template extends AA_Optimize_Add_Seo_Field_Template {

    public function name(): string             { return _m("Add _url_preview field template"); }
    /** the field identifier  */
    protected function fieldType(): string       { return '_url_preview'; }
    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return ['name'              => 'Preview Url',
                'input_help'        => 'Url for item previw - could contain AA Expressions'
        ];
    }
}

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AA_Optimize_Add_Secret_Field_Template extends AA_Optimize_Add_Seo_Field_Template {

    public function name(): string             { return _m("Add secret field template"); }
    /** the field identifier  */
    protected function fieldType(): string       { return 'secret'; }
    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return ['input_help'        => 'Secret key for 2FA - encrypted, not acessible as normal field',
                'input_pri'         => '2056',
        ];
    }
}

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AA_Optimize_Add_Date_Field_Template extends AA_Optimize_Add_Seo_Field_Template {

    public function name(): string             { return _m("Add date field template"); }
    /** the field identifier  */
    protected function fieldType(): string       { return 'date'; }
    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return ['input_default'     => 'now',
                'input_show_func'   => 'dte::::2',
                'alias1_func'       => 'f_d:m/d/Y',
                'html_default'      => '0',
                'input_validate'    => 'date',
                'input_insert_func' => 'dte',
                'text_stored'       => '0'
        ];
    }
}

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AA_Optimize_Add_Code_Field_Template extends AA_Optimize_Add_Seo_Field_Template {

    public function name(): string             { return _m("Add code field template"); }
    /** the field identifier  */
    protected function fieldType(): string       { return 'code'; }
    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return ['input_show_func'   => 'cod'];
    }
}

/** Restore Data from Backup Tables
 *  This script DELETES all the current tables (slice, item, ...) where we have
 *  bck_table and renames all backup tables (bck_slice, bck_item, ...) to right
 *  names (slice, item, ...).
 **/
class AA_Optimize_Restore_Bck_Tables extends AA_Optimize {

    /** Name function
    * @return string - a name
        */
    public function name(): string {
        return _m("Restore data from backup tables");
    }

    /** Description function
    * @return string - a description
    */
    public function description(): string {
        return _m("[experimental] "). _m("Deletes all the current tables (slice, item, ...) where we have bck_table and renames all backup tables (bck_slice, bck_item, ...) to right names (slice, item, ...).");
    }

    /** checks if the this Optimize class belongs to specified type (like "sql_update") */
    function isType($type)  { return in_array($type, []); }

    /** Test function
    * @return string - a message
    */
    public function test() : bool {
        $ret = true;

        $bck_exists = GetTable2Array("SHOW TABLES LIKE 'bck_content'", "aa_first");
        if (!$bck_exists) {
            $this->message(_m('The bck_ tables do not exist (or at least bck_content table)'));
            $ret = false;
        }
        return $ret;
    }

    /** Main update function
     *  @return bool
     */
    public function repair() : bool {
        $metabase   = AA::Metabase();
        $tablenames = $metabase->getTableNames();

        foreach($tablenames as $tablename) {
            // checks if the backup table exist
            $bck_exists = GetTable2Array("SHOW TABLES LIKE 'bck_$tablename'", "aa_first");
            if ( !$bck_exists ) {
                // we do not have bck table
                $this->message(_m('There is no bck_%1 table - %1 not restored.', [$tablename]));
                continue;
            }
            $this->message(_m('Replace table bck_%1 -> %1', [$tablename]));
            $this->query("DROP TABLE IF EXISTS `$tablename`");
            $this->query("ALTER TABLE `bck_$tablename` RENAME `$tablename");
        }
        return true;
    }
}

/** Restore Data from Backup Tables
 *  This script DELETES all the current tables (slice, item, ...) where we have
 *  bck_table and renames all backup tables (bck_slice, bck_item, ...) to right
 *  names (slice, item, ...).
 **/
class AA_Optimize_Backup_Tables extends AA_Optimize {

    /** Name function
    * @return string - a name
        */
    public function name(): string {
        return _m("Backup tables");
    }

    /** Description function
    * @return string - a description
    */
    public function description(): string {
        return _m("[experimental] "). _m("Deletes all the current backup tables (bck_slice, bck_item, ...) and copies there live tables (slice, item, ...).");
    }

    /** checks if the this Optimize class belongs to specified type (like "sql_update") */
    function isType($type)  { return in_array($type, []); }

    function actions()      { return ['repair']; }

    /** Main update function
     *  @return bool
     */
    public function repair() : bool {
        $metabase   = AA::Metabase();
        $tablenames = $metabase->getTableNames();

        foreach($tablenames as $tablename) {
            if (substr($tablename,0,4) == 'bck_') {
                continue;
            }

            // create backup table
            $this->message(_m('Deleting old backup table bck_%1, if exist.', [$tablename]));
            $this->query("DROP TABLE IF EXISTS `bck_$tablename`");

            $this->message(_m('Creating empty backup table bck_%1.', [$tablename]));
            $this->query("CREATE TABLE `bck_$tablename` LIKE `$tablename`");

            // create new tables that do not exist in database
            // (we need it for next data copy, else it ends up with db error)
            $this->message(_m('Copy data from %1 to bck_%1.', [$tablename]));
            $this->query("INSERT INTO `bck_$tablename` SELECT * FROM `$tablename`");

            $this->message(_m('%1 done.', [$tablename]));

        }
        return true;
    }
}

/** Add mandtory fields to each slice where missing */
class AA_Optimize_Add_Mandatory_Status_Code extends AA_Optimize {

    /** Name function @return a message */
    public function name(): string {
        return _m("Fixing missing %1 field definitions in slices", ['status_code.....']);
    }

    /** Description function @return a message */
    public function description(): string {
        return _m("Adds fields definitions for slices, where missing");
    }

    function _field() {
        return 'status_code.....';
    }

    function _fieldDefinition($sid) {
        return "REPLACE INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'status_code.....', '', '".quote($sid)."', 'Status', '5020', '', '', 'qte:1', '1', '0', '0', 'sel:AA_Core_Bins....', '', '100', '', '', '', '', '0', '0', '0', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', 'status_code', 'number', 'num', '0', '0')";
    }

    /** checks if the this Optimize class belongs to specified type (like "sql_update") */
    function isType($type)  { return in_array($type, ['sql_update']); }

    /** Test function @return a message  */
    public function test() : bool {
        $ret = true;

        $row_count   = GetTable2Array("SELECT count(*) as count FROM slice LEFT JOIN field ON (slice.id=field.slice_id AND field.id='".$this->_field()."') WHERE field.id IS NULL AND slice.id <> 'AA_Core_Fields..'", "aa_first", 'count');
        if ($row_count > 0) {
            $this->message(_m('Missing %1 field definition in some slices - need to repair', [$this->_field()]));
            $ret = false;
        }
        return $ret;
    }

    /** Deletes the pagecache - the renaming and deleting is much, much quicker,
     *  than easy DELETE FROM ...
     * @return bool
     */
    public function repair() : bool {
        $this->message(_m('Fixing missing %1 fields in slices', [$this->_field()]));

        $slices_to_fix = GetTable2Array("SELECT slice.id as sid FROM slice LEFT JOIN field ON (slice.id=field.slice_id AND field.id='".$this->_field()."') WHERE field.id IS NULL", "", 'sid');

        foreach ($slices_to_fix as $sid) {
            if ( $sid == 'AA_Core_Fields..' ) {
                continue;
            }
            $this->query($this->_fieldDefinition($sid));
        }

        // UPDATE ALSO AA_Optimize_Redefine_Field_Templates !!!

        $this->message(_m('Mandatory field %1 added to all slices', [$this->_field()]));
        return true;
    }
}

/** Add mandtory fields to each slice where missing */
class AA_Optimize_Add_Mandatory_Display_Count extends AA_Optimize_Add_Mandatory_Status_Code {
    public function name(): string                 { return _m("Fixing missing %1 field definitions in slices", ['display_count...']); }
    function _field()               { return 'display_count...'; }
    function _fieldDefinition($sid) { return "REPLACE INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'display_count...', '', '". quote($sid) ."', 'Displayed Times', '5050', 'Internal field - do not change', '', 'qte:0', '1', '1', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#DISPL_NO', 'f_h', 'alias for number of displaying of this item', '', '', '', '', '', '', '', '', '0', '0', '0', 'display_count', '', 'nul', '0', '1')"; }
}

/** Add mandtory fields to each slice where missing */
class AA_Optimize_Add_Mandatory_Disc_Count extends AA_Optimize_Add_Mandatory_Status_Code {
    public function name(): string                 { return _m("Fixing missing %1 field definitions in slices", ['disc_count......']); }
    function _field()               { return 'disc_count......';    }
    function _fieldDefinition($sid) { return "REPLACE INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'disc_count......', '', '". quote($sid) ."', 'Comments Count', '5060', 'Internal field - do not change',  '', 'qte:0', '1', '1', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#D_ALLCNT', 'f_h', 'alias for number of all discussion comments for this item', '', '', '', '', '', '', '', '', '0', '0', '0', 'disc_count', '', 'nul', '0', '1')";    }
}

/** Add mandtory fields to each slice where missing */
class AA_Optimize_Add_Mandatory_Disc_App extends AA_Optimize_Add_Mandatory_Status_Code {
    public function name(): string                 { return _m("Fixing missing %1 field definitions in slices", ['disc_app........']); }
    function _field()               { return 'disc_app........';   }
    function _fieldDefinition($sid) { return "REPLACE INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'disc_app........', '', '". quote($sid) ."', 'Approved Comments Count', '5070', 'Internal field - do not change', '', 'qte:0', '1', '1', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#D_APPCNT', 'f_h', 'alias for number of approved discussion comments for this item', '', '', '', '', '', '', '', '', '0', '0', '0', 'disc_app', '', 'nul', '0', '1')";    }
}

/** Add mandtory fields to each slice where missing */
class AA_Optimize_Add_Mandatory_Id extends AA_Optimize_Add_Mandatory_Status_Code {
    public function name(): string                 { return _m("Fixing missing %1 field definitions in slices", ['id..............']); }
    function _field()               { return 'id..............';    }
    function _fieldDefinition($sid) { return "REPLACE INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('id..............', '', '". quote($sid) ."', 'Long ID', 5080, 'Internal field - do not change', '', 'txt:', 0, 0, 0, 'nul', '', 0, '', '', '', '', 1, 1, 1, '_#ITEM_ID_', 'f_n:', 'alias for Long Item ID', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, 'id', '', 'nul', 0, 1)";    }
}

/** Add mandtory fields to each slice where missing */
class AA_Optimize_Add_Mandatory_Short_Id extends AA_Optimize_Add_Mandatory_Status_Code {
    public function name(): string                 { return _m("Fixing missing %1 field definitions in slices", ['short_id........']); }
    function _field()               { return 'short_id........';    }
    function _fieldDefinition($sid) { return "REPLACE INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('short_id........', '', '". quote($sid) ."', 'Short ID', 5090, 'Internal field - do not change', '', 'txt:', 0, 0, 0, 'nul', '', 100, '', '', '', '', 1, 1, 1, '_#SITEM_ID', 'f_t:', 'alias for Short Item ID', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, 'short_id', '', 'nul', 0, 0)";    }
}


/** Generate metabase definition row */
class AA_Optimize_Generate_Metabase extends AA_Optimize {

    /** Name function
    * @return string - a name
    */
    public function name() : string {
        return _m("Generate metabase definition row");
    }

    /** Description function
    * @return string - a description
    */
    public function description(): string {
        return _m("For programmers only - Generate metabace definition row from current database bo be placed in /service/metabase.class.php3 and /include/metabase.class.php3 scripts");
    }

    /** implemented actions within this class */
    function actions()      { return ['repair']; }

    /** Name function
    * @return bool
    */
    public function repair() : bool {
        $metabase  = new AA_Metabase;
        $metabase->loadFromDb();
        echo '$instance = unserialize(\''. str_replace("'", '\\\'', serialize($metabase)) .'\');';
        exit;
    }
}


