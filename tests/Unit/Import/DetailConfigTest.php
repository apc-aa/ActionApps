<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use AA\Import\Definition;
use AA\Import\DetailConfig;
use AA\Import\DetailPage;
use AA\Import\Registry;
use AA\Import\Run;
use AA\Import\RunCursor;
use AA\Import\Service;
use AA\Import\StreamState;
use AA\IO\DB\DB_AA;
use AA\Managed_Object;
use AA\Util\FormToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\TableDb;
use Tests\Unit\Import\Fixtures\RunSteps;
use Tests\Unit\Legacy\Fixtures\FakeFields;

require_once __DIR__ . '/fixtures/RunSteps.php';

/**
 * The parts of the import detail that change what runs are made of: the mapping (read only, reset
 * from the source), the settings of the source and the source itself, and the state of the keys
 * the source needs - set or missing for all, the end of a key for a superadmin only, never a key.
 * Each test in its own process, as DetailPageTest: FakeSlice loads include/locsess.php, and the
 * key constants stay defined.
 */
#[RunTestsInSeparateProcesses]
final class DetailConfigTest extends TestCase
{
    use RunSteps {
        setUp as private stepsSetUp;
        tearDown as private stepsTearDown;
    }

    private const SOURCE    = 'custom:service';
    private const GONE      = 'custom:gone';
    private const POST      = ['REQUEST_METHOD' => 'POST'];
    private const OTHER_DEF = 'fedcba9876543210fedcba9876543210';

    protected function setUp(): void
    {
        $this->stepsSetUp();
        session_id('test-session');
        $dir = __DIR__ . '/fixtures/service';
        Registry::setCustomRootForTests(dirname($dir), $dir);
        Registry::sources();
        \ServiceSource::$streamKeys     = ['s1', 's2', 's3'];
        \ServiceSource::$refuse         = null;
        \ServiceSource::$failStreams    = null;
        \ServiceSource::$created        = [];
        \ServiceSource::$mapping        = null;
        \ServiceSource::$failProperties = null;
        \ServiceSource::$failMapping    = null;
        \ServiceSource::$credentials    = [];
        TableDb::$tables['module'] = [['id' => self::SLICE, 'type' => 'S'], ['id' => self::OTHER, 'type' => 'S']];
        \AA::$module_id = self::SLICE;
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        \AA::$module_id = null;
        unset($GLOBALS['r_superuser']);
        session_id('');
        Registry::setCustomRootForTests(null);
        $this->stepsTearDown();
    }

    // ------------------------------------------------------------ mapping

