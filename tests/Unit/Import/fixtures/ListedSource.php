<?php

declare(strict_types=1);

namespace Tests\Unit\Import\Fixtures;

use AA\Import\Cataloged;
use AA\Import\CatalogPage;
use AA\Import\Context;

require_once __DIR__ . '/ScriptedSource.php';

/** A ScriptedSource that lists its catalog as the test sets it, without check() or probe() */
final class ListedSource extends ScriptedSource implements Cataloged
{
    /** @var array<string, CatalogPage> cursor => the page */
    public array $pages = [];

    public function catalog(Context $ctx, string $cursor): CatalogPage
    {
        return $this->pages[$cursor] ?? throw new \LogicException("No catalog page at '$cursor'");
    }
}
