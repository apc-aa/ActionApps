<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\Module;
use AA_Generator;
use Throwable;

/** The values an install gives the settings fields (id `_...`) of a slice it creates, or adds to an existing
 *  slice: their input_default, evaluated as the item form evaluates the default of a new item
 *  (AA_Field::getDefault(), no profile). A value the slice has stays.
 *  An install carries no values, so without this every setting of a new app starts empty.
 *
 *  Only defaults that give the same value whoever installs: a user id or login would be the installer's,
 *  a random code or an AA expression may read the request or other data. Those, and a field whose insert
 *  function changes the value (a password stores its hash), are I SKIPPED - left to fill by hand. */
final class SettingDefaults
{
    /** I: a settings field's input_default is not used - the field is left to fill; ref = the input_default */
    public const SKIPPED = 'setting-default-skipped';

    /** AA_Generator classes of input_default (their shortcut) that depend on no user and no request */
    private const SAFE = ['txt', 'qte', 'now', 'never', 'dte', 'mul'];
    /** input_insert_func that store the value as it is; boo only turns it into 0 or 1 */
    private const PLAIN_INSERT = ['', 'qte', 'dte', 'num', 'boo'];

    /** @param list<string> $valued fields that have a value already - it stays
     *  @return array<string, array<int, array{value: string, flag: int}>> field id => values, keyed as
     *          Module::$transfer['setting-values'] - fields with no value left out */
    public static function of(Module $m, Schema $schema, FindingList $findings, array $valued = []): array
    {
        $values = [];
        foreach ($m->rows['field'] ?? [] as $i => $row) {
            $id = ValueCodec::bytes($row['id'] ?? '');
            if (!str_starts_with($id, '_') || in_array($id, $valued, true)) {
                continue;
            }
            $column  = static fn(string $c): string => ValueCodec::bytes(array_key_exists($c, $row) ? $row[$c] : $schema->columnDefault('field', $c));
            $default = $column('input_default');
            $insert  = strtolower(explode(':', $column('input_insert_func'), 2)[0]);
            if (trim($default) === '') {
                continue;
            }
            $generated = in_array($insert, self::PLAIN_INSERT, true) ? self::generate($default) : null;
            if ($generated === null) {
                $findings->add($schema->rowPath("$m->type[$m->id]", 'field', $row, $i) . '/input_default', new LintFinding(LintFinding::INFO, self::SKIPPED, substr($default, 0, ReferenceLint::MAX_REF), 0));
                continue;
            }
            $flag = ((int) $column('html_default') > 0 ? FLAG_HTML : 0) | ((int) $column('text_stored') !== 0 ? FLAG_TEXT_STORED : 0);
            foreach ($generated as $key => $value) {
                $values[$id][$key] = ['value' => ($insert === 'boo') ? ($value ? '1' : '0') : $value, 'flag' => $flag];
            }
        }
        return $values;
    }

    /** the values of a safe default ('' left out); null = not a safe one @return array<int, string>|null */
    private static function generate(string $default): ?array
    {
        if (!in_array(strtolower(explode(':', $default, 2)[0]), self::SAFE, true)) {
            return null;
        }
        try {
            $generator = AA_Generator::factoryByString($default);
        } catch (Throwable) {
            return null;
        }
        return $generator ? array_filter(array_map('strval', $generator->generate()->getValues()), static fn(string $v): bool => $v !== '') : null;
    }
}
