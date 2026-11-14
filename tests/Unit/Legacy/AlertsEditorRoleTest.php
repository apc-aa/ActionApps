<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA_Perm;
use PHPUnit\Framework\TestCase;

// AA_Perm lives in include/perm_core.php, which the legacy boot loads
require_once __DIR__ . '/fixtures/object_form_fixtures.php';

/**
 * The roles of an Alerts module: the Administrator manages it, the Editor only sees its mailings and
 * their statistics - every other page of the module wants PS_USERS or PS_FULLTEXT.
 */
final class AlertsEditorRoleTest extends TestCase
{
    private const MODULE = '7a7a7a7a7a7a7a7a7a7a7a7a7a7a7a7a';

    /** a user with $perms on the module, as the storage keeps them */
    private static function perm(string $perms): AA_Perm
    {
        return new class ([], $perms) extends AA_Perm {
            public function __construct(array $systems, private readonly string $perms)
            {
                parent::__construct($systems);
            }

            public function getUserPerms($uid, $objectType, $flags = 0): array
            {
                return (($uid === 'uid-1') && ($objectType === 'slice')) ? ['7a7a7a7a7a7a7a7a7a7a7a7a7a7a7a7a' => $this->perms] : [];
            }
        };
    }

    private static function has(string $role, string $letter): bool
    {
        return self::perm(AA_Perm::ROLES[$role]['id'])->hasSlicePerm('uid-1', self::MODULE, $letter);
    }

    public function test_alertsRoles_editorAndAdministrator(): void
    {
        $this->assertSame(['EDITOR', 'ADMINISTRATOR'], AA_Perm::PERMS_ROLES_MODULES['Alerts']);
    }

    public function test_editorPerms_holdStatsButNotSettings(): void
    {
        $this->assertTrue(self::has('EDITOR', PS_ALERTS_STATS));
        $this->assertFalse(self::has('EDITOR', PS_USERS), 'settings, selections, sending and readers');
        $this->assertFalse(self::has('EDITOR', PS_FULLTEXT), 'the selections page');
    }

    public function test_administratorPerms_holdSettingsAndStats(): void
    {
        $this->assertTrue(self::has('ADMINISTRATOR', PS_USERS));
        $this->assertTrue(self::has('ADMINISTRATOR', PS_FULLTEXT));
        $this->assertTrue(self::has('ADMINISTRATOR', PS_ALERTS_STATS));
    }

    /** the stats letter is in the range of the Editor role, not of the Author */
    public function test_statsLetter_isAnEditorLetter(): void
    {
        $this->assertStringContainsString(PS_ALERTS_STATS, AA_Perm::ROLES['EDITOR']['perm']);
        $this->assertStringNotContainsString(PS_ALERTS_STATS, AA_Perm::ROLES['AUTHOR']['perm']);
    }

    public function test_otherModuleRoles_unchanged(): void
    {
        $this->assertSame([
            'S'     => ['AUTHOR', 'EDITOR', 'ADMINISTRATOR'],
            'W'     => ['ADMINISTRATOR'],
            'A'     => ['ADMINISTRATOR'],
            'J'     => ['ADMINISTRATOR'],
            'P'     => ['EDITOR', 'ADMINISTRATOR'],
            'Links' => ['AUTHOR', 'EDITOR', 'ADMINISTRATOR'],
        ], array_diff_key(AA_Perm::PERMS_ROLES_MODULES, ['Alerts' => true]));
    }

    /** the pages need a session, so the test reads their source: the module type is checked before the right */
    public function test_mailingsPage_checksTheTypeBeforeTheRight(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/modules/alerts/mailings.php');
        $this->assertSame(1, preg_match(
            '~if \(!AA_Module_Alerts::getOwnModule\(AA::\$module_id\)\) \{\s*MsgPage\([^;]*\);\s*exit;\s*\}\s*if \(!IfSlPerm\(PS_ALERTS_STATS\)\) \{\s*MsgPage\([^;]*\);\s*exit;\s*\}~',
            $src,
        ));
        $this->assertStringNotContainsString('<form', $src, 'the page only reads');
        $this->assertStringNotContainsString('_POST', $src, 'the page only reads');
    }

    public function test_index_sendsOnlyAnEditorOfAnAlertsModuleToTheMailings(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/modules/alerts/index.php');
        $this->assertStringContainsString("(AA_Module_Alerts::getOwnModule(AA::\$module_id) && !IfSlPerm(PS_USERS) && IfSlPerm(PS_ALERTS_STATS)) ? 'mailings.php' : 'modedit.php'", $src);
    }

    /** um_util.php keys the role lists by the first letter of the type: no other registered type starts with A */
    public function test_noRegisteredTypeSharesTheFirstLetterOfAlerts(): void
    {
        $types = array_keys(iterator_to_array(\AA_Moduleregistry::singleton()));
        $this->assertSame(['Alerts'], array_values(array_filter($types, static fn($t): bool => ((string) $t)[0] === 'A')));
    }
}
