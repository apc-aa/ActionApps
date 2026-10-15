<?php

namespace AA\Optimize;

use AA;
use AA\IO\DB\DB_AA;
use AA_MbT;

/** Index content (text(12), field_id) - lookups of a value in one field (SEO path, relations, categories)
 *
 *  The old text(12) index cannot tell fields apart: on ekolist.cz the SEO lookup of 'zpravodajstvi'
 *  went through ~300 000 rows of 'Zpravodajství' categories and took 0.7 s on every article page.
 *  The field goes second: with field_id first (the index field_text) MariaDB 10.6-11.8 reads a whole
 *  field through it for "field = X and text one of a list" - vozejkov.cz news 4.5 s instead of 0.5 s.
 *  It replaces text(12), which is its prefix: about 15 bytes a row more, not a second index.
 */
class AddContentFieldIndex extends AbstractOptimize
{
    private const INDEX = 'text';
    /** the field-first index this one replaces */
    private const FIELD_FIRST = 'field_text';

    public static function name(): string
    {
        return _m('Index of field values in the content table');
    }

    public static function description(): string
    {
        return _m('Adds the field to the text index of the content table, so a value is found in one field without reading the same value of all other fields. On a big database it takes minutes - run it when the traffic is low.');
    }

    public static function types(): array
    {
        return ['sql_update'];
    }

    /** before UpdateDbStructure (50), which would otherwise rebuild the whole content table for the changed index */
    public static function priority(): int
    {
        return 40;
    }

    public static function actions(): array
    {
        return ['test', 'repair'];
    }

    public function test(): bool
    {
        if ($changes = self::changes()) {
            $this->message(_m('The content table needs: %1', [implode(', ', $changes)]));
            return false;
        }
        $this->message(_m('OK - the index %1 of the content table contains the field', [self::INDEX]));
        return true;
    }

    public function repair(): bool
    {
        if (!$changes = self::changes()) {
            $this->message(_m('The index %1 of the content table already contains the field', [self::INDEX]));
            return true;
        }
        // one ALTER: InnoDB builds it in place and keeps the table writable meanwhile, MyISAM copies and locks it
        $this->query('ALTER TABLE `content` ' . implode(', ', $changes));
        $this->message(_m('The index %1 of the content table changed', [self::INDEX]));
        return true;
    }

    /** ALTER clauses that bring the live indexes to the template's; none without the table - a new install creates it whole
     * @return string[]
     */
    private static function changes(): array
    {
        if (!DB_AA::exists_table('content')) {
            return [];
        }
        $live    = AA_MbT::factoryFromDb('content')->i;
        $wanted  = AA::Metabase()->tables['content']->i[self::INDEX]->getCreateSql();
        $changes = isset($live[self::FIELD_FIRST]) ? ['DROP INDEX `' . self::FIELD_FIRST . '`'] : [];
        if (!isset($live[self::INDEX]) || ($live[self::INDEX]->getCreateSql() !== $wanted)) {
            if (isset($live[self::INDEX])) {
                $changes[] = 'DROP INDEX `' . self::INDEX . '`';
            }
            $changes[] = "ADD $wanted";
        }
        return $changes;
    }
}
