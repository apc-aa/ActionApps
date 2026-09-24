<?php

/* Regenerates src/Central/Package/aa-app-1.xsd, the schema of the aa-app package, from
 * include/metabase_schema.php (design balicek-v1.md § 3.2). Run it after a change of the AA schema
 * or of AA\Central\Package\Schema and commit the result together with the change - SchemaTest
 * fails until the repo copy matches.
 *
 *   php dev/central/aa-app-xsd.php
 */

declare(strict_types=1);

use AA\Central\Package\Schema;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../../vendor/autoload.php';

$xsd = (new Schema())->xsd();
if (file_put_contents(Schema::FILE, $xsd) !== strlen($xsd)) {
    fwrite(STDERR, 'Cannot write ' . Schema::FILE . "\n");
    exit(1);
}
echo 'Written ' . Schema::FILE . "\n";
