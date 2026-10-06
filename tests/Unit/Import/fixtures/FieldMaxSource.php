<?php

declare(strict_types=1);

namespace Tests\Unit\Import\Fixtures;

use AA\Import\FieldMaxProbeable;

require_once __DIR__ . '/CatalogSource.php';

/** A CatalogSource whose probe() vouches for the maximum of a stream's versionField */
final class FieldMaxSource extends CatalogSource implements FieldMaxProbeable {}
