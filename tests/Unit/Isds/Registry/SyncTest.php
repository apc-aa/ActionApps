<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Registry;

use AA\Isds\Registry\Box;
use AA\Isds\Registry\Config;
use AA\Isds\Registry\MatchReport;
use AA\Isds\Registry\MatchRow;
use AA\Isds\Registry\RegisterIndex;
use AA\Isds\Registry\SdsList;
use AA\Isds\Registry\Sync;
use AA\Isds\Registry\UnrecordedException;
use AA_Credentials;
use AA_Field;
use AA_Metabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use PHPUnit\Framework\TestCase;
use AA\Http\Response;
use Tests\Unit\Fixtures\FakeHttp;
use Tests\Unit\Isds\Registry\Fixtures\DownloadSync;
use Tests\Unit\Isds\Registry\Fixtures\LockedRegisterIndex;
use Tests\Unit\Isds\Registry\Fixtures\PropertySlice;
use Tests\Unit\Isds\Registry\Fixtures\RecordingSync;
use Tests\Unit\Legacy\Fixtures\FakeFields;
use Tests\Unit\Legacy\Fixtures\RecordingDb;

/** The items come from RecordingSync, the boxes from the 31 real ones of the SdsList fixture. */
final class SyncTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/fixtures/seznam_ds_ovm-sample.xml';

    private const SLICE = '5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a';

    private const NAME = 'headline........';
    private const ICO  = 'text...........1';
    private const DS   = 'text...........2';
    private const TYPE = 'category........';

    /** a content field the settings do not name */
    private const OTHER = 'text...........9';

    /** the slice of the tests through the real storeItem() */
    private const REAL_SLICE = 'e1e1e1e1e1e1e1e1e1e1e1e1e1e1e1e1';

    private const CITY     = 'obec';
    private const DISTRICT = 'městská část';

    /** @var list<Box> */
    private static array $boxes = [];

    /** a directory of the test's own, '' = none - other runs on the machine share the default one */
    private string $scratch = '';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../fixtures/util_shims.php';             // the constants of config.php
        require_once __DIR__ . '/../../Legacy/fixtures/store_fixtures.php';    // the legacy boot, no DB
        require_once __DIR__ . '/fixtures/RecordingSync.php';
        require_once __DIR__ . '/fixtures/DownloadSync.php';
        require_once __DIR__ . '/fixtures/LockedRegisterIndex.php';
        require_once __DIR__ . '/fixtures/PropertySlice.php';
        require_once __DIR__ . '/../../fixtures/FakeHttp.php';
        if (!defined('CACHE_TTL')) {
            define('CACHE_TTL', 600);                                           // AA::Pagecache()
        }
        self::$boxes = iterator_to_array(SdsList::fromString((string) file_get_contents(self::FIXTURE)), false);
    }

    /** the lock and the attempts of the downloads */
    protected function setUp(): void
    {
        DownloadSync::reset();
    }

    /** the process-wide state the tests through the real storeItem() set */
    protected function tearDown(): void
    {
        if ($this->scratch !== '') {
            self::removeTree($this->scratch);
        }
        RecordingDb::reset();
        $GLOBALS['spareDBs'] = [];
        PropertySlice::forget(self::REAL_SLICE);
        (new \ReflectionProperty(AA_Credentials::class, 'instance'))->setValue(null, null);    // the slice passwords it knows
        (new \ReflectionProperty(\AA::class, 'pagecache'))->setValue(null, null);              // opening a slice keeps the page out of it
    }

    public function test_itemsFrom_firstValueOfTheConfiguredFields(): void
    {
        $items = self::sync()->itemsFrom([
            self::id(1) => [
                self::NAME => [['value' => 'Brno', 'flag' => 0], ['value' => 'Brünn', 'flag' => 0]],
                self::ICO  => [['value' => 235300, 'flag' => 0]],     // a number-stored field
                self::DS   => [],
                'text...........9' => [['value' => 'not read', 'flag' => 0]],
            ],
        ]);

        self::assertSame([self::id(1) => ['name' => 'Brno', 'ico' => '235300', 'ds' => '', 'type' => '']], $items);
    }

    public function test_itemsFrom_fieldsNotConfiguredAreEmpty(): void
    {
        $sync  = self::sync(over: ['ico_field' => '', 'type_field' => '']);
        $items = $sync->itemsFrom([self::id(1) => self::item('Brno', '44992785', 'a7kbrrn', self::CITY), self::id(2) => 'not content']);

        self::assertSame([
            self::id(1) => ['name' => 'Brno', 'ico' => '', 'ds' => 'a7kbrrn', 'type' => ''],
            self::id(2) => ['name' => '', 'ico' => '', 'ds' => '', 'type' => ''],
        ], $items);
    }

    public function test_itemsFrom_onlyTheConfiguredTypes(): void
    {
        $content = [
            self::id(1) => self::item('Brno', type: self::CITY),
            self::id(2) => self::item('Jihomoravský kraj', type: 'kraj'),
            self::id(3) => self::item('Brno-střed', type: self::DISTRICT),
            self::id(4) => self::item('Bez typu'),
        ];

        self::assertSame([self::id(1), self::id(3)], array_keys(self::sync(over: ['types' => [self::CITY, self::DISTRICT]])->itemsFrom($content)));
        self::assertCount(4, self::sync()->itemsFrom($content), 'no types = all items');
    }

    // Config trims the types, and the matcher compares the type with them as it is
    public function test_itemsFrom_typeTrimmed(): void
    {
        $sync  = self::sync(over: ['types' => [self::DISTRICT], 'district_types' => [self::DISTRICT]]);
        $items = $sync->itemsFrom([self::id(1) => self::item('Brno-střed', type: ' ' . self::DISTRICT . "\n")]);

        self::assertSame(self::DISTRICT, $items[self::id(1)]['type'] ?? null);
    }

    // loadContent() answers in id order, as GetItemContent() does - the slice's order runs the other way
    public function test_items_readInChunksInTheSlicesOrder(): void
    {
        $sync = self::sync();
        for ($i = 1001; $i > 0; --$i) {
            $sync->content[self::id($i)] = self::item("Obec $i");
        }

        $items = $sync->items();

        self::assertSame([500, 500, 1], $sync->chunks);
        self::assertSame(array_keys($sync->content), array_keys($items));
    }

    public function test_report_districtTypesGoToTheDistrictBoxes(): void
    {
        $sync = self::sync([
            1 => self::item('Brno', '44992785', 'x2pbqzq', self::CITY),
            2 => self::item('Brno-střed', '', '', self::DISTRICT),
            3 => self::item('Brno-střed', '', '', self::CITY),
        ], ['district_types' => [self::DISTRICT]]);

        $rows = $sync->report(self::$boxes)->rows;

        self::assertSame([self::id(1), self::id(2), self::id(3)], array_column($rows, 'itemId'));
        self::assertSame(['a7kbrrn', 'qykbwe7', ''], array_column($rows, 'newDs'));
        self::assertSame([MatchRow::CHANGED, MatchRow::NEW, MatchRow::UNMATCHED], array_column($rows, 'status'));
    }

    public function test_defaultTicked_onlyNewOrChangedBoxesFoundByIco(): void
    {
        $report = self::report([
            1 => self::item('Brno', '44992785', '', self::CITY),               // new by IČO
            2 => self::item('Liberec', '00262978', 'x2pbqzq', self::CITY),     // changed by IČO
            3 => self::item('Cerhenice', '00235300', 'eerak7a', self::CITY),   // same
            4 => self::item('Praha', '', '', self::CITY),                      // new by name
            5 => self::item('Plzeň', '05282446', '', self::CITY),              // by name, the IČO of a school
            6 => self::item('Adamov', '', '', self::CITY),                     // ambiguous
            7 => self::item('Nikde', '', '', self::CITY),                      // unmatched
        ]);

        self::assertSame([MatchRow::NEW, MatchRow::CHANGED, MatchRow::SAME, MatchRow::NEW, MatchRow::NEW, MatchRow::AMBIGUOUS, MatchRow::UNMATCHED], array_column($report->rows, 'status'));
        self::assertTrue($report->rows[4]->icoMismatch);
        self::assertSame([self::id(1), self::id(2)], Sync::defaultTicked($report));
    }

    // two items would hold one box, or an item would get the box another one already has
    public function test_defaultTicked_neverASharedBox(): void
    {
        $report = self::report([
            1 => self::item('Adamov', '00581160', '', self::CITY),
            2 => self::item('Adamov', '581160', '', self::CITY),
            3 => self::item('Nehvizdy', '00240524', '', self::CITY),
            4 => self::item('Nikde', '', 'BGXBR7U', self::CITY),
            5 => self::item('Brno', '44992785', '', self::CITY),
        ]);

        self::assertSame(['q7bb677' => [self::id(1), self::id(2)], 'bgxbr7u' => [self::id(3), self::id(4)]], $report->sharedBoxes());
        self::assertSame([self::id(5)], Sync::defaultTicked($report));
    }

    // the matcher sets it on name matches only; the ticking does not count on that
    public function test_defaultTicked_neverAnIcoMismatch(): void
    {
        $box    = new Box('a7kbrrn', '44992785', '', 'Statutární město Brno', 'OVM', 10, true, false);
        $report = new MatchReport([new MatchRow(self::id(1), 'Brno', self::CITY, '', $box, MatchRow::BY_ICO, MatchRow::NEW, [], true)]);

        self::assertSame([], Sync::defaultTicked($report));
    }

    public function test_toWrite_tickedRowsWithANewOrChangedBox(): void
    {
        $report = self::report([
            1 => self::item('Brno', '44992785', '', self::CITY),               // new by IČO
            2 => self::item('Liberec', '00262978', 'x2pbqzq', self::CITY),     // changed by IČO
            3 => self::item('Cerhenice', '00235300', 'eerak7a', self::CITY),   // same
            4 => self::item('Praha', '', '', self::CITY),                      // new by name, ticked by hand
            5 => self::item('Plzeň', '', '', self::CITY),                      // not ticked
        ]);
        $ticked = [self::id(4) => self::tick('48ia97h'), self::id(1) => self::tick('a7kbrrn'), self::id(2) => self::tick('7c6by6u', 'x2pbqzq'), self::id(3) => self::tick('eerak7a', 'eerak7a')];

        $result = Sync::toWrite($report, $ticked);

        self::assertSame([self::id(1), self::id(2), self::id(4)], array_column($result['rows'], 'itemId'), 'in report order');
        self::assertSame(['a7kbrrn', '7c6by6u', '48ia97h'], array_column($result['rows'], 'newDs'));
        self::assertSame([[], 0], [$result['stale'], $result['left']], 'Cerhenice has the box already');
    }

    // a hash of the box stored, as the statuses compare it: a browser posts a line break as CR LF
    public function test_tickValue_theBoxOfferedAndAHashOfTheBoxStored(): void
    {
        $report = self::report([
            1 => self::item('Brno', '44992785', '', self::CITY),
            2 => self::item('Liberec', '00262978', ' X2pbqzq ', self::CITY),
            3 => self::item('Cerhenice', '00235300', "ab\ncd|ef", self::CITY),
        ]);
        $values = array_map(Sync::tickValue(...), $report->rows);

        self::assertSame([self::tick('a7kbrrn'), self::tick('7c6by6u', 'x2pbqzq'), self::tick('eerak7a', "ab\ncd|ef")], $values);
        self::assertMatchesRegularExpression('/^eerak7a\|[0-9a-f]{12}$/', $values[2], 'no text of the item in the form');
        self::assertSame([self::id(1), self::id(2), self::id(3)], array_column(Sync::toWrite($report, array_combine([self::id(1), self::id(2), self::id(3)], $values))['rows'], 'itemId'));
    }

    // another editor changed the item, or a new register another box, between the view and the submit
    public function test_toWrite_rowsChangedSinceTheViewAreStale(): void
    {
        $report = self::report([
            1 => self::item('Brno', '44992785', '', self::CITY),
            2 => self::item('Liberec', '00262978', '', self::CITY),
            3 => self::item('Adamov', '', '', self::CITY),                     // ambiguous now
            4 => self::item('Nikde', '', '', self::CITY),                      // unmatched now
            5 => self::item('Cerhenice', '00235300', 'abcdefg', self::CITY),   // another editor typed a box
        ]);
        $ticked = [
            self::id(1)  => self::tick('x2pbqzq'),     // the person saw another box
            self::id(2)  => self::tick('7c6by6u'),
            self::id(3)  => self::tick('q7bb677'),
            self::id(4)  => self::tick('abcdefg'),
            self::id(5)  => self::tick('eerak7a'),     // the item had no box when the person looked
            self::id(99) => self::tick('abcdefg'),     // the item is gone or of another type now
            'no-item'    => self::tick('abcdefg'),
        ];

        $result = Sync::toWrite($report, $ticked);

        self::assertSame([self::id(2)], array_column($result['rows'], 'itemId'));
        self::assertSame([self::id(1), self::id(3), self::id(4), self::id(5), self::id(99)], $result['stale']);
    }

    /** @return array<string, array{mixed}> */
    public static function noTicks(): array
    {
        $none = self::tick('');
        return [
            'no stored part'     => ['a7kbrrn'],
            'capitals'           => ['A7KBRRN' . $none],
            'spaces'             => [' a7kbrrn' . $none],
            'too short'          => ['a7kbrr' . $none],
            'markup'             => ['<b>a7k' . $none],
            'empty box'          => [$none],
            'no hash'            => ['a7kbrrn|'],
            'the stored text'    => ['a7kbrrn|x2pbqzq'],
            'hash in capitals'   => ['a7kbrrn' . strtoupper($none)],
            'hash too long'      => ['a7kbrrn' . $none . '0'],
            'an array'           => [['a7kbrrn' . $none]],
            'a number'           => [7],
        ];
    }

    // what no page posts is no tick: neither written nor said to have changed
    #[DataProvider('noTicks')]
    public function test_toWrite_onlyWellFormedTicks(mixed $value): void
    {
        $report = self::report([1 => self::item('Brno', '44992785', '', self::CITY)]);

        self::assertSame(['rows' => [], 'stale' => [], 'left' => 0], Sync::toWrite($report, [self::id(1) => $value]));
    }

    public function test_toWrite_atMostCapTheRestLeft(): void
    {
        $report = self::report([
            1 => self::item('Brno', '44992785', '', self::CITY),
            2 => self::item('Liberec', '00262978', '', self::CITY),
            3 => self::item('Cerhenice', '00235300', '', self::CITY),
        ]);
        $all = [self::id(3) => self::tick('eerak7a'), self::id(2) => self::tick('7c6by6u'), self::id(1) => self::tick('a7kbrrn')];

        self::assertSame([self::id(1), self::id(2)], array_column(Sync::toWrite($report, $all, 2)['rows'], 'itemId'));
        self::assertSame(['rows' => [], 'stale' => [], 'left' => 3], Sync::toWrite($report, $all, 0), 'the rest is not stale, but left');
        self::assertCount(3, Sync::toWrite($report, $all)['rows']);
    }

    public function test_registerStamp_changesWithTheRegisterAndTheSettings(): void
    {
        $config = Config::fromValues(self::SLICE, ['name_field' => self::NAME, 'ds_field' => self::DS]);
        $other  = Config::fromValues(self::SLICE, ['name_field' => self::NAME, 'ds_field' => self::DS, 'ico_field' => self::ICO]);
        $sha256 = str_repeat('a', 64);

        $stamp = Sync::registerStamp($sha256, $config);

        self::assertSame($stamp, Sync::registerStamp($sha256, Config::fromValues(self::SLICE, $config->values())));
        self::assertNotSame($stamp, Sync::registerStamp(str_repeat('b', 64), $config), 'another register');
        self::assertNotSame($stamp, Sync::registerStamp($sha256, $other));
    }

    public function test_registerFile_inTheUploadDirectoryOfTheInstall(): void
    {
        self::assertSame(IMG_UPLOAD_PATH . AA_ID . '/' . Sync::REGISTER_FILE, Sync::registerFile());
    }

    public function test_registerExists_looksWithoutCreatingTheDirectory(): void
    {
        $dir  = $this->scratch() . '/upload/aa';
        $file = "$dir/register.xml";

        self::assertFalse(Sync::registerExists($file));
        self::assertDirectoryDoesNotExist(dirname($dir), 'only a download creates it');
        mkdir($dir, 0o700, true);
        self::assertFalse(Sync::registerExists($file));
        file_put_contents($file, '12345');
        self::assertTrue(Sync::registerExists($file));
    }

    // any PHP user of the server may set the file's time and size; the record keeps the download's
    public function test_registerInfo_theRecordedDownloadNotTheFile(): void
    {
        $file = $this->scratch() . '/register.xml';
        self::assertNull(DownloadSync::registerInfo($file), 'nothing downloaded');

        LockedRegisterIndex::$record = ['xml' => str_repeat('a', 64), 'size' => '17000000', 'time' => '1760000000'];
        $recorded = ['time' => 1760000000, 'size' => 17000000, 'sha256' => str_repeat('a', 64)];
        self::assertSame(['state' => RegisterIndex::REPLACED] + $recorded, DownloadSync::registerInfo($file), 'gone is no register not downloaded');
        file_put_contents($file, '12345');
        touch($file, 1790000000);
        self::assertSame(['state' => RegisterIndex::RECORDED] + $recorded, DownloadSync::registerInfo($file));
        LockedRegisterIndex::$record = [];
        self::assertSame(['state' => RegisterIndex::UNRECORDED, 'time' => 0, 'size' => 0, 'sha256' => ''], DownloadSync::registerInfo($file), 'nothing vouches for it');
    }

    // the boxes a page pre-ticks come only from the register the record vouches for
    public function test_registerBoxes_onlyTheRecordedRegister(): void
    {
        $file = $this->scratch() . '/register.xml';
        LockedRegisterIndex::genuine($file);
        $sha256 = (string) hash_file('sha256', self::FIXTURE);

        self::assertEquals(self::$boxes, Sync::registerBoxes($sha256, $file));
        LockedRegisterIndex::swap($file);
        self::assertNull(Sync::registerBoxes($sha256, $file), 'another file in its place');
        unlink($file);
        mkdir($file);
        self::assertNull(Sync::registerBoxes($sha256, $file), 'no file in its place');
    }

    /** @return array<string, array{string, string}> the directory PHP may not search, the register's */
    public static function hiddenRegisters(): array
    {
        return [
            'its own directory'      => ['upload/aa', 'upload/aa'],
            'a directory above'      => ['upload', 'upload/aa'],
            'above a missing one'    => ['upload', 'upload/aa/missing'],
        ];
    }

    // a register PHP cannot see is no register not downloaded yet: the share of the upload directory
    // can lack the PHP user's group
    #[DataProvider('hiddenRegisters')]
    public function test_registerExists_aDirectoryPhpCannotSearchNamed(string $hidden, string $register): void
    {
        if (!function_exists('posix_geteuid') || (posix_geteuid() === 0)) {
            self::markTestSkipped('root searches every directory');
        }
        $root = $this->scratch();
        mkdir("$root/upload/aa", 0o700, true);
        file_put_contents("$root/upload/aa/register.xml", '12345');
        chmod("$root/$hidden", 0o600);

        try {
            Sync::registerExists("$root/$register/register.xml");
            self::fail('a register PHP cannot see passed for none');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString("$root/$hidden ", $e->getMessage());
        }
    }

    // ------------------------------------------------------------ download

    public function test_download_firstOneAllowed(): void
    {
        $file = $this->scratch() . '/register.xml';
        $http = self::registerHttp();

        $done = DownloadSync::download($http, 1760000000, $file);

        self::assertSame(['status' => Sync::DOWNLOADED, 'last' => 0], $done);
        self::assertCount(1, $http->calls);
        self::assertFileEquals(self::FIXTURE, $file);
        self::assertSame([[1760000000], 1, 1], [DownloadSync::$attempts, DownloadSync::$locks, DownloadSync::$unlocks]);
    }

    // the hash of what was downloaded vouches for the file; and no page has to parse the 17 MB
    public function test_download_recordsTheRegisterAndWritesItsIndex(): void
    {
        $file = $this->scratch() . '/register.xml';

        DownloadSync::download(self::registerHttp(), 1760000000, $file);

        self::assertSame([hash_file('sha256', self::FIXTURE), (string) filesize(self::FIXTURE)], [LockedRegisterIndex::$record['xml'], LockedRegisterIndex::$record['size']]);
        $index = new LockedRegisterIndex($file);
        self::assertSame(RegisterIndex::OK, $index->state());
        self::assertSame([1, 1], [LockedRegisterIndex::$locks, LockedRegisterIndex::$unlocks], 'made under the lock, no page makes it again');
        self::assertArrayHasKey('eerak7a', $index->lookup(['eerak7a']));
    }

    // the register is in place: the download succeeded, the failure is logged
    public function test_download_failedIndexWriteStillDownloaded(): void
    {
        $file = $this->scratch() . '/register.xml';
        mkdir($this->scratch . '/' . RegisterIndex::FILE);   // rename() cannot put a file over a directory

        self::assertSame(['status' => Sync::DOWNLOADED, 'last' => 0], DownloadSync::download(self::registerHttp(), 1760000000, $file));

        self::assertFileEquals(self::FIXTURE, $file);
        self::assertCount(1, LockedRegisterIndex::$logged);
        self::assertSame(RegisterIndex::UNREADABLE, (new LockedRegisterIndex($file))->state());
    }

    // a register no record vouches for cannot be used: a type of its own tells the page to say so
    public function test_download_failsWhenTheRecordCannotBeWritten(): void
    {
        $file = $this->scratch() . '/register.xml';
        LockedRegisterIndex::$writeFails = new \RuntimeException('Lock wait timeout');

        try {
            DownloadSync::download(self::registerHttp(), 1760000000, $file);
            self::fail('a download without its record passed');
        } catch (UnrecordedException $e) {
            self::assertStringEndsWith(': Lock wait timeout', $e->getMessage());
        }
        self::assertFileEquals(self::FIXTURE, $file, 'in place, so the previous one is not in use');
        self::assertSame([[1760000000], 1], [DownloadSync::$attempts, DownloadSync::$unlocks], 'an attempt, the lock released');
        self::assertSame([0, []], [LockedRegisterIndex::$locks, LockedRegisterIndex::$logged], 'no index made of it');
    }

    // another server, or another click, is downloading it
    public function test_download_refusedWithoutTheLock(): void
    {
        DownloadSync::$free = false;
        $http = self::registerHttp();

        self::assertSame(['status' => Sync::RUNNING, 'last' => 0], DownloadSync::download($http, 1760000000, $this->scratch() . '/register.xml'));
        self::assertSame([[], [], 0], [$http->calls, DownloadSync::$attempts, DownloadSync::$unlocks], 'no fetch, no attempt, nothing to unlock');
    }

    // no connection for the lock: "running" would hold every download back for good
    public function test_download_failsWhenTheDatabaseGivesNoLock(): void
    {
        DownloadSync::$free   = false;
        DownloadSync::$holder = null;
        $http = self::registerHttp();

        try {
            DownloadSync::download($http, 1760000000, $this->scratch() . '/register.xml');
            self::fail('no lock passed for a running download');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('no lock', $e->getMessage());
        }
        self::assertSame([[], [], 0], [$http->calls, DownloadSync::$attempts, DownloadSync::$unlocks]);
    }

    // every click costs the operator 17 MB, a failing one too
    public function test_download_notAgainSoonAfterAnAttempt(): void
    {
        $file = $this->scratch() . '/register.xml';
        $http = new FakeHttp([new Response(503, 'busy')]);

        try {
            DownloadSync::download($http, 1760000000, $file);
            self::fail('a failed download passed');
        } catch (\RuntimeException) {
        }
        $again = DownloadSync::download($http, 1760000000 + Sync::MIN_DOWNLOAD_AGE - 1, $file);

        self::assertSame(['status' => Sync::RECENT, 'last' => 1760000000], $again);
        self::assertCount(1, $http->calls, 'no second fetch');
        self::assertSame([[1760000000], 2, 2], [DownloadSync::$attempts, DownloadSync::$locks, DownloadSync::$unlocks], 'the attempt recorded before it failed, the lock released after it');
        self::assertFileDoesNotExist($file);

        $http = self::registerHttp();
        self::assertSame(Sync::DOWNLOADED, DownloadSync::download($http, 1760000000 + Sync::MIN_DOWNLOAD_AGE, $file)['status']);
        self::assertCount(1, $http->calls);
    }

    // the recorded download counts too: an install that does not log the attempts; not the file's
    // time, however old
    public function test_download_notSoonAfterTheRecordedDownload(): void
    {
        $file = $this->scratch() . '/register.xml';
        file_put_contents($file, 'x');
        touch($file, 1760000000 - 30 * 86400);
        LockedRegisterIndex::$record['time'] = '1760000000';
        $http = self::registerHttp();

        self::assertSame(['status' => Sync::RECENT, 'last' => 1760000000], DownloadSync::download($http, 1760000000 + 60, $file));
        self::assertSame([[], []], [$http->calls, DownloadSync::$attempts]);
    }

    // any PHP user of the server may touch the file: that holds no download back
    public function test_download_touchedFileHoldsNothingBack(): void
    {
        $file = $this->scratch() . '/register.xml';
        file_put_contents($file, 'x');
        touch($file, 1760000000 - 60);
        $http = self::registerHttp();

        self::assertSame(['status' => Sync::DOWNLOADED, 'last' => 0], DownloadSync::download($http, 1760000000, $file));
        self::assertCount(1, $http->calls);
    }

    // the clock of another server: a time ahead of now would block until then
    public function test_download_aTimeAheadOfNowIgnored(): void
    {
        $file = $this->scratch() . '/register.xml';
        DownloadSync::$attempts = [1760000000 + 86400];
        LockedRegisterIndex::$record['time'] = (string) (1760000000 + 365 * 86400);
        $http = self::registerHttp();

        self::assertSame(['status' => Sync::DOWNLOADED, 'last' => 0], DownloadSync::download($http, 1760000000, $file));
        self::assertCount(1, $http->calls);
    }

    /** @return array<string, array{string, int, string}> the register's directory, the mode of 'up', the cause */
    public static function unusableDirectories(): array
    {
        return [
            'not writable'          => ['up/aa', 0o500, 'PHP cannot write into the directory of the register of data boxes @/up/aa'],
            'missing'               => ['up/aa', 0o700, 'PHP cannot create the directory @/up/aa for the register of data boxes in @/up'],
            'parent not searchable' => ['up/aa', 0o600, 'PHP cannot look into the directory @/up on the way'],
        ];
    }

    // a directory PHP cannot write into fails before 17 MB are fetched, naming the cause
    #[DataProvider('unusableDirectories')]
    public function test_download_unusableDirectoryFailsBeforeTheFetch(string $dir, int $mode, string $cause): void
    {
        if (!function_exists('posix_geteuid') || (posix_geteuid() === 0)) {
            self::markTestSkipped('root writes into every directory');
        }
        $root = $this->scratch();
        mkdir("$root/up");
        if ($mode === 0o500) {
            mkdir("$root/$dir");
            chmod("$root/$dir", $mode);
        } else {
            chmod("$root/up", $mode);
        }
        $http = self::registerHttp();

        try {
            DownloadSync::download($http, 1760000000, "$root/$dir/register.xml");
            self::fail('downloaded into a directory PHP cannot write into');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString(str_replace('@', $root, $cause), $e->getMessage());
        }
        self::assertSame([[], [], 0], [$http->calls, DownloadSync::$attempts, DownloadSync::$locks], 'no fetch, no attempt, no lock');
    }

    public function test_isOld_afterOldDays(): void
    {
        self::assertFalse(Sync::isOld(1760000000, 1760000000 + Sync::OLD_DAYS * 86400));
        self::assertTrue(Sync::isOld(1760000000, 1760000000 + Sync::OLD_DAYS * 86400 + 1));
    }

    public function test_write_onlyTheDataBoxField(): void
    {
        $sync = self::sync([
            1 => self::item('Brno', '44992785', '', self::CITY),
            2 => self::item('Liberec', '00262978', 'x2pbqzq', self::CITY),
        ]);
        $report = $sync->report(self::$boxes);

        $result = $sync->write(self::rows($report, Sync::defaultTicked($report)));

        self::assertSame(['written' => 2, 'failed' => []], $result);
        self::assertCount(2, $sync->stored, 'one store per row');
        foreach ([0 => [self::id(1), 'a7kbrrn'], 1 => [self::id(2), '7c6by6u']] as $i => [$id, $box]) {
            $content = $sync->stored[$i]['content'];
            ksort($content);
            self::assertSame(['id..............', 'slice_id........', self::DS, 'unpacked_id.....'], array_keys($content));
            self::assertSame($box, $content[self::DS][0]['value']);
            self::assertSame($id, $content['unpacked_id.....'][0]['value']);
            self::assertSame(self::SLICE, unpack_id($content['slice_id........'][0]['value']));
        }
        // events off: ITEM_UPDATED would send a mail per item
        self::assertSame(['update_silent', [false, false, false, false]], [$sync->stored[0]['mode'], $sync->stored[0]['flags']]);
        self::assertSame(1, $sync->invalidated, 'the page cache once for all rows');
        self::assertSame(1, $sync->readingAllowed, 'storeItem() reads the old content with the reading password');
    }

    public function test_write_failuresCollected(): void
    {
        $sync = self::sync([
            1 => self::item('Brno', '44992785', '', self::CITY),
            2 => self::item('Liberec', '00262978', '', self::CITY),
            3 => self::item('Cerhenice', '00235300', '', self::CITY),
        ]);
        $sync->refuse = [self::id(1) => 'Bad Item Id', self::id(3) => ''];
        $report = $sync->report(self::$boxes);

        $result = $sync->write(self::rows($report, [self::id(1), self::id(2), self::id(3)]));

        self::assertSame(['written' => 1, 'failed' => [self::id(1) => 'Bad Item Id', self::id(3) => 'The item was not stored']], $result, 'not the message of the earlier failure');
        self::assertSame(1, $sync->invalidated);
    }

    public function test_write_nothingWrittenNothingInvalidated(): void
    {
        $sync = self::sync([1 => self::item('Brno', '44992785', '', self::CITY)]);
        $sync->refuse = [self::id(1) => ''];
        $report = $sync->report(self::$boxes);

        self::assertSame(['written' => 0, 'failed' => [self::id(1) => 'The item was not stored']], $sync->write(self::rows($report, [self::id(1)])));
        self::assertSame(['written' => 0, 'failed' => []], $sync->write([]));
        self::assertSame(0, $sync->invalidated);
    }

    /** @return array<string, array{list<string>, list<string>}> fields left out, other fields */
    public static function slicesChangedSince(): array
    {
        return [
            'the box field deleted'      => [[self::DS], []],
            'the box field in the item'  => [[self::DS], [self::DS => 'status_code']],
            'the name field a password'  => [[self::NAME], [self::NAME => 'pwd']],
        ];
    }

    /**
     * The settings were checked when saved, the slice's fields may have changed since.
     * @param list<string>          $without
     * @param array<string, string> $changed field id => in_item_tbl column, or 'pwd' for a password inserter
     */
    #[DataProvider('slicesChangedSince')]
    public function test_write_refusedWhenTheSettingsNoLongerFit(array $without, array $changed): void
    {
        $sync   = self::sync([1 => self::item('Brno', '44992785', '', self::CITY)]);
        $report = $sync->report(self::$boxes);
        $sync->fields = self::changedFields($without, $changed);

        try {
            $sync->write(self::rows($report, [self::id(1)]));
            self::fail('written with settings that do not fit');
        } catch (\RuntimeException $e) {
            self::assertNotSame('', $e->getMessage());
            self::assertSame([[], 0, 0], [$sync->stored, $sync->invalidated, $sync->readingAllowed]);
        }
    }

    /**
     * A protected field would show on the page.
     * @param list<string>          $without
     * @param array<string, string> $changed
     */
    #[DataProvider('slicesChangedSince')]
    public function test_report_refusedWhenTheSettingsNoLongerFit(array $without, array $changed): void
    {
        $sync = self::sync([1 => self::item('Brno', '44992785', '', self::CITY)]);
        $sync->fields = self::changedFields($without, $changed);

        try {
            $sync->report(self::$boxes);
            self::fail('read with settings that do not fit');
        } catch (\RuntimeException) {
            self::assertSame([], $sync->chunks, 'no item read');
        }
    }

    // storeItem() sets the slice of the item it updates - another slice's item would move here
    public function test_write_refusesAnItemTheReportDidNotRead(): void
    {
        $sync   = self::sync([1 => self::item('Brno', '44992785', '', self::CITY)]);
        $report = $sync->report(self::$boxes);
        $box    = new Box('7c6by6u', '00262978', '', 'Statutární město Liberec', 'OVM', 10, true, false);
        $rows   = [...self::rows($report, [self::id(1)]), new MatchRow(self::id(2), 'Liberec', self::CITY, '', $box, MatchRow::BY_ICO, MatchRow::NEW)];

        try {
            $sync->write($rows);
            self::fail('another item was written');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $sync->stored, 'not even the rows before it');
        }
    }

    // a row without a box would clear the field
    public function test_write_onlyWritableRows(): void
    {
        $sync   = self::sync([1 => self::item('Nikde', '', 'abcdefg', self::CITY), 2 => self::item('Cerhenice', '00235300', 'eerak7a', self::CITY)]);
        $report = $sync->report(self::$boxes);

        self::assertSame(['written' => 0, 'failed' => []], $sync->write($report->rows));
        self::assertSame([[], 0], [$sync->stored, $sync->invalidated]);
    }

    /**
     * The real storeItem() against RecordingDb: the old rows of the box field go, its new row comes,
     * the item row keeps its slice - no item column, no other field.
     */
    #[WithoutErrorHandler]      // the full legacy storeItem() trips notices on unset keys
    public function test_write_storeItemWritesOnlyTheBoxField(): void
    {
        $sync = $this->storeForReal(['historylog' => '0']);

        $writes = array_values(array_filter(RecordingDb::$log, static fn(string $sql): bool => (bool) preg_match('/^(INSERT|UPDATE|DELETE|REPLACE)\b/', $sql)));
        self::assertCount(3, $writes, implode("\n", $writes));
        self::assertMatchesRegularExpression('/^DELETE FROM `content`.*field_id\s*=\s*\'' . preg_quote(self::DS, '/') . '\'$/s', $writes[0]);
        self::assertStringStartsWith('INSERT INTO `content`', $writes[1]);
        self::assertMatchesRegularExpression('/^UPDATE `item` SET `slice_id`\s*=\s*0x' . self::REAL_SLICE . ' WHERE id\s*=\s*0x' . self::id(1) . '$/', $writes[2]);
        self::assertSame([
            self::contentRow(self::NAME, 'Liberec'),
            self::contentRow(self::OTHER, 'keep'),
            self::contentRow(self::DS, '7c6by6u'),
        ], RecordingDb::$content);
        self::assertSame(1, $sync->readingAllowed);
    }

    /**
     * storeItem() reads the item before the update with the reading password of the request - without
     * the slice's one, every field reads as "Error: Missing Reading Password" and the history lists
     * them all as changed.
     */
    #[WithoutErrorHandler]      // the full legacy storeItem() trips notices on unset keys
    public function test_write_historyOfAPasswordProtectedSliceHoldsOnlyTheBoxField(): void
    {
        $this->storeForReal(['historylog' => '1', 'reading_password' => AA_Credentials::encrypt('secret')]);

        $changes = RecordingDb::inserts('change_record');
        self::assertSame([self::DS], array_values(array_unique(array_column($changes, 'selector'))), print_r($changes, true));
        self::assertSame(['x2pbqzq'], array_column($changes, 'value'), 'the old value');
    }

    /** a password the request learnt would ride in the token of an {ajax} to later requests */
    #[WithoutErrorHandler]      // the full legacy storeItem() trips notices on unset keys
    public function test_write_opensTheSliceForTheWritingOnly(): void
    {
        $this->storeForReal(['historylog' => '0', 'reading_password' => AA_Credentials::encrypt('secret')]);

        self::assertFalse(AA_Credentials::singleton()->checkSlice(self::REAL_SLICE));
        self::assertSame(['spwd' => []], \AA_Stringexpand_Encrypt::decrypt_time_token(AA_Credentials::singleton()->getAsToken(), 'slice-pwd'));
    }

    /**
     * Writes the box of Liberec (x2pbqzq -> 7c6by6u) through the real storeItem() into a slice with
     * the settings $props, two item-table fields and a field the settings do not name.
     * @param array<string, mixed> $props
     */
    private function storeForReal(array $props): RecordingSync
    {
        $fields = self::fields(extra: [
            self::field('status_code.....', ['in_item_tbl' => 'status_code', 'text_stored' => 0]),
            self::field('publish_date....', ['in_item_tbl' => 'publish_date', 'text_stored' => 0]),
            self::field(self::OTHER),
        ]);
        new PropertySlice(self::REAL_SLICE, $fields, $props);
        $GLOBALS['spareDBs']  = [new RecordingDb()];
        RecordingDb::$content = [self::contentRow(self::DS, 'x2pbqzq'), self::contentRow(self::NAME, 'Liberec'), self::contentRow(self::OTHER, 'keep')];
        // the item row, and the content as the statements so far have left it
        RecordingDb::$answer = static fn(string $sql): array => match (true) {
            str_starts_with($sql, 'SELECT * FROM item WHERE') => [[
                'id' => pack_id(self::id(1)), 'slice_id' => pack_id(self::REAL_SLICE), 'status_code' => '1', 'publish_date' => '1700000000', 'expiry_date' => '4000000000',
            ] + array_fill_keys(AA_Metabase::singleton()->getColumnNames('item'), '')],
            str_starts_with($sql, 'SELECT * FROM content WHERE') => array_map(
                static fn(array $r): array => ['item_id' => pack_id($r['item']), 'field_id' => $r['field'], 'number' => $r['number'], 'text' => $r['text'], 'flag' => $r['flag']],
                RecordingDb::$content,
            ),
            default => [],
        };

        $sync = self::sync([1 => self::item('Liberec', '00262978', 'x2pbqzq', self::CITY)], slice: self::REAL_SLICE);
        $sync->fields    = $fields;
        $sync->realStore = true;
        $report = $sync->report(self::$boxes);
        self::assertSame(['written' => 1, 'failed' => []], $sync->write(self::rows($report, [self::id(1)])));
        return $sync;
    }

    /** @return array{item: string, field: string, number: int, text: string, flag: int} a `content` row of item 1 as RecordingDb keeps it */
    private static function contentRow(string $field, string $text): array
    {
        return ['item' => self::id(1), 'field' => $field, 'number' => 0, 'text' => $text, 'flag' => 64];
    }

    /**
     * @param array<int, array<string, mixed>> $items number => content, see id()
     * @param array<string, mixed>             $over  config values in place of the defaults
     */
    private static function sync(array $items = [], array $over = [], string $slice = self::SLICE): RecordingSync
    {
        $sync = new RecordingSync(Config::fromValues($slice, $over + [
            'name_field' => self::NAME, 'ico_field' => self::ICO, 'ds_field' => self::DS, 'type_field' => self::TYPE,
        ]));
        $sync->fields = self::fields();
        foreach ($items as $n => $content) {
            $sync->content[self::id($n)] = $content;
        }
        return $sync;
    }

    /**
     * The slice's fields: the four the settings name, as content fields.
     * @param list<string>   $without ids left out
     * @param list<AA_Field> $extra
     */
    private static function fields(array $without = [], array $extra = []): FakeFields
    {
        $ids = array_diff([self::NAME, self::ICO, self::DS, self::TYPE], $without);
        return new FakeFields(self::SLICE, [...array_map(static fn(string $id): AA_Field => self::field($id), $ids), ...$extra]);
    }

    /**
     * @param list<string>          $without ids left out
     * @param array<string, string> $changed field id => its in_item_tbl column, or 'pwd' for a password inserter
     */
    private static function changedFields(array $without, array $changed): FakeFields
    {
        $extra = [];
        foreach ($changed as $id => $how) {
            $extra[] = self::field($id, ($how === 'pwd') ? ['input_insert_func' => 'pwd:'] : ['in_item_tbl' => $how]);
        }
        return self::fields($without, $extra);
    }

    /** @param array<string, mixed> $data */
    private static function field(string $id, array $data = []): AA_Field
    {
        // no slice id: AA_Field::isPasswordCopy() would load the slice
        return new AA_Field($data + ['id' => $id, 'slice_id' => '', 'in_item_tbl' => '', 'text_stored' => 1, 'input_show_func' => 'fld', 'input_insert_func' => 'qte', 'content_edit' => 0, 'html_default' => 0, 'multiple' => 0]);
    }

    /**
     * toWrite()['rows'] of the items ticked as the report shows them.
     * @param list<string> $ids
     * @return list<MatchRow>
     */
    private static function rows(MatchReport $report, array $ids): array
    {
        $seen = array_column(array_map(static fn(MatchRow $row): array => [(string) $row->itemId, Sync::tickValue($row)], $report->rows), 1, 0);
        return Sync::toWrite($report, array_intersect_key($seen, array_flip($ids)))['rows'];
    }

    /** @param array<int, array<string, mixed>> $items number => content */
    private static function report(array $items): MatchReport
    {
        return self::sync($items)->report(self::$boxes);
    }

    /** @return array<string, list<array{value: string, flag: int}>> content as GetItemContent() returns it */
    private static function item(string $name, string $ico = '', string $ds = '', string $type = ''): array
    {
        $values = [self::NAME => $name, self::ICO => $ico, self::DS => $ds, self::TYPE => $type];
        return array_map(static fn(string $v): array => [['value' => $v, 'flag' => 0]], array_filter($values, static fn(string $v): bool => $v !== ''));
    }

    /** a checkbox value as tickValue() makes it: the box offered, a hash of the box the item had */
    private static function tick(string $box, string $stored = ''): string
    {
        return $box . '|' . substr(md5(strtolower(trim($stored))), 0, 12);
    }

    /** answers with the register of the fixture */
    private static function registerHttp(): FakeHttp
    {
        return new FakeHttp([new Response(200, (string) file_get_contents(self::FIXTURE))]);
    }

    private function scratch(): string
    {
        $this->scratch = sys_get_temp_dir() . '/isds-sync-' . bin2hex(random_bytes(6));
        mkdir($this->scratch, 0o700);
        return $this->scratch;
    }

    private static function removeTree(string $dir): void
    {
        @chmod($dir, 0o700);
        foreach (scandir($dir) ?: [] as $name) {
            if (($name !== '.') && ($name !== '..')) {
                is_dir("$dir/$name") && !is_link("$dir/$name") ? self::removeTree("$dir/$name") : @unlink("$dir/$name");
            }
        }
        @rmdir($dir);
    }

    /** a long id with a letter, so PHP never makes it an int key */
    private static function id(int $n): string
    {
        return sprintf('a%031d', $n);
    }
}
