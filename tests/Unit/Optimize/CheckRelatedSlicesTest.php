<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Optimize\CheckRelatedSlices;
use AA\Optimize\Finding;
use PHPUnit\Framework\TestCase;

/**
 * Editors of a slice may edit the items its related-item fields edit, with no role in that slice -
 * the check lists such fields pointing to a slice of another website (a copied template, typically).
 */
final class CheckRelatedSlicesTest extends TestCase
{
    private const ARTICLES = '11111111111111111111111111111111';
    private const PHOTOS   = '22222222222222222222222222222222';
    private const FOREIGN  = '33333333333333333333333333333333';
    private const SITE     = '44444444444444444444444444444444';
    private const OTHER    = '55555555555555555555555555555555';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // _m(), safe()
    }

    /** @return Finding[] */
    private static function findings(string $target, array $sites): array
    {
        $links = [['slice' => self::ARTICLES, 'field' => 'relation........', 'target' => $target]];
        return CheckRelatedSlices::findingsFor($links, $sites, static fn(string $id, bool $link): string => ($link ? 'link:' : '') . $id);
    }

    public function test_a_related_slice_of_the_same_website_is_fine(): void
    {
        [$finding] = self::findings(self::PHOTOS, [self::ARTICLES => [self::SITE], self::PHOTOS => [self::SITE, self::OTHER]]);

        $this->assertSame(Finding::OK, $finding->severity);
    }

    public function test_a_related_slice_of_another_website_is_listed(): void
    {
        [$finding] = self::findings(self::FOREIGN, [self::ARTICLES => [self::SITE], self::FOREIGN => [self::OTHER]]);

        $this->assertSame(Finding::INFO, $finding->severity);
        $this->assertSame([
            _m('Slice')         => 'link:' . self::ARTICLES,
            _m('Field')         => 'relation........',
            _m('Related slice') => 'link:' . self::FOREIGN,
            _m('Its websites')  => self::SITE,
        ], $finding->evidence[0]);
    }

    public function test_a_related_slice_no_website_uses_is_listed(): void
    {
        [$finding] = self::findings(self::FOREIGN, [self::ARTICLES => [self::SITE], self::FOREIGN => []]);

        $this->assertSame(Finding::INFO, $finding->severity);
    }

    public function test_a_slice_no_website_uses_is_not_called_fine(): void
    {
        [$finding] = self::findings(self::FOREIGN, [self::ARTICLES => [], self::FOREIGN => [self::OTHER]]);

        $this->assertSame(Finding::OK, $finding->severity);
        $this->assertStringContainsString('1', $finding->title);
        $this->assertNotSame(_m('Every related-item field points to a slice of its own website'), $finding->title);
    }

    public function test_no_related_field_is_fine(): void
    {
        [$finding] = CheckRelatedSlices::findingsFor([], [], static fn(string $id, bool $link): string => $id);

        $this->assertSame(_m('Every related-item field points to a slice of its own website'), $finding->title);
    }
}
