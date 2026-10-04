<?php

declare(strict_types=1);

// AA_Content lives in include/item_content.php, which loads include/locsess.php;
// that needs the constants include/config.php normally provides.

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

namespace Tests\Unit\Import {

    use AA\Http\ClientInterface;
    use AA\Import\ParamsGrabber;
    use AA\IO\Grabber\AbstractGrabber;
    use AA\IO\SourceRefused;
    use AA\IO\SourceUnavailable;
    use AA_Content;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\IO\Fixtures\ArrayGrabber;

    /**
     * Each test in its own process: AA_Content loads include/locsess.php, whose getDB()
     * collides with the stub tests/Unit/IO/DB loads when that directory runs first.
     */
    #[RunTestsInSeparateProcesses]
    final class ParamsGrabberTest extends TestCase
    {
        protected function setUp(): void
        {
            // locsess.php warns about the web request and config a real install has
            set_error_handler(static fn() => true, E_WARNING | E_DEPRECATED);
            try {
                class_exists(AA_Content::class);
            } finally {
                restore_error_handler();
            }
            require_once __DIR__ . '/../IO/fixtures/ArrayGrabber.php';
        }

        /** a source that hands out parsed data for the mapping, as every import grabber does */
        private static function source(array $items, ?\Exception $prepare_error = null, ?\Closure $on_get = null): ArrayGrabber
        {
            return new class ($items, $prepare_error, $on_get) extends ArrayGrabber {
                public function isDirectfill(): bool
                {
                    return false;
                }
            };
        }

        /** @param array<string, mixed> $fields */
        private static function item(array $fields): AA_Content
        {
            $item = new AA_Content();
            foreach ($fields as $field => $value) {
                $item->setValue($field, $value);
            }
            return $item;
        }

        /** @return list<AA_Content> items numbered 1..$n in field `n`, and `rok` */
        private static function items(int $n, int $from = 1): array
        {
            $items = [];
            for ($i = $from; $i < $from + $n; $i++) {
                $items[] = self::item(['n' => (string) $i, 'rok' => '2020']);
            }
            return $items;
        }

        /** @return list<string> field `n` of every item handed out */
        private static function drain(ParamsGrabber $g): array
        {
            $seen = [];
            while ($item = $g->getItem()) {
                $seen[] = (string) $item->getValue('n');
            }
            return $seen;
        }

        /** the fingerprint a fresh run of this source computes */
        private static function fingerprintOf(array $items): string
        {
            $g = new ParamsGrabber(self::source($items), []);
            $g->prepare();
            return $g->fingerprint();
        }

        public function test_getItem_paramsOverrideSourceFields(): void
        {
            $source = self::item(['fld' => 'from source', 'value' => '42']);
            $g = new ParamsGrabber(self::source([$source]), ['fld' => 'from param', 'geo' => 'CZ0100']);
            $g->prepare();
            $item = $g->getItem();

            $this->assertSame('from param', $item->getValue('fld'));
            $this->assertSame('CZ0100', $item->getValue('geo'));
            $this->assertSame('42', $item->getValue('value'));
            $this->assertSame(['from param'], $item->getValuesArray('fld'), 'one value, not appended');
            $this->assertNull($g->getItem());
        }

        public function test_getItem_paramsDoNotChangeFingerprint(): void
        {
            $items = self::items(3);
            $plain = new ParamsGrabber(self::source($items), []);
            $plain->prepare();
            $with = new ParamsGrabber(self::source($items), ['n' => 'x']);
            $with->prepare();
            self::drain($with);

            $this->assertSame($plain->fingerprint(), $with->fingerprint());
            $this->assertSame('1', $items[0]->getValue('n'), 'the source item stays as the source gave it');
        }

        public function test_offset_skipsOnMatchingFingerprint(): void
        {
            $items = self::items(30);
            $fp = self::fingerprintOf($items);

            $g = new ParamsGrabber(self::source($items), [], 25, $fp);
            $g->prepare();

            $this->assertFalse($g->restarted());
            $this->assertSame($fp, $g->fingerprint());
            $this->assertSame(['26', '27', '28', '29', '30'], self::drain($g));
            $this->assertSame(30, $g->offset());
        }

        public function test_offset_skipsWithinBuffer(): void
        {
            $items = self::items(30);
            $g = new ParamsGrabber(self::source($items), [], 3, self::fingerprintOf($items));
            $g->prepare();

            $this->assertSame('4', $g->getItem()->getValue('n'));
            $this->assertSame(4, $g->offset());
            $this->assertCount(26, self::drain($g));
        }