    /** the mapping holds expressions the queue evaluates - the page shows them as text, never expands them */
    public function test_mapping_renderedEscaped(): void
    {
        self::definition([
            'mapping'      => '{"rows":[{"to":"relation........","t":"value","p":{"new_flag":"t","new_content":"<script>alert(1)</script>{user:password}"}},'
                . '{"to":"param:fld","t":"copyfield","p":{"field2copy":"a\"b"}}]}',
            'validate'     => '["{ids:<b>x</b>}"]',
            'after_stream' => '<i>{item:x:_#HEADLINE}</i>',
        ]);
        self::nameField('relation........', '<b>Rel</b>');

        $html = self::config()->mappingHtml();

        $rows = self::tableRows($html, 'aa-import-mapping');
        $this->assertCount(2, $rows);
        $this->assertSame('<b>Rel</b> relation........', $rows[0][0], 'the name of the field and its id');
        $this->assertSame('value', $rows[0][1]);
        $this->assertStringContainsString('<script>alert(1)</script>{user:password}', $rows[0][2]);
        $this->assertStringContainsString('param:fld', $rows[1][0]);
        $this->assertStringContainsString('a"b', $rows[1][2]);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;{user:password}', $html);
        $this->assertStringContainsString('{ids:&lt;b&gt;x&lt;/b&gt;}', $html, 'the validation');
        $this->assertStringContainsString('&lt;i&gt;{item:x:_#HEADLINE}&lt;/i&gt;', $html, 'the expression after a stream');
        $this->assertStringContainsString('update/insert', $html, 'the store mode');
        foreach (['<script>', '<b>x', '<i>{', '<b>Rel'] as $raw) {
            $this->assertStringNotContainsString($raw, $html);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function hostileMappings(): iterable
    {
        yield 'not json'        => ['not json'];
        yield 'target a number' => ['{"rows":[{"to":5,"t":"value","p":{}}]}'];
        yield 'target an object' => ['{"rows":[{"to":{"a":1},"t":"value","p":{}}]}'];
        yield 'no target'       => ['{"rows":[{"t":"value","p":{}}]}'];
        yield 'name a list'     => ['{"rows":[{"to":"relation........","t":["value"],"p":{}}]}'];
        yield 'params a text'   => ['{"rows":[{"to":"relation........","t":"value","p":"x"}]}'];
        yield 'param a list'    => ['{"rows":[{"to":"relation........","t":"value","p":{"new_flag":["t"],"new_content":"x"}}]}'];
        yield 'params numbers'  => ['{"rows":[{"to":"relation........","t":"value","p":[1,2]}]}'];
        yield 'row a text'      => ['{"rows":["x"]}'];
    }

    /** whatever is stored, the page says the mapping cannot be read - never a failure of the page */
    #[DataProvider('hostileMappings')]
    public function test_mapping_hostileJsonUnreadable(string $json): void
    {
        self::definition(['mapping' => $json]);

        $html = self::config()->mappingHtml();

        $this->assertStringContainsString('cannot be read', $html);
        $this->assertSame([], self::tableRows($html, 'aa-import-mapping'));
        $this->assertStringContainsString('cannot be read', self::page()->html(time()), 'the overview too');
    }

    /** read only: the mapping section is no form of the mapping, only the reset of it */
    public function test_mapping_readOnly(): void
    {
        self::definition();

        $doc = self::dom(self::config()->mappingHtml());

        $this->assertSame(0, $doc->getElementsByTagName('textarea')->length);
        $names = [];
        foreach ($doc->getElementsByTagName('input') as $input) {
            $names[] = $input->getAttribute('name') . '=' . (($input->getAttribute('name') === 'token') ? '' : $input->getAttribute('value'));
        }
        $this->assertSame(['token=', 'action=reset_mapping'], $names);
    }

    // ------------------------------------------------------------ reset mapping

    public function test_resetMapping_postOnly(): void
    {
        self::definition();
        $page = self::page();
        TableDb::$log = [];

        $this->assertNull($page->handle(['action' => 'reset_mapping', 'token' => self::token()], ['REQUEST_METHOD' => 'GET']), 'a GET');
        $msg = self::shown($page, $page->handle(['action' => 'reset_mapping'], self::POST));
        $this->assertStringContainsString('expired', $msg, 'no token');
        $msg = self::shown($page, $page->handle(['action' => 'reset_mapping', 'token' => FormToken::get('import-' . self::OTHER)], self::POST));
        $this->assertStringContainsString('expired', $msg, 'a token of another import');
        $this->assertSame([], TableDb::writes());
        $this->assertSame(self::NO_PARAM_MAPPING, self::stored(self::DEF)['mapping']);

        $msg = self::act(['action' => 'reset_mapping']);

        $this->assertStringContainsString('aa-ok', $msg);
        $this->assertSame((new \ServiceSource())->defaultMapping()->toJson(), self::stored(self::DEF)['mapping']);
        $this->assertSame(['headline........'], json_decode(self::stored(self::DEF)['key_fields'], true));
    }

    /** a refusal says why and where to fix it */
    public function test_resetMapping_refusalPointsToSettings(): void
    {
        self::definition(['settings' => '{"slice":"' . self::OTHER . '"}']);
        self::perms(PS_FEEDING, PS_EDIT_ALL_ITEMS);
        TableDb::$log = [];

        $msg = self::act(['action' => 'reset_mapping']);

        $this->assertStringContainsString('aa-err', $msg);
        $this->assertStringContainsString('The setting &quot;Slice of definitions&quot; names a slice whose feeding you do not manage.', $msg);
        $this->assertStringContainsString('href="#aa-import-settings"', $msg);
        $this->assertSame([], TableDb::writes());
    }

    /** only a refusal the settings or the source cause points to them */
    public function test_resetMapping_pointerOnlyForSettingsRefusals(): void
    {
        foreach (['SETTINGS:', 'SLICE:Slice', 'NO_MAPPING:', 'SOURCE:'] as $reason) {
            $this->assertStringContainsString('aa-import-settings', DetailConfig::outcomeHtml('mapping_refused', $reason), $reason);
        }
        foreach (['KEYS:', 'FORBIDDEN:', 'MAPPING:UNKNOWN_FIELD', 'NOT_OF_SLICE:'] as $reason) {
            $this->assertStringNotContainsString('aa-import-settings', DetailConfig::outcomeHtml('mapping_refused', $reason), $reason);
        }
        $this->assertStringContainsString('UNKNOWN_FIELD', DetailConfig::outcomeHtml('mapping_refused', 'MAPPING:UNKNOWN_FIELD'));
        $this->assertStringContainsString('aa-import-settings', DetailConfig::outcomeHtml('mapping_refused', 'MAPPING:UNKNOWN_FIELD, NO_KEY'), 'the settings give the key');
    }

    public function test_resetMapping_disabledWithoutSource(): void
    {
        self::definition(['source' => self::GONE]);

        $html = self::config()->mappingHtml();

        $this->assertTrue(self::submit($html, 'Reset mapping from the source')->hasAttribute('disabled'));
        $this->assertStringContainsString('The import source is not available.', self::text($html));
    }

    // ------------------------------------------------------------ a new import of a source mapped by its settings

    /** The Manager form has no settings, so the new definition has no key yet: it is saved, the
     *  detail says why it cannot start, and the settings give it the key. */
    public function test_newCsvImport_savedWithoutKeyStartsOnceSettingsGiveIt(): void
    {
        self::perms(PS_FEEDING, PS_FEEDING);
        $_POST['aa'] = ['n1_' . self::SLICE => ['aa_name' => ['prices'], 'source' => ['core:csvurl'], 'enabled' => ['1']]];

        $this->assertNull((new Managed_Object(Definition::class))->save(self::SLICE, ''));

        $def = Definition::byNameOrId(self::SLICE, 'prices') ?? throw new \LogicException('No definition');
        $id  = (string) $def->getId();
        $this->assertSame([], TableDb::rows('toexecute'));
        $page = DetailPage::forRequest(self::SLICE, $id, AuthGetUid()) ?? throw new \LogicException('No page');
        $this->assertStringContainsString('The mapping has no key field, so an item cannot be found again.', $page->overviewHtml(time()));
        $this->assertStringContainsString('(DEFINITION)', $page->actionsHtml(time()), 'the start buttons say why they are off');
        $refused = Service::start($def, 'run', [], 'manual', AuthGetUid());
        $this->assertSame(['invalid', 'DEFINITION'], [$refused->code, $refused->reason]);

        $settings = ['url' => 'https://example.org/data.csv', 'columns' => '{"Name": "headline........", "Code": "text...........1"}', 'key_column' => 'Code'];
        $this->assertSame('CHANGED', Service::changeSettings($def, 'core:csvurl', $settings, AuthGetUid())->code);

        $def = Definition::fresh($id) ?? throw new \LogicException('No definition');
        $this->assertSame(['text...........1'], $def->mapping()->keyFields);
        $this->assertSame([], $def->mapping()->problems(self::SLICE));
        $this->assertSame('queued', Service::start($def, 'run', [], 'manual', AuthGetUid())->code);
        $this->assertContains('Import_' . $id, array_column(self::queue(), 'selector'));
    }

    // ------------------------------------------------------------ refused starts point to the settings

    /** the stored settings no longer pass for this user: start and Continue are refused, the page says where to fix it */
    public function test_startAndContinue_settingsRefusalPointsToSettings(): void
    {
        self::definition(['settings' => '{"slice":"' . self::OTHER . '"}']);
        self::perms(PS_FEEDING, PS_FEEDING);
        $id = Service::start(Definition::fresh(self::DEF) ?? throw new \LogicException('No definition'), 'run', [], 'manual', AuthGetUid())->runId;
        self::storedSet($id, 'state', Run::ERROR);
        TableDb::$tables['toexecute'] = [];
        self::perms(PS_FEEDING, PS_EDIT_ALL_ITEMS);

        foreach (['continue' => ['action' => 'resume', 'run' => $id], 'start, after End' => ['action' => 'run']] as $case => $post) {
            if ($post['action'] === 'run') {
                Service::end($id, AuthGetUid());
            }
            $msg = self::act($post);

            $this->assertStringContainsString('The settings name a slice whose feeding you do not manage.', $msg, $case);
            $this->assertStringContainsString('href="#aa-import-settings"', $msg, $case);
            $this->assertStringContainsString('Planned starts', $msg, $case);
        }
        $this->assertNotContains('Import_' . self::DEF, array_column(self::queue(), 'selector'), 'no step queued');
    }

    /** other refusals say no more than before */
    public function test_refusal_otherReasonsNoPointer(): void
    {
        $this->assertStringNotContainsString('aa-import-settings', DetailPage::outcomeHtml('refused', 'FORBIDDEN'));
        $this->assertStringNotContainsString('aa-import-settings', DetailPage::outcomeHtml('refused', 'NO_MAPPING'));
        $this->assertStringContainsString('aa-import-settings', DetailPage::outcomeHtml('refused', 'SETTINGS'));
    }

    // ------------------------------------------------------------ settings

    /** a form from the source's own properties, filled with the stored settings - outside the live sections */
    public function test_settings_formFromSourceProperties(): void
    {
        self::definition(['settings' => '{"territory":"<b>cz</b>\"","active":"1","fixed":"<i>F</i>","object":"O1"}']);
        TableDb::$log = [];

        $page = self::page();
        $html = $page->config()->settingsHtml();
        $this->assertSame([], TableDb::writes(), 'the page only reads');
        $doc = self::dom($html);

        $inputs = [];
        foreach ($doc->getElementsByTagName('input') as $input) {
            $inputs[$input->getAttribute('name')][] = $input;
        }
        $base = 'aa[u' . self::DEF . ']';
        $this->assertSame('<b>cz</b>"', $inputs["{$base}[territory][0]"][0]->getAttribute('value'));
        $this->assertStringNotContainsString('<b>cz', $html);
        $this->assertSame('', $inputs["{$base}[from_year][0]"][0]->getAttribute('value'));
        $this->assertTrue($inputs["{$base}[active][chb][0]"][0]->hasAttribute('checked'));
        $this->assertArrayHasKey("{$base}[slice][0]", $inputs);
        $this->assertSame(['settings'], array_map(static fn(\DOMElement $i): string => $i->getAttribute('value'), $inputs['action']));
        $this->assertSame(self::SOURCE, $inputs['source'][0]->getAttribute('value'));
        $this->assertSame(Service::settingsHash(Definition::fresh(self::DEF) ?? throw new \LogicException('No definition')), $inputs['stored'][0]->getAttribute('value'));
        $this->assertCount(1, $inputs['token']);

        // a setting the page only shows: as text, escaped, no field of it
        foreach (['fixed', 'object'] as $name) {
            $this->assertSame([], preg_grep('/\[' . $name . '\]/', array_keys($inputs)), $name);
        }
        $this->assertStringContainsString('Fixed: <i>F</i>', self::text($html));
        $this->assertStringContainsString('&lt;i&gt;F&lt;/i&gt;', $html);
        $this->assertStringContainsString('Object: O1', self::text($html));

        // before it is sent, the form says what a change does
        $text = self::text($html);
        $this->assertStringContainsString('ends the open run', $text);
        $this->assertStringContainsString('the mapping', $text);
        $this->assertStringNotContainsString('No other source is available.', $text, 'a core source is always there to pick');
        // besides the GET form choosing another source
        $forms = array_values(array_filter(iterator_to_array($doc->getElementsByTagName('form')), fn(\DOMElement $f): bool => $f->getAttribute('method') !== 'get'));
        $this->assertCount(1, $forms);
        $this->assertNotSame('', $forms[0]->getAttribute('data-aa-confirm'));
        $this->assertSame('post', $forms[0]->getAttribute('method'));

        // a pick that is no source shows the current one
        $this->assertStringContainsString('name="source" value="' . self::SOURCE . '"', self::config()->settingsHtml('custom:nonsense'));
        $this->assertStringNotContainsString('You are changing the source', self::config()->settingsHtml('custom:nonsense'));

        // the live refresh would replace it while the user types
        $this->assertMatchesRegularExpression('~<details id="aa-import-settings"~', $html);
        $this->assertStringNotContainsString('data-aa-live', $html);
        $this->assertStringNotContainsString('aa-import-settings', $page->html(time()), 'not among the live sections');
        $this->assertStringNotContainsString('data-aa-live', $page->config()->mappingHtml());
    }

    /** a browser posts every field - blank ones, an unchecked box: nothing changed, the run goes on */
    public function test_settings_browserRoundTripUnchanged(): void
    {
        self::definition(['settings' => '{"territory":"cz","from_year":"2006","active":"1","fixed":"F1"}']);
        $run    = Service::start(Definition::fresh(self::DEF) ?? throw new \LogicException('No definition'), 'run', [], 'manual', AuthGetUid())->runId;
        $before = self::stored(self::DEF)['settings'];

        $msg = self::act(self::browserPost(self::config()->settingsHtml()));

        $this->assertStringContainsString('nothing changed', $msg);
        $this->assertSame(Run::RUNNING, self::stored($run)['state']);
        $this->assertSame($before, self::stored(self::DEF)['settings']);
    }

    public function test_settings_changeEndsRun(): void
    {
        self::definition(['settings' => '{"territory":"cz"}']);
        $run = Service::start(Definition::fresh(self::DEF) ?? throw new \LogicException('No definition'), 'run', [], 'manual', AuthGetUid())->runId;

        $msg = self::act(self::settingsPost(['territory' => 'sk', 'from_year' => '2006']));

        $this->assertStringContainsString('aa-ok', $msg);
        $this->assertStringContainsString('saved', $msg);
        $this->assertSame(['territory' => 'sk', 'from_year' => '2006'], json_decode(self::stored(self::DEF)['settings'], true));
        $this->assertSame(Run::ENDED, self::stored($run)['state']);
        $this->assertSame('SETTINGS_CHANGED', json_decode(self::stored($run)['progress'], true)['note']);
        $this->assertSame((new \ServiceSource())->defaultMapping()->toJson(), self::stored(self::DEF)['mapping'], 'the mapping is rebuilt');
    }

    /** a setting the page only shows keeps its stored value - whatever is posted for it */
    public function test_settings_fixedSettingsKept(): void
    {
        self::definition(['settings' => '{"territory":"cz","fixed":"F1","object":"O1"}']);
        $post = self::settingsPost(['territory' => 'sk', 'fixed' => 'forged', 'object' => 'forged']);

        $this->assertStringContainsString('aa-ok', self::act($post));

        $this->assertSame(['territory' => 'sk', 'fixed' => 'F1', 'object' => 'O1'], json_decode(self::stored(self::DEF)['settings'], true));
    }

    /** only the settings the chosen source declares are taken; a checkbox posts as its widget does */
    public function test_settings_onlyDeclaredOnesTaken(): void
    {
        self::definition();
        $post = self::settingsPost(['territory' => 'cz', 'evil' => 'x']);
        $post['aa']['u' . self::DEF]['active'] = ['chb' => ['0' => '1', 'def' => '0']];
        $post['aa']['u' . self::OTHER] = ['territory' => ['other']];

        $this->assertStringContainsString('aa-ok', self::act($post));

        $this->assertSame(['territory' => 'cz', 'active' => '1'], json_decode(self::stored(self::DEF)['settings'], true));
    }

    /** a value the form cannot send, or more values for one setting, are refused - nothing is cut off */
    public function test_settings_nonTextOrMoreValuesRefused(): void
    {
        self::definition(['settings' => '{"territory":"cz"}']);
        TableDb::$log = [];
        foreach (['nested' => ['territory', [['nested']], 'Territory'], 'two values' => ['tags', ['a', 'b'], 'Tags'], 'two of a single one' => ['territory', ['a', 'b'], 'Territory']] as $case => [$name, $values, $label]) {
            $post = self::settingsPost([]);
            $post['aa']['u' . self::DEF][$name] = $values;

            $msg = self::act($post);

            $this->assertStringContainsString('The setting &quot;' . $label . '&quot; of the import source is not valid.', $msg, $case);
        }
        $this->assertSame([], TableDb::writes());

        $this->assertStringContainsString('aa-ok', self::act(self::settingsPost(['tags' => 'a'])), 'one value of a multiple one');
    }

    /** a refused save names the setting at fault */
    public function test_settings_refusalNamesTheSetting(): void
    {
        self::definition();

        $msg = self::act(self::settingsPost(['year' => 'MMVI']));

        $this->assertStringContainsString('The settings were not saved:', $msg);
        $this->assertStringContainsString('The setting &quot;From year&quot; of the import source is not valid.', $msg);
    }

    /** another source is picked by a GET that only shows its settings; one save changes both */
    public function test_settings_switchSourceInOneSave(): void
    {
        self::definition(['source' => self::GONE, 'settings' => '{"territory":"old","fixed":"OLD"}']);
        $run   = self::openRun();
        $state = StreamState::create(self::DEF);
        $state->record('s1', ['v' => '1']);
        $state->store();
        TableDb::$log = [];

        $html = self::config()->settingsHtml(self::SOURCE);

        $this->assertSame([], TableDb::writes(), 'picking only shows');
        $this->assertStringContainsString('You are changing the source from custom:gone to Service.', self::text($html));
        $this->assertFalse(self::submit($html, 'Change the source and save')->hasAttribute('disabled'));
        $post = self::browserPost($html);
        $this->assertSame(self::SOURCE, $post['source']);
        $this->assertSame([''], $post['aa']['u' . self::DEF]['territory'], 'the settings of the old source are not the new one\'s');
        $this->assertStringNotContainsString('OLD', $html, 'nor a fixed one of the same name');
        $post['aa']['u' . self::DEF]['territory'] = ['cz'];

        $msg = self::act($post);

        $this->assertStringContainsString('aa-ok', $msg);
        $stored = self::stored(self::DEF);
        $this->assertSame([self::SOURCE, '{"territory":"cz"}'], [$stored['source'], $stored['settings']], 'the source with its settings at once');
        $this->assertSame((new \ServiceSource())->defaultMapping()->toJson(), $stored['mapping']);
        $this->assertSame(Run::ENDED, self::stored($run)['state']);
        $this->assertNull(StreamState::forDefinition(self::DEF));
    }

    /** a refused switch comes back to the form of the picked source, not of the current one */
    public function test_settings_refusedSwitchStaysOnPickedSource(): void
    {
        self::definition(['source' => self::GONE]);
        $page = self::page();
        $post = self::browserPost(self::config()->settingsHtml(self::SOURCE));
        $post['aa']['u' . self::DEF]['year'] = ['MMVI'];

        $outcome = $page->handle($post + ['token' => self::token()], self::POST);

        $this->assertSame('settings_refused', $outcome[0] ?? null);
        $this->assertStringContainsString('source=' . rawurlencode(self::SOURCE), $page->afterPost($outcome));

        $post['aa']['u' . self::DEF]['year'] = ['2006'];
        $outcome = $page->handle($post + ['token' => self::token()], self::POST);
        $this->assertSame('settings_saved', $outcome[0] ?? null);
        $this->assertStringNotContainsString('source=', $page->afterPost($outcome));

        $page    = self::page();
        $outcome = $page->handle(self::settingsPost(['year' => 'MMVI']) + ['token' => self::token()], self::POST);
        $this->assertSame('settings_refused', $outcome[0] ?? null);
        $this->assertStringNotContainsString('source=', $page->afterPost($outcome), 'the current source needs no pick');
    }

    public function test_settings_noOtherSourceToPick(): void
    {
        $root = sys_get_temp_dir() . '/aa-detailconfig-' . getmypid();
        @mkdir("$root/empty", 0o700, true);
        Registry::setCustomRootForTests($root, "$root/empty");
        try {
            self::definition(['source' => 'core:csvurl']);

            $html = self::config()->settingsHtml();
        } finally {
            rmdir("$root/empty");
            rmdir($root);
        }

        $this->assertStringContainsString('No other source is available.', self::text($html));
        $this->assertStringNotContainsString('method="get"', $html);
    }

    /** the choice of a source is a GET form of the page; an unknown pick shows the current source */
    public function test_settings_sourceChoiceIsGet(): void
    {
        self::definition(['source' => self::GONE]);

        $html = self::config()->settingsHtml('custom:nonsense');
        $doc  = self::dom($html);

        $get = null;
        foreach ($doc->getElementsByTagName('form') as $form) {
            $get = ($form->getAttribute('method') === 'get') ? $form : $get;
        }
        $this->assertNotNull($get);
        $fields = [];
        foreach ($get->getElementsByTagName('input') as $input) {
            $fields[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        $this->assertSame(self::SLICE, $fields['change_id'] ?? null);
        $this->assertSame(self::DEF, $fields['id'] ?? null);
        $this->assertArrayNotHasKey('token', $fields, 'it changes nothing');
        $options = [];
        foreach ($get->getElementsByTagName('option') as $option) {
            $options[$option->getAttribute('value')] = $option->hasAttribute('selected');
        }
        $this->assertSame(['core:csvurl' => false, self::SOURCE => false], $options);
        $this->assertStringContainsString('not available', $html, 'the settings of the missing current source');
        $this->assertStringNotContainsString('name="stored"', $html, 'no settings form for it');
    }

    public function test_settings_sliceIdWithoutFeedingRefused(): void
    {
        self::definition();
        self::perms(PS_FEEDING, PS_EDIT_ALL_ITEMS);
        TableDb::$log = [];

        $msg = self::act(self::settingsPost(['slice' => self::OTHER]));

        $this->assertStringContainsString('aa-err', $msg);
        $this->assertStringContainsString('names a slice whose feeding you do not manage.', $msg);
        $this->assertSame([], TableDb::writes());

        self::perms(PS_FEEDING, PS_FEEDING);
        $this->assertStringContainsString('aa-ok', self::act(self::settingsPost(['slice' => self::OTHER])));
        $this->assertSame(['slice' => self::OTHER], json_decode(self::stored(self::DEF)['settings'], true));
    }

    /** a form made before another save - of the settings or the source - would undo it */
    public function test_settings_staleFormRefused(): void
    {
        self::definition(['settings' => '{"territory":"cz"}']);
        $old = self::settingsPost(['territory' => 'at']);
        $this->assertStringContainsString('aa-ok', self::act(self::settingsPost(['territory' => 'sk'])), 'saved meanwhile');
        TableDb::$log = [];

        foreach (['made before' => $old['stored'], 'none' => null, 'array' => [$old['stored']]] as $case => $stored) {
            $post           = $old;
            $post['stored'] = $stored;

            $msg = self::act($post);

            $this->assertStringContainsString('aa-err', $msg, $case);
            $this->assertStringContainsString('changed meanwhile', $msg, $case);
        }
        foreach (['no source' => null, 'array' => [self::SOURCE]] as $case => $source) {
            $post           = self::settingsPost(['territory' => 'at']);
            $post['source'] = $source;
            $this->assertStringContainsString('aa-err', self::act($post), $case);
        }
        $this->assertSame([], TableDb::writes());
        $this->assertSame(['territory' => 'sk'], json_decode(self::stored(self::DEF)['settings'], true));
    }

    public function test_settings_postOnly(): void
    {
        self::definition();
        $page = self::page();
        TableDb::$log = [];

        $this->assertNull($page->handle(self::settingsPost(['territory' => 'sk']) + ['token' => self::token()], ['REQUEST_METHOD' => 'GET']));
        $this->assertStringContainsString('expired', self::shown($page, $page->handle(self::settingsPost(['territory' => 'sk']), self::POST)));
        $this->assertSame([], TableDb::writes());
    }

    public function test_settings_sourceWithoutPropertiesOrFailing(): void
    {
        self::definition();
        \ServiceSource::$failProperties = new \RuntimeException('bug <b>');
        TableDb::$log = [];

        $html = self::config()->settingsHtml();

        $this->assertStringContainsString('did not describe its settings', $html);
        $this->assertStringNotContainsString('bug', $html);
        $this->assertSame([], TableDb::writes(), 'a page that only reads logs nothing');
    }

    /** a source with keys: the feeding right sees its settings, only a superadmin changes them */
    public function test_settings_keysSourceDisabledForEditor(): void
    {
        \ServiceSource::$credentials = [['vendor' => 'd4keys', 'context' => '']];
        self::definition(['settings' => '{"territory":"cz"}']);
        $config = self::config();
        $html   = $config->settingsHtml() . $config->mappingHtml();
        $reason = 'This import uses API keys, so only a superadmin may change or delete it.';

        foreach (['Save the settings', 'Reset mapping from the source'] as $label) {
            $button = self::submit($html, $label);
            $this->assertTrue($button->hasAttribute('disabled'), $label);
            $this->assertSame($reason, $button->getAttribute('title'), $label);
        }
        $this->assertStringContainsString($reason, self::text($html), 'said on the page, not only in a title');
        $this->assertStringContainsString($reason, self::act(self::settingsPost(['territory' => 'sk'])));
        $this->assertStringContainsString($reason, self::act(['action' => 'reset_mapping']));
        $this->assertSame(['territory' => 'cz'], json_decode(self::stored(self::DEF)['settings'], true));

        $GLOBALS['r_superuser'] = [AA_ID => true];
        $this->assertFalse(self::submit(self::config()->settingsHtml(), 'Save the settings')->hasAttribute('disabled'));
        $this->assertStringContainsString('aa-ok', self::act(self::settingsPost(['territory' => 'sk'])));
    }

    /** unreadable stored settings are said by the settings form; the keys do not depend on them */
    public function test_settings_unreadableStoredSettings(): void
    {
        \ServiceSource::$credentials = [['vendor' => 'd4unread', 'context' => '']];
        self::definition(['settings' => '{"broken"']);

        $html = self::config()->settingsHtml();

        $this->assertStringContainsString('The stored settings cannot be read', $html);
        $this->assertSame([['d4unread', 'Key is missing']], self::tableRows($html, 'aa-import-keys'));
    }

    // ------------------------------------------------------------ keys

    public function test_keys_hintOnlyForSuperadmin(): void
    {
        define('AA_APIKEY_D4SET_LOGIN', 'abcdefghijklmnop<Z9>');
        \ServiceSource::$credentials = [['vendor' => 'd4set', 'context' => 'login'], ['vendor' => 'd4missing', 'context' => '']];
        self::definition();

        $rows = self::tableRows(self::config()->settingsHtml(), 'aa-import-keys');
        $this->assertSame([['d4set login', 'Key is set'], ['d4missing', 'Key is missing']], $rows, 'the state, no hint');

        $GLOBALS['r_superuser'] = [AA_ID => true];
        $html = self::config()->settingsHtml();
        $rows = self::tableRows($html, 'aa-import-keys');
        $this->assertSame(['d4set login', 'Key is set'], array_slice($rows[0], 0, 2));
        $this->assertStringContainsString('<Z9>', $rows[0][2], 'the end of the key');
        $this->assertStringContainsString('&lt;Z9&gt;', $html, 'escaped');
        $this->assertStringNotContainsString('abcdefghijklmnop', $html);
        $this->assertStringContainsString('AA_APIKEY_D4MISSING', $rows[1][2], 'where to set it');
    }

    public function test_keys_valueNeverRendered(): void
    {
        define('AA_APIKEY_D4KEY', 'Secret-Value-1234567890');
        define('AA_APIKEY_D4BROKEN', "Broken-Value\nsecond-line-0987");
        \ServiceSource::$credentials = [['vendor' => 'd4key', 'context' => ''], ['vendor' => 'd4broken', 'context' => ''], ['vendor' => '<i>v</i>', 'context' => '<b>c</b>']];
        self::definition();

        foreach (['editor' => [], 'superadmin' => [AA_ID => true]] as $case => $superuser) {
            $GLOBALS['r_superuser'] = $superuser;
            $page = self::page();
            $html = $page->config()->html() . $page->html(time());

            foreach (['Secret-Value', '1234567890', 'Broken-Value', 'second-line'] as $part) {
                $this->assertStringNotContainsString($part, $html, "$case: $part");
            }
            $rows = self::tableRows($html, 'aa-import-keys');
            $this->assertSame(['Key is set', 'Key is damaged', 'Key is missing'], array_column($rows, 1), $case);
            $this->assertSame('<i>v</i> <b>c</b>', $rows[2][0], "$case: the names the source gives, as text");
            $this->assertStringNotContainsString('<i>v', $html, $case);
        }
    }

    public function test_keys_noneOrSourceFailing(): void
    {
        self::definition();
        $this->assertStringContainsString('needs no key', self::config()->settingsHtml());

        \ServiceSource::$refuse = new \RuntimeException('bug');
        $this->assertStringContainsString('needs no key', self::config()->settingsHtml(), 'the keys need no instance');

        \ServiceSource::$failKeys = new \RuntimeException('bug');
        $this->assertStringContainsString('did not say which keys', self::config()->settingsHtml());

        \ServiceSource::$failKeys    = null;
        \ServiceSource::$credentials = ['csu'];
        $this->assertStringContainsString('did not say which keys', self::config()->settingsHtml(), 'a list of another shape');
    }

    // ------------------------------------------------------------ helpers

    private static function page(string $id = self::DEF): DetailPage
    {
        return DetailPage::forRequest(self::SLICE, $id, AuthGetUid()) ?? throw new \LogicException('No page');
    }

    private static function config(): DetailConfig
    {
        return self::page()->config();
    }

    private static function token(): string
    {
        return FormToken::get('import-' . self::DEF);
    }

    /** posts $post with the token, follows the redirect: the message the GET page shows
     *  @param array<string, mixed> $post */
    private static function act(array $post): string
    {
        $page = self::page();
        return self::shown($page, $page->handle($post + ['token' => self::token()], self::POST));
    }

    /** @param ?array{string, string} $outcome */
    private static function shown(DetailPage $page, ?array $outcome): string
    {
        self::assertNotNull($outcome, 'the POST did something');
        $page->afterPost($outcome);
        return $page->outcomeOnceHtml();
    }

    /** the settings form of DEF as its widgets post it, made from the definition as stored now
     *  @param array<string, string> $fields  @return array<string, mixed> */
    private static function settingsPost(array $fields): array
    {
        $def = Definition::fresh(self::DEF) ?? throw new \LogicException('No definition');
        return ['action' => 'settings', 'source' => self::SOURCE, 'stored' => Service::settingsHash($def), 'aa' => ['u' . self::DEF => array_map(static fn(string $v): array => [$v], $fields)]];
    }

    /** What a browser posts of the settings form in $html: every field, an unchecked box not, the
     *  token too - as $_POST.
     *  @return array<string, mixed> */
    private static function browserPost(string $html): array
    {
        $doc = self::dom($html);
        foreach ($doc->getElementsByTagName('form') as $form) {
            if ($form->getAttribute('method') !== 'post') {
                continue;
            }
            $pairs = [];
            foreach ((new \DOMXPath($doc))->query('.//input|.//select|.//textarea', $form) ?: [] as $el) {
                $name = ($el instanceof \DOMElement) ? $el->getAttribute('name') : '';
                if (($name === '') || $el->hasAttribute('disabled')) {
                    continue;
                }
                $type = strtolower($el->getAttribute('type') ?: 'text');
                if (($el->nodeName === 'input') && (in_array($type, ['submit', 'button'], true) || (in_array($type, ['checkbox', 'radio'], true) && !$el->hasAttribute('checked')))) {
                    continue;
                }
                $value = match ($el->nodeName) {
                    'textarea' => $el->textContent,
                    'select'   => (new \DOMXPath($doc))->query('.//option[@selected]', $el)?->item(0)?->getAttribute('value') ?? '',
                    default    => $el->getAttribute('value'),
                };
                $pairs[] = rawurlencode($name) . '=' . rawurlencode($value);
            }
            parse_str(implode('&', $pairs), $post);
            if (($post['action'] ?? '') === 'settings') {
                unset($post['token']);
                return $post;
            }
        }
        throw new \LogicException('No settings form');
    }

    /** @param array<string, string> $props  a stored definition over ServiceSource */
    private static function definition(array $props = [], string $id = self::DEF): Definition
    {
        $def = new Definition();
        $def->setNew($id, self::SLICE, 'prices ' . $id);
        $props += [
            'source'     => self::SOURCE,
            'mapping'    => self::NO_PARAM_MAPPING,
            'key_fields' => '["relation........"]',
            'store_mode' => 'update/insert',
            'enabled'    => '1',
        ];
        foreach ($props as $property => $value) {
            $def->setProperty($property, $value);
        }
        self::assertSame($id, $def->save());
        return $def;
    }

    /** @return string a running run of DEF, stored as Service stores one - for a source Service would not start */
    private static function openRun(): string
    {
        $def = Definition::fresh(self::DEF) ?? throw new \LogicException('No definition');
        return (string) DB_AA::transaction(static fn() => Run::start([
            'subject'     => self::DEF,
            'user'        => self::USER,
            'position'    => RunCursor::START,
            'mode'        => 'run',
            'snapshot'    => json_encode($def->snapshot(), JSON_THROW_ON_ERROR),
            'stream_keys' => '["s1"]',
            'trigger'     => 'manual',
        ]))->getId();
    }

    /** the slice's field $id gets the name $name */
    private static function nameField(string $id, string $name): void
    {
        $slice = \AA_Slice::getModule(self::SLICE);
        self::assertInstanceOf(\AA_Slice::class, $slice);
        $field = $slice->getField($id) ?? throw new \LogicException("No field $id");
        $slice->setFields(new FakeFields(self::SLICE, [new \AA_Field(['name' => $name] + iterator_to_array($field))]));
    }

    private static function dom(string $html): \DOMDocument
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>');
        return $doc;
    }

    private static function text(string $html): string
    {
        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
    }

    /** @return list<list<string>> the text of the cells of the body rows of the table of class $class */
    private static function tableRows(string $html, string $class): array
    {
        $rows = [];
        foreach (self::dom($html)->getElementsByTagName('table') as $table) {
            if (!in_array($class, explode(' ', $table->getAttribute('class')), true)) {
                continue;
            }
            foreach ($table->getElementsByTagName('tbody')->item(0)?->getElementsByTagName('tr') ?? [] as $tr) {
                $cells = [];
                foreach ($tr->childNodes as $td) {
                    if ($td instanceof \DOMElement) {
                        $cells[] = trim((string) preg_replace('/\s+/', ' ', $td->textContent));
                    }
                }
                $rows[] = $cells;
            }
        }
        return $rows;
    }

    private static function submit(string $html, string $label): \DOMElement
    {
        foreach (self::dom($html)->getElementsByTagName('button') as $button) {
            if (trim($button->textContent) === $label) {
                return $button;
            }
        }
        throw new \LogicException("No button $label");
    }
}
