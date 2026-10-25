<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Fixtures;

use AA\Isds\Access;
use AA\Isds\Actions;
use AA\Isds\DatovkaConf;
use AA\Isds\Gate;
use AA\Isds\Recipients;
use AA\Isds\SendRun;
use AA\Isds\Setup;
use AA\Isds\SetupException;
use AA\Isds\SliceRules;

require_once __DIR__ . '/FakeActions.php';

/**
 * The gate on a page given here: the site rendered, the slices (FakeSliceRules), the accounts of
 * datovka_conf.php, the login (Access over the rights of FakePerms), the campaigns, their runs and the recipients, the register of data boxes,
 * the actions (FakeActions), the clock and whether output went out. A redirect throws Redirected.
 * The slices open in the real AA_Credentials.
 */
final class FakeGate extends Gate
{
    /** @var list<array{string, string}> selector, problem of each log row of Gate::LOG_EVENT */
    public array $logged = [];

    /** @var list<array{string, string}> selector, problem of each log row of Gate::RECIPIENTS_EVENT */
    public array $recipientsLogged = [];

    public int $noStores = 0;

    /** @var array<string, array{slice: string, active: bool, filter: string, state: string}> item id => what stored() gives */
    public array $campaigns = [];

    /** @var array<string, SendRun> campaign id => its newest run */
    public array $runs = [];

    public ?Recipients $resolver = null;

    /** output went out before the gate - ?debug */
    public bool $headersSent = false;

    /** @var list<string> the headers the gate sent itself */
    public array $sentHeaders = [];

    public int $now = 1_800_000_000;

    /** the settings as checked; null = checked from the template over $rules and $conf */
    public ?Setup $setup = null;

    /** @var ?array{state: string, time: int, size: int, sha256: string} Sync::registerInfo(); null = none downloaded */
    public ?array $register = null;

    /** what Sync::registerInfo() throws; null = it answers $register */
    public ?\RuntimeException $registerFails = null;

    /** @var array<string, string>|\RuntimeException Sync::offered() of the source slice, or what it throws */
    public array|\RuntimeException $offered = [];

    /** how many times the register was paired with the source slice */
    public int $offeredReads = 0;

    /** @var list<array{int, string, ?string}> vid, ids and settings of each view shown */
    public array $shownViews = [];

    /** @var list<string> the campaigns of the page in the active bin, the last edited first */
    public array $campaignList = [];

    /** how many times the campaigns were searched for */
    public int $campaignSearches = 0;

    /** @var array<string, list<string>> campaign => its test messages, the newest first */
    public array $tests = [];

    /** @var array<string, array{slice: string, active?: bool, fields: array<string, string|list<string>>}> item id => an item as
     *       FakeRecipients takes it (Sending::item()) - what readItems() reads */
    public array $items = [];

    /** @var list<list<string>> the ids of each readItems() */
    public array $reads = [];

    /**
     * @param ?string $site the site whose page is rendered; null = no site page (view.php, cron)
     */
    public function __construct(
        private ?string $site,
        private FakeSliceRules $rules,
        private DatovkaConf $conf,
        private Access $access,
    ) {}

    protected function site(): ?string
    {
        return $this->site;
    }

    protected function noStore(): void
    {
        $this->noStores++;
    }

    protected function setup(string $settings, string $site): Setup|SetupException
    {
        return $this->setup ?? Setup::checked($settings, $site, $this->rules, $this->conf);
    }

    protected function rules(): SliceRules
    {
        return $this->rules;
    }

    protected function access(): Access
    {
        return $this->access;
    }

    protected function log(string $selector, string $problem, string $event = Gate::LOG_EVENT): void
    {
        if ($event === Gate::RECIPIENTS_EVENT) {
            $this->recipientsLogged[] = [$selector, $problem];
        } else {
            $this->logged[] = [$selector, $problem];
        }
    }

    protected function stored(string $id): ?array
    {
        return $this->campaigns[$id] ?? null;
    }

    protected function registerInfo(): ?array
    {
        return $this->registerFails ? throw $this->registerFails : $this->register;
    }

    protected function offered(Setup $setup): array
    {
        ++$this->offeredReads;

        return ($this->offered instanceof \RuntimeException) ? throw $this->offered : $this->offered;
    }

    protected function run(string $campaign): ?SendRun
    {
        return $this->runs[$campaign] ?? null;
    }

    protected function resolver(): Recipients
    {
        return $this->resolver ?? throw new \LogicException('The test gives no recipients');
    }

    protected function showView(int $vid, string $ids, ?string $settings): string
    {
        $this->shownViews[] = [$vid, $ids, $settings];

        return "<view $vid>";
    }

    protected function readItems(array $ids): array
    {
        $this->reads[] = $ids;
        $read          = [];
        foreach (array_intersect_key($this->items, array_flip($ids)) as $id => $item) {
            $read[(string) $id] = ['slice' => $item['slice'], 'active' => $item['active'] ?? true, 'values' => array_map(static fn(string|array $v): array => array_values((array) $v), $item['fields'])];
        }

        return $read;
    }

    protected function campaignIds(Setup $setup): array
    {
        ++$this->campaignSearches;

        return $this->campaignList;
    }

    protected function testMessages(Setup $setup, string $campaign): array
    {
        return $this->tests[$campaign] ?? [];
    }

    protected function actions(Access $access): Actions
    {
        return new FakeActions($access, $this->campaignOfPage(...), $this->run(...), $this->answered(...), $this->resolver(...));
    }

    protected function headersSent(): bool
    {
        return $this->headersSent;
    }

    protected function sendHeader(string $header): void
    {
        $this->sentHeaders[] = $header;
    }

    protected function redirect(string $url): never
    {
        throw new Redirected($url);
    }

    protected function now(): int
    {
        return $this->now;
    }
}

/** the gate sent the browser to $url and ended the request */
final class Redirected extends \RuntimeException
{
    public function __construct(public readonly string $url)
    {
        parent::__construct("Redirected to $url");
    }
}
