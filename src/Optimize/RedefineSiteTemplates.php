<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA;
use AA\IO\DB\DB_AA;
use AA_Module_Site;

/** Recreates the default site module template when it is missing; one that names a control file AA does not
 *  have (old installs: "template.php3") only gets the router home, the rest of it stays */
class RedefineSiteTemplates extends \AA\Optimize\AbstractOptimize
{
    /**  {@inheritdoc} Implementation of \AA\Util\NamedInterface */
    public static function name(): string
    {
        return _m("Recreate Site module template");
    }

    /**  {@inheritdoc} Implementation of \AA\Util\NamedInterface */
    public static function description(): string
    {
        return _m("Deletes and recreates the default template for Site module");
    }

    /** checks if this Optimize class belongs to specified type (like "sql_update") */
    public static function types(): array
    {
        return ['sql_update'];
    }


    /** the router home a new site starts with */
    private const HOME = '/en/home';

    /** Test function @return bool */
    public function test(): bool
    {
        return match (self::problem()) {
            'missing' => $this->failed(_m('Site Module template should be recreated')),
            'stale'   => $this->failed(_m('The Site Module template names a control file that does not exist, and the form of a new site offers it - it should use the router home %1', [self::HOME])),
            default   => true,
        };
    }

    private function failed(string $message): bool
    {
        $this->message($message);
        return false;
    }

    /** 'missing' (no module row), 'stale' (the template names a control file that is not there) or '' -
     *  a template switched off (deleted) is left as it is, as State Check leaves it */
    private static function problem(): string
    {
        $module = DB_AA::select1('', 'SELECT `deleted` FROM `module`', [['id', AA_Module_Site::TEMPLATE_ID]]);
        if (!is_array($module)) {
            return 'missing';
        }
        if ((int) ($module['deleted'] ?? 0) !== 0) {
            return '';
        }
        $site = DB_AA::select1('', 'SELECT `flag`, `state_file` FROM `site`', [['id', AA_Module_Site::TEMPLATE_ID]]);
        return (is_array($site) && self::staleControlFile($site)) ? 'stale' : '';
    }

    /** The template's site row is of the legacy kind (flag <> 1) and its control file is not there - old
     *  installs keep "template.php3", which AA never had. A new site copies both into its form.
     *  @param array<string, mixed> $site flag and state_file of the row */
    private static function staleControlFile(array $site): bool
    {
        if ((int) ($site['flag'] ?? 0) === 1) {
            return false;
        }
        $name = AA_Module_Site::stateFileName((string) ($site['state_file'] ?? ''));
        $path = AA_Module_Site::stateFilePath($name);
        return ($name !== '') && !(is_file($path) && is_readable($path));
    }

    /** Main update function @return bool */
    public function repair(): bool
    {
        // the template of a live install: its name, language, URL and spots stay, only the control file goes
        if (self::problem() === 'stale') {
            AA::Metabase()->doUpdate('site', ['id' => unpack_id(AA_Module_Site::TEMPLATE_ID), 'state_file' => self::HOME, 'flag' => 1]);
            $this->message(_m('The Site Module template now uses the router home %1', [self::HOME]));
            return true;
        }
        $now = time();
        $this->message(_m('Replacing module definition'));
        $this->transaction(function () use ($now) {
            $this->query("REPLACE INTO module  (id, name, deleted, type, slice_url, lang_file, created_at, created_by, owner, flag) VALUES ('" . AA_Module_Site::TEMPLATE_ID . "', 'Site Template', 0, 'W', 'https://example.org', 'en-utf8_site_lang.php', $now, '', '', 1)");
            $this->query("REPLACE INTO site    (id, state_file, structure, flag) VALUES ('" . AA_Module_Site::TEMPLATE_ID . "', '" . self::HOME . "', '" . 'O:8:"sitetree":2:{s:4:"tree";a:1:{i:1;O:4:"spot":8:{s:2:"id";s:1:"1";s:1:"n";s:5:"start";s:1:"c";N;s:1:"v";N;s:1:"p";s:1:"1";s:2:"po";a:1:{i:0;s:1:"1";}s:2:"ch";N;s:1:"f";i:0;}}s:8:"start_id";s:1:"1";}' . "', 0)");
        });

        $this->message(_m('Site Module template recreation - done.'));
        return true;
    }
}
