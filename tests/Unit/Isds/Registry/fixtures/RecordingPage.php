<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Registry\Fixtures;

use AA\Isds\Registry\Box;
use AA\Isds\Registry\Config;
use AA\Isds\Registry\Page;
use AA\Isds\Registry\RegisterIndex;
use AA\Isds\Registry\Sync;

require_once __DIR__ . '/DownloadSync.php';

/** Page over $sync and the boxes in $boxes instead of the downloaded register - or over the register
 *  in $file and its record in LockedRegisterIndex; downloads, saves of the settings and log entries are
 *  only recorded. */
class RecordingPage extends Page
{
    public ?RecordingSync $sync = null;

    /** @var list<Box>|\RuntimeException the exception is thrown as by a damaged register */
    public array|\RuntimeException $boxes = [];

    /** @var array{state: string, time: int, size: int, sha256: string}|\RuntimeException|null null = no
     *  register downloaded; the exception is thrown as by a directory PHP may not look into */
    public array|\RuntimeException|null $register = ['state' => RegisterIndex::RECORDED, 'time' => 1760000000, 'size' => 17000000, 'sha256' => '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef'];

    /** a register read as Sync reads the downloaded one, in place of $register and $boxes */
    public ?string $file = null;

    /** runs once before the register is read - another request at work meanwhile */
    public ?\Closure $beforeRead = null;

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

    protected function registerBoxes(string $sha256): ?array
    {
        [$before, $this->beforeRead] = [$this->beforeRead, null];
        if ($before !== null) {
            $before();
        }
        if ($this->file !== null) {
            return Sync::registerBoxes($sha256, $this->file);
        }
        if ($this->boxes instanceof \RuntimeException) {
            throw $this->boxes;
        }
        return $this->boxes;
    }

    /** public, so a test makes the stamp of the register the page sees */
    public function registerInfo(): ?array
    {
        if ($this->file !== null) {
            return DownloadSync::registerInfo($this->file);
        }
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
