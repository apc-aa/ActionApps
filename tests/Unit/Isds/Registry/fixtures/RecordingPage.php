<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Registry\Fixtures;

use AA\Isds\Registry\Box;
use AA\Isds\Registry\Config;
use AA\Isds\Registry\Page;
use AA\Isds\Registry\Sync;

/** Page over $sync and the boxes in $boxes instead of the downloaded register; downloads, saves of
 *  the settings and log entries are only recorded. */
class RecordingPage extends Page
{
    public ?RecordingSync $sync = null;

    /** @var list<Box>|\RuntimeException the exception is thrown as by a damaged register */
    public array|\RuntimeException $boxes = [];

    /** @var array{mtime: int, size: int}|\RuntimeException|null null = no register downloaded; the
     *  exception is thrown as by a directory PHP may not look into */
    public array|\RuntimeException|null $register = ['mtime' => 1760000000, 'size' => 17000000];

    public int $now = 1760000000 + 3600;

    public int $downloads = 0;

    public ?\RuntimeException $downloadFails = null;

    /** @var array{status: string, last: int} what Sync::download() answers */
    public array $downloadAnswer = ['status' => Sync::DOWNLOADED, 'last' => 0];

    /** @var list<Config> */
    public array $saved = [];

    /** @var list<array{0: string, 1: string}> what failed and its message */
    public array $logged = [];

    protected function newSync(Config $config): Sync
    {
        return $this->sync ?? throw new \LogicException('Set the sync');
    }

    protected function registerBoxes(): array
    {
        if ($this->boxes instanceof \RuntimeException) {
            throw $this->boxes;
        }
        return $this->boxes;
    }

    protected function registerInfo(): ?array
    {
        if ($this->register instanceof \RuntimeException) {
            throw $this->register;
        }
        return $this->register;
    }

    protected function download(): array
    {
        ++$this->downloads;
        if ($this->downloadFails !== null) {
            throw $this->downloadFails;
        }
        return $this->downloadAnswer;
    }

    protected function logFailure(string $what, \Throwable $e): void
    {
        $this->logged[] = [$what, $e->getMessage()];
    }

    protected function saveConfig(Config $config): void
    {
        $this->saved[] = $config;
    }

    protected function now(): int
    {
        return $this->now;
    }
}
