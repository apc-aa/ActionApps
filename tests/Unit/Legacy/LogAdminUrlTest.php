<?php

declare(strict_types=1);

// store_fixtures loads the real locsess.php (get_admin_url(), safe()), which needs the constants include/config.php normally provides.

namespace {
    foreach ([
        'AA_INSTAL_URL'   => 'http://localhost/aa/',
        'AA_INSTAL_PATH'  => '/aa/',
        'IMG_UPLOAD_PATH' => sys_get_temp_dir() . '/',
        'AA_BASE_PATH'    => dirname(__DIR__, 3) . '/',
        'PERM_LIB'        => 'sql',
        'DB_HOST'         => 'localhost',
        'DB_NAME'         => 'aa_test',
        'DB_USER'         => 'aa',
        'DB_PASSWORD'     => '',
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
}

namespace Tests\Unit\Legacy {

    use AA_Content;
    use AA_Log;
    use PHPUnit\Framework\TestCase;

    /**
     * The Log page links and cells get logged values - a selector or params a request could shape
     * (a function name from item data, a user id): AA_Log::getAdminUrl() encodes the selector into the
     * URL, and {internal:AA_Log:print|context} (admin/aa_log.php) escapes a value that is not an
     * AA_Logentry instead of printing it raw.
     */
    final class LogAdminUrlTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        public function test_the_selector_is_url_encoded(): void
        {
            $url = AA_Log::getAdminUrl('ALIAS_FU', 'a"b&srchbr_akce=0<x> y');

            $this->assertStringContainsString('=a%22b%26srchbr_akce%3D0%3Cx%3E%20y&', $url);
            $this->assertStringNotContainsString('"', $url);
            $this->assertStringNotContainsString('<', $url);
        }

        /** AA_Plannedtask's manager row links with the _#AA_ID___ alias - it is substituted after the URL is built */
        public function test_an_aa_alias_in_the_selector_survives(): void
        {
            $this->assertStringContainsString('=AA_Plannedtask%3A_#AA_ID___&', AA_Log::getAdminUrl('', 'AA_Plannedtask:_#AA_ID___', 'RLIKE'));
        }

        public function test_plain_params_and_selector_are_escaped_on_the_log_page(): void
        {
            $content = new AA_Content();
            $content->setValue('params', '<script>x</script>');
            $content->setValue('selector', '<img src=x onerror=alert(1)>');

            $this->assertSame('&lt;script&gt;x&lt;/script&gt;', AA_Log::internal_expand($content, 'print'));
            $this->assertSame('&lt;img src=x onerror=alert(1)&gt;', AA_Log::internal_expand($content, 'context'));
        }
    }
}
