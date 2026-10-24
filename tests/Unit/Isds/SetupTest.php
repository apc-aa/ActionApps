<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA;
use AA\Isds\Account;
use AA\Isds\DatovkaConf;
use AA\Isds\Registry\Config;
use AA\Isds\Setup;
use AA\Isds\SetupException;
use AA\Isds\SliceProblem;
use AA\Isds\SliceRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Isds\Fixtures\FakeSliceRules;
use Tests\Unit\Isds\Fixtures\Sending;

// data providers read its ids before setUpBeforeClass()
require_once __DIR__ . '/fixtures/Sending.php';

/**
 * The settings a template gives {datovkabulk:page}: checked on every page, one problem per thing to
 * fix. A template names any slices it likes - only the site and the pair datovka_conf.php binds
 * together pass, and of the others it learns nothing.
 */
final class SetupTest extends TestCase
{
    private const SITE     = Sending::SITE;
    private const SITE2    = Sending::SITE2;
    private const CAMPAIGN = Sending::CAMPAIGN;
    private const MESSAGE  = Sending::MESSAGE;
    private const SOURCE   = Sending::SOURCE;
    private const OTHER    = Sending::OTHER;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
        require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
        require_once __DIR__ . '/Registry/fixtures/PropertySlice.php';
        require_once __DIR__ . '/fixtures/FakeSliceRules.php';
        class_exists(\AA_Stringexpand_Jsonasoc::class);   // stringexpand.php: aa_json_decode()
    }

    protected function tearDown(): void
    {
        FakeSliceRules::forget();
    }

    /** @return array<string, mixed> settings of the template that pass */
    private static function settings(): array
    {
        return [
            'campaign_slice' => self::CAMPAIGN,
            'message_slice'  => self::MESSAGE,
            'source_slice'   => self::SOURCE,
            'types'          => ['mesto' => 'Cities', 'mestocast' => 'City districts'],
            'default_types'  => ['mesto'],
            'member_field'   => Sending::MEMBER,
            'test_box'       => [' DJ7J6MK '],
            'letterhead'     => "Národní síť Zdravých měst ČR\nPalackého 1, Praha",
        ];
    }

    private static function rules(): FakeSliceRules
    {
        return FakeSliceRules::ready(self::SITE, self::CAMPAIGN, self::MESSAGE)->withSource(self::SITE, self::SOURCE);
    }

    /** @param array<string, mixed>|string $settings */
    private static function build(array|string $settings, string $site = self::SITE, ?FakeSliceRules $rules = null, ?DatovkaConf $conf = null): Setup
    {
        return Setup::fromTemplate(is_string($settings) ? $settings : (string) json_encode($settings), $site, $rules ?? self::rules(), $conf ?? Sending::bound());
    }

    /**
     * @param array<string, mixed>|string $settings
     * @return list<string> the problems
     */
    private static function problems(array|string $settings, string $site = self::SITE, ?FakeSliceRules $rules = null, ?DatovkaConf $conf = null): array
    {
        $checked = Setup::checked(is_string($settings) ? $settings : (string) json_encode($settings), $site, $rules ?? self::rules(), $conf ?? Sending::bound());
        self::assertInstanceOf(SetupException::class, $checked, 'the settings passed');

        return array_map(static fn(SliceProblem $p): string => $p->text, $checked->problems);
    }

    public function test_fromTemplate_ready(): void
    {
        $setup = self::build(self::settings(), strtoupper(self::SITE));

        self::assertSame(self::SITE, $setup->site);
        self::assertSame([self::CAMPAIGN, self::MESSAGE, self::SOURCE], [$setup->campaignSlice, $setup->messageSlice, $setup->sourceSlice]);
        self::assertSame(['mesto' => 'Cities', 'mestocast' => 'City districts'], $setup->types);
        self::assertSame(['mesto'], $setup->defaultTypes);
        self::assertSame(Sending::MEMBER, $setup->memberField);
        self::assertSame(['dj7j6mk'], $setup->testBoxes);
        self::assertSame(Setup::DEFAULT_RECIPIENTS, $setup->maxRecipients);
        self::assertSame(Account::TEST, $setup->environment);
        self::assertSame('', $setup->senderBox, 'a name and a password do not tell the box');
        self::assertSame("Národní síť Zdravých měst ČR\nPalackého 1, Praha", $setup->letterhead);
    }

    public function test_fromTemplate_idsInCapitals(): void
    {
        $settings = ['campaign_slice' => strtoupper(self::CAMPAIGN), 'message_slice' => self::MESSAGE, 'source_slice' => self::SOURCE, 'types' => ['mesto' => 'Cities']];

        self::assertSame(self::CAMPAIGN, self::build($settings)->campaignSlice);
    }

    // what a template gives: {jsonasoc} turns a nested object or list into its JSON text
    public function test_fromTemplate_asJsonasocGivesIt(): void
    {
        $settings = AA::Un('{jsonasoc:campaign_slice:' . self::CAMPAIGN . ':message_slice:' . self::MESSAGE . ':source_slice:' . self::SOURCE
            . ':types:{jsonasoc:mesto:Cities:mestocast:City districts}:default_types:["mesto","mestocast"]:member_field::test_box:["dj7j6mk","abc1234"]'
            . ':max_recipients::environment:test:letterhead:NSZM ČR}');
        $setup    = self::build($settings);

        self::assertSame(['mesto' => 'Cities', 'mestocast' => 'City districts'], $setup->types);
        self::assertSame(['mesto', 'mestocast'], $setup->defaultTypes);
        self::assertSame(['dj7j6mk', 'abc1234'], $setup->testBoxes);
        self::assertSame(['', Setup::DEFAULT_RECIPIENTS, 'NSZM ČR'], [$setup->memberField, $setup->maxRecipients, $setup->letterhead], 'an empty value is no value');
    }

    public function test_fromTemplate_nestedAsJsonText(): void
    {
        $setup = self::build(['types' => '{"mesto":"Cities"}', 'default_types' => ' ["mesto"] ', 'test_box' => '["dj7j6mk"]'] + self::settings());

        self::assertSame([['mesto' => 'Cities'], ['mesto'], ['dj7j6mk']], [$setup->types, $setup->defaultTypes, $setup->testBoxes]);
        self::assertSame(['mesto'], self::build(['default_types' => 'mesto'] + self::settings())->defaultTypes, 'one type as a text');
    }

    // a type of digits - PHP makes the key an int, the settings compare it as a text
    public function test_fromTemplate_typesOfDigits(): void
    {
        $rules                        = self::rules();
        $rules->configs[self::SOURCE] = Config::fromValues(self::SOURCE, ['name_field' => 'headline........', 'ds_field' => 'text..........11', 'type_field' => 'switch.........1']);
        $setup                        = self::build(['types' => '{"1":"Cities","2":"Districts"}', 'default_types' => '2'] + self::settings(), rules: $rules);

        self::assertSame([1 => 'Cities', 2 => 'Districts'], $setup->types);
        self::assertSame(['2'], $setup->defaultTypes);
        self::assertSame([0 => 'Cities'], self::build(['types' => ['0' => 'Cities'], 'default_types' => '0'] + self::settings(), rules: $rules)->types, 'type 0 too');
    }

    /** @return array<string, array{mixed}> */
    public static function unset(): array
    {
        return ['empty' => [''], 'null' => [null]];
    }

    #[DataProvider('unset')]
    public function test_fromTemplate_emptyIsNotSet(mixed $empty): void
    {
        $settings = array_fill_keys(['default_types', 'member_field', 'test_box', 'max_recipients', 'environment', 'letterhead'], $empty) + self::settings();
        $setup    = self::build($settings);

        self::assertSame([[], '', [], Setup::DEFAULT_RECIPIENTS, Account::TEST, ''], [$setup->defaultTypes, $setup->memberField, $setup->testBoxes, $setup->maxRecipients, $setup->environment, $setup->letterhead]);
        self::assertSame(['Set source_slice to the id of a slice - 32 hexadecimal digits'], self::problems(['source_slice' => $empty] + self::settings()));
    }

    // a template written on more lines: {jsonasoc:...:environment:test
    //   :max_recipients: } gives "test\n" and " "
    public function test_fromTemplate_valuesTrimmed(): void
    {
        $setup = self::build(['environment' => "\ntest\n", 'max_recipients' => " 250\n", 'member_field' => "\t" . Sending::MEMBER, 'campaign_slice' => ' ' . self::CAMPAIGN . "\n"] + self::settings());

        self::assertSame([Account::TEST, 250, Sending::MEMBER, self::CAMPAIGN], [$setup->environment, $setup->maxRecipients, $setup->memberField, $setup->campaignSlice]);
        self::assertSame(Setup::DEFAULT_RECIPIENTS, self::build(['max_recipients' => " \n", 'member_field' => "\t"] + self::settings())->maxRecipients);
        self::assertSame(['Set source_slice to the id of a slice - 32 hexadecimal digits'], self::problems(['source_slice' => "  \n"] + self::settings()), 'blank is not set');
    }

    public function test_fromTemplate_hostedServiceTellsTheSenderBox(): void
    {
        $conf = Sending::conf(['NSZM' => [
            'environment' => 'test',
            'auth'        => 'hosted',
            'sites'       => [self::SITE],
            'slices'      => [self::CAMPAIGN, self::MESSAGE],
            'box'         => 'DJ7J6MK',
            'cert'        => '/etc/aa-isds/ecn.pem',
        ]]);

        self::assertSame('dj7j6mk', self::build(self::settings(), conf: $conf)->senderBox);
    }

    /** @return array<string, array{string}> */
    public static function notObjects(): array
    {
        return ['empty' => [''], 'text' => ['campaign'], 'list' => ['["' . self::CAMPAIGN . '"]'], 'broken' => ['{"campaign_slice":'], 'empty list' => ['[]']];
    }

    #[DataProvider('notObjects')]
    public function test_fromTemplate_notAnObject(string $settings): void
    {
        self::assertSame(['The settings of the mass sending are not a JSON object - write them with {jsonasoc:...}'], self::problems($settings));
    }

    // a typo would silently drop a limit
    public function test_fromTemplate_unknownSetting(): void
    {
        self::assertSame(['Unknown settings of the mass sending: max_recipient - the settings are ' . implode(', ', Setup::SETTINGS)], self::problems(self::settings() + ['max_recipient' => 10]));
    }

    public function test_fromTemplate_requiredSettings(): void
    {
        self::assertSame([
            'Set campaign_slice to the id of a slice - 32 hexadecimal digits',
            'Set message_slice to the id of a slice - 32 hexadecimal digits',
            'Set source_slice to the id of a slice - 32 hexadecimal digits',
            'Set types to an object of the item types to offer, each with its label: {jsonasoc:<type>:<label>:...}',
        ], self::problems(' { } '));
    }

    /** @return array<string, array{mixed}> */
    public static function badTypes(): array
    {
        return ['no label' => [['mesto' => '']], 'a label not a text' => [['mesto' => 1]], 'a text' => ['mesto'], 'broken JSON text' => ['{"mesto":'], 'an empty object' => ['{}']];
    }

    #[DataProvider('badTypes')]
    public function test_fromTemplate_types(mixed $types): void
    {
        self::assertSame(['Set types to an object of the item types to offer, each with its label: {jsonasoc:<type>:<label>:...}'], self::problems(['types' => $types] + self::settings()));
    }

    public function test_fromTemplate_defaultTypesAmongTypes(): void
    {
        self::assertSame(['default_types names types that are not among types: obec'], self::problems(['default_types' => ['mesto', 'obec']] + self::settings()));
        self::assertSame(['Set default_types to a text, or to a list of texts: ["...","..."]'], self::problems(['default_types' => [['mesto']]] + self::settings()));
    }

    public function test_fromTemplate_fieldId(): void
    {
        self::assertSame(['Set member_field to the id of a field - 16 characters like text...........1'], self::problems(['member_field' => "x' OR 1"] + self::settings()));
    }

    public function test_fromTemplate_testBoxes(): void
    {
        self::assertSame(['test_box: dj7j6m is not the id of a data box - 7 letters or digits'], self::problems(['test_box' => ['dj7j6mk', 'dj7j6m']] + self::settings()));
        self::assertSame(['test_box: ["dj7j6mk" is not the id of a data box - 7 letters or digits'], self::problems(['test_box' => '["dj7j6mk"'] + self::settings()), 'broken JSON is a text');
        self::assertSame(['dj7j6mk'], self::build(['test_box' => 'dj7j6mk'] + self::settings())->testBoxes, 'one box as a text');
        self::assertSame([], self::build(['test_box' => []] + self::settings())->testBoxes);
    }

    // production takes only the boxes datovka_conf.php lists for the account: a template narrows them, never adds one -
    // one beyond them is left out, the page still sends
    public function test_fromTemplate_testBoxesOnlyNarrowProduction(): void
    {
        $conf = Sending::conf(['NSZM' => Sending::account(over: ['environment' => 'production', 'auth' => 'cert', 'login' => null, 'password' => null, 'cert' => '/etc/aa/nszm.pem', 'test_boxes' => ['dj7j6mk', 'ab12cde']])]);

        self::assertSame(['dj7j6mk'], self::build(self::settings(), conf: $conf)->testBoxes);
        self::assertSame(['dj7j6mk', 'ab12cde'], self::build(['test_box' => []] + self::settings(), conf: $conf)->testBoxes, 'all of the account');
        self::assertSame(['dj7j6mk'], self::build(['test_box' => ['KV62BQF', 'dj7j6mk']] + self::settings(), conf: $conf)->testBoxes);
        self::assertSame([], self::build(['test_box' => ['kv62bqf']] + self::settings(), conf: $conf)->testBoxes, 'none left: no test message');
    }

    // the test ISDS reaches nobody: the template may name its own boxes
    public function test_fromTemplate_testBoxesOfTheTestAccount(): void
    {
        $conf = Sending::conf(['NSZM' => Sending::account(over: ['test_boxes' => ['ab12cde']])]);

        self::assertSame(['dj7j6mk'], self::build(self::settings(), conf: $conf)->testBoxes, "the template's");
        self::assertSame(['ab12cde'], self::build(['test_box' => []] + self::settings(), conf: $conf)->testBoxes, "the account's");
    }

    /** @return array<string, array{mixed}> */
    public static function badMax(): array
    {
        return ['zero' => [0], 'over' => [Setup::MAX_RECIPIENTS + 1], 'negative' => ['-5'], 'a word' => ['many'], 'a fraction' => [10.5]];
    }

    #[DataProvider('badMax')]
    public function test_fromTemplate_maxRecipients(mixed $max): void
    {
        self::assertSame(['Set max_recipients to a number from 1 to 5000'], self::problems(['max_recipients' => $max] + self::settings()));
    }

    public function test_fromTemplate_maxRecipientsAsText(): void
    {
        self::assertSame(250, self::build(['max_recipients' => '250'] + self::settings())->maxRecipients);
        self::assertSame(Setup::MAX_RECIPIENTS, self::build(['max_recipients' => Setup::MAX_RECIPIENTS] + self::settings())->maxRecipients);
    }

    // production only where datovka_conf.php binds a production account to the pair
    public function test_fromTemplate_environmentOnlyNarrows(): void
    {
        self::assertSame(['environment may only be "test" - misc/datovka/datovka_conf.php decides when production is used'], self::problems(['environment' => 'production'] + self::settings()));
        self::assertSame(Account::TEST, self::build(['environment' => 'test'] + self::settings())->environment);
    }

    // a template of another site naming the slices bound to SITE
    public function test_fromTemplate_anotherSiteNamesTheBoundSlices(): void
    {
        $rules                       = self::rules();
        $rules->sites[]              = self::SITE2;
        $rules->related[self::SITE2] = [self::CAMPAIGN, self::MESSAGE, self::SOURCE];
        $problems                    = self::problems(self::settings(), self::SITE2, $rules);

        self::assertCount(1, $problems);
        self::assertStringStartsWith('No usable ISDS account in misc/datovka/datovka_conf.php is bound to this site and these slices.', $problems[0]);
        self::assertStringNotContainsString('NSZM', $problems[0], 'names no account there is');
        self::assertStringNotContainsString('nszm01login', $problems[0]);
    }

    /**
     * An open slice of another site: neither its name nor anything of its settings or fields - only that
     * the site does not use it, and how datovka_conf.php would bind it.
     * @return array<string, array{string}>
     */
    public static function foreignSlice(): array
    {
        return ['as the message slice' => ['message_slice'], 'as the source' => ['source_slice'], 'as the campaign slice' => ['campaign_slice']];
    }

    #[DataProvider('foreignSlice')]
    public function test_fromTemplate_openSliceOfAnotherSite(string $setting): void
    {
        $rules                       = self::rules();
        $rules->slices[self::OTHER]  = FakeSliceRules::sliceOf(self::OTHER, $setting === 'campaign_slice', ['reading_password' => '', 'd_listlen' => 10], ['text...........1']);
        $rules->names[self::OTHER]   = 'Open elsewhere';
        $rules->outlets[self::OTHER] = SliceRules::OUTLETS;
        $rules->sites[]              = self::SITE2;
        $rules->related[self::SITE2] = [self::OTHER];

        $problems = implode("\n", self::problems([$setting => self::OTHER] + self::settings(), rules: $rules));

        self::assertStringContainsString('Site "Terminal" does not use slice ' . self::OTHER . ' - add the slice to Uses modules in the settings of the site', $problems);
        foreach (['Open elsewhere', 'reading password', 'Listing length', 'Feeding', 'Notification', 'lacks fields', 'data boxes'] as $leak) {
            self::assertStringNotContainsString($leak, $problems);
        }
    }

    // the site uses the pair, but datovka_conf.php binds it nowhere: nothing of the slices yet
    public function test_fromTemplate_openSlicesOfTheSiteNotBound(): void
    {
        $rules                         = self::rules();
        $rules->slices[self::CAMPAIGN] = FakeSliceRules::sliceOf(self::CAMPAIGN, true, ['reading_password' => '', 'permit_anonymous_post' => 1]);
        $rules->slices[self::MESSAGE]  = FakeSliceRules::sliceOf(self::MESSAGE, false, ['reading_password' => ''], ['text...........1']);
        $rules->outlets[self::MESSAGE] = ['feeds_out'];

        $problems = self::problems(self::settings(), rules: $rules, conf: Sending::bound(self::SITE2));

        self::assertCount(1, $problems);
        self::assertStringStartsWith('No usable ISDS account in misc/datovka/datovka_conf.php is bound to this site and these slices.', $problems[0]);
    }

    // a protected slice of the installation the site does not use
    public function test_fromTemplate_slicesNotBoundInConfig(): void
    {
        $rules                      = self::rules();
        $rules->slices[self::OTHER] = FakeSliceRules::sliceOf(self::OTHER, false);

        self::assertSame([
            "No usable ISDS account in misc/datovka/datovka_conf.php is bound to this site and these slices. If a test account is bound and you mean it, ask for the test environment. To bind a test account, add to DATOVKA_CONF['accounts'] an account with 'environment' => 'test', 'auth' => 'basic', 'sites' => ['" . self::SITE . "'], 'slices' => ['" . self::CAMPAIGN . "', '" . self::OTHER . "'] (the campaign slice first), 'login' and 'password'; a production account logs in with a certificate ('auth' => 'cert' or 'hosted') instead. The State Check lists the accounts and their problems",
            'Site "Terminal" does not use slice ' . self::OTHER . ' - add the slice to Uses modules in the settings of the site',
        ], self::problems(['message_slice' => self::OTHER] + self::settings(), rules: $rules));
    }

    /**
     * A file AA cannot use: no advice to bind an account that may well be there, nothing of the file -
     * whoever writes the template reads it
     */
    #[DataProvider('fileAaCannotUse')]
    public function test_fromTemplate_fileAaCannotUseTellsNoMore(bool $broken): void
    {
        self::assertSame(['The settings of data boxes cannot be read now - ask the administrator of the server'], self::problems(self::settings(), conf: Sending::fileConf($broken)));
    }

    /** @return array<string, array{bool}> */
    public static function fileAaCannotUse(): array
    {
        return ['unreadable' => [false], 'broken' => [true]];
    }

    // the slices of the site, bound to it: now everything about them
    public function test_fromTemplate_openSliceOfTheSite(): void
    {
        $rules                         = self::rules();
        $rules->slices[self::CAMPAIGN] = FakeSliceRules::sliceOf(self::CAMPAIGN, true, ['reading_password' => '']);

        $checked = Setup::checked((string) json_encode(self::settings()), self::SITE, $rules, Sending::bound());

        self::assertInstanceOf(SetupException::class, $checked);
        self::assertEquals([new SliceProblem('Slice "ISDS campaigns" has no reading password - fill in Password for Reading in the settings of the slice', self::CAMPAIGN, SliceRules::SLICE_PAGE)], $checked->problems);
        self::assertSame(self::CAMPAIGN, $checked->campaignSlice);
    }

    // site.php leaves only readers logged in there, and nobody may send
    public function test_fromTemplate_siteLetsOnlyReadersIn(): void
    {
        $rules                         = self::rules();
        $rules->permModes[self::SITE]  = '1';
        $rules->slices[self::CAMPAIGN] = FakeSliceRules::sliceOf(self::CAMPAIGN, true, ['reading_password' => '']);

        self::assertSame(['Site "Terminal" lets only readers of its Reader Management slices log in, so nobody gets the right to send - set User login databases to "AA + all Reader Management slices in AA (old behavior)" in the settings of the site'], self::problems(self::settings(), rules: $rules), 'the slices once the site is ready');
    }

    public function test_fromTemplate_sourceRules(): void
    {
        $rules = self::rules();
        unset($rules->configs[self::SOURCE]);

        self::assertSame(['Slice "Territory" has no settings of data boxes - set its fields on its page Data boxes from the register'], self::problems(self::settings(), rules: $rules));
    }

    public function test_checked_givesTheProblemsInsteadOfThrowing(): void
    {
        $checked = Setup::checked('not json', self::SITE);

        self::assertInstanceOf(SetupException::class, $checked);
        self::assertSame('', $checked->campaignSlice);
    }

    public function test_fromSnapshot_takesTheValues(): void
    {
        $setup = Setup::fromSnapshot(self::SITE, self::CAMPAIGN, self::MESSAGE, self::SOURCE, ['mesto' => 'Cities'], environment: Account::PRODUCTION);

        self::assertSame([self::CAMPAIGN, ['mesto' => 'Cities'], [], Setup::DEFAULT_RECIPIENTS, Account::PRODUCTION], [$setup->campaignSlice, $setup->types, $setup->defaultTypes, $setup->maxRecipients, $setup->environment]);
    }
}
