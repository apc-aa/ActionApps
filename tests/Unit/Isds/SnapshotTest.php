<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\IO\DB\DB_AA;
use AA\Isds\File;
use AA\Isds\Letter;
use AA\Isds\Message;
use AA\Isds\Recipient;
use AA\Isds\Schema;
use AA\Isds\Snapshot;
use AA\Later\BatchFailed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\TableDb;
use Tests\Unit\Later\Fixtures\BatchRunTables;

// the in-memory object_text and the config constants of the batch run tests
require_once __DIR__ . '/../Later/Fixtures/BatchRunTables.php';

/**
 * The frozen content of a sending over TableDb's object_text: it comes back as it went in, or the
 * run fails with CONTENT_CHANGED - never with other content.
 */
final class SnapshotTest extends TestCase
{
    use BatchRunTables;

    private const CAMPAIGN = 'cafe0000000000000000000000000001';
    private const REGISTER = ['sha256' => 'ab12', 'time' => 1_760_000_000];
    private const BODY     = '<p>Vážení, zveme Vás</p>';

    /** @param int $bytes of the attachment - no two of its rows alike, so a swap of them shows */
    private static function letter(int $bytes = 1000): Letter
    {
        $pdf = '%PDF' . substr(implode('', array_map(static fn(int $i): string => pack('N', $i), range(0, intdiv($bytes, 4)))), 0, $bytes - 4);
        return new Letter(' Pozvánka ', [
            new File('Dopis.html', 'text/html', File::MAIN, self::BODY),
            new File('Příloha č. 1.pdf', 'application/pdf', File::ENCLOSURE, $pdf),
        ], 'starosta');
    }

    private static function saved(int $bytes = 1000): Snapshot
    {
        $snapshot = Snapshot::of(self::letter($bytes), [
            new Recipient('a0000000000000000000000000000001', 'Adamov', 'ada1234', '', '00283001', 'Město Adamov'),
            new Recipient('b0000000000000000000000000000002', "Bene\xC5ov", '', Schema::BOX_MISSING),
        ], self::REGISTER, self::CAMPAIGN);
        $snapshot->save();
        return $snapshot;
    }

    /** @return list<int> keys of the stored rows of $property */
    private static function rowsOf(string $id, string $property): array
    {
        return array_keys(array_filter(TableDb::rows('object_text'), static fn(array $r): bool => ($r['object_id'] === $id) && ($r['property'] === $property)));
    }

    private static function assertChanged(\Closure $load): void
    {
        try {
            $load();
            self::fail('content changed, but read');
        } catch (BatchFailed $e) {
            self::assertSame(Snapshot::CHANGED, $e->reason);
        }
    }

