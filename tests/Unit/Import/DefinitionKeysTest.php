<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use AA\Import\Definition;
use AA\Import\Registry;
use AA\Import\Run;
use AA\Later\BatchBudget;
use AA\Later\BatchFailed;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Later\Fixtures\BatchRunTables;
use Tests\Unit\Legacy\Fixtures\FakeSlice;

require_once __DIR__ . '/../Later/Fixtures/BatchRunTables.php';

/**
 * A definition whose source needs keys is a superadmin's to create, change or delete - its settings
 * decide where the keys are sent; the feeding right of the slice still sees and runs it. The keys are
 * one static fact of the source type, the same for that right and for the run. Each test in its own
 * process, as DefinitionTest: FakeSlice loads include/locsess.php.
 */
#[RunTestsInSeparateProcesses]
final class DefinitionKeysTest extends TestCase
{
    use BatchRunTables {
        setUp as private tablesSetUp;
        tearDown as private tablesTearDown;
    }

    private const SLICE   = '5b5b5b5b5b5b5b5b5b5b5b5b5b5b5b5b';
    private const KEYED   = 'custom:keyed';
    private const KEYLESS = 'custom:keyless';
    private const DEF     = '0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        $this->tablesSetUp();
        $sources = __DIR__ . '/fixtures/keys';
        Registry::setCustomRootForTests(dirname($sources), $sources);
        Registry::sources();
        \KeyedSource::$refuse   = null;
        \KeyedSource::$failKeys = null;
        \KeyedSource::$keys     = null;
        new FakeSlice(self::SLICE);
        self::perms(PS_FEEDING);
    }

    protected function tearDown(): void
    {
        $this->tablesTearDown();
        Registry::setCustomRootForTests(null);
        unset($GLOBALS['permission_uid'], $GLOBALS['permission_to'], $GLOBALS['r_superuser']);
    }

    public function test_mayManage_credentialSourceSuperadminOnly(): void
    {
        $keyed   = self::definition(self::KEYED);
        $keyless = self::definition(self::KEYLESS);

        self::assertFalse($keyed->mayManage(AuthGetUid()), 'feeding alone');
        self::assertSame('KEYS', $keyed->manageRefusal(AuthGetUid()), 'and why');
        self::assertTrue($keyless->mayManage(AuthGetUid()), 'a source without keys stays the feeding right\'s');

        $GLOBALS['r_superuser'] = [AA_ID => true];
        self::assertTrue($keyed->mayManage(AuthGetUid()), 'a superadmin');

        // a superadmin who does not import into the slice gets nothing more here
        self::perms(PS_EDIT_ALL_ITEMS);
        self::assertSame('FORBIDDEN', $keyed->manageRefusal(AuthGetUid()));
    }

    /** a change of the source asks for both: away from a source with keys and to one */
    public function test_mayManage_sourceChangeToOrFromKeysSuperadminOnly(): void
    {
        $keyless = self::definition(self::KEYLESS);
        $keyed   = self::definition(self::KEYED);

        self::assertFalse($keyless->mayManage(AuthGetUid(), self::KEYED), 'to a source with keys');
        self::assertFalse($keyed->mayManage(AuthGetUid(), self::KEYLESS), 'away from one');
        self::assertTrue($keyless->mayManage(AuthGetUid(), self::KEYLESS));
        self::assertTrue($keyless->mayManage(AuthGetUid(), 'custom:gone'), 'a source that is gone needs no key');

        $GLOBALS['r_superuser'] = [AA_ID => true];
        self::assertTrue($keyless->mayManage(AuthGetUid(), self::KEYED));
        self::assertTrue($keyed->mayManage(AuthGetUid(), self::KEYLESS));
    }

    /** keys that cannot be known are taken as keys: a failing list, a list of another shape, a file that did not load */
    public function test_mayManage_unknownKeysSuperadminOnly(): void
    {
        \KeyedSource::$failKeys = new \RuntimeException('bug');
        self::assertNull(Definition::sourceKeys(self::KEYED));
        self::assertFalse(self::definition(self::KEYLESS)->mayManage(AuthGetUid(), self::KEYED));

        \KeyedSource::$failKeys = null;
        foreach (['a text' => ['csu'], 'no context' => [['vendor' => 'csu']], 'a number' => [['vendor' => 1, 'context' => '']], 'not a list' => ['a' => ['vendor' => 'csu', 'context' => '']]] as $case => $keys) {
            \KeyedSource::$keys = $keys;
            self::assertNull(Definition::sourceKeys(self::KEYED), $case);
        }

        self::assertArrayHasKey('BrokenKeysSource', Registry::rejected());
        self::assertNull(Definition::sourceKeys('custom:brokenkeys'));
        self::assertFalse(self::definition('custom:brokenkeys')->mayManage(AuthGetUid()));
    }

    /** the keys are static: a source that refuses to be made without settings still tells them */
    public function test_sourceKeys_noInstanceNeeded(): void
    {
        \KeyedSource::$refuse = new \RuntimeException('needs settings');

        self::assertSame([['keyed', 'login']], Definition::sourceKeys(self::KEYED));
        self::assertSame([], Definition::sourceKeys(self::KEYLESS));
        self::assertSame([], Definition::sourceKeys('custom:gone'));
        self::assertSame([], Definition::sourceKeys(''));
        self::assertFalse(self::definition(self::KEYED)->mayManage(AuthGetUid()));
    }

    /** the run gets the keys of its source type - those the right was judged by - not what the instance says */
    public function test_runContext_keysOfTheType(): void
    {
        $run    = new Run(['snapshot' => json_encode(['source' => 'custom:shifty'], JSON_THROW_ON_ERROR)]);
        $source = Registry::create('custom:shifty', []);
        self::assertContains(['vendor' => 'other', 'context' => ''], $source::credentials(), 'the instance would want another key');

        $ctx = (new \ReflectionMethod(Run::class, 'context'))->invoke($run, $source, new BatchBudget(microtime(true) + 30));

        self::assertNull($ctx->secret('shifty'));
        $this->expectException(\LogicException::class);
        $ctx->secret('other');
    }

    public function test_deleteWithRuns_keysSourceSuperadminOnly(): void
    {
        $def = self::definition(self::KEYED);
        self::assertSame(self::DEF, $def->save());

        try {
            $def->deleteWithRuns(AuthGetUid());
            self::fail('deleted by the feeding right alone');
        } catch (BatchFailed $e) {
            self::assertSame('KEYS', $e->reason);
        }
        self::assertNotNull(Definition::fresh(self::DEF));

        $GLOBALS['r_superuser'] = [AA_ID => true];
        $def->deleteWithRuns(AuthGetUid());
        self::assertNull(Definition::fresh(self::DEF));
    }

    /** the Manager form says why, also for a rename */
    public function test_formRefusal_keysSourceSaysWhy(): void
    {
        self::assertSame('This import uses API keys, so only a superadmin may change or delete it.', self::definition(self::KEYED)->formRefusal());
        self::assertSame('This import uses API keys, so only a superadmin may change or delete it.', Definition::refusal('KEYS'));
    }

    private static function definition(string $source): Definition
    {
        $def = new Definition();
        $def->setNew(self::DEF, self::SLICE, 'prices');
        $def->setProperty('source', $source);
        return $def;
    }

    /** the permission letters of the (anonymous) test user on SLICE */
    private static function perms(string $own): void
    {
        $GLOBALS['permission_uid'] = AuthGetUid();
        $GLOBALS['permission_to']  = ['aa' => [AA_ID => ''], 'slice' => [self::SLICE => $own]];
    }
}
