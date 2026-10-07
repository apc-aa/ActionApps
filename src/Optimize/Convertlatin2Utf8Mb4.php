<?php

namespace AA\Optimize;

use AA\IO\DB\DB_AA;

/**
 *
 */
class Convertlatin2Utf8Mb4 extends \AA\Optimize\Convert2utf8mb4
{
    /** Name function
     * @return string - a name
     */
    public static function name(): string
    {
        return _m("Convert database from 1Byte encoding (latin1, cp1250, ...) to utf8mb4 collation");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string
    {
        return _m("Convert all tables with 1Byte encoded texts (latin1, cp1250, ...) to utf8mb4 and set database default to utf8mb4. Texts keep the characters AA reads from them now.");
    }

    /** @return string[] 1Byte charsets - ascii needs no conversion, binary holds bytes, not texts */
    protected function sourceCharsets(): array
    {
        return array_values(array_diff(DB_AA::select('', 'SELECT CHARACTER_SET_NAME FROM information_schema.CHARACTER_SETS', [['MAXLEN', 1, 'i']]), ['ascii', 'binary']));
    }

    /** ALTER converts the bytes as the column charset says. AA reads the same characters as before
     *  only when it reads them converted to utf8 already - over a 1Byte connection the bytes may be
     *  in the encoding of the slice (cp1250 in a latin1 column) and would end up garbled. */
    protected function connectionOk(): bool
    {
        $charset = $this->connectionCharset();
        if (str_starts_with($charset, 'utf8')) {
            return true;
        }
        $this->message(_m('DB_CHARACTER_SET in config.php is "%1". Switch it to "utf8mb4" first and check the texts in AA look right - the conversion keeps what AA shows over the utf8mb4 connection.', [$charset]));
        return false;
    }

    /** @param array<string, string> $collations [table_name => table_collation] */
    protected function fallbackCollation(array $collations): string
    {
        return 'utf8mb4_unicode_ci';
    }
}
