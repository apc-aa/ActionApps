<?php

declare(strict_types=1);

/**
 * The app store: which apps an AA publishes (the site settings app_*), what Get_Apps lists, what Get_App
 * gives the public account (the definitions of a published site, for an install - checked every request),
 * and the store's own limits.
 */

namespace Tests\Unit\Central;

use AA\Central\AppExport;
use AA\Central\AppRequest;
use AA\Central\AppStore;
use AA\Central\Package\Reader;
use AA\Central\ResponderPolicy;
use AA_Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\FakeSource;
use Tests\Unit\Central\Package\Fixtures\ImportFixture;

require_once __DIR__ . '/Package/fixtures/ImportFixture.php';
require_once __DIR__ . '/Package/fixtures/FakeSource.php';

final class AppStoreTest extends TestCase
{
    use ImportFixture;

    private const WEB     = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
    private const DRAFT   = 'a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2';
    private const GONE    = 'a3a3a3a3a3a3a3a3a3a3a3a3a3a3a3a3';
    private const ARTICLES = 'b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1';
    private const OTHER   = 'b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2';
    /** owns the constant group ct_foreign, in no published site */
    private const OWNER   = 'b3b3b3b3b3b3b3b3b3b3b3b3b3b3b3b3';
    private const STARTED = 1_790_000_000;

    private FakeSource $source;

    protected function setUp(): void
    {
        $module = static fn(string $id, string $type, string $name, string $lang, int $deleted = 0): array => ['id' => $id, 'type' => $type, 'name' => $name,
            'lang_file' => $lang, 'flag' => 0, 'deleted' => $deleted, 'owner' => '', 'created_by' => 'uid-17', 'created_at' => 1700000000, 'slice_url' => '', 'priority' => 5, 'app_id' => null];
        $slice  = static fn(string $id, string $name): array => ['id' => $id, 'name' => $name, 'type' => 'S', 'vid' => 0, 'lang_file' => 'cz-utf8_news_lang.php', 'deleted' => 0];
        $site   = static fn(string $id): array => ['id' => $id, 'state_file' => '/cz/home', 'structure' => '', 'flag' => 1];
        $uses   = static fn(string $site, string $module): array => ['source_id' => $site, 'destination_id' => $module, 'flag' => 1];
        $tables = [
            'module'   => [
                $module(self::WEB, 'W', 'Store web', 'cz-utf8_site_lang.php'),
                $module(self::DRAFT, 'W', 'Draft web', 'cz-utf8_site_lang.php'),
                $module(self::GONE, 'W', 'Deleted web', 'cz-utf8_site_lang.php', 1),
                $module(self::ARTICLES, 'S', 'Articles', 'cz-utf8_news_lang.php'),
                $module(self::OTHER, 'S', 'Other', 'cz-utf8_news_lang.php'),
                $module(self::OWNER, 'S', 'Owner', 'cz-utf8_news_lang.php'),
            ],
            'site'     => [$site(self::WEB), $site(self::DRAFT), $site(self::GONE)],
            'slice'    => [$slice(self::ARTICLES, 'Articles'), $slice(self::OTHER, 'Other'), $slice(self::OWNER, 'Owner')],
            'relation' => [$uses(self::WEB, self::ARTICLES), $uses(self::DRAFT, self::OTHER), $uses(self::GONE, self::OTHER)],
            'field'    => [['id' => 'headline........', 'slice_id' => self::ARTICLES, 'type' => 'headline', 'name' => 'Title', 'input_pri' => 100, 'input_show_func' => 'fld:', 'input_insert_func' => '']],
        ];
        $approved = [self::WEB => [self::WEB, self::ARTICLES], self::DRAFT => [], self::GONE => [self::GONE, self::OTHER], self::ARTICLES => [self::ARTICLES]];
        foreach ([self::WEB => 1, self::DRAFT => 0, self::GONE => 1, self::ARTICLES => 1] as $owner => $publish) {
            $this->settings($tables, $owner, $publish, "About $owner", '1.2', $approved[$owner]);
        }
        $this->source = new FakeSource($tables);
    }

