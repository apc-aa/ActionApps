<?php

namespace AA\Optimize;

use AA;
use AA\IO\DB\DB_AA;

/** Index content (field_id, text) - lookups of a value in one field (SEO path, relations, categories)
 *
 *  The old text(12) index cannot tell fields apart: on ekolist.cz the SEO lookup of 'zpravodajstvi'
 *  went through ~300 000 rows of 'Zpravodajství' categories and took 0.7 s on every article page.
 */
class AddContentFieldIndex extends AbstractOptimize
{
    private const INDEX = 'field_text';

    public static function name(): string
    {
        return _m('Index of field values in the content table');
    }

    public static function description(): string
    {
        return _m('Adds the index (field_id, text) to the content table, so a value is found in one field without reading the same value of all other fields. On a big database it takes minutes and about as much disk as the other indexes of the table - run it when the traffic is low.');
    }

    public static function types(): array
    {
        return ['sql_update'];
    }

    /** before UpdateDbStructure (50), which would otherwise rebuild the whole content table for the missing index */
    public static function priority(): int
    {
        return 40;
    }

    public static function actions(): array
    {
        return ['test', 'repair', 'revert'];
    }

    public function test(): bool
    {
        if (!DB_AA::exists_index('content', self::INDEX)) {
            $this->message(_m('The index %1 is missing in the content table', [self::INDEX]));
            return false;
        }
        $this->message(_m('OK - the content table has the index %1', [self::INDEX]));
        return true;
    }

    public function repair(): bool
    {
        if (DB_AA::exists_index('content', self::INDEX)) {
            $this->message(_m('The content table already has the index %1', [self::INDEX]));
            return true;
        }
        // InnoDB builds it in place and keeps the table writable meanwhile, MyISAM copies and locks it
        $this->query('ALTER TABLE `content` ADD ' . AA::Metabase()->tables['content']->i[self::INDEX]->getCreateSql());
        $this->message(_m('The index %1 added', [self::INDEX]));
        return true;
    }

    public function revert(): bool
    {
        if (DB_AA::exists_index('content', self::INDEX)) {
            $this->query('ALTER TABLE `content` DROP INDEX `' . self::INDEX . '`');
        }
        $this->message(_m('The index %1 removed', [self::INDEX]));
        return true;
    }
}
