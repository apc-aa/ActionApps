<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA;
use AA\IO\DB\DB_AA;
use AA_MbC;

/** ActionApps Central keeps the login token of each AA it manages in central_conf.AA_ADMIN_PWD - encrypted,
 *  several hundred bytes. Databases from before the tokens have the column 30 bytes long, and AA writes in
 *  a non-strict SQL mode: the token is cut without an error and Get Token reports a login that never works.
 *  The update of a database widens it with all the other columns (UpdateDbStructure); this one lets State Check
 *  and the Central page say it and fix just the column. */
class CentralTokenColumn extends AbstractOptimize
{
    private const TABLE  = 'central_conf';
    private const COLUMN = 'AA_ADMIN_PWD';

    public static function name(): string
    {
        return _m('Column for the Central login tokens');
    }

    public static function description(): string
    {
        return _m('Widens the column central_conf.AA_ADMIN_PWD to the length of the template, so ActionApps Central can keep the encrypted login tokens of the AAs it manages. A shorter column cuts them and Get Token cannot work.');
    }

    public static function types(): array
    {
        return [self::CHECK_BASIC];
    }

    public static function category(): string
    {
        return 'db';
    }

    public static function actions(): array
    {
        return ['test', 'repair'];
    }

    /** the same as findings(), with a line for the report of the Optimize page */
    public function test(): bool
    {
        if ($lengths = self::shortBy()) {
            $this->message(($lengths[0] === null)
                ? _m('The column %1 is missing', [self::TABLE . '.' . self::COLUMN])
                : _m('The column %1 holds %2 bytes, the template %3', [self::TABLE . '.' . self::COLUMN, $lengths[0], $lengths[1]]));
            return false;
        }
        $this->message(_m('OK - the column %1 is long enough', [self::TABLE . '.' . self::COLUMN]));
        return true;
    }

    public function findings(): array
    {
        if (!$lengths = self::shortBy()) {
            return [new Finding(Finding::OK, _m('Central can keep the login tokens'))];
        }
        return [new Finding(
            Finding::ERROR,
            _m('Central cannot keep the login tokens'),
            evidence: [[_m('Column') => self::TABLE . '.' . self::COLUMN, _m('Bytes') => (string) ($lengths[0] ?? 0), _m('Template') => (string) $lengths[1]]],
            advice: _m('The column is too short for an encrypted token: Get Token stores it cut and Central cannot log in to the AA. Repair widens the column, then press Get Token again.'),
            links: [_m('ActionApps Central') => AA_INSTAL_PATH . 'central/index.php'],
            repairable: true,
            key: 'length',
        )];
    }

    public function repair(): bool
    {
        if (!$lengths = self::shortBy()) {
            return true;
        }
        $sql = AA::Metabase()->tables[self::TABLE]->getColumn(self::COLUMN)->getCreateSql();
        // a widened varbinary keeps every byte - the tokens cut so far stay cut, Get Token replaces them
        return $this->query('ALTER TABLE `' . self::TABLE . '` ' . (($lengths[0] === null) ? 'ADD ' : 'MODIFY ') . trim($sql));
    }

    /** [bytes of the live column (null = no such column), bytes of the template's] when the live one is
     *  shorter; null when it is long enough or there is no table - a new install creates it whole
     *  @return array{0: ?int, 1: int}|null */
    private static function shortBy(): ?array
    {
        $wanted = AA::Metabase()->tables[self::TABLE]->getColumn(self::COLUMN)->getMaxLength();
        // one query on every load of the Central page
        $type = DB_AA::select1('COLUMN_TYPE', 'SELECT COLUMN_TYPE FROM information_schema.COLUMNS', [['table_schema', (string) constant('DB_NAME')], ['table_name', self::TABLE], ['column_name', self::COLUMN]]);
        if ($type === false) {
            return DB_AA::exists_table(self::TABLE) ? [null, $wanted] : null;
        }
        $bytes = self::bytes((string) $type);
        return ($bytes < $wanted) ? [$bytes, $wanted] : null;
    }

    /** how many bytes a column of this type holds */
    private static function bytes(string $type): int
    {
        $column = AA_MbC::fromArray([self::COLUMN, $type, false, '', '', '', '']);
        if ($column->getFieldType() !== 'text') {
            return $column->getMaxLength();
        }
        // text and blob types state no length: tinytext and tinyblob hold 255 bytes, the others more than a token
        return str_starts_with(strtolower($type), 'tiny') ? 255 : PHP_INT_MAX;
    }
}
