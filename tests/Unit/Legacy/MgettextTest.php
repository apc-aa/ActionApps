<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for the mini-gettext runtime in include/mgettext.php:
 * _m() lookup/fallback/substitution and bind_mgettext_domain() loading.
 * They pin current behaviour before the lang-file format change.
 */
final class MgettextTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('DEFAULT_LANG_INCLUDE')) {
            define('DEFAULT_LANG_INCLUDE', 'en-utf8_news_lang.php');
        }
        require_once __DIR__ . '/../../../include/mgettext.php';
    }

    /** the translation globals before the test - a bound Czech file would otherwise leak into later tests */
    private array $saved_globals = [];

    protected function setUp(): void
    {
        foreach (['_m', 'mgettext_lang', 'mgettext_domain'] as $name) {
            $this->saved_globals[$name] = $GLOBALS[$name] ?? null;
        }
        $GLOBALS['_m'] = [];
        $GLOBALS['mgettext_lang'] = null;
        $GLOBALS['mgettext_domain'] = null;
    }

    protected function tearDown(): void
    {
        foreach ($this->saved_globals as $name => $value) {
            $GLOBALS[$name] = $value;
        }
    }

    // --- _m(): lookup and fallback ---

    public function test_empty_id_returns_empty_string(): void
    {
        $this->assertSame('', _m(''));
    }

    public function test_returns_translation_when_present(): void
    {
        $GLOBALS['_m'] = ['Hello' => 'Ahoj'];
        $this->assertSame('Ahoj', _m('Hello'));
    }

    public function test_falls_back_to_id_when_no_lang_file_loaded(): void
    {
        $GLOBALS['_m'] = [];
        $this->assertSame('Hello', _m('Hello'));
    }

    public function test_falls_back_to_id_when_key_missing(): void
    {
        $GLOBALS['_m'] = ['Other' => 'Jiné'];
        $this->assertSame('Hello', _m('Hello'));
    }

    public function test_falls_back_to_id_when_translation_empty(): void
    {
        $GLOBALS['_m'] = ['Hello' => ''];
        $this->assertSame('Hello', _m('Hello'));
    }

    // --- _m(): %N parameter substitution ---

    public function test_substitutes_positional_params(): void
    {
        $GLOBALS['_m'] = ['Hello %1, you have %2 items' => 'Ahoj %1, máš %2 zpráv'];
        $this->assertSame('Ahoj Jan, máš 5 zpráv', _m('Hello %1, you have %2 items', ['Jan', 5]));
    }

    public function test_substitutes_params_in_fallback_too(): void
    {
        $GLOBALS['_m'] = [];
        $this->assertSame('Hello Jan', _m('Hello %1', ['Jan']));
    }

    public function test_escaped_percent_is_preserved(): void
    {
        $GLOBALS['_m'] = [];
        $this->assertSame('100% of 7', _m('100\% of %1', [7]));
    }

    public function test_no_params_leaves_placeholders_untouched(): void
    {
        $GLOBALS['_m'] = [];
        $this->assertSame('Hello %1', _m('Hello %1'));
    }

    public function test_mdelayed_behaves_like_m(): void
    {
        $GLOBALS['_m'] = ['Hi %1' => 'Nazdar %1'];
        $this->assertSame(_m('Hi %1', ['X']), _mdelayed('Hi %1', ['X']));
    }

    // --- bind_mgettext_domain(): loading real lang files ---

    public function test_bind_loads_lang_file_into_m_array(): void
    {
        bind_mgettext_domain('en-utf8_news_lang.php', false, 'en-utf8');

        $this->assertIsArray($GLOBALS['_m']);
        $this->assertNotEmpty($GLOBALS['_m']);
        $this->assertSame('en-utf8', $GLOBALS['mgettext_lang']);
        // stable key present in the news section since 2008
        $this->assertArrayHasKey('Either your username or your password is not valid', $GLOBALS['_m']);
    }

    public function test_mgettext_bind_composes_filename(): void
    {
        mgettext_bind('en-utf8', 'news');

        $this->assertStringEndsWith('en-utf8_news_lang.php', (string) $GLOBALS['mgettext_domain']);
        $this->assertNotEmpty($GLOBALS['_m']);
    }

    public function test_bind_accepts_legacy_php3_extension(): void
    {
        bind_mgettext_domain('en-utf8_news_lang.php3', false, 'en-utf8');

        $this->assertStringEndsWith('en-utf8_news_lang.php', (string) $GLOBALS['mgettext_domain']);
        $this->assertNotEmpty($GLOBALS['_m']);
    }

    public function test_bind_same_domain_twice_does_not_reload(): void
    {
        bind_mgettext_domain('en-utf8_news_lang.php', false, 'en-utf8');
        $GLOBALS['_m'] = ['sentinel' => 'kept'];

        bind_mgettext_domain('en-utf8_news_lang.php', false, 'en-utf8');

        $this->assertSame(['sentinel' => 'kept'], $GLOBALS['_m']);
    }

    public function test_bind_missing_file_reports_wrong_domain(): void
    {
        $this->expectOutputRegex('/WRONG MGETTEXT DOMAIN/');
        bind_mgettext_domain('xx-nonexistent_lang.php', false, 'xx');
    }

    public function test_bind_switching_lang_replaces_translations(): void
    {
        bind_mgettext_domain('en-utf8_news_lang.php', false, 'en-utf8');
        bind_mgettext_domain('cz-utf8_news_lang.php', false, 'cz-utf8');

        $this->assertSame('cz-utf8', $GLOBALS['mgettext_lang']);
        $this->assertSame(
            'Buď jméno nebo heslo nejsou v pořádku',
            $GLOBALS['_m']['Either your username or your password is not valid'] ?? null,
        );
    }
}
