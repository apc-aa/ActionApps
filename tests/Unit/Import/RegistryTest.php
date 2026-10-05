<?php

declare(strict_types=1);

namespace {
    // AA_Components (include/statestore.php) pulls in include/constants.php, which needs these config.php constants
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

namespace Tests\Unit\Import {

    use AA\Import\AbstractSource;
    use AA\Import\Registry;
    use AA\Import\SourceInterface;
    use AA\IO\SourceRefused;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    trait RegistryTestTrait {}

    /**
     * Adding a source is adding a file, so what matters is a file that is not a source: it must
     * not break the page, it must show up in rejected() instead. Only the custom part is compared,
     * so the tests hold once core sources exist.
     */
    final class RegistryTest extends TestCase
    {
        private const FIXTURES = __DIR__ . '/fixtures/sources';

        /** holds the directories a test makes, removed afterwards */
        private string $tmpRoot = '';
        protected function tearDown(): void
        {
            if ($this->tmpRoot !== '') {
                foreach (scandir($this->tmpRoot) ?: [] as $dir) {
                    if ($dir !== '.' && $dir !== '..') {
                        array_map('unlink', glob("$this->tmpRoot/$dir/*") ?: []);
                        rmdir("$this->tmpRoot/$dir");
                    }
                }
                rmdir($this->tmpRoot);
            }
            self::root(null);   // last, so a broken Registry does not leave the directories behind
        }

        public function test_sources_missingDirAddsNoCustom(): void
        {
            $dir = $this->tmpDir() . '/no-such-dir';

            self::assertSame([], self::custom(Registry::sources($dir)));
            self::assertArrayNotHasKey($dir, Registry::rejected($dir));
        }

        public function test_sources_nullDirWithoutConstantIsCoreOnly(): void
        {
            self::assertFalse(defined('AA_CUSTOM_DIR'), 'the test process has no installation directory');
            self::assertSame([], self::custom(Registry::sources()));
        }

        public function test_sources_defaultDirOfTestsReadWithoutDir(): void
        {
            Registry::setCustomRootForTests(dirname(self::FIXTURES), self::FIXTURES);

            self::assertSame(['custom:good' => \GoodSource::class], self::custom(Registry::sources()));
            self::assertSame(\GoodSource::class, get_class(Registry::create('custom:good', [])));

            Registry::setCustomRootForTests(null, self::FIXTURES);
            self::assertSame([], self::custom(Registry::sources()), 'no root, no default directory');
        }

        public function test_sources_customDirFound(): void
        {
            self::assertSame(['custom:good' => \GoodSource::class], self::custom(Registry::sources($this->fixtures())));
        }

        public function test_sources_dirOutsideRootIsRejected(): void
        {
            $dir = $this->fixtures();
            self::root($this->tmpDir());

            self::assertSame([], self::custom(Registry::sources($dir)));
            self::assertStringContainsString('outside include/custom', Registry::rejected($dir)[$dir] ?? '');
        }

        public function test_sources_skipsAbstract(): void
        {
            $sources  = Registry::sources($this->fixtures());
            $rejected = Registry::rejected($this->fixtures());

            self::assertTrue(class_exists(\AbstractSkippedSource::class, false), 'a base is loaded for the sources that extend it');
            self::assertArrayNotHasKey('custom:abstractskipped', $sources);
            self::assertArrayNotHasKey(\AbstractSkippedSource::class, $rejected);
        }

        public function test_sources_loadsBasesFirst(): void
        {
            $dir = $this->tmpDir();
            // ARESSource.php sorts before AbstractBaseSource.php, yet needs it declared
            file_put_contents("$dir/ARESSource.php", self::sourceCode('ARESSource', 'extends \\AbstractBaseSource'));
            file_put_contents("$dir/AbstractBaseSource.php", "<?php\nabstract class AbstractBaseSource extends \\AA\\Import\\AbstractSource {}\n");

            self::assertSame(['custom:ares' => 'ARESSource'], self::custom(Registry::sources($dir)));
        }

