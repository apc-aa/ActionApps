<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Fixtures;

use AA\Isds\Access;
use AA\Isds\Gate;
use AA\Isds\Setup;
use AA\Isds\SetupException;
use AA\Isds\SliceRules;

/**
 * The gate on a page given here: the site rendered, the slices (FakeSliceRules), the accounts of
 * include/config.php and the login (Access over the rights of FakePerms). The slices open in the real
 * AA_Credentials.
 */
final class FakeGate extends Gate
{
    /** @var list<array{string, string}> selector, problem of each log row */
    public array $logged = [];

    public int $noStores = 0;

    /**
     * @param ?string               $site      the site whose page is rendered; null = no site page (view.php, cron)
     * @param array<string, string> $constants as Store::userConstants()
     */
    public function __construct(
        private ?string $site,
        private FakeSliceRules $rules,
        private array $constants,
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
        return Setup::checked($settings, $site, $this->rules, $this->constants);
    }

    protected function rules(): SliceRules
    {
        return $this->rules;
    }

    protected function access(): Access
    {
        return $this->access;
    }

    protected function log(string $selector, string $problem): void
    {
        $this->logged[] = [$selector, $problem];
    }
}