    /** the settings object of a module as AA_Object::save() stores it (the site class also for a slice: no site)
     *  @param list<string> $approved the modules published with the site */
    private function settings(array &$tables, string $owner, int $publish, string $description, string $version, array $approved): void
    {
        $id = string2id('AA_Modulesettings_Site' . $owner);
        foreach (['aa_type' => 'AA_Modulesettings_Site', 'aa_owner' => $owner, 'app_publish' => $publish ? '1' : '', 'app_description' => $description, 'app_version' => $version, 'app_modules' => implode(',', $approved)] as $property => $value) {
            $tables['object_text'][] = ['object_id' => $id, 'property' => $property, 'priority' => 0, 'value' => $value];
        }
    }

    /** as a superadmin publishes it: the flag and the modules the site uses now */
    private function publish(string $site, int $publish): void
    {
        $values = ['app_publish' => $publish ? '1' : '', 'app_modules' => $publish ? implode(',', $this->store()->modulesOf($site)) : ''];
        foreach ($this->source->tables['object_text'] as $i => $row) {
            if (($row['object_id'] === string2id('AA_Modulesettings_Site' . $site)) && isset($values[$row['property']])) {
                $this->source->tables['object_text'][$i]['value'] = $values[$row['property']];
            }
        }
    }

    /** the site uses one more module - e.g. a site admin adds a slice to "uses" */
    private function uses(string $site, string $module): void
    {
        $this->source->tables['relation'][] = ['source_id' => $site, 'destination_id' => $module, 'flag' => 1];
    }

    private function store(): AppStore
    {
        return new AppStore($this->source);
    }

    /** Get_App of the public account: an export with no rights but the published modules */
    private function publicAnswer(array $param): AA_Response
    {
        $store  = $this->store();
        $export = new AppExport($this->source, ['url' => 'https://store.example/aa/', 'aa-version' => '2026.9'], static fn(string $module): bool => in_array($module, $store->publishedModules(), true), false, true);
        return (new AppRequest($export, 'store-uid', static fn(): bool => true, 'st0re000000000000000000000000000', $store))->answer($param);
    }

    private static function definitions(array $modules = [self::WEB], string $mode = 'install'): array
    {
        return ['part' => 'definitions', 'modules' => $modules, 'mode' => $mode, 'bins' => 'active', 'started' => self::STARTED];
    }

    // ---------------------------------------------------------------- the store and its account

    public function test_the_default_store_is_actionapps_org(): void
    {
        self::assertFalse(defined('AA_APP_STORE_URL'), 'a config may name another store');
        self::assertSame('https://actionapps.org/apc-aa/', AppStore::url());
        self::assertSame('https://actionapps.org/apc-aa/central/responder.php', AppStore::responderUrl());
    }

    public function test_the_public_password_is_long_and_random(): void
    {
        self::assertGreaterThanOrEqual(32, strlen(AppStore::PUBLIC_PASSWORD));
        self::assertGreaterThanOrEqual(20, count(count_chars(AppStore::PUBLIC_PASSWORD, 1)), 'no word, no pattern');
        self::assertNotSame(AppStore::PUBLIC_USER, AppStore::PUBLIC_PASSWORD);
    }

    /** @return array<string, array{string, int, int, int, bool}> */
    public static function limits(): array
    {
        return [
            'Get_Jwt below'        => ['Get_Jwt', 29, 29, 29, false],
            'Get_Jwt at'           => ['Get_Jwt', 30, 30, 30, true],
            'Get_App below'        => ['Get_App', 119, 119, 119, false],
            'Get_App at'           => ['Get_App', 120, 120, 120, true],
            'Get_Apps at'          => ['Get_Apps', 120, 120, 120, true],
            'another command'      => ['Get_Modules', 0, 0, 0, true],
            'Get_App, /48 below'   => ['Get_App', 0, 479, 479, false],
            'Get_App, /48 at'      => ['Get_App', 0, 480, 480, true],
            'Get_Apps, /48 at'     => ['Get_Apps', 0, 480, 480, true],
            'Get_Jwt, /48 below'   => ['Get_Jwt', 0, 119, 119, false],
            'Get_Jwt, /48 at'      => ['Get_Jwt', 0, 120, 120, true],
            'Get_App, all below'   => ['Get_App', 0, 0, 1999, false],
            'Get_App, all at'      => ['Get_App', 0, 0, 2000, true],
            'Get_Apps, all at'     => ['Get_Apps', 0, 0, 2000, true],
            'Get_Jwt, all below'   => ['Get_Jwt', 0, 0, 499, false],
            'Get_Jwt, all at'      => ['Get_Jwt', 0, 0, 500, true],
        ];
    }