        public function test_sources_sameTypeTwiceIsRejected(): void
        {
            $dir = $this->tmpDir();
            file_put_contents("$dir/CSUSource.php", self::sourceCode('CSUSource'));
            file_put_contents("$dir/CsuSource.php", self::sourceCode('CsuSource'));
            if (count(glob("$dir/*") ?: []) < 2) {
                self::markTestSkipped('case-insensitive file system');
            }

            self::assertSame(['custom:csu' => 'CSUSource'], self::custom(Registry::sources($dir)));
            self::assertSame('same type as CSUSource.php', Registry::rejected($dir)['CsuSource.php'] ?? '');
        }

        public function test_sources_acceptsWhatOnlyLooksLikeADeclaration(): void
        {
            $dir = $this->tmpDir();
            file_put_contents("$dir/TrickySource.php", "<?php\nuse function strlen as tricky_len;\n" . substr(self::sourceCode('TrickySource', more: <<<'PHP'
                public function helper(): array
                {
                    $f = function () { return self::class; };
                    $g = fn() => static::class;
                    $o = new class { public function inner(): string { return "{$this->x()}"; } private function x(): string { return ''; } };
                    $x = 'a';
                    $p = new class ("{$x}") { public function __construct(public string $s) {} public function m(): void {} };
                    $q = new class (new class {}) { public function __construct(public object $o) {} public function m(): void {} };
                    return [$f, $g, $o, $p, $q];
                }
                PHP), 6));

            self::assertSame(['custom:tricky' => 'TrickySource'], self::custom(Registry::sources($dir)));
        }

        public function test_rejected_listsNonSourceAndWrongName(): void
        {
            $rejected = self::customRejected(Registry::rejected($this->fixtures()));

            self::assertSame(['WrongNameSource.php', 'NotASource'], array_keys($rejected), 'the wrong name is found before loading');
            self::assertStringContainsString(SourceInterface::class, $rejected['NotASource']);
            self::assertStringContainsString('SomeOtherName', $rejected['WrongNameSource.php']);
            self::assertFalse(class_exists('SomeOtherName', false));
        }

        /** complete, so it loads - yet the next method added to the contract would make it a fatal error */
        public function test_rejected_sourceNotExtendingAbstractSource(): void
        {
            $dir = $this->tmpDir();
            file_put_contents("$dir/DirectSource.php", self::sourceCode('DirectSource', 'implements \\AA\\Import\\SourceInterface', <<<'PHP'
                public static function credentials(): array { return []; }
                public function minJobSeconds(): int { return 15; }
                public function defaultMapping(): \AA\Import\Mapping { return new \AA\Import\Mapping([]); }
                PHP));

            self::assertSame([], self::custom(Registry::sources($dir)));
            self::assertSame('does not extend ' . AbstractSource::class, Registry::rejected($dir)['DirectSource'] ?? '');
            self::assertTrue(Registry::isRejected('custom:direct', $dir));
        }

        /** @return iterable<string, array{string, string}> */
        public static function forbiddenDeclarations(): iterable
        {
            yield 'second class' => ["final class SecondClassSource {}\nfinal class SneakedExtra {}\n", 'SneakedExtra'];
            yield 'function'     => ["function sneaked_helper() {}\nfinal class FunctionSource {}\n", 'sneaked_helper'];
            yield 'namespace'    => ["namespace AA\\Import\\Source;\nfinal class NamespaceSource {}\n", 'namespace'];
            yield 'interface'    => ["interface InterfaceSource {}\n", 'InterfaceSource'];
            yield 'conditional'  => ["if (true) { final class ConditionalSource {} }\n", 'ConditionalSource'];
            yield 'nested function' => ["final class NestedSource { public function f(): void { function sneaked_nested() {} } }\n", 'sneaked_nested'];
            yield 'none'         => ["\$x = 1;\n", 'does not declare'];
            yield 'anon ctor closure' => ["\$o = new class((function () { function sneaked_ctor() {} })()) {};\nfinal class AnonCtorSource {}\n", 'sneaked_ctor'];
            yield '::class'      => ["\$c = \\stdClass::class;\nif (true) { function sneaked_colon() {} }\nfinal class ColonSource {}\n", 'sneaked_colon'];
            yield 'use close tag' => ["use function sprintf ?>\n<?php function sneaked_tag() {}\nfinal class TagSource {}\n", 'sneaked_tag'];
            yield 'closure use'  => ["\$f = function () use (\$c) { function sneaked_use() {} };\nfinal class ClosureUseSource {}\n", 'sneaked_use'];
        }

