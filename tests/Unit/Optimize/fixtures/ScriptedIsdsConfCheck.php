<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize\Fixtures;

use AA\Isds\DatovkaConf;
use AA\Optimize\CheckIsdsConf;

/** CheckIsdsConf over the settings $conf, the directory of AA $base and the log rows in $rows instead of
 *  datovka_conf.php of this tree and the database, with the modules $here in this database, at the time $now;
 *  $asked records the look-ups of modules. */
final class ScriptedIsdsConfCheck extends CheckIsdsConf
{
    public DatovkaConf $conf;

    public int $now = 1790841600;

    /** @var ?list<string> the sites and slices of this database; null = all */
    public ?array $here = null;

    /** @var list<list<string>> the ids each look-up of the database asked about */
    public array $asked = [];

    /** @var array<string, list<array<string, ?string>>> LogOnce::recent() rows by log.type */
    public array $rows = [];

    /** @param string $base the directory of AA, ending with a slash */
    public function __construct(public string $base)
    {
        $this->conf = DatovkaConf::fromArray([]);
    }

    protected function conf(): DatovkaConf
    {
        return $this->conf;
    }

    protected function base(): string
    {
        return $this->base;
    }

    protected function modulesHere(array $ids): array
    {
        $this->asked[] = $ids;

        return is_null($this->here) ? $ids : array_values(array_intersect($ids, $this->here));
    }

    protected function now(): int
    {
        return $this->now;
    }

    protected function recent(string $event): array
    {
        return $this->rows[$event] ?? [];
    }
}
