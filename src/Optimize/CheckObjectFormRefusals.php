<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use AA\Util\LogOnce;
use AA_Log;

/** Object forms AA refused in the last 30 days: a settings or Manager form of a module (site and slice
 *  settings, tasks, forms, exports, aliases) posted an object that is not the one the form was built
 *  for, or not the module's own or of another class (AA\Managed_Object::save()). Logged once a day per
 *  module, object and user. Index-only, hence basic.
 */
class CheckObjectFormRefusals extends AbstractOptimize
{
    /** log.type of the refusal rows - selector module:object_id:user, params form_class:stored_class:stored_owner */
    public const EVENT = 'OBJ_DENY';
    /** the object_id of the selector when no object is stored at the posted id - it may be any string */
    public const NO_OBJECT = '-';

    private const WINDOW_SECONDS = 30 * 86400;
    private const GROUPS = 50;

    public static function name(): string
    {
        return _m('Refused object forms');
    }

    public static function description(): string
    {
        return _m('Lists the settings and Manager forms refused in the last 30 days because they posted an object of another module, of another type or other than the edited one.');
    }

    public static function types(): array
    {
        return [self::CHECK_BASIC];
    }

    public static function category(): string
    {
        return 'security';
    }

    public static function actions(): array
    {
        return ['test'];
    }

    public function findings(): array
    {
        $rows    = LogOnce::recent(self::EVENT, now() - self::WINDOW_SECONDS, self::GROUPS);
        $modules = [];
        foreach ($rows as $row) {
            $ids = [ParamExplode((string) $row['selector'])[0] ?? '', ParamExplode((string) $row['params'])[2] ?? ''];
            foreach ($ids as $id) {
                if (is_long_id($id)) {
                    $modules[$id] = $id;
                }
            }
        }
        $names = $modules ? DB_AA::select(['unpackid' => 'name'], 'SELECT LOWER(HEX(`id`)) AS unpackid, `name` FROM `module`', [['id', array_values($modules), 'l']]) : [];
        return self::findingsFor($rows, $names);
    }

    /**
     * @param list<array<string, ?string>> $rows          LogOnce::recent() rows
     * @param array<string, string>        $module_names  module id => name
     * @return Finding[]
     */
    public static function findingsFor(array $rows, array $module_names): array
    {
        $findings = [];
        foreach ($rows as $row) {
            $selector = (string) $row['selector'];
            [$module, $object, $user] = array_pad(ParamExplode($selector), 3, '');
            [$class, $stored, $owner] = array_pad(ParamExplode((string) $row['params']), 3, '');
            $module_name = (string) ($module_names[$module] ?? '') ?: $module;
            $owner_name  = (string) ($module_names[$owner] ?? '') ?: $owner;
            $object      = ($object === self::NO_OBJECT) ? _m('(no such object)') : $object;
            $findings[] = new Finding(
                Finding::WARNING,
                _m('A form of %1 tried to write the object %2', [$module_name, $object]),
                count: (int) $row['days'],
                since: (int) $row['first'],
                evidence: [[
                    _m('Module')      => safe($module_name),
                    _m('Object')      => safe($object),
                    _m('Form type')   => safe($class),
                    _m('Stored type') => safe($stored),
                    _m('Owner')       => safe($owner_name),
                    _m('User')        => safe($user),
                    _m('Days seen')   => (string) (int) $row['days'],
                    _m('Last')        => date('Y-m-d', (int) $row['last']),
                ]],
                advice: _m('The form posted an object other than the one it was opened for, one that belongs to another module (Owner) or one of another type - nothing was saved. The pages post only the edited object, so unless the object was moved or deleted meanwhile (an empty owner - it does not exist), the user edited the request, most likely to change the settings of another site or slice. Find out who the user is.'),
                links: [_m('Log rows') => AA_Log::getAdminUrl(self::EVENT, $selector)],
                key: $selector,
                last: (int) $row['last'],
            );
        }
        return $findings ? CheckRunner::sort($findings) : [new Finding(Finding::OK, _m('No object form was refused in the last 30 days'))];
    }
}
