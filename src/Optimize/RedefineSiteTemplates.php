<?php

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use function _m;

/** Recreates the default site module template **/
class RedefineSiteTemplates extends \AA\Optimize\AbstractOptimize {

    /**  {@inheritdoc} Implementation of \AA\Util\NamedInterface */
    public static function name(): string {
        return _m("Recreate Site module template");
    }

    /**  {@inheritdoc} Implementation of \AA\Util\NamedInterface */
    public static function description(): string {
        return _m("Deletes and recreates the default template for Site module");
    }

    /** checks if this Optimize class belongs to specified type (like "sql_update") */
    public static function types(): array { return ['sql_update']; }


    /** Test function @return bool */
    public function test(): bool {
        $ret = true;

        $row_count = DB_AA::select1('count', 'SELECT count(*) as count FROM module', [['id', 'SiteTemplate....']]);
        if ($row_count <> 1) {
            $this->message(_m('Site Module template should be recreated'));
            $ret = false;
        }
        return $ret;
    }

    /** Main update function @return bool */
    public function repair(): bool {

        $now = time();
        $this->message(_m('Replacing module definition'));
        $this->query("REPLACE INTO module  (id, name, deleted, type, slice_url, lang_file, created_at, created_by, owner, flag) VALUES ('SiteTemplate....', 'Site Template', 0, 'W', 'https://example.org', 'en-utf8_site_lang.php', $now, '', '', 1)");
        $this->query("REPLACE INTO site    (id, state_file, structure, flag) VALUES ('SiteTemplate....', '/en/home', '" . 'O:8:"sitetree":2:{s:4:"tree";a:1:{i:1;O:4:"spot":8:{s:2:"id";s:1:"1";s:1:"n";s:5:"start";s:1:"c";N;s:1:"v";N;s:1:"p";s:1:"1";s:2:"po";a:1:{i:0;s:1:"1";}s:2:"ch";N;s:1:"f";i:0;}}s:8:"start_id";s:1:"1";}' . "', 0)");

        $this->message(_m('Site Module template recreation - done.'));
        return true;
    }
}