    /** $recent = the requests of the command from the client in the last hour, $network = from its IPv6 /48,
     *  $all = from anywhere - many addresses must not add up to more than the store can give */
    #[DataProvider('limits')]
    public function test_the_public_account_has_a_limit_per_hour(string $command, int $recent, int $network, int $all, bool $over): void
    {
        self::assertSame($over, AppStore::overLimit($command, $recent, $all, $network));
    }

    /** @return array<string, array{string, ?string}> */
    public static function networks(): array
    {
        return [
            'IPv6'                 => ['2001:db8:1:2:aaaa:bbbb:cccc:dddd', '2001:db8:1::/48'],
            'IPv6 of another /64'  => ['2001:db8:1:ffff::1', '2001:db8:1::/48'],
            'IPv6 of another /48'  => ['2001:db8:2::1', '2001:db8:2::/48'],
            'IPv4'                 => ['192.0.2.7', null],
            'IPv4 mapped to IPv6'  => ['::ffff:192.0.2.7', null],
            'no IP'                => ['x', null],
        ];
    }

    /** a site commonly has a /48: its /64s count together too */
    #[DataProvider('networks')]
    public function test_an_ipv6_client_counts_also_in_its_48(string $ip, ?string $network): void
    {
        self::assertSame($network, AppStore::networkKey($ip));
    }

    /** @return array<string, array{string, string}> */
    public static function clients(): array
    {
        return [
            'IPv4'                  => ['192.0.2.7', '192.0.2.7'],
            'IPv6'                  => ['2001:db8:1:2:aaaa:bbbb:cccc:dddd', '2001:db8:1:2::/64'],
            'IPv6 of the same /64'  => ['2001:db8:1:2::1', '2001:db8:1:2::/64'],
            'IPv6 of another /64'   => ['2001:db8:1:3::1', '2001:db8:1:3::/64'],
            'IPv6 written long'     => ['2001:0DB8:0001:0002:0000:0000:0000:0001', '2001:db8:1:2::/64'],
            'IPv4 mapped to IPv6'   => ['::ffff:192.0.2.7', '192.0.2.7'],
            'no address'            => ['', 'unknown'],
            'no IP'                 => ['x; DROP', 'unknown'],
        ];
    }

    /** one IPv6 host has a whole /64 - counted per address it would have no limit */
    #[DataProvider('clients')]
    public function test_the_limit_counts_an_ipv6_network_as_one_client(string $ip, string $key): void
    {
        self::assertSame($key, AppStore::clientKey($ip));
    }

    /** a store run logs in again by itself when its token is (nearly) out - the password is public */
    public function test_a_store_token_is_used_while_it_lives(): void
    {
        $token = static fn(array $claims): string => 'x.' . rtrim(strtr(base64_encode((string) json_encode($claims)), '+/', '-_'), '=') . '.sig';
        $now   = 1_800_000_000;

        self::assertTrue(AppStore::fresh($token(['iat' => $now - 60, 'exp' => $now + 3600]), $now));
        self::assertFalse(AppStore::fresh($token(['iat' => $now - 86000, 'exp' => $now + 60]), $now), 'out within minutes - a preview and its write');
        self::assertFalse(AppStore::fresh($token(['iat' => $now - 90000, 'exp' => $now - 1]), $now));
        self::assertFalse(AppStore::fresh('', $now), 'none kept');
        self::assertFalse(AppStore::fresh('garbage', $now));
    }

    public function test_only_the_sql_backend_has_the_public_account(): void
    {
        self::assertTrue(AppStore::sqlBackend('sql'));
        self::assertFalse(AppStore::sqlBackend('ldap'), 'the name there may be anyone\'s');
        self::assertFalse(AppStore::sqlBackend(''));
        self::assertTrue(AppStore::sqlBackend(), 'these tests run as sql');
    }

