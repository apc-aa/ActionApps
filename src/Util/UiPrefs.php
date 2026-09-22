<?php

declare(strict_types=1);

namespace AA\Util;

use AA_Profile;

/**
 * Per-user admin UI preferences: theme skin + interface language.
 *
 * Persistence is two-layered:
 *   - a server-readable cookie (AA_admin_theme / AA_ui_lang) drives the very
 *     first paint with no flash and works on the login page before auth;
 *   - the `profile` table scoped to the whole-install id AA_ID is the
 *     cross-device source of truth, seeded into the cookie on a fresh browser
 *     by {@see self::seedCookiesFromProfile()}.
 *
 * Security: the theme cookie carries an allowlist KEY (e.g. "gold"), never a
 * filename. The CSS filename only ever comes from self::THEMES, so a tampered
 * cookie can never inject an arbitrary <link href>. The language cookie is
 * validated against the installed UTF-8 languages AND the file's existence.
 */
class UiPrefs
{
    /** theme key => admin CSS overlay filename. 'default' = baseline only (no overlay). */
    private const THEMES = [
        'default' => 'admin-ecn.css',
        'gold'    => 'admin-gold.css',
    ];

    /** cookie names (already include the AA_ prefix used across the codebase). */
    public const COOKIE_THEME = 'AA_admin_theme';
    public const COOKIE_LANG  = 'AA_ui_lang';

    /** profile property names under the AA_ID-scoped profile (selector '0'). */
    private const PROP_THEME = 'ui_theme';
    private const PROP_LANG  = 'ui_lang';

    /** cookie lifetime - ~1 year */
    private const COOKIE_TTL = 31536000;

    /* ---- theme ---------------------------------------------------------- */

    /** theme key => overlay filename map (for building the switcher UI). */
    public static function themes(): array
    {
        return self::THEMES;
    }

    /** human label for a theme key (translatable). */
    public static function themeLabel(string $key): string
    {
        return match ($key) {
            'gold'  => _m('Gold'),
            default => _m('Default'),
        };
    }

    /** Currently selected theme key (for preselecting the switcher).
     *  Cookie override wins; otherwise reflect the server-wide ADMIN_CSS default.
     */
    public static function themeKey(): string
    {
        $key = (string) ($_COOKIE[self::COOKIE_THEME] ?? '');
        if (isset(self::THEMES[$key])) {
            return $key;
        }
        $admin_css = defined('ADMIN_CSS') ? ADMIN_CSS : 'admin-ecn.css';
        $found     = array_search($admin_css, self::THEMES, true);
        return ($found !== false) ? (string) $found : 'default';
    }

    /** The overlay CSS to load AFTER the admin-ecn.css baseline, or '' for none.
     *  A valid cookie overrides; otherwise the server-wide ADMIN_CSS default is
     *  preserved unchanged (so admin.css / admin-cml.css installs keep working).
     */
    public static function themeOverlay(): string
    {
        $key = (string) ($_COOKIE[self::COOKIE_THEME] ?? '');
        if (isset(self::THEMES[$key])) {
            $css = self::THEMES[$key];                                       // allowlisted override
        } else {
            $css = defined('ADMIN_CSS') ? ADMIN_CSS : 'admin-ecn.css';       // server default
        }
        return ($css === 'admin-ecn.css') ? '' : $css;                       // baseline is never an overlay
    }

    /* ---- language ------------------------------------------------------- */

    /** Installed UTF-8 languages as code => display name (legacy single-byte
     *  excluded; the redundant "(Unicode)" suffix is trimmed for the switcher).
     */
    public static function availableLangs(): array
    {
        $out = [];
        foreach (Langs::getNames() as $key => $name) {
            if (Langs::getCharset($key) === 'utf-8') {
                $out[$key] = trim(str_replace('(Unicode)', '', $name));
            }
        }
        return $out;
    }

    /** Currently selected language override code (e.g. 'cz-utf8'), or '' = module default. */
    public static function langKey(): string
    {
        $code = (string) ($_COOKIE[self::COOKIE_LANG] ?? '');
        return isset(self::availableLangs()[$code]) ? $code : '';
    }

    /** Given the module's lang file, return the user-overridden one (same section,
     *  swapped language) or the module file unchanged when there is no valid override.
     */
    public static function langOverrideFile(string $module_lang_file): string
    {
        $code = self::langKey();
        if ($code === '') {
            return $module_lang_file;
        }
        $section   = self::sectionOf($module_lang_file !== '' ? $module_lang_file : DEFAULT_LANG_INCLUDE);
        $candidate = $code . '_' . $section . '_lang.php';
        return is_file(__DIR__ . '/../../include/lang/' . $candidate) ? $candidate : $module_lang_file;
    }

