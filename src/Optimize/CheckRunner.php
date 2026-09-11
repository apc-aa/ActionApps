<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Util\Note;
use AA_Components;
use Throwable;

/** Runs the Optimize classes tagged as checks (types() with CHECK_BASIC, ...)
 *  and collects their findings for State Check and the daily snapshot.
 */
final class CheckRunner
{
    /** classes carrying any of the tags, short name => class name
     * @param string[] $types    AbstractOptimize::CHECK_* tags
     * @param ?string  $category only classes of this category() when given
     * @return array<string, class-string<AbstractOptimize>>
     */
    public static function classes(array $types, ?string $category = null): array
    {
        $found = [];
        foreach (AA_Components::getClassNames('AA\\Optimize\\', null, false) as $short => $class) {
            if (!is_subclass_of($class, AbstractOptimize::class) || !array_intersect($types, $class::types())) {
                continue;
            }
            if ($category !== null && $class::category() !== $category) {
                continue;
            }
            $found[$short] = $class;
        }
        return $found;
    }

    /** @param string[] $types AbstractOptimize::CHECK_* tags
     *  @return Finding[] worst first */
    public static function run(array $types, ?string $category = null): array
    {
        $findings = [];
        foreach (self::classes($types, $category) as $short => $class) {
            array_push($findings, ...self::runClass($short, $class));
        }
        return self::sort($findings);
    }

    /** One class, timed. A crashing check is reported as a finding, not as a broken page.
     * @param class-string<AbstractOptimize> $class
     * @return Finding[]
     */
    public static function runClass(string $short, string $class): array
    {
        $start = microtime(true);
        try {
            $findings = (new $class())->findings();
        } catch (Throwable $e) {
            $findings = [new Finding(Finding::ERROR, _m('Check %1 failed: %2', [$short, $e->getMessage()]), advice: _m('The check itself is broken; the state it watches is unknown.'))];
        }
        $seconds = microtime(true) - $start;
        foreach ($findings as $i => $finding) {
            if ($finding->key === '') {   // a class with one finding is its own identity - readonly, so rebuilt with the key filled in
                $findings[$i] = $finding = new Finding(
                    severity: $finding->severity,
                    title: $finding->title,
                    count: $finding->count,
                    since: $finding->since,
                    evidence: $finding->evidence,
                    advice: $finding->advice,
                    links: $finding->links,
                    repairable: $finding->repairable,
                    key: $short,
                    last: $finding->last,
                );
            }
            $finding->check    = $short;
            $finding->category = $class::category();
            $finding->seconds  = $seconds;
        }
        return $findings;
    }

    /** Takes out the findings a superadmin marked resolved (a resolved AA\Util\Note
     *  on finding:{check}:{key} that still holds - Note::hides()).
     * @param Finding[] $findings
     * @return array{0: Finding[], 1: list<array{0: Finding, 1: Note}>}  visible ones, hidden ones with their note
     */
    public static function splitResolved(array $findings, int $now): array
    {
        $notes    = Note::resolved();
        $visible  = [];
        $resolved = [];
        foreach ($findings as $finding) {
            $note = $notes[Note::resource('finding', $finding->check, $finding->key)] ?? null;
            if ($note && $finding->isProblem() && $note->hides($finding->last, $now)) {
                $resolved[] = [$finding, $note];
            } else {
                $visible[] = $finding;
            }
        }
        return [$visible, $resolved];
    }

    /** @param Finding[] $findings
     *  @return Finding[] worst first, stable for equal severity and count */
    public static function sort(array $findings): array
    {
        usort($findings, [Finding::class, 'compare']);
        return $findings;
    }
}
