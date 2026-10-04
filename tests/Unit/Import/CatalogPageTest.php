<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use AA\Import\CatalogEntry;
use AA\Import\CatalogPage;
use PHPUnit\Framework\TestCase;

final class CatalogPageTest extends TestCase
{
    public function test_construct_keepsEntriesAndNext(): void
    {
        $entry = new CatalogEntry('K1', 'Name', ['unit' => '%'], '2026-01-01');
        $page  = new CatalogPage([$entry], 'page-2');

        self::assertSame([$entry], $page->entries);
        self::assertSame('page-2', $page->next);
        self::assertSame(['unit' => '%'], $entry->meta);
    }
}