    /** Extract the section ('news', 'site', ...) from a {lang}_{section}_lang.php name. */
    private static function sectionOf(string $lang_file): string
    {
        if (preg_match('/^[^_]+_(.+)_lang\.php$/', basename($lang_file), $m)) {
            return $m[1];
        }
        return 'news';
    }

    /* ---- CSRF token for the prefs popover forms ------------------------- */

    /** Token protecting the admin/aa_uiprefs.php posts (theme / language / feedback).
     *
     *  Derived from the session id instead of being stored in the session -
     *  stateless, nothing to lose or clean up.
     *  The session id is httponly and same-origin, so a cross-site attacker can
     *  neither read it nor derive the token; the hash never exposes it.
     *
     *  @param  string $session_id explicit id (tests); '' = the current session
     *  @return string '' when there is no session - callers must fail closed
     */
    public static function formToken(string $session_id = ''): string
    {
        $sid = ($session_id !== '') ? $session_id : (string) session_id();
        return ($sid === '') ? '' : hash_hmac('sha256', 'aa_uiprefs', $sid);
    }

    /* ---- persistence (cookie + AA_ID-scoped profile) -------------------- */

    /** Save the theme choice. Both 'default' and 'gold' are stored EXPLICITLY
     *  (so "Default" pins the classic skin even where the server-wide ADMIN_CSS
     *  default is gold); an unknown key clears the override.
     */
    public static function saveTheme(string $uid, string $key): void
    {
        if (isset(self::THEMES[$key])) {
            self::writePref($uid, self::PROP_THEME, self::COOKIE_THEME, $key);
        } else {
            self::clearPref($uid, self::PROP_THEME, self::COOKIE_THEME);
        }
    }

    /** Save the language choice. '' (or an unknown code) clears the override. */
    public static function saveLang(string $uid, string $code): void
    {
        if ($code !== '' && isset(self::availableLangs()[$code])) {
            self::writePref($uid, self::PROP_LANG, self::COOKIE_LANG, $code);
        } else {
            self::clearPref($uid, self::PROP_LANG, self::COOKIE_LANG);
        }
    }

    /** Set ONLY the theme cookie (no profile write) - for the login page, where
     *  there is no authenticated user yet. Validates against the allowlist;
     *  both 'default' and 'gold' are stored explicitly, an unknown key is ignored.
     */
    public static function setThemeCookie(string $key): void
    {
        if (isset(self::THEMES[$key])) {
            self::setCookie(self::COOKIE_THEME, $key);
        }
    }

    /** On a fresh browser (cookie absent) seed the cookie from the stored profile,
     *  so the preference still drives this request and persists going forward.
     */
    public static function seedCookiesFromProfile(string $uid): void
    {
        if ($uid === '') {
            return;
        }
        if (!isset($_COOKIE[self::COOKIE_THEME])) {
            $theme = self::profile($uid)->getProperty(self::PROP_THEME);
            if ($theme !== false && isset(self::THEMES[(string) $theme])) {
                self::setCookie(self::COOKIE_THEME, (string) $theme);
            }
        }
        if (!isset($_COOKIE[self::COOKIE_LANG])) {
            $lang = self::profile($uid)->getProperty(self::PROP_LANG);
            if ($lang !== false && isset(self::availableLangs()[(string) $lang])) {
                self::setCookie(self::COOKIE_LANG, (string) $lang);
            }
        }
    }

    private static function profile(string $uid): AA_Profile
    {
        return AA_Profile::getProfile($uid, AA_ID);
    }

    private static function writePref(string $uid, string $prop, string $cookie, string $value): void
    {
        $profile = self::profile($uid);
        $profile->deleteProperty($prop);
        $profile->insertProperty($prop, '0', $value);
        self::setCookie($cookie, $value);
    }

    private static function clearPref(string $uid, string $prop, string $cookie): void
    {
        self::profile($uid)->deleteProperty($prop);
        self::expireCookie($cookie);
        unset($_COOKIE[$cookie]);
    }

    private static function setCookie(string $name, string $value): void
    {
        $_COOKIE[$name] = $value;                       // visible to the rest of this request
        if (!headers_sent()) {
            setcookie($name, $value, [
                'expires'  => time() + self::COOKIE_TTL,
                'path'     => '/',
                'samesite' => 'Lax',
                'secure'   => !empty($_SERVER['HTTPS']),
                'httponly' => false,                    // theme is read/set by client JS for instant preview
            ]);
        }
    }

    private static function expireCookie(string $name): void
    {
        if (!headers_sent()) {
            setcookie($name, '', ['expires' => time() - 3600, 'path' => '/', 'samesite' => 'Lax']);
        }
    }
}
