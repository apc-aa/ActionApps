<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use AA\Util\LogOnce;
use AA_Log;

/** Object forms AA refused in the last 30 days: a settings or Manager form of a module (site and slice
 *  settings, tasks, forms, exports, aliases) posted an object that is not the one the form was built
 *  for, or not the module's own or of another class (AA\Managed_Object::save()). Also the Manager
 *  actions (delete, move to slice) and table forms (views, feeds, poll designs, the poll edit page)
 *  posting objects or rows of another module - the form class of a table is _T<table>; not the actions
 *  of the pages over all modules, which list objects the user can not change. And the Item Manager's
 *  export to slice posting items of another slice (_Titem) - to be copied out of it, not written.
 *  Logged once a day per module, object and user. Index-only, hence basic.
 */
class CheckObjectFormRefusals extends AbstractOptimize
{
    /** log.type of the refusal rows - selector module:object_id:user, params form_class:stored_class:stored_owner */
    public const EVENT = 'OBJ_DENY';
    /** the object_id of the selector when no object is stored at the posted id - it may be any string */
    public const NO_OBJECT = '-';
    /** the form class of the refused item exports (AA\Manageraction\Item_Feed) */
    public const ITEM_EXPORT = '_Titem';

    private const WINDOW_SECONDS = 30 * 86400;
    private const GROUPS = 50;

    public static function name(): string
    {
        return _m('Refused object forms');
    }

    public static function description(): string
    {
        return _m('Lists the settings and Manager forms and actions refused in the last 30 days because they posted an object or row of another module, of another type or other than the edited one.');
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
            // two modules may share a name
            $owner_name  = isset($module_names[$owner]) ? "{$module_names[$owner]} ($owner)" : $owner;
            $object      = ($object === self::NO_OBJECT) ? _m('(no such object)') : $object;
            $export      = ($class === self::ITEM_EXPORT);
            $findings[] = new Finding(
                Finding::WARNING,
                $export ? _m('The Item Manager of %1 tried to export the item %2 of another slice', [$module_name, $object]) : _m('A form of %1 tried to write the object %2', [$module_name, $object]),
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
                advice: $export ? _m('The export to slice posted an item of another slice (Owner) to copy it out of there; it was not exported. The Item Manager lists only the items of its slice, so unless the item was moved meanwhile, the user edited the request, most likely to read or copy the items of a slice he has no rights to. Find out who the user is.') : _m('The form or action posted an object or row other than the one it was opened for, one that belongs to another module (Owner) or one of another type; it was left untouched. The pages post only the edited objects and those of the listed module, so unless the object was moved or deleted meanwhile (an empty owner - it does not exist), the user edited the request, most likely to change the settings of another site or slice. Find out who the user is.'),
                links: [_m('Log rows') => AA_Log::getAdminUrl(self::EVENT, $selector)],
                key: $selector,
                last: (int) $row['last'],
                kind: $export ? _m('Export of an item of another slice refused') : _m('Write to an object of another module refused'),
                place: (string) ($module_names[$module] ?? ''),     // an unknown module is only an id
            );
        }
        return $findings ? CheckRunner::sort($findings) : [new Finding(Finding::OK, _m('No object form was refused in the last 30 days'))];
    }
}
