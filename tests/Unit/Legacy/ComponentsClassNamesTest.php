<?php

declare(strict_types=1);

/**
 * AA_Components::getClassNames() finds plugin classes by directory. It built the directory from
 * the namespace mask without turning "\" into "/", so a nested namespace (AA\IO\Grabber\...)
 * resolved to the non-existent "src/IO\Grabber": a foreach(false) warning and no classes.
 */

namespace {
    // AA_Components pulls include/constants.php, which needs these config.php constants.
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

    use AA_Components;
    use PHPUnit\Framework\TestCase;

    final class ComponentsClassNamesTest extends TestCase
    {
        /** characterization - passes before and after the fix */
        public function test_getClassNames_singleLevelNamespace_mapsShortcutToClass(): void
        {
            $classes = AA_Components::getClassNames('AA\\Widget\\Widget');

            self::assertSame('AA\\Widget\\FldWidget', $classes['fld'] ?? null);
            self::assertArrayNotHasKey('abstract', $classes);
        }

        public function test_getClassNames_nestedNamespace_findsClasses(): void
        {
            $classes = AA_Components::getClassNames('AA\\IO\\Grabber\\AbstractGrabber');

            self::assertSame('AA\\IO\\Grabber\\CSV', $classes['csv'] ?? null);
        }

        public function test_getClassNames_missingDirectory_returnsEmptyArrayWithoutForeachWarning(): void
        {
            $warnings = [];
            set_error_handler(static function (int $no, string $str) use (&$warnings): bool {
                $warnings[] = $str;
                return true;
            });
            try {
                $classes = AA_Components::getClassNames('AA\\NoSuchDir\\Nothing\\');
            } finally {
                restore_error_handler();
            }

            self::assertSame([], $classes);
            self::assertSame([], array_values(array_filter($warnings, static fn(string $w): bool => str_contains($w, 'foreach'))));
        }
    }
}
