<?php

declare(strict_types=1);

namespace AA\Central\Package;

use RuntimeException;

/** Reader refused a package: too big, not UTF-8, a DTD or an entity, another major format version,
 *  not valid against the XSD, a value it cannot decode. Nothing of the package may be used. The
 *  message can quote package text (libxml errors, paths) - escape it (safe()) when shown. */
final class PackageRejected extends RuntimeException {}
