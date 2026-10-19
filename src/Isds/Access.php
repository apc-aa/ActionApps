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
        return self::isLogin($this->uid());
    }

    public function may(string $campaignSlice): bool
    {
        return self::userMay($this->uid(), $campaignSlice);
    }

    /** may() for a $uid the caller gives - a batch run asks it for whoever lists, continues or ends the run, with no session at hand */
    public static function userMay(string $uid, string $campaignSlice): bool
    {
        return self::has($uid, $campaignSlice, PS_EDIT_ALL_ITEMS);
    }

    /** a user who could fix the settings of the page: one who may send, or edit the code of the site */
    public function mayFix(string $site, string $campaignSlice): bool
    {
        return $this->may($campaignSlice) || self::has($this->uid(), $site, PS_MODW_EDIT_CODE);
    }

    private static function has(string $uid, string $module, string $perm): bool
    {
        return ($module !== '') && self::isLogin($uid) && AA::$perm->hasSlicePerm($uid, $module, $perm);
    }

    /** '' and nobody are no login, whatever rights the storage gives them */
    private static function isLogin(string $uid): bool
    {
        return !in_array($uid, ['', 'nobody'], true);
    }

    // ------------------------------------------------------------ what tests replace

    protected function uid(): string
    {
        return AuthGetUid();
    }
}
