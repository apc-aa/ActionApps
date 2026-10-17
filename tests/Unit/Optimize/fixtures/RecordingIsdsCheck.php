<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize\Fixtures;

use AA\Isds\Registry\Box;
use AA\Isds\Registry\Config;
use AA\Isds\Registry\RegisterIndex;
use AA\Isds\Registry\Sync;
use AA\Optimize\CheckIsdsRegistry;
use Tests\Unit\Isds\Registry\Fixtures\DownloadSync;
use Tests\Unit\Isds\Registry\Fixtures\LockedRegisterIndex;

require_once __DIR__ . '/../../Isds/Registry/fixtures/DownloadSync.php';

/** CheckIsdsRegistry over the settings in $configs, a RecordingSync per slice and the boxes in $boxes
 *  instead of the database and the downloaded register - or over the register in $file and its record
 *  in LockedRegisterIndex. */
final class RecordingIsdsCheck extends CheckIsdsRegistry
{
    /** @var list<Config> */
    public array $configs = [];

    /** @var list<string> ids of the slices in the trash */
    public array $trashed = [];

    /** @var array<string, Sync> slice id => its sync */
    public array $syncs = [];

    /** @var list<Box>|\RuntimeException the register, or how reading it fails */
    public array|\RuntimeException $boxes = [];

    /** @var array{state: string, time: int, size: int, sha256: string}|\RuntimeException|null null = no
     *  register downloaded; the exception is thrown as by a directory PHP may not look into */
    public array|\RuntimeException|null $register = ['state' => RegisterIndex::RECORDED, 'time' => 1760000000, 'size' => 17000000, 'sha256' => '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef'];

    /** a register read as Sync reads the downloaded one, its index made by LockedRegisterIndex, in
     *  place of $register, $boxes and $indexProblem */
    public ?string $file = null;

    public int $now = 1760000000 + 3600;

    /** @var array<string, string> slice id => name */
    public array $names = [];

    public int $registerReads = 0;

    /** whether include/config.php sets up an ISDS account */
    public bool $isdsAccount = false;

    /** RegisterIndex::problem() */
    public string $indexProblem = '';

    public int $indexChecks = 0;

    protected function allConfigs(): array
    {
        return $this->configs;
    }

    protected function isStoredSlice(string $sliceId): bool
    {
        return !in_array($sliceId, $this->trashed, true);
    }

    protected function newSync(Config $config): Sync
    {
        return $this->syncs[$config->sliceId()] ?? throw new \LogicException('Set the sync of the slice');
    }

    protected function hasIsdsAccount(): bool
    {
        return $this->isdsAccount;
    }

    protected function indexProblem(): string
    {
        ++$this->indexChecks;
        return ($this->file !== null) ? (new LockedRegisterIndex($this->file))->problem() : $this->indexProblem;
    }

    protected function registerInfo(): ?array
    {
        if ($this->file !== null) {
            return DownloadSync::registerInfo($this->file);
        }
        if ($this->register instanceof \RuntimeException) {
            throw $this->register;
        }
        return $this->register;
    }

    protected function registerBoxes(string $sha256): ?array
    {
        ++$this->registerReads;
        if ($this->file !== null) {
            return Sync::registerBoxes($sha256, $this->file);
        }
        if ($this->boxes instanceof \RuntimeException) {
            throw $this->boxes;
        }
        return $this->boxes;
    }

    protected function sliceName(string $sliceId): string
    {
        return $this->names[$sliceId] ?? $sliceId;
    }

    protected function now(): int
    {
        return $this->now;
    }
}
