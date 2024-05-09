<?php

namespace AA\Optimize;

use AA;
use AA\IO\DB\DB_AA;
use function _m;
use function unpack_id;

/** Recreates the default Links module template **/
class RedefineLinksTemplates extends \AA\Optimize\AbstractOptimize {

    /**  {@inheritdoc} Implementation of \AA\Util\NamedInterface */
    public static function name(): string {
        return _m("Recreate Links module template");
    }

    /**  {@inheritdoc} Implementation of \AA\Util\NamedInterface */
    public static function description(): string {
        return _m("Deletes and recreates the default settings for Links module");
    }

    /** checks if this Optimize class belongs to specified type (like "sql_update") */
    public static function types(): array { return ['sql_update']; }


    /** Test function @return bool */
    public function test(): bool {
        $ret = true;

        $row_count = DB_AA::select1('count', 'SELECT count(*) as count FROM module', [['id', '1Links%', 'LIKE']]);
        if ($row_count <> 1) {
            $this->message(_m('There is no root Link Module category - Links module should be recreted'));
            $ret = false;
        }
        return $ret;
    }


    /** Main update function @return bool */
    public function repair(): bool {

        $now = time();
        $this->message(_m('Replacing module definition'));
        $plinks_root_id = substr('1Links' . q_pack_id(AA_ID), 0, 16); // the same as q_pack_id(Links_Category2SliceID(1)); see /modules/links/util.php
        $plinks_test_id = substr('2Links' . q_pack_id(AA_ID), 0, 16); // the same as q_pack_id(Links_Category2SliceID(2)); see /modules/links/util.php
        $this->query("REPLACE INTO module (id, name, deleted, type, slice_url, lang_file, created_at, created_by, owner, flag) VALUES ('$plinks_root_id', 'Links root', 0, 'Links', 'https://example.org/index.shtml', 'en_links_lang.php', $now, '', '', 0)");
        $this->query("REPLACE INTO module (id, name, deleted, type, slice_url, lang_file, created_at, created_by, owner, flag) VALUES ('$plinks_test_id', 'Links example', 0, 'Links', 'https://example.org/index.shtml', 'en_links_lang.php', $now, '', '', 0)");


        $std_row = AA::Metabase()->getEmptyRowArray('links');

        $this->message(_m('Replacing the e-mail templates'));
        $row = [
            'id'           => unpack_id($plinks_root_id),
            'start_id'     => 1,
            'tree_start'   => 1,
            'select_start' => 1,
        ];
        AA::Metabase()->doReplace('links', array_merge($std_row, $row));
        $row = [
            'id'           => unpack_id($plinks_test_id),
            'start_id'     => 2,
            'tree_start'   => 2,
            'select_start' => 2,
        ];
        AA::Metabase()->doReplace('links', array_merge($std_row, $row));

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