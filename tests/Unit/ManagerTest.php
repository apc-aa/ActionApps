<?php

declare(strict_types=1);

namespace Tests\Unit;

use AA\Manager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Not every Manager page has a searchbar (its settings may leave it out).
 */
final class ManagerTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/Legacy/fixtures/object_form_fixtures.php';
    }

    public function test_withoutSearchbar_bookmarksAreEmpty(): void
    {
        // the constructor needs a Managed source; the searchbar property is all this test is about
        $manager = (new ReflectionClass(Manager::class))->newInstanceWithoutConstructor();

        $this->assertSame([], $manager->getBookmarkNames());
        $manager->resetSearchBar();
        $this->assertNull($manager->getState()['searchbar']);   // the state is saved after every page
    }
}
