<?php

declare(strict_types=1);

namespace AA\Central\Package;

/** Findings of an import check (Validator, SecurityLint): at most MAX_PER_CODE of one level and code,
 *  the rest counted into one `+N more (<code>)`; all() lists them by danger S, E, W, I - so a rare
 *  dangerous finding never hides behind thousands of common ones. */
final class FindingList
{
    public const MAX_PER_CODE = 50;

    private const RANK = [LintFinding::SECURITY => 0, LintFinding::ERROR => 1, LintFinding::WARNING => 2, LintFinding::INFO => 3];

    /** @var list<array{string, LintFinding}> */
    private array $list = [];
    /** @var array<string, int> "<level> <code>" => findings of it, kept or not */
    private array $count = [];

    public function add(string $path, LintFinding $finding): void
    {
        $key = "$finding->level $finding->code";
        if (($this->count[$key] = ($this->count[$key] ?? 0) + 1) <= self::MAX_PER_CODE) {
            $this->list[] = [$path, $finding];
        }
    }

    /** true: findings of the level and code are no longer kept - a caller may skip the work of making one */
    public function full(string $level, string $code): bool
    {
        return ($this->count["$level $code"] ?? 0) >= self::MAX_PER_CODE;
    }

    /** $n more findings of the level and code, found but not made (SecurityLint::lint() stops making them) */
    public function skipped(string $level, string $code, int $n): void
    {
        $this->count["$level $code"] = ($this->count["$level $code"] ?? 0) + $n;
    }

    /** @return list<array{string, LintFinding}> by danger (stable), a code's `+N more` after its findings */
    public function all(): array
    {
        $list = $this->list;
        foreach ($this->count as $key => $n) {
            if ($n > self::MAX_PER_CODE) {
                [$level, $code] = explode(' ', $key, 2);
                $list[]         = ['', new LintFinding($level, $code, '+' . ($n - self::MAX_PER_CODE) . " more ($code)", 0)];
            }
        }
        return self::sort($list);
    }

    /** findings by danger S, E, W, I - stable, so lists of two checks (Validator, ImportPlan) merge
     *  without counting a `+N more` again
     *  @param list<array{string, LintFinding}> $list @return list<array{string, LintFinding}> */
    public static function sort(array $list): array
    {
        usort($list, static fn(array $a, array $b): int => (self::RANK[$a[1]->level] ?? 4) <=> (self::RANK[$b[1]->level] ?? 4));
        return $list;
    }
}
