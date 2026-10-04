<?php

declare(strict_types=1);

use AA\Import\AbstractSource;

/** A base the installation's sources may share - loaded, never offered */
abstract class AbstractSkippedSource extends AbstractSource {}
