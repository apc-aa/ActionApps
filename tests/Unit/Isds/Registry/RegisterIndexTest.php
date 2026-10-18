<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Registry;

use AA\Isds\Registry\RegisterIndex;
use AA\Isds\Registry\UnusableRegisterException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Fixtures\Alarm;
use Tests\Unit\Fixtures\ScriptedFile;
use Tests\Unit\Isds\Registry\Fixtures\LockedRegisterIndex;

/**
 * RegisterIndex over a register in a directory of each test's own, with the record, the log and the
 * lock of LockedRegisterIndex. The boxes are the 31 real ones of the SdsList fixture, or a few made up.
 */
final class RegisterIndexTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/fixtures/seznam_ds_ovm-sample.xml';

    private const CHANGED = 'The register of data boxes is not the one AA downloaded - download it again';

    private string $dir = '';

    private string $register = '';

    private string $index = '';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/fixtures/LockedRegisterIndex.php';
        require_once __DIR__ . '/../../fixtures/Alarm.php';
        require_once __DIR__ . '/../../fixtures/ScriptedFile.php';
        ScriptedFile::register();
    }

    protected function setUp(): void
    {
        LockedRegisterIndex::reset();
        $this->dir      = sys_get_temp_dir() . '/isds-index-' . bin2hex(random_bytes(6));
        $this->register = $this->dir . '/register.xml';
        $this->index    = $this->dir . '/' . RegisterIndex::FILE;
        mkdir($this->dir, 0o700);
    }

    protected function tearDown(): void
    {
        @chmod($this->dir, 0o700);
        foreach (scandir($this->dir) ?: [] as $name) {
            if (($name !== '.') && ($name !== '..')) {
                is_dir("$this->dir/$name") ? @rmdir("$this->dir/$name") : @unlink("$this->dir/$name");
            }
        }
        @rmdir($this->dir);
    }

    public function test_lookup_keepsOnlyAskedBoxes(): void
    {
        $index = $this->fixtureIndex();

        $found = $index->lookup(['eerak7a', 'zzzzzzz', 'x2pbqzq', '27hmz4e', 'eerak7a']);

        self::assertSame([
            '27hmz4e' => ['id' => '27hmz4e', 'ico' => '', 'subtype' => 10, 'master' => true, 'name' => 'Celní úřad v Praze'],
            'x2pbqzq' => ['id' => 'x2pbqzq', 'ico' => '70888337', 'subtype' => 10, 'master' => true, 'name' => 'Jihomoravský kraj'],
            'eerak7a' => ['id' => 'eerak7a', 'ico' => '00235300', 'subtype' => 10, 'master' => true, 'name' => 'Městys Cerhenice'],
        ], $found, 'in the order of the register');
        self::assertSame(RegisterIndex::OK, $index->state());
        self::assertSame([], $index->lookup([]));
        self::assertSame('', $index->problem());
        self::assertCount(31 + 1, (array) file($this->index), 'a line per box and the header');
        self::assertSame([1, 1], [LockedRegisterIndex::$locks, LockedRegisterIndex::$unlocks], 'made once, at the download');
    }

    // a part of an authority shares its IČO, persons and companies act as one: the index tells them apart
    public function test_lookup_carriesSubtypeAndMaster(): void
    {
        $found = $this->fixtureIndex()->lookup(['8iedd6n', 'eerak7a', 'mqhmhtu', 'qebd4jz', '3th5n6b']);

        self::assertSame([
            '8iedd6n' => [16, true],
            'eerak7a' => [10, true],
            'mqhmhtu' => [13, false],
            'qebd4jz' => [10, false],
            '3th5n6b' => [15, true],
        ], array_map(static fn(array $row): array => [$row['subtype'], $row['master']], $found));
    }

    // as items keep the IČO; an IČO that is none must not pass for a missing one
    public function test_lookup_normalizesIcoMarksBadOneAndKeepsNamesOnOneLine(): void
    {
        $this->writeRegister([
            'abcdefg' => ['63703', "Obec\tHorní&#10;Dolní&#13;&#10;&#9;Lhota"],
            'bcdefgh' => ['', 'Městská část Praha 9'],
            'cdefghi' => ['IČO 1', 'Úřad'],
        ]);
        LockedRegisterIndex::download($this->register);

        $found = (new LockedRegisterIndex($this->register))->lookup(['abcdefg', 'bcdefgh', 'cdefghi']);

        self::assertSame([
            'abcdefg' => ['id' => 'abcdefg', 'ico' => '00063703', 'subtype' => 0, 'master' => false, 'name' => 'Obec Horní Dolní Lhota'],
            'bcdefgh' => ['id' => 'bcdefgh', 'ico' => '', 'subtype' => 0, 'master' => false, 'name' => 'Městská část Praha 9'],
            'cdefghi' => ['id' => 'cdefghi', 'ico' => RegisterIndex::BAD_ICO, 'subtype' => 0, 'master' => false, 'name' => 'Úřad'],
        ], $found);
        foreach (array_slice((array) file($this->index, FILE_IGNORE_NEW_LINES), 1) as $line) {
            self::assertCount(5, explode("\t", (string) $line));
        }
    }

    // a line of the index has a bounded length, and a cut never splits a letter
    public function test_lookup_longNameCutAtALetter(): void
    {
        $this->writeRegister(['abcdefg' => ['63703', 'x' . str_repeat('Ř', 1000)]]);
        LockedRegisterIndex::download($this->register);

        $found = (new LockedRegisterIndex($this->register))->lookup(['abcdefg']);

        self::assertSame('x' . str_repeat('Ř', 499), $found['abcdefg']['name']);
    }

    // the longest line make() can write is no damage
    public function test_lookup_longestLineRead(): void
    {
        $this->writeRegister(['abcdefg' => ['63703', str_repeat('x', 1001), str_repeat('9', 20)]]);
        LockedRegisterIndex::download($this->register);

        $found = (new LockedRegisterIndex($this->register))->lookup(['abcdefg']);

        self::assertSame([PHP_INT_MAX, 1000], [$found['abcdefg']['subtype'], strlen($found['abcdefg']['name'])]);
        self::assertSame(1, LockedRegisterIndex::$locks, 'not made again');
    }

    // PHP makes such a key an int; the row keeps the id as text
    public function test_lookup_digitOnlyIdKeepsItsIdInTheRow(): void
    {
        $this->writeRegister(['1234567' => ['00063703', 'Obec']]);
        LockedRegisterIndex::download($this->register);

        $found = (new LockedRegisterIndex($this->register))->lookup(['1234567']);

        self::assertSame([['id' => '1234567', 'ico' => '00063703', 'subtype' => 0, 'master' => false, 'name' => 'Obec']], array_values($found));
        self::assertSame('1234567', $found[1234567]['id']);
    }

    // another request held the lock at the download: the first page that looks makes it
    public function test_state_makesTheIndexOfANewDownload(): void
    {
        $this->fixtureIndex();
        $this->writeRegister(['abcdefg' => ['00063703', 'Obec']]);
        LockedRegisterIndex::$free = false;
        LockedRegisterIndex::download($this->register);
        LockedRegisterIndex::$free = true;

        $index = new LockedRegisterIndex($this->register);

        self::assertSame(['abcdefg'], array_keys($index->lookup(['abcdefg', 'eerak7a'])));
        self::assertSame([3, 2], [LockedRegisterIndex::$locks, LockedRegisterIndex::$unlocks]);
    }

    public function test_state_missingRegister(): void
    {
        $index = new LockedRegisterIndex($this->register);

        self::assertSame(RegisterIndex::MISSING, $index->state());
        self::assertSame(['', 0], [$index->problem(), LockedRegisterIndex::$locks]);
        self::assertFileDoesNotExist($this->index);
        $this->expectException(\RuntimeException::class);
        $index->lookup(['abcdefg']);
    }

    // the downloaded register was deleted: it cannot be used, and the problem says to download it again
    public function test_state_recordedRegisterGoneIsUnreadable(): void
    {
        $this->fixtureIndex();
        unlink($this->register);

        $index = new LockedRegisterIndex($this->register);

        self::assertSame(RegisterIndex::UNREADABLE, $index->state());
        self::assertSame(self::CHANGED, $index->problem());
    }

    // the time of the download counts, not the file's - another server may have another clock
    public function test_state_oldRegister(): void
    {
        $this->fixtureIndex();
        LockedRegisterIndex::$record['time'] = (string) (time() - 8 * 86400);
        $index = new LockedRegisterIndex($this->register);

        self::assertSame(RegisterIndex::OLD, $index->state());
        self::assertSame(['eerak7a'], array_keys($index->lookup(['eerak7a'])));
    }

    // GlusterFS refused PHP the directory at times
    public function test_state_unreadableDirIsUnreadableNotException(): void
    {
        self::skipAsRoot();
        $this->fixtureIndex();
        chmod($this->dir, 0o000);
        $index = new LockedRegisterIndex($this->register);

        self::assertSame(RegisterIndex::UNREADABLE, $index->state());
        self::assertStringContainsString($this->dir, $index->problem());
        $this->expectException(\RuntimeException::class);
        $index->lookup(['eerak7a']);
    }

    // downloaded by an older AA, or put there by hand: nothing vouches for it
    public function test_state_registerNotRecordedIsUnreadable(): void
    {
        copy(self::FIXTURE, $this->register);
        $index = new LockedRegisterIndex($this->register);

        self::assertSame(RegisterIndex::UNREADABLE, $index->state());
        self::assertSame(self::CHANGED, $index->problem());
        self::assertSame(0, LockedRegisterIndex::$locks, 'nothing is parsed');
        self::assertFileDoesNotExist($this->index);
    }

    // another user of the server put another file in its place: not indexed, logged, held back
    public function test_state_forgedRegisterRefused(): void
    {
        $this->fixtureIndex();
        $this->writeRegister(['abcdefg' => ['00063703', 'Podvržený úřad']]);
        unlink($this->index);

        $index = new LockedRegisterIndex($this->register);

        self::assertSame(RegisterIndex::UNREADABLE, $index->state());
        self::assertSame(self::CHANGED, $index->problem());
        self::assertSame([self::CHANGED], LockedRegisterIndex::$logged);
        self::assertSame(LockedRegisterIndex::$record['xml'], LockedRegisterIndex::$record['failed_of']);
        self::assertSame(['register.xml'], $this->files(), 'no index, no temporary file');

        // another request within RETRY neither waits for the lock nor parses
        self::newRequest();
        self::assertSame(RegisterIndex::UNREADABLE, (new LockedRegisterIndex($this->register))->state());
        self::assertSame([2, 1], [LockedRegisterIndex::$locks, count(LockedRegisterIndex::$logged)]);

        // after it, one tries again
        LockedRegisterIndex::$record['failed'] = (string) (time() - 601);
        self::newRequest();
        self::assertSame(RegisterIndex::UNREADABLE, (new LockedRegisterIndex($this->register))->state());
        self::assertSame([3, 2], [LockedRegisterIndex::$locks, count(LockedRegisterIndex::$logged)]);
    }

    // a forged index under the right header: made again from the register the record vouches for
    public function test_state_forgedIndexMadeAgain(): void
    {
        $this->fixtureIndex();
        $header = (string) strtok((string) file_get_contents($this->index), "\n");
        file_put_contents($this->index, "$header\nabcdefg\t00063703\t10\t1\tPodvržený úřad\n");

        $index = new LockedRegisterIndex($this->register);

        self::assertSame(RegisterIndex::OK, $index->state());
        self::assertSame(['eerak7a'], array_keys($index->lookup(['abcdefg', 'eerak7a'])));
        self::assertSame(2, LockedRegisterIndex::$locks);
    }

    // an index the previous AA made, its hash recorded: made again, never read as one of today's format
    public function test_state_indexOfAnOlderFormatMadeAgain(): void
    {
        $this->fixtureIndex();
        $old = 'aa-isds-register-index-1 ' . LockedRegisterIndex::$record['xml'] . "\nmqhmhtu\t00235300\tSpráva domů a bytů Cerhenice\n";
        file_put_contents($this->index, $old);
        LockedRegisterIndex::$record['index'] = hash('sha256', $old);

        $found = (new LockedRegisterIndex($this->register))->lookup(['mqhmhtu']);

        self::assertSame([13, false], [$found['mqhmhtu']['subtype'], $found['mqhmhtu']['master']]);
        self::assertSame(2, LockedRegisterIndex::$locks);
        self::assertStringStartsWith(self::header(LockedRegisterIndex::$record['xml']), (string) file_get_contents($this->index));
    }

    // replaced after state() checked it: lookup() answers from the very bytes that were checked
    public function test_lookup_indexReplacedAfterStateReadsTheCheckedOne(): void
    {
        $index = $this->fixtureIndex();
        self::assertSame(RegisterIndex::OK, $index->state());

        file_put_contents($this->index, self::header(str_repeat('0', 64)) . "abcdefg\t\t10\t1\tPodvržený úřad\n");

        self::assertSame(['eerak7a'], array_keys($index->lookup(['abcdefg', 'eerak7a'])));
        self::assertSame('', $index->problem());
        self::assertSame(1, LockedRegisterIndex::$locks, 'nothing made again');
    }

    // state() made the index, so lookup() reads it - another one by then, under a forged register
    public function test_lookup_forgedIndexAndRegisterThrows(): void
    {
        $this->fixtureIndex();
        unlink($this->index);
        $index = new LockedRegisterIndex($this->register);
        self::assertSame(RegisterIndex::OK, $index->state());
        $this->writeRegister(['abcdefg' => ['00063703', 'Podvržený úřad']]);
        file_put_contents($this->index, 'garbage');

        try {
            $index->lookup(['abcdefg']);
            self::fail('a forged index passed');
        } catch (UnusableRegisterException $e) {
            self::assertSame(self::CHANGED, $e->getMessage());
        }
        self::assertSame(RegisterIndex::UNREADABLE, $index->state(), 'the state says so from then on');
    }

    // the index and the hash are read and computed once for state() and every lookup()
    public function test_lookup_readsTheIndexOnce(): void
    {
        $index = $this->fixtureIndex();
        self::assertSame(RegisterIndex::OK, $index->state());
        unlink($this->index);

        self::assertSame(['eerak7a'], array_keys($index->lookup(['eerak7a'])));
        self::assertSame(['x2pbqzq'], array_keys($index->lookup(['x2pbqzq'])));
        self::assertSame(1, LockedRegisterIndex::$locks, 'nothing made again');
    }

    // a failed write of the record ends like any failure to make the index - never in the page
    public function test_state_indexHashNotRecordedIsUnreadable(): void
    {
        copy(self::FIXTURE, $this->register);
        LockedRegisterIndex::$free = false;
        LockedRegisterIndex::download($this->register);
        LockedRegisterIndex::$free       = true;
        LockedRegisterIndex::$writeFails = new \RuntimeException('Lost connection to the database');
        $index                           = new LockedRegisterIndex($this->register);

        self::assertSame([RegisterIndex::UNREADABLE, false], [$index->state(), $index->usable()]);
        self::assertSame('The index of the register of data boxes is made, but its hash cannot be recorded: Lost connection to the database', $index->problem());
        self::assertSame([$index->problem() . ' - not recorded: Lost connection to the database'], LockedRegisterIndex::$logged);
        self::assertSame(RegisterIndex::UNREADABLE, (new LockedRegisterIndex($this->register))->state(), 'held back in this process');
        self::assertSame([2, 1], [LockedRegisterIndex::$locks, count(LockedRegisterIndex::$logged)]);
    }

    // another request makes it for longer than the wait, or the database gives no lock
    public function test_state_busyLockIsUnreadableForTheRegister(): void
    {
        copy(self::FIXTURE, $this->register);
        LockedRegisterIndex::$free = false;
        LockedRegisterIndex::download($this->register);

        self::assertSame(RegisterIndex::UNREADABLE, (new LockedRegisterIndex($this->register))->state());
        self::assertSame([2, 0], [LockedRegisterIndex::$locks, LockedRegisterIndex::$unlocks], 'nothing to unlock');
        self::assertSame([], LockedRegisterIndex::$logged, 'a busy lock is no failure');

        LockedRegisterIndex::$free = true;
        self::assertSame(RegisterIndex::UNREADABLE, (new LockedRegisterIndex($this->register))->state(), 'for the rest of the request');
        self::assertSame(2, LockedRegisterIndex::$locks, 'without waiting again');

        // a new download: another register, tried again in the same process (the task queue)
        $this->writeRegister(['abcdefg' => ['00063703', 'Obec']]);
        LockedRegisterIndex::$free = false;
        LockedRegisterIndex::download($this->register);
        LockedRegisterIndex::$free = true;
        self::assertSame(RegisterIndex::OK, (new LockedRegisterIndex($this->register))->state());
    }

    // parsing 17 MB again would fail the same way: not in this process, nor in others for RETRY
    public function test_state_failedWriteNotRetried(): void
    {
        copy(self::FIXTURE, $this->register);
        mkdir($this->index);    // rename() cannot put a file over a directory

        LockedRegisterIndex::download($this->register);

        self::assertCount(1, LockedRegisterIndex::$logged);
        self::assertStringContainsString('Cannot replace ' . $this->index, LockedRegisterIndex::$logged[0]);
        self::assertSame(['isds-seznam-ds-ovm.idx', 'register.xml'], $this->files(), 'no temporary file left');
        $index = new LockedRegisterIndex($this->register);
        self::assertSame(RegisterIndex::UNREADABLE, $index->state());
        self::assertSame(LockedRegisterIndex::$logged[0], $index->problem());
        self::assertSame(1, LockedRegisterIndex::$locks, 'no second attempt');

        rmdir($this->index);
        LockedRegisterIndex::$record['failed'] = (string) (time() - 601);
        self::newRequest();
        self::assertSame(RegisterIndex::OK, (new LockedRegisterIndex($this->register))->state());
    }

    // an index scan() cannot read would send every lookup back to the register
    public function test_write_indexLargerThanScanReadsRefused(): void
    {
        $this->writeRegister(['abcdefg' => ['63703', 'Obec'], 'bcdefgh' => ['', str_repeat('x', 200)]]);
        LockedRegisterIndex::$maxIndex = 200;

        LockedRegisterIndex::download($this->register);

        self::assertCount(1, LockedRegisterIndex::$logged);
        self::assertStringContainsString('unusually large', LockedRegisterIndex::$logged[0]);
        self::assertSame(['register.xml'], $this->files());
        $index = new LockedRegisterIndex($this->register);
        self::assertSame(RegisterIndex::UNREADABLE, $index->state());
        self::assertSame(LockedRegisterIndex::$logged[0], $index->problem());
        self::assertSame(1, LockedRegisterIndex::$locks, 'no second attempt');
    }

    // check() would refuse it on every request, each parsing the register again
    public function test_write_lineLongerThanCheckReadsRefused(): void
    {
        $this->writeRegister(['abcdefg' => ['63703', 'Obec'], 'bcdefgh' => ['', str_repeat('x', 20)]]);
        LockedRegisterIndex::$maxLine = 33;

        LockedRegisterIndex::download($this->register);

        self::assertSame(['The register of data boxes has the box bcdefgh, whose line in the index would have 33 bytes - AA reads lines of 32 at most'], LockedRegisterIndex::$logged);
        self::assertSame(['register.xml'], $this->files());
        $index = new LockedRegisterIndex($this->register);
        self::assertSame([RegisterIndex::UNREADABLE, LockedRegisterIndex::$logged[0]], [$index->state(), $index->problem()]);
        self::assertSame(1, LockedRegisterIndex::$locks, 'no second attempt');
    }

    public function test_write_indexAsLargeAsScanReadsMade(): void
    {
        $this->writeRegister(['abcdefg' => ['63703', 'Obec'], 'bcdefgh' => ['', str_repeat('x', 200)]]);
        LockedRegisterIndex::download($this->register);
        LockedRegisterIndex::$maxIndex = (int) filesize($this->index);
        unlink($this->index);
        self::newRequest();

        $index = new LockedRegisterIndex($this->register);

        self::assertSame(RegisterIndex::OK, $index->state());
        self::assertSame(['bcdefgh'], array_keys($index->lookup(['bcdefgh'])));
    }

    // the temporary file goes again with the failed parse
    public function test_write_damagedRegisterLeavesNothing(): void
    {
        file_put_contents($this->register, '<html><body>Service unavailable</body></html>');

        LockedRegisterIndex::download($this->register);

        self::assertCount(1, LockedRegisterIndex::$logged);
        self::assertSame(['register.xml'], $this->files());
        self::assertSame(RegisterIndex::UNREADABLE, (new LockedRegisterIndex($this->register))->state());
    }

    public function test_write_refusedInADirectoryPhpCannotWriteInto(): void
    {
        self::skipAsRoot();
        copy(self::FIXTURE, $this->register);
        chmod($this->dir, 0o500);

        LockedRegisterIndex::download($this->register);

        self::assertStringContainsString("Cannot create a file in $this->dir", LockedRegisterIndex::$logged[0] ?? '');
        self::assertSame(['register.xml'], $this->files());
    }

    // another file put in place of the register after it was read and hashed is never parsed
    public function test_write_parsesTheBytesItHashed(): void
    {
        copy(self::FIXTURE, $this->register);
        LockedRegisterIndex::$free      = false;
        LockedRegisterIndex::download($this->register);
        LockedRegisterIndex::$free      = true;
        LockedRegisterIndex::$afterRead = function (): void {
            rename($this->register, $this->register . '.genuine');
            $this->writeRegister(['abcdefg' => ['00063703', 'Podvržený úřad']]);
        };

        $index = new LockedRegisterIndex($this->register);

        self::assertSame(RegisterIndex::OK, $index->state());
        self::assertNull(LockedRegisterIndex::$afterRead, 'swapped between the read and the parse');
        self::assertSame(['eerak7a'], array_keys($index->lookup(['abcdefg', 'eerak7a'])));
        self::assertStringNotContainsString('abcdefg', LockedRegisterIndex::indexed($this->register));
        self::assertCount(31, explode("\n", rtrim(LockedRegisterIndex::indexed($this->register))));
    }

    // a download recorded register B while a slower request wrote the index of A and its hash
    public function test_state_indexOfAnotherRegisterMadeAgain(): void
    {
        $this->fixtureIndex();
        $this->writeRegister(['abcdefg' => ['00063703', 'Obec']]);
        LockedRegisterIndex::$record['xml'] = (string) hash_file('sha256', $this->register);

        $index = new LockedRegisterIndex($this->register);

        self::assertSame(['abcdefg'], array_keys($index->lookup(['abcdefg', 'eerak7a'])));
        self::assertSame(2, LockedRegisterIndex::$locks);
    }

    // a failure recorded for the previous register holds the new one back for nothing
    public function test_state_failureOfAnotherRegisterIgnored(): void
    {
        copy(self::FIXTURE, $this->register);
        LockedRegisterIndex::$free = false;
        LockedRegisterIndex::download($this->register);
        LockedRegisterIndex::$free   = true;
        LockedRegisterIndex::$record = ['failed' => (string) time(), 'failed_of' => str_repeat('a', 64), 'failure' => 'old'] + LockedRegisterIndex::$record;

        self::assertSame(RegisterIndex::OK, (new LockedRegisterIndex($this->register))->state());
    }

    // the request waited for made it: this one reads it, parses nothing
    public function test_state_indexMadeWhileWaitingNotMadeAgain(): void
    {
        copy(self::FIXTURE, $this->register);
        LockedRegisterIndex::$free = false;
        LockedRegisterIndex::download($this->register);
        LockedRegisterIndex::$free         = true;
        LockedRegisterIndex::$writes       = [];
        LockedRegisterIndex::$whileWaiting = fn() => LockedRegisterIndex::download($this->register);

        self::assertSame(RegisterIndex::OK, (new LockedRegisterIndex($this->register))->state());
        self::assertCount(1, array_filter(LockedRegisterIndex::$writes, static fn(array $w): bool => ($w['index'] ?? '') !== ''), 'made once, by the request waited for');
    }

    /** @return array<string, array{string}> what may lie at a path instead of a file */
    public static function notFiles(): array
    {
        return ['FIFO' => ['fifo'], 'device' => ['/dev/zero'], 'directory' => ['dir']];
    }

    // a FIFO would block the open, a device never end - with the index lock held; whatever lies there,
    // it is not the register AA downloaded
    #[DataProvider('notFiles')]
    public function test_state_registerThatIsNoFileRefusedAtOnce(string $what): void
    {
        $this->fixtureIndex();
        unlink($this->index);
        unlink($this->register);
        $this->notAFile($this->register, $what);

        $index = new LockedRegisterIndex($this->register);

        self::assertSame(RegisterIndex::UNREADABLE, Alarm::within(2, $index->state(...)));
        self::assertSame(self::CHANGED, $index->problem());
    }

    #[DataProvider('notFiles')]
    public function test_state_indexThatIsNoFileMadeAgain(string $what): void
    {
        $this->fixtureIndex();
        unlink($this->index);
        $this->notAFile($this->index, $what);

        $index = new LockedRegisterIndex($this->register);

        // rename() puts the new index in place of a FIFO or a link, not of a directory
        self::assertSame(($what === 'dir') ? RegisterIndex::UNREADABLE : RegisterIndex::OK, Alarm::within(2, $index->state(...)));
        self::assertSame($what !== 'dir', is_file($this->index) && !is_link($this->index));
    }

    // longer than any line the index gets: damage, made again
    public function test_state_overLongLineMadeAgain(): void
    {
        $this->fixtureIndex();
        $text = (string) file_get_contents($this->index);
        $long = substr($text, 0, (int) strpos($text, "\n") + 1) . 'abcdefg' . "\t\t10\t1\t" . str_repeat('x', 2000) . "\n";
        file_put_contents($this->index, $long);
        LockedRegisterIndex::$record['index'] = hash('sha256', $long);

        $index = new LockedRegisterIndex($this->register);

        self::assertSame(['eerak7a'], array_keys($index->lookup(['abcdefg', 'eerak7a'])));
        self::assertSame(2, LockedRegisterIndex::$locks);
    }

    // cut short after its last box: damage, made again
    public function test_state_lastLineWithoutItsEndMadeAgain(): void
    {
        $this->fixtureIndex();
        $cut = rtrim((string) file_get_contents($this->index), "\n");
        file_put_contents($this->index, $cut);
        LockedRegisterIndex::$record['index'] = hash('sha256', $cut);

        $index = new LockedRegisterIndex($this->register);

        self::assertSame(['eerak7a'], array_keys($index->lookup(['eerak7a'])));
        self::assertSame(2, LockedRegisterIndex::$locks);
        self::assertStringEndsWith("\n", (string) file_get_contents($this->index));
    }

    // an index someone keeps writing to would never end: it is read only up to the size make() may
    // write, and is no index then
    public function test_state_growingIndexReadOnlyToItsCap(): void
    {
        $cap = (int) (new \ReflectionClassConstant(RegisterIndex::class, 'MAX_INDEX'))->getValue();
        LockedRegisterIndex::$record = ['xml' => hash('sha256', 'register'), 'time' => time(), 'index' => hash('sha256', 'index')];
        ScriptedFile::$content = static fn(int $open): string => self::header(hash('sha256', 'register'));
        ScriptedFile::$growth  = "abcdefg\t\t10\t1\tx\n";
        try {
            $index = new LockedRegisterIndex(ScriptedFile::SCHEME . '://register/register.xml');

            self::assertSame(RegisterIndex::UNREADABLE, $index->state());
            // before the lock and under it; more opens would make the bound below prove nothing
            self::assertContains(ScriptedFile::$opens, [1, 2]);
            // one read ahead of PHP's stream buffer past the cap, at most
            self::assertLessThanOrEqual(ScriptedFile::$opens * ($cap + (2 << 20)), ScriptedFile::$served);
        } finally {
            ScriptedFile::reset();
        }
    }

    // reading a directory fails with a notice, which must not reach the page
    public function test_state_indexThatIsADirectoryReadWithoutNotice(): void
    {
        copy(self::FIXTURE, $this->register);
        LockedRegisterIndex::$free = false;
        LockedRegisterIndex::download($this->register);
        LockedRegisterIndex::$record['index'] = str_repeat('0', 64);
        mkdir($this->index);

        self::assertSame(RegisterIndex::UNREADABLE, (new LockedRegisterIndex($this->register))->state());
    }

    // ------------------------------------------------------------ helpers

    /** the fixture as Sync::download() leaves it: recorded, with its index */
    private function fixtureIndex(): LockedRegisterIndex
    {
        copy(self::FIXTURE, $this->register);
        LockedRegisterIndex::download($this->register);
        return new LockedRegisterIndex($this->register);
    }

    /** @param array<string, array{0: string, 1: string, 2?: string}> $boxes id => IČO, name and subtype, as XML text */
    private function writeRegister(array $boxes): void
    {
        $xml = '';
        foreach ($boxes as $id => $box) {
            [$ico, $name, $subtype] = $box + [2 => ''];
            $xml .= "<box><id>$id</id><subtype>$subtype</subtype><ico>$ico</ico><name><tradeName>$name</tradeName></name></box>";
        }
        file_put_contents($this->register, '<?xml version="1.0" encoding="UTF-8"?><list xmlns="http://seznam.gov.cz/ovm/datafile/seznam_ds/v1">' . $xml . '</list>');
    }

    /** a FIFO, a link to a device or a directory at $path */
    private function notAFile(string $path, string $what): void
    {
        match ($what) {
            'fifo'  => posix_mkfifo($path, 0o600) ?: self::markTestSkipped('no FIFO here'),
            'dir'   => mkdir($path),
            default => symlink($what, $path),
        };
    }

    /** the first line of an index of today's format */
    private static function header(string $registerSha): string
    {
        return (string) (new \ReflectionMethod(RegisterIndex::class, 'header'))->invoke(null, $registerSha);
    }

    /** what one PHP process remembers is gone, as in the next request */
    private static function newRequest(): void
    {
        (new \ReflectionProperty(RegisterIndex::class, 'failed'))->setValue(null, []);
    }

    /** @return list<string> byte order - scandir() sorts by the locale another test may have set */
    private function files(): array
    {
        $files = array_values(array_diff((array) scandir($this->dir), ['.', '..']));
        sort($files, SORT_STRING);
        return $files;
    }

    private static function skipAsRoot(): void
    {
        if (!function_exists('posix_geteuid') || (posix_geteuid() === 0)) {
            self::markTestSkipped('root reads and writes every directory');
        }
    }
}