    // ---------------------------------------------------------------- what is published

    public function test_a_site_is_published_by_its_setting(): void
    {
        $store = $this->store();

        self::assertTrue($store->published(self::WEB));
        self::assertFalse($store->published(self::DRAFT), 'app_publish 0');
        self::assertFalse($store->published(self::GONE), 'a deleted site');
        self::assertFalse($store->published(self::ARTICLES), 'a slice - only sites are published');
        self::assertFalse($store->published(str_repeat('c', 32)), 'no module');
        self::assertFalse($store->published('../x'), 'no id');
    }

    public function test_a_site_tells_its_description_and_version(): void
    {
        self::assertSame(['published' => true, 'description' => 'About ' . self::WEB, 'version' => '1.2', 'changed' => false, 'added' => []], $this->store()->site(self::WEB));
        self::assertSame(['published' => false, 'description' => 'About ' . self::DRAFT, 'version' => '1.2', 'changed' => false, 'added' => []], $this->store()->site(self::DRAFT));
        self::assertNull($this->store()->site(self::ARTICLES));
    }

    public function test_the_published_modules_are_the_published_sites_and_the_modules_they_use(): void
    {
        self::assertEqualsCanonicalizing([self::WEB, self::ARTICLES], $this->store()->publishedModules(), 'not the modules of the draft or of the deleted site');
    }

    public function test_the_modules_of_a_site_are_it_and_the_modules_it_uses(): void
    {
        self::assertSame([self::WEB, self::ARTICLES], $this->store()->modulesOf(self::WEB));
        self::assertSame([], $this->store()->modulesOf(self::GONE), 'a deleted site');
        self::assertSame([], $this->store()->modulesOf(self::ARTICLES), 'no site');
    }

    /** "uses" is edited by any site admin: a module added after publishing must not go public with it */
    public function test_a_site_that_uses_more_modules_than_published_is_not_served(): void
    {
        $this->uses(self::WEB, self::OWNER);
        $store = $this->store();

        self::assertFalse($store->published(self::WEB));
        self::assertTrue($store->site(self::WEB)['changed']);
        self::assertSame([], $store->publishedModules());
        self::assertSame([], $store->apps(null));
        self::assertSame(ResponderPolicy::ERR_APP_DENIED, $this->publicAnswer(self::definitions())->getError());

        $this->publish(self::WEB, 1);           // a superadmin publishes it again
        self::assertTrue($this->store()->published(self::WEB));
        self::assertFalse($this->publicAnswer(self::definitions())->isError());
    }

    public function test_the_changed_site_names_the_modules_added_since_publishing(): void
    {
        $this->uses(self::WEB, self::OWNER);

        self::assertSame([self::OWNER], $this->store()->site(self::WEB)['added']);
        self::assertSame([], $this->store()->site(self::DRAFT)['added'], 'not published - nothing approved yet');
    }

    /** @return array<string, array{?list<string>, list<string>, bool, list<string>}> */
    public static function approvals(): array
    {
        $a = str_repeat('a', 32);
        $b = str_repeat('b', 32);
        $c = str_repeat('c', 32);
        return [
            'published first - what it uses'        => [null, [$a, $b], false, [$a, $b]],
            'the same modules'                      => [[$a, $b], [$a, $b], false, [$a, $b]],
            'one added, not approved'               => [[$a, $b], [$a, $b, $c], false, [$a, $b]],
            'one added and approved'                => [[$a, $b], [$a, $b, $c], true, [$a, $b, $c]],
            'one taken away - never approved again' => [[$a, $b], [$a], false, [$a]],
            'one swapped, not approved'             => [[$a, $b], [$a, $c], false, [$a]],
        ];
    }

    /** a save of the site form approves modules added since publishing only when asked to - explicitly
     *  @param ?list<string> $stored null = not published yet */
    #[DataProvider('approvals')]
    public function test_modules_added_since_publishing_are_approved_only_explicitly(?array $stored, array $current, bool $approve, array $approved): void
    {
        self::assertSame($approved, AppStore::approvedModules($stored, $current, $approve));
    }

