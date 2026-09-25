<?php

declare(strict_types=1);

namespace AA\Util;

/** The credential stamp of an LDAP user (AA_Permsystem_Ldap::credentialStamp()) from the attributes of
 *  its entry. modifyTimestamp, not userPassword: the hash would travel over plain ldap://, and a lock or
 *  any other change of the entry has to end the long-lived login as well.
 */
final class LdapStamp
{
    /** @param array<int|string, mixed> $attributes ldap_get_attributes() of the entry, any key case
     *  @return ?string null when the entry has no modifyTimestamp */
    public static function fromAttributes(array $attributes): ?string
    {
        $stamp = array_change_key_case($attributes)['modifytimestamp'][0] ?? '';
        return (is_string($stamp) && ($stamp !== '')) ? $stamp : null;
    }
}
