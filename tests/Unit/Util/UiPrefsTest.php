<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use AA\Util\UiPrefs;
use PHPUnit\Framework\TestCase;

// Constants normally provided by config.php. The DB-backed methods
// (saveTheme/saveLang/seedCookiesFromProfile) are intentionally NOT tested
// here - only the pure resolvers, which never touch the DB.
if (!defined('ADMIN_CSS')) {
    define('ADMIN_CSS', 'admin-ecn.css');
}
if (!defined('DEFAULT_LANG_INCLUDE')) {
    define('DEFAULT_LANG_INCLUDE', 'en-utf8_news_lang.php');
}

/**
 * @covers \AA\Util\UiPrefs
 */
final class UiPrefsTest extends TestCase
{
    private array $cookieBackup;

    protected function setUp(): void
    {
        $this->cookieBackup = $_COOKIE;
        unset($_COOKIE[UiPrefs::COOKIE_THEME], $_COOKIE[UiPrefs::COOKIE_LANG]);
    }

    protected function tearDown(): void
    {
        $_COOKIE = $this->cookieBackup;
    }

    /* ---- theme ---------------------------------------------------------- */

    public function testThemeOverlayBaselineWhenNoCookie(): void
    {
        // ADMIN_CSS is the baseline in tests -> no overlay
        $this->assertSame('', UiPrefs::themeOverlay());
        $this->assertSame('default', UiPrefs::themeKey());
    }

    public function testThemeOverlayGoldFromCookie(): void
    {
        $_COOKIE[UiPrefs::COOKIE_THEME] = 'gold';
        $this->assertSame('admin-gold.css', UiPrefs::themeOverlay());
        $this->assertSame('gold', UiPrefs::themeKey());
    }

    public function testThemeOverlayDefaultKeySuppressesOverlay(): void
    {
        $_COOKIE[UiPrefs::COOKIE_THEME] = 'default';
        $this->assertSame('', UiPrefs::themeOverlay());
        $this->assertSame('default', UiPrefs::themeKey());
    }

    public function testThemeOverlayIgnoresTamperedCookie(): void
    {
        // a filename / path-injection attempt must never reach the <link href>
        $_COOKIE[UiPrefs::COOKIE_THEME] = '../../../etc/passwd';
        $this->assertSame('', UiPrefs::themeOverlay());
        $this->assertSame('default', UiPrefs::themeKey());
    }

    public function testThemesMap(): void
    {
        $this->assertSame(
            ['default' => 'admin-ecn.css', 'gold' => 'admin-gold.css'],
            UiPrefs::themes(),
        );
    }

    /* ---- language ------------------------------------------------------- */

    public function testAvailableLangsExcludesLegacySingleByte(): void
    {
        $langs = UiPrefs::availableLangs();
        $this->assertArrayHasKey('cz-utf8', $langs);
        $this->assertArrayHasKey('en-utf8', $langs);
        $this->assertArrayHasKey('vn', $langs);          // utf-8, no -utf8 suffix
        $this->assertArrayNotHasKey('cz', $langs);       // windows-1250
        $this->assertArrayNotHasKey('en', $langs);       // iso-8859-1
        $this->assertArrayNotHasKey('ja', $langs);       // EUC-JP
    }

    public function testLangKeyEmptyWhenNoCookie(): void
    {
        $this->assertSame('', UiPrefs::langKey());
    }

    public function testLangKeyRejectsLegacyAndInvalid(): void
    {
        $_COOKIE[UiPrefs::COOKIE_LANG] = 'cz';           // legacy single-byte -> not offered
        $this->assertSame('', UiPrefs::langKey());

        $_COOKIE[UiPrefs::COOKIE_LANG] = 'xx-utf8';      // not installed
        $this->assertSame('', UiPrefs::langKey());
    }

    public function testLangOverrideKeepsSectionAndSwapsLanguage(): void
    {
        $_COOKIE[UiPrefs::COOKIE_LANG] = 'cz-utf8';
        // language prefix swapped, module's section ('news' / 'site') preserved
        $this->assertSame('cz-utf8_news_lang.php', UiPrefs::langOverrideFile('en-utf8_news_lang.php'));
        $this->assertSame('cz-utf8_site_lang.php', UiPrefs::langOverrideFile('en-utf8_site_lang.php'));
    }

    public function testLangOverrideToGerman(): void
    {
        $_COOKIE[UiPrefs::COOKIE_LANG] = 'de-utf8';
        $this->assertSame('de-utf8_site_lang.php', UiPrefs::langOverrideFile('cz-utf8_site_lang.php'));
    }

    public function testLangOverrideFallsBackWhenSectionFileMissing(): void
    {
        $_COOKIE[UiPrefs::COOKIE_LANG] = 'cz-utf8';
        // no cz-utf8 file for a fabricated section -> keep the module's own file
        $module = 'en-utf8_nonexistentsection_lang.php';
        $this->assertSame($module, UiPrefs::langOverrideFile($module));
    }

    public function testLangOverrideNoCookieReturnsModuleFile(): void
    {
        $this->assertSame('en-utf8_news_lang.php', UiPrefs::langOverrideFile('en-utf8_news_lang.php'));
    }

    /* ---- form token ----------------------------------------------------- */

    public function testFormTokenIsStableForTheSameSession(): void
    {
        $this->assertSame(UiPrefs::formToken('sid-alpha'), UiPrefs::formToken('sid-alpha'));
    }

    public function testFormTokenDiffersPerSession(): void
    {
        $this->assertNotSame(UiPrefs::formToken('sid-alpha'), UiPrefs::formToken('sid-beta'));
    }

    public function testFormTokenNeverLeaksTheSessionId(): void
    {
        $token = UiPrefs::formToken('sid-alpha');
        $this->assertNotSame('', $token);
        $this->assertStringNotContainsString('sid-alpha', $token);
    }

    public function testFormTokenEmptyWithoutSession(): void
    {
        // no session id -> empty token, so the endpoint check fails closed
        $this->assertSame('', UiPrefs::formToken(''));
    }
}