    public function test_a_site_that_uses_fewer_modules_than_published_is_served(): void
    {
        $this->source->tables['relation'] = array_values(array_filter($this->source->tables['relation'], static fn(array $r): bool => $r['source_id'] !== self::WEB));

        self::assertTrue($this->store()->published(self::WEB));
        self::assertFalse($this->store()->site(self::WEB)['changed']);
    }

    public function test_the_public_list_has_the_published_sites_only(): void
    {
        self::assertSame([
            ['id' => self::WEB, 'name' => 'Store web', 'description' => 'About ' . self::WEB, 'version' => '1.2', 'published' => true, 'slices' => 1, 'alerts' => 0],
        ], $this->store()->apps(null));
    }

    /** the list is UTF-8 whatever the site's language - AppExport sends names so too */
    public function test_the_list_has_the_names_in_utf8(): void
    {
        $this->source->tables['module'][0]['lang_file'] = 'cz_site_lang.php';
        $this->source->tables['module'][0]['name']      = "Web \xE8esky";           // windows-1250

        self::assertSame("Web \u{010D}esky", $this->store()->apps(null)[0]['name']);
    }

    public function test_a_superadmin_lists_every_site_whose_modules_all_are_editable(): void
    {
        $all = $this->store()->apps(static fn(): bool => true);
        self::assertSame([self::DRAFT, self::WEB], array_column($all, 'id'), 'by name, not the deleted one');
        self::assertSame([false, true], array_column($all, 'published'));

        $some = $this->store()->apps(static fn(string $module): bool => $module !== self::OTHER);
        self::assertSame([self::WEB], array_column($some, 'id'), 'the draft uses a module the user may not edit');
    }

    // ---------------------------------------------------------------- Get_App of the public account

    public function test_the_public_account_gets_a_published_site_for_an_install(): void
    {
        $response = $this->publicAnswer(self::definitions());

        self::assertFalse($response->isError(), (string) json_encode($response->getResponse()));
        $package = (new Reader())->read($response->getResponse()['package'])->package;
        self::assertTrue($package->published, 'meta/publish: the app is published');
        self::assertSame('About ' . self::WEB, $package->description);
        self::assertSame('1.2', $package->version);
        self::assertEqualsCanonicalizing([self::WEB, self::ARTICLES], array_map(static fn($m): string => $m->id, $package->modules));
    }

    /** @return array<string, array{array<string, mixed>, int}> */
    public static function refusedToThePublic(): array
    {
        return [
            'an unpublished site'   => [self::definitions([self::DRAFT]), ResponderPolicy::ERR_APP_DENIED],
            'a deleted site'        => [self::definitions([self::GONE]), ResponderPolicy::ERR_APP_DENIED],
            'a slice of a site'     => [self::definitions([self::ARTICLES]), ResponderPolicy::ERR_APP_DENIED],
            'two sites'             => [self::definitions([self::WEB, self::DRAFT]), ResponderPolicy::ERR_APP_DENIED],
            'a transfer'            => [self::definitions([self::WEB], 'transfer'), ResponderPolicy::ERR_APP_REQUEST],
            'a batch'               => [['part' => 'batch', 'mode' => 'transfer', 'slice' => self::ARTICLES, 'after' => '', 'started' => self::STARTED], ResponderPolicy::ERR_APP_REQUEST],
            'the item counts'       => [['part' => 'counts', 'mode' => 'install', 'slices' => [self::ARTICLES], 'edited_after' => 0, 'started' => self::STARTED], ResponderPolicy::ERR_APP_REQUEST],
            'the item ids'          => [['part' => 'item_ids', 'mode' => 'install', 'slice' => self::ARTICLES, 'after' => '', 'started' => self::STARTED], ResponderPolicy::ERR_APP_REQUEST],
        ];
    }

    #[DataProvider('refusedToThePublic')]
    public function test_the_public_account_gets_nothing_else(array $param, int $code): void
    {
        self::assertSame($code, $this->publicAnswer($param)->getError());
    }

    public function test_publishing_is_checked_on_every_request(): void
    {
        self::assertFalse($this->publicAnswer(self::definitions())->isError());

        $this->publish(self::WEB, 0);
        self::assertSame(ResponderPolicy::ERR_APP_DENIED, $this->publicAnswer(self::definitions())->getError(), 'no longer published - at once');
    }

