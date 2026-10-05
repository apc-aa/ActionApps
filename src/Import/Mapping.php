<?php

declare(strict_types=1);

namespace AA\Import;

use AA;
use AA\IO\SourceRefused;
use AA_Field;
use AA_Slice;
use AA_Transformation;
use AA_Transformation_AddValue;
use AA_Transformation_CopyField;
use AA_Transformation_Exactvalues;
use AA_Transformation_ParseMulti;
use AA_Transformation_Replace;
use AA_Transformation_Setflag;
use AA_Transformation_Value;
use JsonException;

/**
 * How an item of a source becomes an item of the target slice: rows "target <- transformation"
 * in the order the Saver runs them, plus the key, store mode, validation and the expression after
 * a stream - all the definition takes from Source::defaultMapping().
 *
 * A row's target is a field of the slice, or "param:<name>": the field is then the value of that
 * job parameter (one row serves every indicator of NSZM). That value comes from the data of the
 * source, so it may only name an ordinary field - never one the engine keeps in the item table.
 * No target may be a password, secret or unreadable field.
 *
 * Built from stored JSON and from code alike, so the constructor checks the shape; an invalid
 * mapping is SourceRefused('DEFINITION') - the run stops instead of failing on every item.
 */
final class Mapping
{
    /**
     * Without "overwrite" - it drops the fields the import does not fill - and "insert_if_new": it has
     * no key, so a repeated run counts every stored item as an error and a source without item ids
     * stores only its first item ("insert" with key fields stores only what is new).
     */
    public const STORE_MODES = ['update/insert', 'update', 'insert'];

    private const PARAM          = 'param:';
    private const PARAM_NAME     = '/^[a-z][a-z0-9_]{0,31}\z/';
    private const TRANSFORMATION = '/^[A-Za-z][A-Za-z0-9]{0,63}\z/';
    private const MAX_JSON       = 65536;
    private const MAX_ROWS       = 200;

    /**
     * What a row may name, with the parameters the constructor reads without a default.
     * Left out: translate, regexpreplace (evaluate source data as AA expressions), recompute (returns no value).
     * @var array<string, array{class-string<AA_Transformation>, list<string>}>
     */
    private const TRANSFORMATIONS = [
        'value'       => [AA_Transformation_Value::class, ['new_flag', 'new_content']],
        'exactvalues' => [AA_Transformation_Exactvalues::class, ['new_content']],
        'addvalue'    => [AA_Transformation_AddValue::class, ['new_content']],
        'parsemulti'  => [AA_Transformation_ParseMulti::class, ['new_flag', 'source', 'delimiter']],
        'setflag'     => [AA_Transformation_Setflag::class, ['new_flag']],
        'replace'     => [AA_Transformation_Replace::class, ['searchpattern', 'replacestring']],
        'copyfield'   => [AA_Transformation_CopyField::class, ['field2copy']],
    ];

    /**
     * @param list<array{to:string, t:string, p:array<string, string>}> $rows
     * @param list<string> $keyFields
     * @param list<string> $validate
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $keyFields = [],
        public readonly string $storeMode = 'update/insert',
        public readonly array $validate = [],
        public readonly string $afterStream = '',
    ) {
        self::checkRows($rows);
        self::checkLists($keyFields, $validate);
        if (!in_array($storeMode, self::STORE_MODES, true)) {
            throw self::refused('unknown store mode');
        }
    }

    /** only the rows, as the definition stores them in its property "mapping" */
    public static function fromJson(string $json): self
    {
        if (strlen($json) > self::MAX_JSON) {
            throw self::refused('too long');
        }
        try {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw self::refused('not valid JSON');
        }
        if (!is_array($data) || (array_keys($data) !== ['rows']) || !is_array($data['rows']) || !array_is_list($data['rows'])) {
            throw self::refused('expected {"rows":[...]}');
        }
        $rows = [];
        foreach ($data['rows'] as $row) {
            if (!is_array($row) || !is_string($row['to'] ?? null) || !is_string($row['t'] ?? null) || array_diff(array_keys($row), ['to', 't', 'p'])) {
                throw self::refused('a row needs "to" and "t" texts and may have "p"');
            }
            $p = $row['p'] ?? [];
            if (!is_array($p) || (count(array_filter($p, is_string(...))) !== count($p))) {
                throw self::refused('"p" must be an object of texts');
            }
            $rows[] = ['to' => $row['to'], 't' => $row['t'], 'p' => $p];
        }
        return new self($rows);
    }

