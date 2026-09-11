<?php

declare(strict_types=1);

// AA_Property lives in include/widget.class.php, whose require chain reaches include/locsess.php
// and needs the constants include/config.php normally provides. Safe test values, defined
// before the autoloader resolves the first legacy class (same block as ItemStoreFieldsTest).

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

    use AA\Widget\SelWidget;
    use AA_Modulesettings_Site;
    use AA_Property;
    use AA_Validate_Enum;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /**
     * The enum of an AA_Property (include/widget.class.php) - the ['enum', values] validator
     * that feeds the select box and the AA_Validate_Enum check.
     *
     * Pins the array form as it always worked, and the lazy form (T4a): a Closure in place
     * of the values is called on first use - getConstants() or getValidator() - never when
     * the property list is merely built. AA_Modulesettings_Site puts
     * AA_Module::getUserModules() behind such closures, so loading a site's settings on a
     * public page (AA_Object::load() builds the property list twice) no longer runs the
     * module queries and the per-module permission checks of a form nobody renders.
     */
    final class PropertyLazyEnumTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
        }

        /** @param mixed $enum */
        private static function property($enum, bool $multi = false): AA_Property
        {
            return new AA_Property('color', 'Color', 'string', $multi, true, ['enum', $enum]);
        }

        /** @return array{AA_Property, callable(): int} the property and a call counter of its enum closure */
        private static function lazyProperty(array $values, bool $multi = false): array
        {
            $calls    = 0;
            $property = self::property(static function () use ($values, &$calls): array {
                $calls++;
                return $values;
            }, $multi);
            return [$property, static function () use (&$calls): int {
                return $calls;
            }];
        }

        public function testArrayEnumIsAvailableAtOnce(): void
        {
            $property = self::property(['r' => 'Red', 'g' => 'Green']);

            self::assertSame(['r' => 'Red', 'g' => 'Green'], $property->getConstants());
            self::assertSame(['r' => 'Red', 'g' => 'Green'], $property->const_arr, 'public field filled at construction');
            $validator = $property->getValidator();
            self::assertInstanceOf(AA_Validate_Enum::class, $validator);
            $value = 'g';
            self::assertTrue($validator->validate($value), 'a listed value passes');
            $value = 'x';
            self::assertFalse($validator->validate($value), 'an unlisted one does not');
            $value = null;
            self::assertFalse($validator->validate($value));
        }

        public function testClosureEnumIsNotComputedWhenThePropertyIsBuilt(): void
        {
            [$property, $calls] = self::lazyProperty(['a' => 'A', 'b' => 'B']);

            self::assertSame(0, $calls(), 'building the property must not evaluate the enum');
            self::assertSame(['a' => 'A', 'b' => 'B'], $property->getConstants());
            self::assertSame(['a' => 'A', 'b' => 'B'], $property->getConstants());
            self::assertSame(1, $calls(), 'evaluated once, on first use');
        }

        public function testClosureEnumFeedsTheValidator(): void
        {
            [$property, $calls] = self::lazyProperty(['a' => 'A', 'b' => 'B']);

            $validator = $property->getValidator();
            self::assertInstanceOf(AA_Validate_Enum::class, $validator);
            self::assertSame(1, $calls());
            $value = 'a';
            self::assertTrue($validator->validate($value));
            $value = 'zz';
            self::assertFalse($validator->validate($value));
            self::assertSame(['a' => 'A', 'b' => 'B'], $property->getConstants());
            self::assertSame(1, $calls(), 'the validator and the constants share one evaluation');
        }

        public function testSetConstantsReplacesAnUnusedClosure(): void
        {
            [$property, $calls] = self::lazyProperty(['a' => 'A']);

            $property->setConstants(['x' => 'X']);

            self::assertSame(['x' => 'X'], $property->getConstants());
            self::assertInstanceOf(AA_Validate_Enum::class, $property->getValidator());
            $value = 'x';
            self::assertTrue($property->getValidator()->validate($value));
            $value = 'a';
            self::assertFalse($property->getValidator()->validate($value), 'the closure values are not the enum any more');
            self::assertSame(0, $calls(), 'an explicitly set enum wins, the closure is dropped');
        }

        public function testEnumWithNumericValuesValidatesByValueNotByPosition(): void
        {
            $property = self::property([5 => 'five', 7 => 'seven']);
            $validator = $property->getValidator();

            $value = '7';
            self::assertTrue($validator->validate($value));
            $value = '1';
            self::assertFalse($validator->validate($value), 'a position in the list is not a value');
        }

        public function testDefaultWidgetGetsTheResolvedValues(): void
        {
            [$property, $calls] = self::lazyProperty(['a' => 'A', 'b' => 'B']);

            $widget = $property->defaultWidget();

            self::assertInstanceOf(SelWidget::class, $widget);
            self::assertSame(['a' => 'A', 'b' => 'B'], $widget->getProperty('const_arr'));
            self::assertSame(1, $calls());
        }

        public function testToValueValidatesAgainstTheResolvedEnum(): void
        {
            [$property, $calls] = self::lazyProperty(['a' => 'A', 'b' => 'B'], true);

            self::assertSame(['a', 'b'], $property->toValue(new \AA_Value(['a', 'b'])));
            self::assertSame(1, $calls());
        }

        public function testSerializingResolvesTheClosureAndLeavesItOut(): void
        {
            [$property, $calls] = self::lazyProperty(['a' => 'A']);

            $copy = unserialize(serialize($property));

            self::assertSame(1, $calls(), 'resolved once, at serialize time');
            self::assertInstanceOf(AA_Property::class, $copy);
            self::assertSame(['a' => 'A'], $copy->getConstants());
            self::assertInstanceOf(AA_Validate_Enum::class, $copy->getValidator());
            self::assertSame(1, $calls(), 'the copy carries the values, not the closure');
        }

        public function testSiteSettingsPropertyListIsBuiltWithoutTouchingTheDatabase(): void
        {
            $props = AA_Modulesettings_Site::getClassProperties();

            self::assertArrayHasKey('translate_slice', $props);
            self::assertArrayHasKey('add_aliases', $props);
            self::assertSame([], RecordingDb::$log, 'no SQL while the property list is built - the module enums are lazy');
        }
    }
}
