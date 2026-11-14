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
    private const OTHER  = '8b8b8b8b8b8b8b8b8b8b8b8b8b8b8b8b';
    private const SLICE  = '9c9c9c9c9c9c9c9c9c9c9c9c9c9c9c9c';

    /** a user with $perms (module id => perms, as the storage keeps them); MODULE and OTHER are Alerts modules */
    private static function perm(array $perms): AA_Perm
    {
        return new class ([], $perms) extends AA_Perm {
            public function __construct(array $systems, private readonly array $perms)
            {
                parent::__construct($systems);
            }

            public function getUserPerms($uid, $objectType, $flags = 0): array
            {
                return (($uid === 'uid-1') && ($objectType === 'slice')) ? $this->perms : [];
            }

            protected function moduleType(string $module_id): string
            {
                return in_array($module_id, ['7a7a7a7a7a7a7a7a7a7a7a7a7a7a7a7a', '8b8b8b8b8b8b8b8b8b8b8b8b8b8b8b8b'], true) ? 'Alerts' : 'S';
            }
        };
    }

    private static function has(string $role, string $letter, string $module = self::MODULE): bool
    {
        return self::perm([$module => AA_Perm::ROLES[$role]['id']])->hasSlicePerm('uid-1', $module, $letter);
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

    /** pages and lists check slice letters on a module id without its type - the Editor must hold none of them */
    public function test_editorPerms_onlyTheStatsLetter(): void
    {
        foreach (str_split(AA_Perm::ROLES['EDITOR']['perm']) as $letter) {
            $this->assertSame($letter === PS_ALERTS_STATS, self::has('EDITOR', $letter), "letter $letter");
        }
        $this->assertSame(PS_ALERTS_STATS, AA_Perm::resolveRoles(AA_Perm::ROLES['EDITOR']['id'], 'Alerts'));
    }

    public function test_editorOfASlice_keepsAllEditorLetters(): void
    {
        foreach (str_split(AA_Perm::ROLES['EDITOR']['perm']) as $letter) {
            $this->assertTrue(self::has('EDITOR', $letter, self::SLICE), "letter $letter");
        }
    }

    /** a group adds the Administrator role to the Editor role of the user: the Administrator letters count */
    public function test_editorJoinedWithAdministrator_holdsTheAdministratorLetters(): void
    {
        $perms = AA_Perm::ROLES['EDITOR']['id'] . AA_Perm::ROLES['ADMINISTRATOR']['id'];
        $this->assertTrue(self::perm([self::MODULE => $perms])->hasSlicePerm('uid-1', self::MODULE, PS_USERS));
    }

    /** Administrator of one Alerts module and Editor of another: the mail templates of the other one are not
     *  in the lists of the e-mails pages (GetUserEmails(), modules/alerts/emails.php, the Owner choice) */
    public function test_cache_editorModuleOutOfTheMailOwners(): void
    {
        $saved = [];
        foreach (['permission_uid', 'permission_to', 'r_superuser', 'sess', 'auth'] as $key) {
            $saved[$key] = $GLOBALS[$key] ?? null;
        }
        $GLOBALS['sess'] = null;
        $GLOBALS['auth'] = new class {
            public function getUid(): string
            {
                return 'uid-1';
            }
        };
        try {
            self::perm([self::MODULE => AA_Perm::ROLES['EDITOR']['id'], self::OTHER => AA_Perm::ROLES['ADMINISTRATOR']['id']])->cache('uid-1');

            $this->assertSame([self::OTHER], array_keys(GetUserSlices(PS_EDIT_SELF_ITEMS)));
            $this->assertSame([self::MODULE, self::OTHER], array_keys(GetUserSlices()), 'the module switcher still offers both');
            $this->assertTrue(CheckPerms('uid-1', 'slice', self::MODULE, PS_ALERTS_STATS));
            $this->assertFalse(CheckPerms('uid-1', 'slice', self::MODULE, PS_EDIT_ALL_ITEMS));
        } finally {
            foreach ($saved as $key => $value) {
                $GLOBALS[$key] = $value;
            }
        }
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
            '~if \(!AA_Module_Alerts::getOwnModule\(AA::\$module_id\)\) \{\s*MsgPage\([^;]*\);\s*\}\s*if \(!IfSlPerm\(PS_ALERTS_STATS\)\) \{\s*MsgPage\([^;]*\);\s*\}~',
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