        public function test_offset_restartsOnDifferentFingerprint(): void
        {
            $old = self::fingerprintOf(self::items(30));
            $changed = self::items(30);
            $changed[7] = self::item(['n' => '8', 'rok' => '2021']);

            $g = new ParamsGrabber(self::source($changed), [], 25, $old);
            $g->prepare();

            $this->assertTrue($g->restarted());
            $this->assertNotSame($old, $g->fingerprint());
            $this->assertSame('1', $g->getItem()->getValue('n'));
            $this->assertSame(1, $g->offset());
        }

        public function test_offset_newJobIsNoRestart(): void
        {
            $g = new ParamsGrabber(self::source(self::items(2)), []);
            $g->prepare();

            $this->assertFalse($g->restarted());
            $this->assertSame(['1', '2'], self::drain($g));
        }

        public function test_offset_withoutFingerprintIsRefused(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            new ParamsGrabber(self::source([]), [], 5);
        }

        public function test_offset_countsRawItems(): void
        {
            $g = new ParamsGrabber(self::source(self::items(30)), []);
            $g->prepare();
            $this->assertSame(0, $g->offset(), 'read ahead for the fingerprint, nothing handed out yet');

            for ($i = 0; $i < 22; $i++) {
                $g->getItem();
            }
            $this->assertSame(22, $g->offset());

            self::drain($g);
            $this->assertSame(30, $g->offset(), 'the end does not count');
        }

        public function test_offset_beyondShrunkSourceEndsAtItsEnd(): void
        {
            $fp = self::fingerprintOf(self::items(20));
            $g = new ParamsGrabber(self::source(self::items(20)), [], 50, $fp);
            $g->prepare();

            $this->assertNull($g->getItem());
            $this->assertSame(20, $g->offset());
        }

        public function test_fingerprint_shortSource(): void
        {
            $three = self::fingerprintOf(self::items(3));

            $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $three);
            $this->assertSame($three, self::fingerprintOf(self::items(3)), 'stable across runs');
            $this->assertNotSame($three, self::fingerprintOf(self::items(2)));
            $this->assertNotSame(self::fingerprintOf([]), self::fingerprintOf(self::items(1)));

            // only the first 20 items count: the source may grow at its end
            $this->assertSame(self::fingerprintOf(self::items(20)), self::fingerprintOf(self::items(25)));
            $this->assertNotSame(self::fingerprintOf(self::items(19)), self::fingerprintOf(self::items(20)));
        }

        public function test_fingerprint_ignoresFieldOrderButNotValueOrder(): void
        {
            $ab = self::fingerprintOf([self::item(['a' => '1', 'b' => '2'])]);

            $this->assertSame($ab, self::fingerprintOf([self::item(['b' => '2', 'a' => '1'])]));
            $this->assertNotSame($ab, self::fingerprintOf([self::item(['a' => '2', 'b' => '1'])]));

            $list = (new AA_Content())->addValue('m', 'x')->addValue('m', 'y');
            $reversed = (new AA_Content())->addValue('m', 'y')->addValue('m', 'x');
            $this->assertNotSame(self::fingerprintOf([$list]), self::fingerprintOf([$reversed]));
        }

        public function test_fingerprint_takesFloatsExactly(): void
        {
            // JSON sources give floats; their string form would follow the ini precision
            $old = ini_get('precision');
            ini_set('precision', '17');
            $a = self::fingerprintOf([self::item(['v' => 0.1])]);
            ini_set('precision', '5');
            $b = self::fingerprintOf([self::item(['v' => 0.1])]);
            ini_set('precision', (string) $old);

            $this->assertSame($a, $b);
            $this->assertNotSame($a, self::fingerprintOf([self::item(['v' => 0.1 + 1e-15])]));
            $this->assertNotSame($a, self::fingerprintOf([self::item(['v' => '0.1'])]));
        }

