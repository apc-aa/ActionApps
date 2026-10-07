<?php

declare(strict_types=1);

namespace AA\IO;

/**
 * A data source did not answer now - a timeout, an unknown host, a refused connection, 5xx, 429.
 * The same request may work later, so the import retries it.
 */
final class SourceUnavailable extends SourceFailure {}
