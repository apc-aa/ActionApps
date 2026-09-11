<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use PHPUnit\Framework\TestCase;

/**
 * GetCategoryFromPath() from modules/links/util.php — pure string helper, no DB
 * and no globals, so the file is required here rather than in tests/bootstrap.php
 * (it is module code and must not leak into every other test).
 *
 * The null case is a regression test: modules/links/index.php passes
 * $links_info['tree_start'], which is null on an installation with no links
 * module, and the declared `: string` return type turned that into an uncaught
 * TypeError. The rest pins the existing path-splitting behaviour.
 */
final class LinksUtilTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // only `use` imports and function declarations - no side effects on load
        require_once __DIR__ . '/../../../modules/links/util.php';
    }

    /** Regression: an installation with no links module yields a null path. */
    public function test_get_category_from_path_returns_empty_string_for_null(): void
    {
        $this->assertSame('', GetCategoryFromPath(null));
    }

    public function test_get_category_from_path_takes_the_segment_after_the_last_comma(): void
    {
        $this->assertSame('3', GetCategoryFromPath('1,2,3'));
        $this->assertSame('5', GetCategoryFromPath(',5'));
    }

    /** No comma - the whole value is already the category id. */
    public function test_get_category_from_path_returns_path_unchanged_without_comma(): void
    {
        $this->assertSame('5', GetCategoryFromPath('5'));
        $this->assertSame('0', GetCategoryFromPath('0'));
        $this->assertSame('', GetCategoryFromPath(''));
    }

    /** A trailing comma leaves nothing after it. */
    public function test_get_category_from_path_returns_empty_string_for_trailing_comma(): void
    {
        $this->assertSame('', GetCategoryFromPath('1,2,'));
    }
}