        #[DataProvider('forbiddenDeclarations')]
        public function test_rejected_otherDeclarationIsNotLoaded(string $code, string $reason): void
        {
            $dir   = $this->tmpDir();
            $class = preg_replace('/^.*?(class|interface) (\w+Source)\b.*$/s', '$2', $code);
            $class = $class === $code ? 'NoneSource' : $class;
            // had it run, the reason would be the exception instead
            file_put_contents("$dir/$class.php", "<?php\nthrow new \\LogicException('ran');\n$code");

            self::assertSame([], self::custom(Registry::sources($dir)));
            self::assertStringContainsString($reason, Registry::rejected($dir)["$class.php"] ?? '');
            self::assertFalse(class_exists($class, false) || interface_exists($class, false), 'the file never ran');
        }

        public function test_rejected_brokenFileDoesNotStopThePage(): void
        {
            $dir = $this->tmpDir();
            file_put_contents("$dir/BrokenSource.php", "<?php\nfinal class BrokenSource {\n");

            self::assertSame([], self::custom(Registry::sources($dir)));
            self::assertSame('does not load: ParseError at BrokenSource.php:3', Registry::rejected($dir)['BrokenSource.php'] ?? '');
        }

        public function test_rejected_exceptionShowsNoMessage(): void
        {
            $dir = $this->tmpDir();
            file_put_contents("$dir/ThrowingSource.php", "<?php\nthrow new \\RuntimeException('key=s3cret');\nfinal class ThrowingSource {}\n");

            $why = Registry::rejected($dir)['ThrowingSource.php'] ?? '';
            self::assertSame('does not load: RuntimeException at ThrowingSource.php:2', $why);
        }

        public function test_sources_siblingOfRootIsRejected(): void
        {
            $root = $this->tmpDir();
            $dir  = $root . 'X';   // include/customX passes a bare prefix test
            mkdir($dir);   // inside the tmp root, removed with it
            file_put_contents("$dir/SiblingSource.php", self::sourceCode('SiblingSource'));
            self::root($root);

            self::assertSame([], self::custom(Registry::sources($dir)));
            self::assertStringContainsString('outside include/custom', Registry::rejected($dir)[$dir] ?? '');
            self::assertFalse(class_exists('SiblingSource', false));
        }

        public function test_rejected_outputInOwnBufferIsDiscarded(): void
        {
            $dir = $this->tmpDir();
            file_put_contents("$dir/BufferedSource.php", self::sourceCode('BufferedSource') . "ob_start();\necho 'x';\n");

            $level = ob_get_level();
            $this->expectOutputString('');
            self::assertSame([], self::custom(Registry::sources($dir)));
            self::assertSame($level, ob_get_level(), 'the buffer the file opened is closed too');
            self::assertStringContainsString('prints output', Registry::rejected($dir)['BufferedSource.php'] ?? '');
        }

        public function test_rejected_outputIsDiscarded(): void
        {
            $dir = $this->tmpDir();
            file_put_contents("$dir/NoisySource.php", "\xEF\xBB\xBF" . self::sourceCode('NoisySource'));

            $this->expectOutputString('');
            self::assertSame([], self::custom(Registry::sources($dir)));
            self::assertStringContainsString('prints output', Registry::rejected($dir)['NoisySource.php'] ?? '');
        }

        public function test_rejected_takenClassNameIsNotLoaded(): void
        {
            Registry::sources($this->fixtures());   // GoodSource is declared now
            $dir = $this->tmpDir();
            file_put_contents("$dir/GoodSource.php", "<?php\nfinal class GoodSource {}\n");

            self::assertSame([], self::custom(Registry::sources($dir)), 'declaring it again would be a fatal error');
            self::assertStringContainsString('already', Registry::rejected($dir)['GoodSource.php'] ?? '');
        }

        public function test_rejected_linkOutOfTheDirectoryIsNotLoaded(): void
        {
            $outside = $this->tmpDir();
            file_put_contents("$outside/LeakSource.php", "<?php\nthrow new \\LogicException('must not run');\n");
            $dir = $this->tmpDir();
            symlink("$outside/LeakSource.php", "$dir/LeakSource.php");

            self::assertSame([], self::custom(Registry::sources($dir)));
            self::assertStringContainsString('outside', Registry::rejected($dir)['LeakSource.php'] ?? '');
        }

