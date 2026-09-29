<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\IO\DB\DB_AA;

/** A slice of this AA as an item batch needs it: a batch carries no definition, and the rows the
 *  definitions write stored are what counts - an admin may have changed them since. */
final readonly class TargetSlice
{
    /**
     * @param string                                   $id      32 hex
     * @param bool                                     $readers a Reader Management slice - its headlines are logins
     * @param string                                   $charset of the module's lang_file
     * @param array<string, array{bool, bool}>         $fields  field id => [text stored, password]
     */
    public function __construct(
        public string $id,
        public bool $readers,
        public string $charset,
        public array $fields,
    ) {}

    /** the slice as the DB has it now; null = no such slice, or its language names no charset */
    public static function read(string $id): ?self
    {
        $type   = DB_AA::select1('type', 'SELECT `type` FROM `slice`', [['id', $id, 'l']]);
        $module = DB_AA::select1([], 'SELECT `type`, `lang_file` FROM `module`', [['id', $id, 'l']]);
        if (($type === false) || !is_array($module) || ($module === [])) {
            return null;
        }
        $charset = ModuleCharset::ofModule(['type' => $module['type'], 'lang_file' => $module['lang_file']])[1] ?? null;
        if ($charset === null) {
            return null;
        }
        $fields = [];
        foreach (DB_AA::select([], 'SELECT `id`, `input_insert_func`, `text_stored` FROM `field`', [['slice_id', $id, 'l']]) as $row) {
            $fields[(string) $row['id']] = [(int) $row['text_stored'] !== 0, self::isPassword((string) $row['input_insert_func'])];
        }
        return new self($id, $type === ItemLint::READER_SLICE, $charset, $fields);
    }

    /** a password field: its input_insert_func stores a hash */
    public static function isPassword(string $insertFunc): bool
    {
        return explode(':', $insertFunc, 2)[0] === 'pwd';
    }

    /** [text stored, password] of a field; a field the slice lacks is stored as text @return array{bool, bool} */
    public function field(string $id): array
    {
        return $this->fields[$id] ?? [true, false];
    }
}
