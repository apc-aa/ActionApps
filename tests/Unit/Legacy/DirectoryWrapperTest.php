<?php

declare(strict_types=1);

/**
 * AA_Directory_Wrapper::wrapper() read the URL scheme as @$info['scheme']. A local path has no
 * scheme, so every call raised "Undefined array key" and only the @ hid it - error handlers that
 * do not honour @ (debug handlers, loggers) still got it, on each plugin lookup.
 */

namespace {
    // include/files.class.php is reached through legacy code that needs these config.php constants.
    foreach ([
        'AA_INSTAL_URL'   => 'http://localhost/aa/',
        'AA_INSTAL_PATH'  => '/aa/',
        'IMG_UPLOAD_PATH' => sys_get_temp_dir() . '/',
        'AA_BASE_PATH'    => dirname(__DIR__, 3) . '/',
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
}

namespace Tests\Unit\Legacy {

    use AA_Directory_Wrapper;
    use PHPUnit\Framework\TestCase;

    final class DirectoryWrapperTest extends TestCase
    {
        /** characterization - passes before and after the fix */
        public function test_getFileNames_localDirectory_listsItsFiles(): void
        {
            $files = AA_Directory_Wrapper::wrapper(dirname(__DIR__, 3) . '/src/Api')->getFileNames();

            self::assertContains('Router.php', $files);
        }

        public function test_wrapper_localPath_raisesNothingEvenForHandlersIgnoringTheAtOperator(): void
        {
            $messages = [];
            set_error_handler(static function (int $no, string $str) use (&$messages): bool {
                $messages[] = $str;   // deliberately no error_reporting() check - @ does not hide it from us
                return true;
            });
            try {
                $wrapper = AA_Directory_Wrapper::wrapper(dirname(__DIR__, 3) . '/src/Api');
            } finally {
                restore_error_handler();
            }

            self::assertInstanceOf(AA_Directory_Wrapper::class, $wrapper);
            self::assertSame([], $messages);
        }
    }
}
