<?php

declare(strict_types=1);

namespace AA\Central;

use AA\Central\Package\LintFinding;
use AA\Central\Package\Model\Module;

/** What the progress page of a transfer gets of TransferProgress::of(): the state and the written counts,
 *  the findings and the error in words - a finding by its FindingText title, what it means and what to
 *  do, a slice by the name of the slice its items go into, an item by its id. No raw finding leaves for
 *  the browser: a found value only as FindingText::found() masks it. One note per code with its count and
 *  the count of each place - a batch of 500 items says the same thing 500 times. The texts are package
 *  text and names: the page escapes them.
 *  @phpstan-type Note array{key: string, level: string, title: string, count: int, places: list<array{string, int}>, lines: list<string>} */
final class TransferText
{
    /** a found value is package text, possibly a whole template */
    private const FOUND_CHARS = 80;
    /** error places that are no place in the package */
    private const NO_PLACE = ['source', 'batch', 'user'];

    /** @var array<string, string> */
    private readonly array $names;

    /** @param array<string, string> $names source slice id => the name of the slice it goes into */
    public function __construct(array $names)
    {
        $this->names = array_change_key_case($names);
    }

    /** notes: the warnings of the run and a cache not cleared after its end; found: the S, E and I
     *  findings of its last batches
     *  @param array<string, mixed> $state TransferProgress::of()
     *  @return array<string, mixed> $state without warnings, findings and the raw error, with
     *          notes: list<Note>, found: list<Note>, error: string */
    public function page(array $state): array
    {
        $page = array_diff_key($state, array_flip(['warnings', 'findings', 'error', 'afterClose']));
        return [
            ...$page,
            'notes' => [...$this->warnings($state['warnings'] ?? []), ...self::afterClose((bool) ($state['afterClose'] ?? false))],
            'found' => $this->found($state['findings'] ?? []),
            'error' => $this->error($state['error'] ?? null),
        ];
    }

    /** @param array<string, array<string, int>> $warnings source slice => code => count @return list<Note> */
    private function warnings(array $warnings): array
    {
        $groups = [];
        foreach ($warnings as $slice => $codes) {
            foreach ($codes as $code => $n) {
                self::add($groups, new LintFinding(LintFinding::WARNING, (string) $code, '', 0), $this->name((string) $slice), (int) $n);
            }
        }
        return self::notes($groups);
    }

    /** @param list<array{level: string, code: string, path: string, ref: string}> $findings @return list<Note> */
    private function found(array $findings): array
    {
        $groups = [];
        foreach ($findings as $f) {
            if ($f['level'] === LintFinding::WARNING) {
                continue;               // counted in the warnings of the run
            }
            $finding = new LintFinding((string) $f['level'], (string) $f['code'], (string) $f['ref'], 0);
            if (($f['path'] === '') && preg_match('~^\+(\d+) more \(~', $finding->ref, $more)) {
                self::add($groups, $finding, '', (int) $more[1]);      // FindingList keeps 50 of a code, counts the rest
                continue;
            }
            $found = mb_strimwidth(FindingText::found($finding, (string) $f['path']), 0, self::FOUND_CHARS, '…');
            self::add($groups, $finding, implode(': ', self::filled([$this->where((string) $f['path']), $found])), 1);
        }
        $notes = self::notes($groups);
        $rank  = array_flip(array_keys(FindingText::levels()));
        usort($notes, static fn(array $a, array $b): int => ($rank[$a['level']] ?? 9) <=> ($rank[$b['level']] ?? 9));
        return $notes;
    }

    /** @return list<Note> */
    private static function afterClose(bool $failed): array
    {
        return $failed ? [[
            'key'    => 'after-close',
            'level'  => LintFinding::WARNING,
            'title'  => FindingText::levels()[LintFinding::WARNING] . ': ' . _m('A cache was not cleared after the transfer'),
            'count'  => 1,
            'places' => [],
            'lines'  => [_m('Pages of the new modules may show old content until their cache expires; the details are in the PHP error log.')],
        ]] : [];
    }

    /** @param array<string, array{LintFinding, array<int|string, int>}> $groups level + code => [finding, place => count] */
    private static function add(array &$groups, LintFinding $finding, string $place, int $count): void
    {
        $key = $finding->level . ' ' . $finding->code;
        $groups[$key] ??= [$finding, []];
        $groups[$key][1][$place] = ($groups[$key][1][$place] ?? 0) + $count;
    }

