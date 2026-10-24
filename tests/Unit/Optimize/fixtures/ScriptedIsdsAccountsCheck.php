<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize\Fixtures;

use AA\Isds\DatovkaConf;
use AA\Isds\SliceRules;
use AA\Optimize\CheckIsdsAccounts;
use Tests\Unit\Isds\Fixtures\FakeSliceRules;
use Tests\Unit\Isds\Fixtures\Sending;

require_once __DIR__ . '/../../Isds/fixtures/Sending.php';

/** CheckIsdsAccounts over the accounts in $accounts, the slice rules in $rules and the log rows in
 *  $pages, $campaigns and $actions instead of datovka_conf.php and the database, with the modules $here in this
 *  database, at the time $now, under the PHP SAPI $sapi, with the directory of AA $bases. */
final class ScriptedIsdsAccountsCheck extends CheckIsdsAccounts
{
    /** @var array<array-key, mixed> as DATOVKA_CONF['accounts'] */
    public array $accounts = [];

    /** the whole settings instead of $accounts - a file AA cannot use */
    public ?DatovkaConf $conf = null;

    /** @var ?list<string> the sites and slices of this database; null = all */
    public ?array $here = null;

    /** @var list<list<string>> what modulesHere() was asked, call by call */
    public array $asked = [];

    /** @var list<string> */
    public array $bases = [];

    public int $now = 1790841600;

    public string $sapi = 'cgi-fcgi';

    /** @var list<array<string, ?string>> LogOnce::recent() rows of the pages not ready */
    public array $pages = [];

    /** @var list<array<string, ?string>> LogOnce::recent() rows of the campaigns whose recipients were not told */
    public array $campaigns = [];

    /** @var list<array<string, ?string>> LogOnce::recent() rows of the actions that failed on an internal error */
    public array $actions = [];

    public function __construct(public FakeSliceRules $rules) {}

    protected function conf(): DatovkaConf
    {
        return $this->conf ?? Sending::conf($this->accounts);
    }

    protected function modulesHere(array $ids): array
    {
        $this->asked[] = $ids;

        return is_null($this->here) ? $ids : array_values(array_intersect($ids, $this->here));
    }

    protected function bases(): array
    {
        return $this->bases;
    }

    protected function sliceRules(): SliceRules
    {
        return $this->rules;
    }

    protected function now(): int
    {
        return $this->now;
    }

    protected function sapi(): string
    {
        return $this->sapi;
    }

    protected function notReadyPages(): array
    {
        return $this->pages;
    }

    protected function unresolvedCampaigns(): array
    {
        return $this->campaigns;
    }

    protected function failedActions(): array
    {
        return $this->actions;
    }
}