    // ---------------------------------------------------------------- a constant group of an unpublished slice

    private function foreignGroup(): void
    {
        $this->source->tables['field'][] = ['id' => 'category.......1', 'slice_id' => self::ARTICLES, 'type' => 'category', 'name' => 'Category', 'input_pri' => 110, 'input_show_func' => 'sel:ct_foreign:', 'input_insert_func' => ''];
        $this->source->tables['constant_slice'][] = ['group_id' => 'ct_foreign', 'slice_id' => self::OWNER, 'propagate' => 1, 'levelcount' => 1, 'horizontal' => 0, 'hidevalue' => 0, 'hierarch' => 0];
        $this->source->tables['constant'][] = ['id' => 'c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1', 'group_id' => 'ct_foreign', 'name' => 'News', 'value' => 'news', 'class' => '', 'pri' => 100, 'ancestors' => '', 'description' => '', 'short_id' => 1];
    }

    public function test_the_public_account_never_gets_a_group_of_an_unpublished_slice(): void
    {
        $this->foreignGroup();

        self::assertSame(ResponderPolicy::ERR_APP_DENIED, $this->publicAnswer(self::definitions())->getError());
    }

    /** the export the store gives, as a superadmin checks it before publishing (AppStore::exportErrors()) */
    private function storeExport(): AppExport
    {
        return new AppExport($this->source, ['url' => 'https://store.example/aa/', 'aa-version' => '2026.9'], static fn(): bool => true, true, true);
    }

    /** what the superadmin publishing the site sees: the export of a published app fails on the group */
    public function test_a_published_app_with_a_group_of_an_unpublished_slice_fails_to_export(): void
    {
        $this->foreignGroup();
        $this->publish(self::WEB, 0);

        try {
            $this->storeExport()->definitions([self::WEB], false, false, self::STARTED);
            self::fail('a published app with a group of an unpublished slice exports');
        } catch (\AA\Central\ExportRefused $e) {
            self::assertContains(AppStore::GROUP_UNPUBLISHED . ' ' . self::OWNER, array_map(static fn(array $f): string => $f[1]->code . ' ' . $f[1]->ref, $e->errors()));
        }
    }

    /** the strict export is the store's only: the superadmin still copies their own published site */
    public function test_a_superadmin_exports_a_published_site_as_any_other(): void
    {
        $this->foreignGroup();
        $this->source->tables['slice'][0]['vid'] = 999;        // a view outside the app - W
        $export = new AppExport($this->source, ['url' => 'https://store.example/aa/', 'aa-version' => '2026.9'], static fn(): bool => true, true);

        [$xml, $findings] = $export->definitions([self::WEB], false, false, self::STARTED);
        self::assertFalse((new Reader())->read($xml)->package->published, 'no meta/publish - the copy is no store app');
        self::assertNotSame([], $findings, 'the warning stays a warning');
    }

    public function test_the_store_exports_a_published_app_with_no_warnings(): void
    {
        $this->source->tables['slice'][0]['vid'] = 999;

        $this->expectException(\AA\Central\ExportRefused::class);
        $this->storeExport()->definitions([self::WEB], false, false, self::STARTED);
    }

    /** the public account is anyone: what is wrong inside the app (paths, ids) stays on the store */
    public function test_the_public_account_gets_no_findings_of_a_failed_export(): void
    {
        $this->source->tables['slice'][0]['vid'] = 999;

        $response = $this->publicAnswer(self::definitions());
        self::assertSame(ResponderPolicy::ERR_APP_EXPORT, $response->getError());
        self::assertSame(['export-failed app'], $response->getResponse());
    }

    public function test_a_group_of_a_slice_of_another_published_site_is_fine(): void
    {
        $this->foreignGroup();
        $this->source->tables['relation'][] = ['source_id' => self::DRAFT, 'destination_id' => self::OWNER, 'flag' => 1];
        $this->publish(self::DRAFT, 1);

        self::assertFalse($this->publicAnswer(self::definitions())->isError());
    }
}
