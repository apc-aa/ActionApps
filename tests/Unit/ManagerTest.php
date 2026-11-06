<?php

declare(strict_types=1);

namespace Tests\Unit;

use AA\Manager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

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
        $state = $manager->getState();   // the state is saved after every page and restored on the next one
        $this->assertNull($state['searchbar']);

        $next = (new ReflectionClass(Manager::class))->newInstanceWithoutConstructor();
        set_error_handler(static fn(): bool => true, E_WARNING);   // AA_Storable reads optional state keys unguarded
        try {
            $next->setFromState($state);
        } finally {
            restore_error_handler();
        }
        $this->assertSame([], $next->getBookmarkNames());
        $this->assertNull((new ReflectionProperty(Manager::class, 'searchbar'))->getValue($next));
    }
}
