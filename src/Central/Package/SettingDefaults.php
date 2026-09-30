<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\Module;
use AA_Generator;
use AA_Validate;
use Throwable;

/** The values an install gives the settings fields (id `_...`) of a slice it creates, or adds to an existing
 *  slice: those the package carries (a superadmin's export, the values typed in the preview), else their
 *  input_default, evaluated as the item form evaluates the default of a new item (AA_Field::getDefault(),
 *  no profile). A value the slice has stays. Without the defaults every setting of a new app starts empty.
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
    /** input_validate types (AA_Validate::factoryCached()) that judge the value alone - unique, login, password
     *  need the item, a user or the DB; packedid, markedid depend on the widget */
    private const PLAIN_VALIDATE = ['text', 'string', 'field', 'all', 'email', 'e-mail', 'url', 'number', 'num', 'int', 'integer', 'short_id', 'float', 'decimal',
        'bool', 'date', 'regexp', 'alpha', 'alias', 'filename', 'id', 'long_id', 'enum'];

    /** The values an install writes: those the module carries (Module::$transfer['setting-values']), the
     *  defaults (of()) of the other fields
     *  @param list<string> $valued fields that have a value already - it stays
     *  @return array<string, array<int, array{value: string, flag: int}>> */
    public static function values(Module $m, Schema $schema, FindingList $findings, array $valued = []): array
    {
        $carried = array_map(static fn(array $list): array => array_map(static fn(array $v): array => ['value' => ValueCodec::bytes($v['value'] ?? ''), 'flag' => (int) ($v['flag'] ?? 0) & (FLAG_HTML | FLAG_TEXT_STORED)], $list), $m->transfer['setting-values'] ?? []);
        return $carried + self::of($m, $schema, $findings, [...$valued, ...array_keys($carried)]);
    }

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
            $default = self::column($row, 'input_default', $schema);
            $insert  = self::insert($row, $schema);
            if (trim($default) === '') {
                continue;
            }
            $generated = self::plainInsert($insert) ? self::generate($default) : null;
            if ($generated === null) {
                $findings->add($schema->rowPath("$m->type[$m->id]", 'field', $row, $i) . '/input_default', new LintFinding(LintFinding::INFO, self::SKIPPED, substr($default, 0, ReferenceLint::MAX_REF), 0));
                continue;
            }
            $flag = self::flag($row, $schema);
            foreach ($generated as $key => $value) {
                $values[$id][$key] = ['value' => self::stored($insert, $value), 'flag' => $flag];
            }
        }
        return $values;
    }

    /** FLAG_HTML | FLAG_TEXT_STORED of the values of a field row - how the item form stores them
     *  @param array<string, mixed> $row */
    public static function flag(array $row, Schema $schema): int
    {
        return ((int) self::column($row, 'html_default', $schema) > 0 ? FLAG_HTML : 0) | ((int) self::column($row, 'text_stored', $schema) !== 0 ? FLAG_TEXT_STORED : 0);
    }

    /** the shortcut of the field's input_insert_func, lower case @param array<string, mixed> $row */
    public static function insert(array $row, Schema $schema): string
    {
        return strtolower(explode(':', self::column($row, 'input_insert_func', $schema), 2)[0]);
    }

    /** the insert function (insert()) stores a value as it is, or only as 0 / 1 */
    public static function plainInsert(string $insert): bool
    {
        return in_array($insert, self::PLAIN_INSERT, true);
    }

    /** a value as a plain insert function stores it: boo as AA_Inserter_Boo, 0 or 1 */
    public static function stored(string $insert, string $value): string
    {
        return ($insert === 'boo') ? ($value ? '1' : '0') : $value;
    }

    /** $value as the field's input_validate leaves it (as in the item form, it may normalize it); null = it
     *  refuses it. A validator that needs more than the value is not asked. @param array<string, mixed> $row */
    public static function checked(array $row, Schema $schema, string $value): ?string
    {
        $validate = self::column($row, 'input_validate', $schema);
        if (!in_array(strtolower(explode(':', $validate, 2)[0]), self::PLAIN_VALIDATE, true)) {
            return $value;
        }
        try {
            $validator = AA_Validate::factoryByString($validate);
        } catch (Throwable) {
            return $value;
        }
        // validate() may turn the value into an int (the number, bool, date checks)
        return (!($validator instanceof AA_Validate) || $validator->validate($value)) ? (string) $value : null;
    }

    /** a settings field that may hold a password or a key, by its id - the preview and the findings show
     *  none of its value. A short word only where a part of the id ends: `_smtppass`, `_pass2`, not `_keywords`,
     *  `_passage`; a harmless id masked by it (`_compass`) only hides its value */
    public static function secret(string $field): bool
    {
        return (bool) preg_match('/password|passwd|passphrase|secret|token|apikey|api_key|credential|bearer|jwt|heslo|pwd|(?:pass|key|api|auth|salt|private|klic|tajne?)(?=[_.\d]|\z)/i', $field);
    }

    /** @param array<string, mixed> $row */
    private static function column(array $row, string $column, Schema $schema): string
    {
        return ValueCodec::bytes(array_key_exists($column, $row) ? $row[$column] : $schema->columnDefault('field', $column));
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
