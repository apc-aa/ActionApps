<?php

declare(strict_types=1);

/**
 *
 * PHP version 8.2+
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
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
 */

namespace AA\Util;

use AA_Components;
use AA_Property;
use AA_Validate;

/**
 * Class Paramwizard
 * @package AA\Util
 */
class Paramwizard
{
    /** Types of one list for <aa-params>: type code => ['desc' => html, 'params' => [...]] in the order
     *  the stored string has them, plus the list's 'hint' when it has one.
     *  @return array{items: array<string, array>, hint?: string}
     */
    public static function definitions(string $list): array
    {
        $mask = match ($list) {
            'INPUT_TYPES'         => 'AA\Widget\Widget',
            'DEFAULT_VALUE_TYPES' => 'AA_Generator_',
            'INSERT_TYPES'        => 'AA_Inserter_',
            default               => null,
        };
        if ($mask !== null) {
            // only the types the select offers (a class with a name, as AA_Components::getClassArray())
            $items = self::fromClasses(AA_Components::getClassNames($mask, static fn($class) => is_callable([$class, 'name']) && trim((string) $class::name()) !== ''));
            if ($list === 'INPUT_TYPES' && isset($items['dte'])) {
                $items['dte']['quote'] = true;   // AA_Field::widgetParams() reads ' as : in old date fields
            }
            return ['items' => $items];
        }
        $wizard = $GLOBALS[$list] ?? [];
        $items  = self::fromArray($wizard);
        if ($list === 'VALIDATE_TYPES') {
            // the validators read the string by their own properties - the array lists some of them, in another order
            foreach ($items as $code => $item) {
                if (class_exists($class = AA_Validate::constructClassName((string) $code, 'AA_Validate_'))) {
                    $items[$code]['params'] = self::classParams($class);
                }
            }
        }
        $ret = ['items' => $items];
        if (isset($wizard['hint'])) {
            $ret['hint'] = (string) $wizard['hint'];
        }
        return $ret;
    }

    /** @param array<string, class-string> $classes type code => class
     *  @return array<string, array> items
     */
    public static function fromClasses(array $classes): array
    {
        $items = [];
        foreach ($classes as $code => $class) {
            $items[(string) $code] = ['desc' => (string) $class::description(), 'params' => self::classParams($class)];
        }
        return $items;
    }

    /** @param class-string $class with getClassProperties()
     *  @return array<int, array> parameters in the order the stored string has them
     */
    private static function classParams(string $class): array
    {
        $params = [];
        /** @var AA_Property $prop */
        foreach ($class::getClassProperties() as $id => $prop) {
            // the form takes plain values, the examples are written as in the stored string
            $param   = ['id' => (string) $id, 'name' => (string) $prop->getName(), 'type' => '', 'help' => $prop->getHelp(), 'example' => str_replace('#:', ':', $prop->getExample())];
            $options = $prop->getConstants();
            if ($prop->isMulti()) {
                $param['type'] = 'text';
            } elseif (is_array($options) && $options) {
                $param['type']    = 'enum';
                $param['options'] = array_map('strval', $options);
            } else {
                $param['type'] = match ($prop->getType()) {
                    'int', 'bool', 'text' => $prop->getType(),
                    default               => 'string',
                };
            }
            $params[] = $param;
        }
        return $params;
    }

    /** @param array $wizard an array as in include/constants_param_wizard.php
     *  @return array<string, array> items
     */
    public static function fromArray(array $wizard): array
    {
        // the arrays write HTML meant to be shown as text as \<BR\>
        $unescape = static fn($text): string => str_replace(['\<', '\>'], ['&lt;', '&gt;'], (string) $text);
        $items    = [];
        foreach ($wizard['items'] ?? [] as $code => $item) {
            $params = [];
            foreach ($item['params'] ?? [] as $param) {
                $params[] = [
                    'id'      => (string) ($param['name'] ?? ''),
                    'name'    => (string) ($param['name'] ?? ''),
                    'type'    => match (strtoupper((string) ($param['type'] ?? ''))) {
                        'INT'   => 'int',
                        'BOOL'  => 'bool',
                        default => 'string',
                    },
                    'help'    => $unescape($param['desc'] ?? ''),
                    'example' => (string) ($param['example'] ?? ''),
                ];
            }
            $items[(string) $code] = ['desc' => $unescape($item['desc'] ?? ''), 'params' => $params];
            if (isset($item['examples'])) {
                $items[(string) $code]['examples'] = $item['examples'];
            }
        }
        return $items;
    }

    /** @return array<string, string> texts of <aa-params> */
    public static function labels(): array
    {
        return [
            'params'        => _m('Parameters'),
            'other'         => _m('Other parameters (the type does not describe them)'),
            'eg'            => _m('e.g. %1'),
            'default'       => _m('(default)'),
            'more'          => _m('more'),
            'less'          => _m('less'),
            'as_text'       => _m('As text'),
            'copy'          => _m('Copy to clipboard'),
            'copied'        => _m('Copied to clipboard'),
            'text_note'     => _m('The whole setting: type and parameters. Paste a text copied from another field and the form follows it.'),
            'no_type'       => _m('The text does not start with a type offered here, taken as parameters of %1.'),
            'unknown_const' => _m('Constant group %1 is not known here, the current one stays.'),
            'help'          => _m('Help'),
            'examples'      => _m('Examples'),
        ];
    }

    /** JS statement setting window.AA_PARAMS (without <script>); safe inside a script element
     *  @param string[] $lists
     */
    public static function script(array $lists): string
    {
        $data = ['labels' => self::labels(), 'lists' => []];
        foreach ($lists as $list) {
            $data['lists'][$list] = self::definitions($list);
        }
        return 'window.AA_PARAMS = ' . json_encode($data, ScriptTag::JS_JSON | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . ';';
    }
}
