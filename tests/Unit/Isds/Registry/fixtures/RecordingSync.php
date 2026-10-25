<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Registry\Fixtures;

use AA\Isds\Registry\DownloadedRegister;
use AA\Isds\Registry\Sync;
use ItemContent;

require_once __DIR__ . '/MemoryDownloadedRegister.php';

/** Sync over the items in $content and the fields in $fields instead of the slice's; its store records
 *  the item instead of writing it (unless $realStore), the page cache and the reading password are
 *  only counted. The register's record is MemoryDownloadedRegister's; fixBox() takes its lock unless
 *  another holds it ($fixLocked), and records what it would log. */
class RecordingSync extends Sync
{
    /** @var array<string, array<string, mixed>> item id => content as GetItemContent() returns it, in the slice's order */
    public array $content = [];

    /** @var ?list<string> the ids the active bin of the slice holds, in its order; null = all of $content */
    public ?array $listed = null;

    /** another fixBox() holds the lock; with $noDatabase there is no lock at all */
    public bool $fixLocked = false;

    public bool $noDatabase = false;

    /** @var list<string> lockFix(), unlockFix() as called */
    public array $fixLocks = [];

    /** @var list<array{string, string}> what failed and its message, as logFailure() logs it */
    public array $logged = [];

    /** @var list<int> how many ids each loadContent() asked for */
    public array $chunks = [];

    /** @var list<array{content: array<string, mixed>, mode: string, flags: array<int, bool>}> */
    public array $stored = [];

    /** @var array<string, string> item id => the message store() fails with, as a failing storeItem() sets it ('' = none) */
    public array $refuse = [];

    public int $invalidated = 0;

    /** @var \AA_Fields|null the slice's fields as write() checks the settings against them */
    public ?\AA_Fields $fields = null;

    public int $readingAllowed = 0;

    /** the real storeItem() and reading password - against RecordingDb and a fake slice */
    public bool $realStore = false;

    protected function itemIds(): array
    {
        return $this->listed ?? array_map('strval', array_keys($this->content));
    }

    protected function lockFix(?int &$holder = null): bool
    {
        $this->fixLocks[] = 'lock';
        $holder           = $this->noDatabase ? null : 17;

        return !$this->fixLocked;
    }

    protected function unlockFix(): void
    {
        $this->fixLocks[] = 'unlock';
    }

    protected function logFailure(string $what, \Throwable $e): void
    {
        $this->logged[] = [$what, $e->getMessage()];
    }

    protected static function downloadedRegister(string $file): DownloadedRegister
    {
        return new MemoryDownloadedRegister($file);
    }

    protected function loadContent(array $ids): array
    {
        $this->chunks[] = count($ids);
        // as GetItemContent(): in id order, not in the slice's
        $content = array_intersect_key($this->content, array_flip($ids));
        ksort($content);
        return $content;
    }

    protected function store(ItemContent $content, string $mode, array $flags): bool
    {
        if ($this->realStore) {
            return parent::store($content, $mode, $flags);
        }
        $id = $content->getItemID();
        if (isset($this->refuse[$id])) {
            if ($this->refuse[$id] !== '') {
                ItemContent::lastErr(1, $this->refuse[$id]);
            }
            return false;
        }
        $this->stored[] = ['content' => $content->getContent(), 'mode' => $mode, 'flags' => $flags];
        return true;
    }

    protected function invalidate(): void
    {
        ++$this->invalidated;
    }

    protected function sliceFields(): \AA_Fields
    {
        return $this->fields ?? throw new \LogicException('Set the fields of the slice');
    }

    protected function whileReadable(\Closure $write): void
    {
        ++$this->readingAllowed;
        if ($this->realStore) {
            parent::whileReadable($write);
        } else {
            $write();
        }
    }
}