    public function toJson(): string
    {
        // empty parameters as {} - the stored JSON keeps one shape; ASCII, as object_text is cp1250
        // or utf8mb3 on many installs, where a 4-byte character would break the JSON
        $rows = array_map(static fn(array $row): array => ['to' => $row['to'], 't' => $row['t'], 'p' => (object) $row['p']], $this->rows);
        return json_encode(['rows' => $rows], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * What is wrong whatever the job: a transformation imports may not use or one missing a
     * parameter, a fixed target that is not a field of the slice, is protected or is filled twice,
     * no key, a key field no fixed row fills (a field reached only through a parameter cannot be the
     * key - it changes from job to job).
     * @return list<string> codes, each once: NO_SLICE, UNKNOWN_TRANSFORMATION, BAD_PARAMS,
     *                      UNKNOWN_FIELD, PROTECTED_FIELD, DUPLICATE_TARGET, NO_KEY, KEY_NOT_TARGET
     */
    public function problems(string $sliceId): array
    {
        $slice = self::slice($sliceId);
        if ($slice === null) {
            return ['NO_SLICE'];
        }
        $problems = [];
        $targets  = [];
        foreach ($this->rows as $row) {
            $problem = self::transformationProblem($row);
            if ($problem !== null) {
                $problems[] = $problem;
            }
            if (self::paramName($row['to']) !== null) {
                continue;
            }
            $field = $slice->getField($row['to']);
            if ($field === null) {
                $problems[] = 'UNKNOWN_FIELD';
            } elseif ($field->isProtected()) {
                $problems[] = 'PROTECTED_FIELD';
            }
            if (isset($targets[$row['to']])) {
                $problems[] = 'DUPLICATE_TARGET';
            }
            $targets[$row['to']] = true;
        }
        if ($this->keyFields === []) {
            $problems[] = 'NO_KEY';
        }
        if (array_diff($this->keyFields, array_keys($targets))) {
            $problems[] = 'KEY_NOT_TARGET';
        }
        return array_values(array_unique($problems));
    }

    /** @param list<string> $codes of problems(), as one text a refusal carries - splitProblems() reads it */
    public static function joinProblems(array $codes): string
    {
        return implode(', ', $codes);
    }

    /** @return list<string> the codes of joinProblems() */
    public static function splitProblems(string $joined): array
    {
        return array_values(array_filter(explode(', ', $joined)));
    }

    /** the codes of joinProblems() in words, each with its code, plain text */
    public static function problemsText(string $joined): string
    {
        return implode(' ', array_map(static fn(string $code): string => self::problemText($code) . " ($code)", self::splitProblems($joined)));
    }

    /** a code of problems() in words, plain text */
    public static function problemText(string $code): string
    {
        return match ($code) {
            'NO_SLICE'               => _m('The slice of the import is not available.'),
            'UNKNOWN_TRANSFORMATION' => _m('A row of the mapping uses a transformation that does not exist.'),
            'BAD_PARAMS'             => _m('A row of the mapping has parameters its transformation does not take.'),
            'UNKNOWN_FIELD'          => _m('The mapping fills a field the slice does not have.'),
            'PROTECTED_FIELD'        => _m('The mapping fills a field an import may not set.'),
            'DUPLICATE_TARGET'       => _m('Two rows of the mapping fill the same field.'),
            'NO_KEY'                 => _m('The mapping has no key field, so an item cannot be found again.'),
            'KEY_NOT_TARGET'         => _m('A key field is not filled by the mapping.'),
            default                  => _m('The mapping has a problem.'),
        };
    }

    /** The id mode of the Saver: the item is found by the key fields (Saver reads them as a JSON list). */
    public function idMode(): string
    {
        if ($this->keyFields === []) {
            // "[]" would match every item of the slice
            throw self::refused('the store mode needs key fields');
        }
        return json_encode($this->keyFields, JSON_THROW_ON_ERROR);
    }

    /**
     * The transformations of one job for the Saver, "param:<name>" replaced by the parameter.
     * Checked again here, as a field may have left the slice since the definition was saved.
     * @param array<string, string> $params parameters of the job
     * @return array<string, AA_Transformation> field id => transformation, in the order of the rows
     */
    public function forJob(array $params, string $sliceId): array
    {
        $slice = self::slice($sliceId) ?? throw self::refused('the target slice does not exist');
        $system = self::systemFields();
        $t13s = [];
        foreach ($this->rows as $i => $row) {
            $at   = 'row ' . ($i + 1);
            $name = self::paramName($row['to']);
            $field_id = ($name === null) ? $row['to'] : ($params[$name] ?? throw self::refused("$at: the job has no parameter $name"));
            if (($name !== null) && isset($system[$field_id])) {
                throw self::refused("$at: parameter $name names a system field");
            }
            $field = $slice->getField($field_id) ?? throw self::refused("$at: the target is not a field of the slice");
            if ($field->isProtected()) {
                throw self::refused("$at: the target is a protected field");
            }
            if (isset($t13s[$field_id])) {
                // the Saver keys transformations by field - the second would silently replace the first
                throw new SourceRefused('DUPLICATE_TARGET', "Mapping: $at: the field is already filled by another row");
            }
            if (self::transformationProblem($row) !== null) {
                throw self::refused("$at: the transformation is not allowed or lacks a parameter");
            }
            $class = self::TRANSFORMATIONS[strtolower($row['t'])][0];
            $t13s[$field_id] = new $class($row['p']);
        }
        return $t13s;
    }

    /** @param array<mixed> $rows */
    private static function checkRows(array $rows): void
    {
        if (!array_is_list($rows) || (count($rows) > self::MAX_ROWS)) {
            throw self::refused('expected a list of at most ' . self::MAX_ROWS . ' rows');
        }
        foreach ($rows as $i => $row) {
            $at = 'row ' . ($i + 1);
            if (!is_array($row) || (count($row) !== 3) || !isset($row['to'], $row['t']) || !array_key_exists('p', $row)) {
                throw self::refused("$at: expected to, t and p");
            }
            $to = $row['to'];
            if (!is_string($to) || (str_starts_with($to, self::PARAM) ? (self::paramName($to) === null) : !AA_Field::isValidFieldId($to))) {
                throw self::refused("$at: the target is neither a field id nor param:<name>");
            }
            if (!is_string($row['t']) || (preg_match(self::TRANSFORMATION, $row['t']) !== 1)) {
                throw self::refused("$at: bad transformation name");
            }
            if (!is_array($row['p'])) {
                throw self::refused("$at: parameters must be an object");
            }
            foreach ($row['p'] as $name => $value) {
                // text only - a transformation gets nothing it could take for an object or a callable
                if (!is_string($name) || (preg_match(self::PARAM_NAME, $name) !== 1) || !is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
                    throw self::refused("$at: a parameter is not name => text");
                }
            }
        }
    }

    /**
     * @param array<mixed> $keyFields
     * @param array<mixed> $validate
     */
    private static function checkLists(array $keyFields, array $validate): void
    {
        if (!array_is_list($keyFields) || (count(array_unique($keyFields)) !== count($keyFields))) {
            throw self::refused('key fields must be a list of different fields');
        }
        foreach ($keyFields as $field_id) {
            if (!is_string($field_id) || !AA_Field::isValidFieldId($field_id)) {
                throw self::refused('a key field is not a field id');
            }
        }
        if (!array_is_list($validate) || (count(array_filter($validate, is_string(...))) !== count($validate))) {
            throw self::refused('validation must be a list of expressions');
        }
    }

    /** the parameter name of a "param:<name>" target, null for anything else */
    private static function paramName(string $to): ?string
    {
        if (!str_starts_with($to, self::PARAM)) {
            return null;
        }
        $name = substr($to, strlen(self::PARAM));
        return (preg_match(self::PARAM_NAME, $name) === 1) ? $name : null;
    }

    /** null also for a slice in the trash - nothing is imported there */
    private static function slice(string $sliceId): ?AA_Slice
    {
        // getModule() takes an unknown id for a slice, isValid() asks the module table
        $slice = AA_Slice::getModule($sliceId);
        return (($slice instanceof AA_Slice) && $slice->isValid()) ? $slice : null;
    }

    /**
     * @param array{to:string, t:string, p:array<string, string>} $row
     * @return ?string UNKNOWN_TRANSFORMATION, BAD_PARAMS or null when the row can be built
     */
    private static function transformationProblem(array $row): ?string
    {
        $allowed = self::TRANSFORMATIONS[strtolower($row['t'])] ?? null;
        if ($allowed === null) {
            return 'UNKNOWN_TRANSFORMATION';
        }
        if (array_diff($allowed[1], array_keys($row['p']))) {
            return 'BAD_PARAMS';
        }
        // the transformation splits by it - explode('') throws inside the Saver's run
        return (($allowed[0] === AA_Transformation_ParseMulti::class) && ($row['p']['delimiter'] === '')) ? 'BAD_PARAMS' : null;
    }

    /** @return array<string, true> ids of the fields stored in the item table (status, dates, author, ...) */
    public static function systemFields(): array
    {
        $ids = array_map(static fn(string $column): string => str_pad($column, 16, '.'), AA::Metabase()->getColumnNames('item'));
        return array_fill_keys($ids, true);
    }

    private static function refused(string $what): SourceRefused
    {
        return new SourceRefused('DEFINITION', 'Mapping: ' . $what);
    }
}
