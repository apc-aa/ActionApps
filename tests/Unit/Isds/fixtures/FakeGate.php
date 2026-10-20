<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Fixtures;

use AA\Isds\Access;
use AA\Isds\DatovkaConf;
use AA\Isds\Gate;
use AA\Isds\Recipients;
use AA\Isds\SendRun;
use AA\Isds\Setup;
use AA\Isds\SetupException;
use AA\Isds\SliceRules;

/**
 * The gate on a page given here: the site rendered, the slices (FakeSliceRules), the accounts of
 * datovka_conf.php, the login (Access over the rights of FakePerms), the campaigns, their runs and the recipients.
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
        return Setup::checked($settings, $site, $this->rules, $this->conf);
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

    protected function run(string $campaign): ?SendRun
    {
        return $this->runs[$campaign] ?? null;
    }

    protected function resolver(): Recipients
    {
        return $this->resolver ?? throw new \LogicException('The test gives no recipients');
    }
}