        public function test_fingerprint_takesBytesThatAreNotUtf8(): void
        {
            // a windows-1250 source read without conversion
            $latin2 = self::fingerprintOf([self::item(['obec' => "P\xF8\xEDbram"])]);

            $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $latin2);
            $this->assertSame($latin2, self::fingerprintOf([self::item(['obec' => "P\xF8\xEDbram"])]));
            $this->assertNotSame($latin2, self::fingerprintOf([self::item(['obec' => 'Příbram'])]));
            $this->assertNotSame($latin2, self::fingerprintOf([self::item(["ob\xE9c" => "P\xF8\xEDbram"])]));
        }

        public function test_fingerprint_takesStringableAsText(): void
        {
            $text = new class implements \Stringable {
                public function __toString(): string
                {
                    return 'Praha';
                }
            };
            $this->assertSame(
                self::fingerprintOf([self::item(['obec' => 'Praha'])]),
                self::fingerprintOf([self::item(['obec' => $text])]),
            );
        }

        public function test_fingerprint_otherObjectNamesGrabberNotData(): void
        {
            $inner = self::source([self::item(['obec' => new \ArrayObject(['secret-key'])])]);
            $g = new ParamsGrabber($inner, []);
            try {
                $g->prepare();
                $this->fail('an object has no stable form');
            } catch (\LogicException $e) {
                $this->assertStringContainsString($inner::class, $e->getMessage());
                $this->assertStringNotContainsString('secret-key', $e->getMessage());
            }
        }

        public function test_fingerprint_emptyBeforePrepare(): void
        {
            $this->assertSame('', (new ParamsGrabber(self::source(self::items(1)), []))->fingerprint());
        }

        public function test_versionMax_numericAndStringYears(): void
        {
            $items = [
                self::item(['rok' => '999']),
                self::item(['rok' => 2021]),
                self::item(['rok' => '1000']),
                self::item(['rok' => '']),
                self::item(['other' => 'x']),
            ];
            $g = new ParamsGrabber(self::source($items), [], 0, '', 'rok');
            $g->prepare();
            self::drain($g);
            $this->assertSame('2021', $g->versionMax(), 'numbers compare as numbers, not text');

            $periods = [self::item(['rok' => '2019-12']), self::item(['rok' => '2020-01']), self::item(['rok' => '2019-13'])];
            $g = new ParamsGrabber(self::source($periods), [], 0, '', 'rok');
            $g->prepare();
            self::drain($g);
            $this->assertSame('2020-01', $g->versionMax());

        }

        public function test_laterVersion_trimsAndIsOrderIndependent(): void
        {
            $this->assertSame('2021', ParamsGrabber::laterVersion('2021', '999'));
            $this->assertSame('2021', ParamsGrabber::laterVersion('', '2021'));
            $this->assertSame('2021', ParamsGrabber::laterVersion('2021', ''));
            $this->assertSame('', ParamsGrabber::laterVersion(' ', ''));
            $this->assertSame('2021', ParamsGrabber::laterVersion(' 2021 ', "999\n"), 'padding is no version');
            $this->assertSame('1000', ParamsGrabber::laterVersion(' 999', '1000 '), 'numbers even when padded');

            // the same number written twice: the answer must not depend on which came first
            $this->assertSame(ParamsGrabber::laterVersion('2021', '2021.0'), ParamsGrabber::laterVersion('2021.0', '2021'));
            $this->assertSame(ParamsGrabber::laterVersion('2021', '02021'), ParamsGrabber::laterVersion('02021', '2021'));
        }

        public function test_versionMax_onlyItemsHandedOut(): void
        {
            $items = [self::item(['n' => '1', 'rok' => '2024']), self::item(['n' => '2', 'rok' => '2020'])];
            $fp = self::fingerprintOf($items);

            $g = new ParamsGrabber(self::source($items), [], 1, $fp, 'rok');
            $g->prepare();
            $this->assertSame('', $g->versionMax(), 'read ahead is not handed out');
            self::drain($g);
            $this->assertSame('2020', $g->versionMax(), 'the skipped item counted in its own step');
        }

        public function test_versionMax_emptyWithoutField(): void
        {
            $g = new ParamsGrabber(self::source(self::items(3)), []);
            $g->prepare();
            self::drain($g);
            $this->assertSame('', $g->versionMax());
        }

        public function test_prepare_delegatesToInner(): void
        {
            $inner = self::source(self::items(2));
            $g = new ParamsGrabber($inner, []);

            $this->assertSame(0, $inner->prepared);
            $g->prepare();
            $this->assertSame(1, $inner->prepared);
            self::drain($g);
            $g->finish();
            $this->assertSame(1, $inner->finished);
        }

        public function test_delegatesSettingsToInner(): void
        {
            // every answer differs from the AbstractGrabber default, so an undelegated method shows
            $inner = new class extends AbstractGrabber {
                public function __construct()
                {
                    $this->message('fetched 3 rows');
                }
                public function isDirectfill(): bool
                {
                    return false;
                }
                public function getIdMode(): string
                {
                    return 'old';
                }
                public function getStoreMode(): string
                {
                    return 'update';
                }
                public function getCharset(): string
                {
                    return 'windows-1250';
                }
                public function getSources(): array
                {
                    return ['row'];
                }
                public function fetchesViaLoadData(): bool
                {
                    return true;
                }
                public function loadFrequency(): ?float
                {
                    return $this->load_frequency;
                }
            };
            $g = new ParamsGrabber($inner, ['geo' => 'CZ']);

            $this->assertFalse($g->isDirectfill());
            $this->assertSame('old', $g->getIdMode());
            $this->assertSame('update', $g->getStoreMode());
            $this->assertSame('windows-1250', $g->getCharset());
            $this->assertSame(['row'], $g->getSources());
            $this->assertTrue($g->fetchesViaLoadData());
            $this->assertSame([['txt' => 'fetched 3 rows']], $g->reportRows());
            $this->assertStringContainsString('fetched 3 rows', $g->report(1, 'plain'));

            $this->assertSame($g, $g->setLoadFrequency(1500.0));
            $this->assertSame(1500.0, $inner->loadFrequency());

            $g->clear_report();
            $this->assertSame([], $inner->reportRows());
        }

        public function test_directFillWithParamsIsRefused(): void
        {
            // ArrayGrabber keeps the AbstractGrabber default: items stored as they are
            new ParamsGrabber(new ArrayGrabber([]), []);
            try {
                new ParamsGrabber(new ArrayGrabber([]), ['geo' => 'CZ']);
                $this->fail('the parameters would be stored into the target item');
            } catch (SourceRefused $e) {
                $this->assertSame('DEFINITION', $e->reason);
                $this->assertStringContainsString(ArrayGrabber::class, $e->getMessage());
            }
        }

        public function test_prepare_againStartsOver(): void
        {
            $items = self::items(3);
            $g = new ParamsGrabber(self::source($items), [], 1, self::fingerprintOf($items));
            $g->prepare();
            self::drain($g);
            $g->prepare();

            $this->assertSame(['2', '3'], self::drain($g));
            $this->assertSame(3, $g->offset());
        }

        public function test_prepare_againForgetsTheLastRun(): void
        {
            $same = self::items(3);
            $changed = self::items(3, 10);
            // round by round: the source as before, then changed, then down
            $inner = new class ([$same, $changed]) extends AbstractGrabber {
                private int $round = -1;
                private int $pos = 0;
                public function __construct(private array $rounds) {}
                public function isDirectfill(): bool
                {
                    return false;
                }
                public function prepare(): void
                {
                    $this->round++;
                    $this->pos = 0;
                    if (!isset($this->rounds[$this->round])) {
                        throw new SourceUnavailable('TRANSPORT');
                    }
                }
                public function getItem(): ?AA_Content
                {
                    return $this->rounds[$this->round][$this->pos++] ?? null;
                }
            };
            $g = new ParamsGrabber($inner, ['p' => 'x'], 2, self::fingerprintOf($same), 'n');

            $g->prepare();
            $this->assertFalse($g->restarted());
            $this->assertSame(['3'], self::drain($g));
            $this->assertSame('3', $g->versionMax());

            $g->prepare();
            $this->assertTrue($g->restarted());
            $this->assertSame('', $g->versionMax());
            $this->assertSame(['10', '11', '12'], self::drain($g), 'the end of the last round is forgotten');
            $this->assertSame('12', $g->versionMax());

            try {
                $g->prepare();
                $this->fail('prepare() swallowed the source failure');
            } catch (SourceUnavailable) {
            }
            $this->assertSame('', $g->fingerprint());
            $this->assertFalse($g->restarted());
            $this->assertSame('', $g->versionMax());
            $this->assertSame(0, $g->offset());
        }

        public function test_setHttp_reachesInner(): void
        {
            $http = $this->createStub(ClientInterface::class);
            $g = new ParamsGrabber(self::source([]), []);

            $this->expectException(\LogicException::class);   // ArrayGrabber does not fetch through loadData()
            $g->setHttp($http);
        }

        public function test_innerExceptionPropagates(): void
        {
            $down = new SourceUnavailable('TRANSPORT', 'No answer from example.org');
            $g = new ParamsGrabber(self::source([], $down), []);
            try {
                $g->prepare();
                $this->fail('prepare() swallowed the source failure');
            } catch (SourceUnavailable $e) {
                $this->assertSame($down, $e);
            }

            $boom = new \RuntimeException('parse error');
            $onGet = function (int $n) use ($boom): void {
                if ($n === 3) {
                    throw $boom;
                }
            };
            $g = new ParamsGrabber(self::source(self::items(5), null, $onGet), []);
            $this->expectExceptionObject($boom);
            $g->prepare();      // the read ahead reaches the third item
        }
    }
}
