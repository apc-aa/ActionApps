<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use AA\Util\Searchfields;
use PHPUnit\Framework\TestCase;

/** The fields of related items a stored condition may ask - what the view form adds to its own list of fields. */
final class SearchfieldsRelatedTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
    }

    public function test_getRelatedArray_keepsOnlyFieldsThroughARelation(): void
    {
        $fields = new Searchfields();
        $fields->add('headline........', 'Headline', 'headline........', 'text', '', 1, 1);
        $fields->add('relation.......1', 'Town', 'relation.......1', 'constants', '', 2, 2);
        // the relation of this slice: the group of the fields of its target
        $fields->add('all_fields@relation.......1', ' > -- any text field --', 'all_fields@relation.......1', 'text', '', 1000, 0, 'Town');
        $fields->add('headline........@relation.......1', ' > Name', 'headline........@relation.......1', 'text', '', 1001, 0, 'Town');
        // a relation leading here from another slice
        $there = 'headline........@7735375488a65e7735375488a65eab2a/relation.......2';
        $fields->add($there, ' > Title', $there, 'text', '', 2001, 0, 'Plans / Town');
        // hidden from the search
        $fields->add('text...........@relation.......1', ' > Note', 'text...........@relation.......1', 'text', '', 0, 0, 'Town');

        self::assertSame(
            [
                'AA_OPTGROUP 2'                      => 'Town',
                'all_fields@relation.......1'        => ' > -- any text field --',
                'headline........@relation.......1'  => ' > Name',
                'AA_OPTGROUP 1001'                   => 'Plans / Town',
                $there                               => ' > Title',
            ],
            $fields->getRelatedArray(),
        );
    }

    public function test_getRelatedArray_sliceWithoutRelations(): void
    {
        $fields = new Searchfields();
        $fields->add('headline........', 'Headline', 'headline........', 'text', '', 1, 1);

        self::assertSame([], $fields->getRelatedArray());
    }
}
