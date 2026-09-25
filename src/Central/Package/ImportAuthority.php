<?php

declare(strict_types=1);

namespace AA\Central\Package;

/** Who may install an aa-app package (design balicek-v1.md § 3.3, Q-H2: the permission PS_INSTALL_APP,
 *  for now only a superadmin has it) - the seam Importer::write() asks before anything else; the AA one is
 *  PermImportAuthority, tests use a fake. */
interface ImportAuthority
{
    /** true: $user may install a package on this AA. Throwing = no. */
    public function mayInstall(string $user): bool;
}