    /** @param array<string, array{LintFinding, array<int|string, int>}> $groups @return list<Note> */
    private static function notes(array $groups): array
    {
        $notes = [];
        foreach ($groups as $key => [$finding, $places]) {
            $explained = FindingText::explain($finding);
            $notes[]   = [
                'key'    => (string) $key,
                'level'  => $finding->level,
                'title'  => (FindingText::levels()[$finding->level] ?? $finding->level) . ': ' . ($explained['title'] ?? $finding->code),
                'count'  => array_sum($places),
                // a place named "2024" is an int key; a place of no name only counts
                'places' => array_values(array_filter(array_map(static fn(int|string $place, int $n): array => [(string) $place, $n], array_keys($places), $places), static fn(array $p): bool => $p[0] !== '')),
                'lines'  => $explained ? [$explained['what'], _m('What to do') . ': ' . $explained['todo']] : [],
            ];
        }
        return $notes;
    }

    /** why the run stopped: the code in words, where @param array{code: string, where: string}|null $error */
    private function error(?array $error): string
    {
        if ($error === null) {
            return '';
        }
        $code  = (string) $error['code'];
        $title = match ($code) {
            SourceFailed::LOGIN          => _m('The source AA refused the login'),
            SourceFailed::UNREACHABLE    => _m('The source AA cannot be reached'),
            SourceFailed::TIMEOUT        => _m('The source AA did not answer in time'),
            SourceFailed::FAILED         => _m('The source AA gave no usable answer'),
            InstallRun::COLLECT_FAILED   => _m('The source could not read the items'),
            InstallRun::SOURCE_DENIED    => _m('The source gives no items of the slice'),
            InstallRun::DENIED           => _m('The user who started the transfer is no superadmin of this AA any more'),
            InstallRun::WRITE_FAILED     => _m('Writing the items failed - the details are in the PHP error log of this AA'),
            'NO_PROGRESS'                => _m('The batch did not fit into the time of a queue step, not even a smaller one'),
            'STEP_DIED'                  => _m('The batch step stopped before its end several times - the details are in the PHP error log of this AA'),
            'FINAL_CHECK'                => _m('The check at the end failed - the details are in the PHP error log of this AA'),
            'INTERNAL'                   => _m('The batch step failed - the details are in the PHP error log of this AA'),
            'DB'                         => _m('The database of this AA refused to write - the details are in the PHP error log of this AA'),
            'GONE'                       => _m('A slice the transfer writes into was deleted meanwhile'),
            'ENGINE'                     => _m('A table of this AA has no transactions (not InnoDB) - convert it in AA > Optimize'),
            // the run keeps a finding code as ITEM_TOO_BIG (Importer::errorCode())
            default => FindingText::explain(new LintFinding(LintFinding::ERROR, strtolower(strtr($code, '_', '-')), '', 0))['title'] ?? '',
        };
        $where = $this->where(InstallRun::pathOf((string) $error['where']));
        return implode(' - ', self::filled([($title === '') ? $code : "$title ($code)", $where]));
    }

    /** a package path by the name of its slice, the item id and the place in it; '' = no place */
    private function where(string $path): string
    {
        $text = new FindingText();
        if (!preg_match('~^' . Module::SLICE . '\[([0-9a-f]{32})\](?:/(.+))?\z~is', $path, $m)) {
            return in_array($path, self::NO_PLACE, true) ? '' : $text->place($path);
        }
        $rest  = $m[2] ?? '';
        $parts = [$this->name($m[1])];
        if (preg_match('~^(?:(.+?)/)?item\[([0-9a-f]{32})\](?:/(.+))?\z~is', $rest, $p)) {
            // place() leaves a long id to the raw path the preview shows beside it - here it is what tells the item
            $parts = [...$parts, $text->place($p[1]), _m('item %1', [strtolower($p[2])]), $text->place($p[3] ?? '')];
        } elseif ($rest !== '') {
            $parts[] = $text->place($rest);
        }
        return implode(' › ', self::filled($parts));
    }

    /** @param list<string> $texts @return list<string> the texts that say something */
    private static function filled(array $texts): array
    {
        return array_values(array_filter($texts, static fn(string $text): bool => $text !== ''));
    }

    private function name(string $slice): string
    {
        return $this->names[strtolower($slice)] ?? $slice;
    }
}
