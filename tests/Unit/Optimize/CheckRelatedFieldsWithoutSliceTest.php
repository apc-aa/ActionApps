<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Optimize\CheckRelatedFieldsWithoutSlice;
use AA\Optimize\Finding;
use PHPUnit\Framework\TestCase;

/**
 * A related-item field whose `#sLiCe-<id>` names no slice - an Alerts module, a module missing in this AA -
 * offers nothing to choose, so State Check lists it.
 */
final class CheckRelatedFieldsWithoutSliceTest extends TestCase
{
    private const STATS   = '11111111111111111111111111111111';
    private const ALERTS  = '22222222222222222222222222222222';
    private const MISSING = '33333333333333333333333333333333';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // _m(), safe()
    }

    /**
     * @param list<array{slice: string, field: string, target: string, type: ?string}> $fields
     * @return Finding[]
     */
    private static function findings(array $fields): array
    {
        return CheckRelatedFieldsWithoutSlice::findingsFor($fields, static fn(string $id, bool $link): string => ($link ? 'link:' : '') . $id);
    }

    public function test_moduleType_noTypeIsAnOldSlice(): void
    {
        $types = [self::STATS => null, self::ALERTS => 'Alerts'];

        $this->assertSame('S', CheckRelatedFieldsWithoutSlice::moduleType($types, self::STATS));
        $this->assertSame('Alerts', CheckRelatedFieldsWithoutSlice::moduleType($types, self::ALERTS));
        $this->assertNull(CheckRelatedFieldsWithoutSlice::moduleType($types, self::MISSING));
    }

    public function test_findingsFor_noSuchField_ok(): void
    {
        [$finding] = self::findings([]);

        $this->assertSame(Finding::OK, $finding->severity);
    }

    public function test_findingsFor_alertsModule_listedWithItsType(): void
    {
        [$finding] = self::findings([['slice' => self::STATS, 'field' => 'relation.......1', 'target' => self::ALERTS, 'type' => 'Alerts']]);

        $this->assertSame(Finding::WARNING, $finding->severity);
        $this->assertSame(_m('%1 related-item fields should offer the items of a module that is not a slice', [1]), $finding->title);
        $this->assertSame([
            _m('Slice')    => 'link:' . self::STATS,
            _m('Field')    => '<a href="' . safe(AA_INSTAL_PATH . 'admin/se_inputform.php?change_id=' . self::STATS . '&fid=relation.......1') . '" >relation.......1</a>',
            _m('Items of') => 'link:' . self::ALERTS . ' (Alerts)',
        ], $finding->evidence[0]);
    }

    public function test_findingsFor_missingModule_listedByIdOnly(): void
    {
        [$finding] = self::findings([['slice' => self::STATS, 'field' => 'relation.......1', 'target' => self::MISSING, 'type' => null]]);

        $this->assertSame(self::MISSING . ' (' . _m('no such module') . ')', $finding->evidence[0][_m('Items of')]);
    }
}
