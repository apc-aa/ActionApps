<?php

declare(strict_types=1);

namespace AA\Isds;

use AA;

/**
 * Who may use a mass sending: whoever AA lets edit all items of the campaign slice - a user of AA, or
 * a reader through a set of readers (Reader Management) the slice grants a role. Only the login of
 * the request and the rights stored now decide - never the URL, the template or an item the page
 * shows, nor the rights the session cached at login (CheckPerms()), so a right taken away counts at once.
 *
 * Grant readers the role through a set only: a role granted to the reader alone replaces whatever
 * the reader's sets grant on that slice (perm_sql.php, getJoinedPerms()), so a lower one takes the
 * right away.
 */
class Access
{
    public function loggedIn(): bool
    {
        return !in_array($this->uid(), ['', 'nobody'], true);
    }

    public function may(string $campaignSlice): bool
    {
        return $this->has($campaignSlice, PS_EDIT_ALL_ITEMS);
    }

    /** a user who could fix the settings of the page: one who may send, or edit the code of the site */
    public function mayFix(string $site, string $campaignSlice): bool
    {
        return $this->may($campaignSlice) || $this->has($site, PS_MODW_EDIT_CODE);
    }

    private function has(string $module, string $perm): bool
    {
        return ($module !== '') && $this->loggedIn() && AA::$perm->hasSlicePerm($this->uid(), $module, $perm);
    }

    // ------------------------------------------------------------ what tests replace

    protected function uid(): string
    {
        return AuthGetUid();
    }
}
