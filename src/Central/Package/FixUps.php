<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\Package;

/** What the importer changes in a package before it writes it (design balicek-v1.md § 15), each one a FixUp:
 *  - email.attachments of SecurityLint::ATTACHMENTS_DROPPED (a local path or a computed value AA_File_Wrapper
 *    would read from the target's disk) => '';
 *  - an unknown slice.lang_file (the old copy) => the module's lang_file;
 *  - ga_id, alternate_url, app_* of the site settings => dropped (ObjectExport::isLeftOut());
 *  - a tag start (SecurityLint::SPOT_TAG) in a spot text => strip_tags() - the site tree admin prints them
 *    unescaped (modules/site/sitetree.php); `{if:..:<:3..}` stays;
 *  - no <spot-tree> => site.structure = '';
 *  - an alerts_filter several Alerts modules carry (the same row) => kept in the first module only, the
 *    others get its new number from the filter map;
 *  - meta/publish => `users` (Q-H1).
 *  Pure: a changed copy and the list come back. */
final class FixUps
{
    /** @var list<FixUp> */
    private array $fixUps = [];

    public function __construct(private readonly Schema $schema) {}

    /** @return array{Package, list<FixUp>} the changed copy, the changes in package order */
    public function apply(Package $package): array
    {
        $this->fixUps = [];
        $filters      = [];            // filter id => path of the module that creates it
        $modules      = [];
        foreach ($package->modules as $m) {
            $path = "$m->type[$m->id]";
            $rows = $this->attachments($path, $m->rows);
            $args = ['rows' => $rows];
            if ($m->type === Module::SITE) {
                $args = $this->site($path, $m, $rows);
            } elseif (($m->type === Module::SLICE) && isset($rows['slice'][0])) {
                $args['rows']['slice'][0] = $this->langFile($path, $rows['slice'][0], ValueCodec::bytes($rows['module'][0]['lang_file'] ?? ''));
            } elseif ($m->type === Module::ALERTS) {
                $args['rows'] = $this->filters($path, $rows, $filters);
            }
            $modules[] = self::with($m, $args);
        }
        if ($package->publish !== 'users') {
            $this->fixUps[] = new FixUp('meta/publish', FixUp::PUBLISH, (string) $package->publish, 'users');
        }
        return [self::with($package, ['modules' => $modules, 'publish' => 'users']), $this->fixUps];
    }

    /** @param array<string, list<array<string, mixed>>> $rows @return array<string, list<array<string, mixed>>> */
    private function attachments(string $path, array $rows): array
    {
        foreach ($rows['email'] ?? [] as $i => $row) {
            $value = ValueCodec::bytes($row['attachments'] ?? '');
            if (SecurityLint::attachments($value)?->code === SecurityLint::ATTACHMENTS_DROPPED) {
                $rows['email'][$i]['attachments'] = '';
                $this->fixUps[] = new FixUp($this->schema->rowPath($path, 'email', $row, $i) . '/attachments', FixUp::ATTACHMENTS, $value, '');
            }
        }
        return $rows;
    }

    /** @param array<string, mixed> $slice @return array<string, mixed> */
    private function langFile(string $path, array $slice, string $module_file): array
    {
        $file = ValueCodec::bytes($slice['lang_file'] ?? '');
        if (($file !== '') && !ModuleCharset::accepts(Module::SLICE, $file)) {       // '' = the module's
            $slice['lang_file'] = $module_file;
            $this->fixUps[]     = new FixUp("$path/slice[#0]/lang_file", FixUp::LANG_FILE, $file, $module_file);
        }
        return $slice;
    }

    /** settings, spot names, structure
     *  @param array<string, list<array<string, mixed>>> $rows @return array<string, mixed> Module args */
    private function site(string $path, Module $m, array $rows): array
    {
        $settings = $m->settings;
        foreach (array_keys($settings ?? []) as $name) {
            if (ObjectExport::isLeftOut(Schema::SETTINGS_CLASSES[$m->type], (string) $name)) {
                $value          = $settings[$name];
                $this->fixUps[] = new FixUp("$path/settings/$name", FixUp::SETTINGS, is_array($value) ? implode(', ', array_map([ValueCodec::class, 'bytes'], $value)) : ValueCodec::bytes($value), '');
                unset($settings[$name]);
            }
        }
        $tree = $m->spotTree;
        if ($tree === null) {
            $rows['site'][0] = array_merge($rows['site'][0] ?? [], ['structure' => '']);
            $this->fixUps[]  = new FixUp("$path/site[#0]/structure", FixUp::NO_TREE, '', '');
        }
        foreach (is_array($tree['spots'] ?? null) ? $tree['spots'] : [] as $k => $spot) {
            if (is_array($spot)) {
                $tree['spots'][$k] = $this->spot("$path/spot-tree/spot[" . ValueCodec::bytes($spot['id'] ?? '') . ']', $spot);
            }
        }
        return ['rows' => $rows, 'settings' => $settings, 'spotTree' => $tree];
    }

    /** the texts of a spot (LintTexts::mapSpot()) with no tag start
     *  @param array<string, mixed> $spot the canonical form of sitetree::toArray() @return array<string, mixed> */
    private function spot(string $at, array $spot): array
    {
        return LintTexts::mapSpot($at, $spot, function (mixed $value, string $path): mixed {
            $text = is_scalar($value) ? (string) $value : '';
            $new  = self::stripped($text);
            if ($new === $text) {
                return $value;
            }
            $this->fixUps[] = new FixUp($path, FixUp::SPOT_MARKUP, $text, $new);
            return $new;
        });
    }

    /** strip_tags() while a tag start is left (`<<b>b>`), then the bare tag starts; no tag start = as it is */
    private static function stripped(string $text): string
    {
        for ($i = 0; ($i < 5) && preg_match(SecurityLint::SPOT_TAG, $text); ++$i) {
            $text = strip_tags($text);
        }
        return (string) preg_replace('~<(?=[a-z/!?])~i', '', $text);
    }

    /** @param array<string, list<array<string, mixed>>> $rows @param array<string, string> $filters
     *  @return array<string, list<array<string, mixed>>> */
    private function filters(string $path, array $rows, array &$filters): array
    {
        if (!isset($rows['alerts_filter'])) {
            return $rows;
        }
        $kept = [];
        foreach ($rows['alerts_filter'] as $i => $row) {
            $id = ValueCodec::bytes($row['id'] ?? '');
            if (isset($filters[$id]) && ($filters[$id] !== $path)) {
                $this->fixUps[] = new FixUp($this->schema->rowPath($path, 'alerts_filter', $row, $i), FixUp::FILTER_SHARED, $id, $filters[$id]);
                continue;
            }
            $filters[$id] = $path;
            $kept[]       = $row;
        }
        if ($kept) {
            $rows['alerts_filter'] = $kept;
        } else {
            unset($rows['alerts_filter']);
        }
        return $rows;
    }

    /** a readonly model object with some constructor arguments changed (the names are the properties)
     *  @template T of object
     *  @param T                    $o
     *  @param array<string, mixed> $changes
     *  @return T */
    private static function with(object $o, array $changes): object
    {
        return new ($o::class)(...array_merge(get_object_vars($o), $changes));
    }
}
