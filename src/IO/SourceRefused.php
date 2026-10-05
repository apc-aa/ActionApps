<?php

declare(strict_types=1);

namespace AA\IO;

/**
 * A data source refused the request or answered something unusable - 4xx, a bad key, an answer
 * over the size limit, a forbidden address. Asking again gets the same, so the import stops.
 * Reasons: AUTH, SOURCE, NO_CREDENTIAL, ...
 */
final class SourceRefused extends SourceFailure {}
