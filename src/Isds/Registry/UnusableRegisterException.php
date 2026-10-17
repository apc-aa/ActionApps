<?php

declare(strict_types=1);

namespace AA\Isds\Registry;

/**
 * The register of data boxes cannot be used in this request (RegisterIndex::usable() is false, or
 * turned false while read): no recipients may be told. The message may name a path - escape it (safe())
 * when shown.
 */
final class UnusableRegisterException extends \RuntimeException {}