    public function test_read_givesBackWhatWasFrozen(): void
    {
        $saved  = self::saved();
        $loaded = Snapshot::read((string) $saved->getId(), $saved->manifest(), true, self::CAMPAIGN);

        $rows = $loaded->recipients();
        self::assertSame(['a0000000000000000000000000000001', 'ada1234', 'Adamov', 'Město Adamov', ''], [$rows[0]['src'], $rows[0]['box'], $rows[0]['name'], $rows[0]['official'], $rows[0]['reason']]);
        self::assertSame(["Bene\u{FFFD}ov", Schema::BOX_MISSING], [$rows[1]['name'], $rows[1]['reason']], 'a broken name does not stop the sending');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $rows[0]['msg']);
        self::assertNotSame($rows[0]['msg'], $rows[1]['msg']);
        self::assertEquals(self::letter(), $loaded->letter());
        self::assertTrue(Snapshot::exists((string) $saved->getId()));
        self::assertFalse(Snapshot::exists(self::CAMPAIGN), 'another id');
        TableDb::$tables['object_text'][] = ['id' => 998, 'object_id' => self::CAMPAIGN, 'property' => 'aa_type', 'priority' => 0, 'value' => 'AA_Set', 'flag' => null];
        self::assertFalse(Snapshot::exists(self::CAMPAIGN), 'another type');
        self::assertSame(self::CAMPAIGN, self::stored((string) $saved->getId())['aa_owner']);
    }

    // a run's record that names the snapshot of another campaign reads none of it
    #[DataProvider('parts')]
    public function test_read_snapshotOfAnotherCampaignIsChanged(bool $files): void
    {
        $saved = self::saved();

        self::assertChanged(static fn() => Snapshot::read((string) $saved->getId(), $saved->manifest(), $files, 'cafe0000000000000000000000000002'));
        self::assertChanged(static fn() => Snapshot::read((string) $saved->getId(), $saved->manifest(), $files, ''));

        // one of two owners is no owner
        TableDb::$tables['object_text'][] = ['id' => 999, 'object_id' => (string) $saved->getId(), 'property' => 'aa_owner', 'priority' => 1, 'value' => 'cafe0000000000000000000000000002', 'flag' => null];
        self::assertChanged(static fn() => Snapshot::read((string) $saved->getId(), $saved->manifest(), $files, self::CAMPAIGN));
    }

    // the run's subject is a long id in small letters; an owner spelt otherwise would fail every step
    public function test_of_ownerIsALongIdInSmallLetters(): void
    {
        foreach ([strtoupper(self::CAMPAIGN), substr(self::CAMPAIGN, 1), ''] as $campaign) {
            try {
                Snapshot::of(self::letter(), [], self::REGISTER, $campaign);
                self::fail("owner $campaign taken");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public static function parts(): array
    {
        return ['with the files' => [true], 'the recipients only' => [false]];
    }

    // object_text.value is text: base64 rows of 512 KiB at most, a file over several in order
    public function test_save_rowsOfAtMost512KiB(): void
    {
        $saved = self::saved(Snapshot::CHUNK * 2 + 1);
        $rows  = array_filter(TableDb::rows('object_text'), static fn(array $r): bool => $r['property'] === 'files');

        self::assertSame([0, 1, 2, 3], array_map('intval', array_column($rows, 'priority')), 'the letter, then the attachment over three rows');
        foreach ($rows as $row) {
            self::assertLessThanOrEqual(512 * 1024, strlen((string) $row['value']));
            self::assertMatchesRegularExpression('~^[A-Za-z0-9+/=]*$~', (string) $row['value']);
        }
        self::assertEquals(self::letter(Snapshot::CHUNK * 2 + 1), Snapshot::read((string) $saved->getId(), $saved->manifest(), true, self::CAMPAIGN)->letter());
    }

    // the priority orders the rows, not the order the database returns them in
    public function test_read_ordersByPriority(): void
    {
        $saved = self::saved(Snapshot::CHUNK * 2 + 1);
        TableDb::$tables['object_text'] = array_reverse(TableDb::rows('object_text'));

        self::assertEquals(self::letter(Snapshot::CHUNK * 2 + 1), Snapshot::read((string) $saved->getId(), $saved->manifest(), true, self::CAMPAIGN)->letter());

        [$a, $b] = array_slice(self::rowsOf((string) $saved->getId(), 'files'), 0, 2);
        [TableDb::$tables['object_text'][$a]['priority'], TableDb::$tables['object_text'][$b]['priority']] = [TableDb::$tables['object_text'][$b]['priority'], TableDb::$tables['object_text'][$a]['priority']];
        self::assertChanged(static fn() => Snapshot::read((string) $saved->getId(), $saved->manifest(), true, self::CAMPAIGN));
    }

    #[DataProvider('damages')]
    public function test_read_damageIsChanged(string $property, \Closure $damage): void
    {
        $saved = self::saved(Snapshot::CHUNK + 1);
        $key   = self::rowsOf((string) $saved->getId(), $property)[1] ?? self::rowsOf((string) $saved->getId(), $property)[0];
        $damage($key);

        self::assertChanged(static fn() => Snapshot::read((string) $saved->getId(), $saved->manifest(), true, self::CAMPAIGN));
    }

    public static function damages(): array
    {
        $byte    = static function (int $key): void {
            $row = &TableDb::$tables['object_text'][$key];
            $row['value'][4] = ($row['value'][4] === 'A') ? 'B' : 'A';
        };
        $missing = static function (int $key): void {
            unset(TableDb::$tables['object_text'][$key]);
        };
        $extra = static function (int $key): void {
            TableDb::$tables['object_text'][] = ['priority' => 99] + TableDb::$tables['object_text'][$key];
        };
        return [
            'a byte of a file'   => ['files', $byte],
            'a byte of the list' => ['rcpt', $byte],
            'a row of a file'    => ['files', $missing],
            'a row of the list'  => ['rcpt', $missing],
            'a row more'         => ['files', $extra],
        ];
    }

    // prep, stop and look need only the recipients: the files are neither read nor checked
    public function test_read_withoutFilesReadsNoContent(): void
    {
        $saved = self::saved();
        foreach (self::rowsOf((string) $saved->getId(), 'files') as $key) {
            TableDb::$tables['object_text'][$key]['value'] = 'broken';
        }
        TableDb::$log = [];

        $loaded = Snapshot::read((string) $saved->getId(), $saved->manifest(), false, self::CAMPAIGN);

        self::assertCount(2, $loaded->recipients());
        self::assertStringNotContainsString("'files'", implode("\n", TableDb::$log));
        $this->expectException(\LogicException::class);
        $loaded->letter();
    }

    // which of two rows of one place counts must not depend on the order the database returns them in
    #[DataProvider('modes')]
    public function test_read_twoRowsOfOnePlaceAreChanged(bool $first): void
    {
        $saved = self::saved();
        $key   = self::rowsOf((string) $saved->getId(), 'files')[1];
        $twin  = ['value' => base64_encode('other')] + TableDb::$tables['object_text'][$key];
        $first ? array_unshift(TableDb::$tables['object_text'], $twin) : (TableDb::$tables['object_text'][] = $twin);

        self::assertChanged(static fn() => Snapshot::read((string) $saved->getId(), $saved->manifest(), true, self::CAMPAIGN));
    }

    public static function modes(): array
    {
        return ['before' => [true], 'after' => [false]];
    }

    // CONTENT_CHANGED, not a TypeError or a loop over an absurd size
    #[DataProvider('shapes')]
    public function test_read_manifestOfAnotherShapeIsChanged(\Closure $reshape): void
    {
        $saved    = self::saved();
        $manifest = $reshape(json_decode($saved->manifest(), true));

        self::assertChanged(static fn() => Snapshot::read((string) $saved->getId(), is_string($manifest) ? $manifest : json_encode($manifest), true, self::CAMPAIGN));
    }

    public static function shapes(): array
    {
        $file = static fn(string $key, mixed $value): \Closure => static function (array $m) use ($key, $value): array {
            $m['files'][1][$key] = $value;
            return $m;
        };
        return [
            'not JSON'          => [static fn(array $m): string => '{"rcpt":'],
            'files no list'     => [static fn(array $m): array => ['files' => 'x'] + $m],
            'file no object'    => [static fn(array $m): array => array_replace_recursive($m, ['files' => [1 => 'x']])],
            'file without name' => [static function (array $m): array {
                unset($m['files'][1]['name']);
                return $m;
            }],
            'name a list'       => [$file('name', ['x'])],
            'huge size'         => [$file('size', 2 ** 60)],
            'largest size'      => [$file('size', PHP_INT_MAX)],
            'size a text'       => [$file('size', '1002')],
            'list of any size'  => [static fn(array $m): array => array_replace_recursive($m, ['rcpt' => ['size' => PHP_INT_MAX]])],
            'subject a list'    => [static fn(array $m): array => ['annotation' => ['x']] + $m],
            'to hands a list'   => [static fn(array $m): array => ['to_hands' => ['x']] + $m],
            'without to hands'  => [static function (array $m): array {
                unset($m['to_hands']);
                return $m;
            }],
            'another count'     => [static fn(array $m): array => array_replace_recursive($m, ['rcpt' => ['n' => 3]])],
            'no main file'      => [static function (array $m): array {
                $m['files'][0]['meta'] = File::ENCLOSURE;
                return $m;
            }],
        ];
    }

    // rows of another shape under a matching hash - another format of of() - are no TypeError either
    #[DataProvider('lists')]
    public function test_read_listOfAnotherShapeIsChanged(string $json): void
    {
        $saved = self::saved();
        $id    = (string) $saved->getId();
        TableDb::$tables['object_text'][self::rowsOf($id, 'rcpt')[0]]['value'] = base64_encode($json);
        $manifest         = json_decode($saved->manifest(), true);
        $manifest['rcpt'] = ['n' => 1, 'size' => strlen($json), 'xxh128' => hash('xxh128', $json)];

        self::assertChanged(static fn() => Snapshot::read($id, json_encode($manifest), false, self::CAMPAIGN));
    }

    public static function lists(): array
    {
        return [
            'row no list'     => ['["x"]'],
            'row too short'   => ['[["a","b"]]'],
            'a list in a row' => ['[[["x"],"b","c","d","e","f"]]'],
            'rows by name'    => ['{"k":["a","b","c","d","e","f"]}'],
            'a number in a row' => ['[[1,"b","c","d","e","f"]]'],
            'a row by name'   => ['[{"a":"a","b":"b","c":"c","d":"d","e":"e","f":"f"}]'],
        ];
    }

    // it goes to the run's record, which progress pages read: these keys and nothing else, in ASCII -
    // object_text is cp1250 or utf8mb3 on some installs
    public function test_manifest_holdsNoContentNorSecret(): void
    {
        $saved    = self::saved();
        $manifest = json_decode($saved->manifest(), true);

        self::assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $saved->manifest());
        self::assertSame(['annotation', 'to_hands', 'files', 'rcpt', 'register'], array_keys($manifest));
        self::assertSame(['Pozvánka', 'starosta', 2, self::REGISTER], [$manifest['annotation'], $manifest['to_hands'], $manifest['rcpt']['n'], $manifest['register']]);
        self::assertSame(['name', 'mime', 'meta', 'size', 'xxh128'], array_keys($manifest['files'][1]));
        self::assertSame(['Příloha č. 1.pdf', 'application/pdf', File::ENCLOSURE], [$manifest['files'][1]['name'], $manifest['files'][1]['mime'], $manifest['files'][1]['meta']]);
        foreach (['zveme', base64_encode(self::BODY), '%PDF', 'Adamov', 'ada1234'] as $content) {
            self::assertStringNotContainsString($content, $saved->manifest());
        }
    }

    // saved in the transaction that starts the run, or failing half-way on its own: no orphan rows
    #[DataProvider('failures')]
    public function test_save_leavesNothingWhenItFails(bool $inRun): void
    {
        $snapshot = Snapshot::of(self::letter(Snapshot::CHUNK + 1), [], self::REGISTER, self::CAMPAIGN);
        TableDb::$fail = $inRun ? null : static fn(string $sql): bool => str_starts_with($sql, 'INSERT') && str_contains($sql, "'files'") && str_contains($sql, ',2,');
        $failure       = null;
        try {
            $inRun ? DB_AA::transaction(static function () use ($snapshot): void {
                $snapshot->save();
                throw new \RuntimeException('the run did not start');
            }) : $snapshot->save();
        } catch (\RuntimeException $e) {
            $failure = $e->getMessage();
        }

        self::assertStringStartsWith($inRun ? 'the run did not start' : 'TableDb: failed INSERT', (string) $failure);
        self::assertFalse(Snapshot::exists((string) $snapshot->getId()));
        self::assertSame([], TableDb::rows('object_text'));
    }

    public static function failures(): array
    {
        return ['the run' => [true], 'a row' => [false]];
    }

    // the longest list of() freezes is one read() takes; a byte more is never frozen
    public function test_of_freezesListOfAPartExactly(): void
    {
        $short    = json_encode([['00000000000000000000000000000000', 'a0000000000000000000000000000001', 'ada1234', '', '', '']]);
        $name     = str_repeat('x', Message::MAX_BYTES - strlen($short));
        $snapshot = Snapshot::of(self::letter(), [new Recipient('a0000000000000000000000000000001', $name, 'ada1234', '')], self::REGISTER, self::CAMPAIGN);

        self::assertSame(Message::MAX_BYTES, json_decode($snapshot->manifest(), true)['rcpt']['size']);
        unset($snapshot);
        $this->expectException(\InvalidArgumentException::class);
        Snapshot::of(self::letter(), [new Recipient('a0000000000000000000000000000001', $name . 'x', 'ada1234', '')], self::REGISTER, self::CAMPAIGN);
    }

    // one read() holds no rows: saving it would wipe what went out
    public function test_save_refusesReadSnapshot(): void
    {
        $saved = self::saved();

        $this->expectException(\LogicException::class);
        Snapshot::read((string) $saved->getId(), $saved->manifest(), true, self::CAMPAIGN)->save();
    }
}