        public function test_rejected_fileNameThatIsNoClassName(): void
        {
            $dir = $this->tmpDir();
            file_put_contents("$dir/my-Source.php", "<?php\nthrow new \\LogicException('must not run');\n");

            self::assertSame([], self::custom(Registry::sources($dir)));
            self::assertStringContainsString('class name', Registry::rejected($dir)['my-Source.php'] ?? '');
        }

        public function test_sift_rejectsWhatIsNoUsableClass(): void
        {
            $sifted = Registry::sift([
                'custom:good'   => \GoodSource::class,
                'core:iface'    => SourceInterface::class,
                'core:trait'    => RegistryTestTrait::class,
                'core:ghost'    => 'AA\Import\Source\NoSuchSource',
                'core:abstract' => AbstractSource::class,
            ]);

            self::assertSame(['custom:good' => \GoodSource::class], $sifted['sources']);
            self::assertSame([SourceInterface::class, RegistryTestTrait::class, 'AA\Import\Source\NoSuchSource', AbstractSource::class], array_keys($sifted['rejected']));
            self::assertStringContainsString('interface or a trait', $sifted['rejected'][SourceInterface::class]);
            self::assertStringContainsString('interface or a trait', $sifted['rejected'][RegistryTestTrait::class]);
            self::assertStringContainsString('no such class', $sifted['rejected']['AA\Import\Source\NoSuchSource']);
            self::assertStringContainsString('abstract', $sifted['rejected'][AbstractSource::class]);
        }

        public function test_create_unknownTypeIsNoSource(): void
        {
            try {
                Registry::create('custom:<b>nope</b>', [], $this->fixtures());
                self::fail('an unknown type must be refused');
            } catch (SourceRefused $e) {
                self::assertSame('NO_SOURCE', $e->reason);
                self::assertStringNotContainsString('<b>', $e->getMessage(), 'the type is not echoed');
            }
        }

        public function test_create_passesSettingsToFromSettings(): void
        {
            $source = Registry::create('custom:good', ['slice' => 'abc'], $this->fixtures());

            self::assertInstanceOf(\GoodSource::class, $source);
            self::assertSame(['slice' => 'abc'], $source->settings);
        }

        /** the fixture directory, with the root allowing it */
        private function fixtures(): string
        {
            self::root(dirname(self::FIXTURES));
            return self::FIXTURES;
        }

        /** a fresh directory, with the root allowing it */
        private function tmpDir(): string
        {
            if ($this->tmpRoot === '') {
                $this->tmpRoot = sys_get_temp_dir() . '/aa-registry-' . bin2hex(random_bytes(6));
                mkdir($this->tmpRoot);
            }
            self::root($this->tmpRoot);
            $dir = $this->tmpRoot . '/' . bin2hex(random_bytes(4));
            mkdir($dir);
            return $dir;
        }

        private static function root(?string $root): void
        {
            Registry::setCustomRootForTests($root);
        }

        /** @param string $parent what follows the class name: extends ... or implements ... */
        private static function sourceCode(string $class, string $parent = 'extends \\AA\\Import\\AbstractSource', string $more = ''): string
        {
            return <<<PHP
                <?php
                final class $class $parent
                {
                    public static function name(): string { return '$class'; }
                    public static function description(): string { return ''; }
                    public static function getClassProperties(): array { return []; }
                    public static function fromSettings(array \$settings): static { return new static(); }
                    public function streams(): iterable { return []; }
                    public function jobs(\\AA\\Import\\Stream \$stream, \\AA\\Import\\Context \$ctx, ?string \$known): iterable { return []; }
                    $more
                }

                PHP;
        }

        /** @param array<string, string> $sources */
        private static function custom(array $sources): array
        {
            return array_filter($sources, static fn(string $type): bool => str_starts_with($type, 'custom:'), ARRAY_FILTER_USE_KEY);
        }

        /** @param array<string, string> $rejected */
        private static function customRejected(array $rejected): array
        {
            return array_filter($rejected, static fn(string $key): bool => !str_starts_with($key, 'AA\\'), ARRAY_FILTER_USE_KEY);
        }
    }
}
