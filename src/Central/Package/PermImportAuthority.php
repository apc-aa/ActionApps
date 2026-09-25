<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA_Perm;

/** PS_INSTALL_APP on the `aa` object of this AA (include/perm_core.php). The letter is part of the SUPER
 *  role only (AA_Perm::ROLES), so today a superadmin may install (Q-H2); a user with the letter granted
 *  directly may too. Not IfSlPerm(): a module's perms must not decide an installation.
 *
 *  Only the logged-in user (AuthGetUid()) may be asked about: CheckPerms() keeps the perms of the user it
 *  asks about in the session (AA_Perm::cache()) - asking about another would put their perms into this
 *  session. Any other user is a no. */
final class PermImportAuthority implements ImportAuthority
{
    public function mayInstall(string $user): bool
    {
        class_exists(AA_Perm::class);           // include/perm_core.php: PS_INSTALL_APP, CheckPerms()
        return ($user !== '') && ($user === \AuthGetUid()) && \CheckPerms($user, 'aa', constant('AA_ID'), \PS_INSTALL_APP);
    }
}
