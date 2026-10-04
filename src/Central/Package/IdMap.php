<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Optimize\RedefineCoreConstants;
use InvalidArgumentException;

/** Source id => target id maps of one package import. Immutable. The plan knows
 *  every map but views, e-mails and alerts filters - AUTO_INCREMENT numbers them when the rows are written
 *  (withNumbers()). The export lint uses an identity map of the app.
 *
 *  Item ids are a function, not a list - a transfer has too many items to keep: item() keeps an id, or,
 *  with an item key (a copy in the same AA, where the source items stay), derives the new id from the key.
 *  Which ids of a text are items of the package's slices only the DB knows: withPackageItems() takes those
 *  the caller looked up, for one write, and never stores them (toJson()). */
final class IdMap
{
    /** AA_CONSTANT_GROUPS_CORE, include/constedit_util.php:674 - exist on every AA, never renamed */
    public const CORE_GROUPS = ['lt_groupNames', ...RedefineCoreConstants::GROUPS];

    /** source group names: constant_group_id_valid(), include/constedit_util.php:706 (that file needs the AA boot) */
    public const GROUP_RE = '/^[^\x00-\x1F\x7F:\'"`\\\\%]{1,16}\z/';

    /** a NEW group name is spliced into templates and input_show_func: no `}`, `#`, `:`, spaces */
    private const NEW_GROUP_RE = '/^[A-Za-z0-9_.-]{1,16}\z/';

    /** gensalt() alphabet (include/util.php:1407); at most 9 chars, so getAlertsField() still fits 16 */
    private const COLLECTION_RE = '/^[A-Za-z0-9]{1,9}\z/';

    /** @var array<string, string> */
    private array $modules;
    /** @var array<string, string> modules + constants + objects + items + settings ids */
    private array $longIds;
    /** @var array<string, array<int, int>> 'view' | 'email' | 'filter' => old => new */
    private array $numbers;
    /** '' = item ids stay; else new = string2id(key . old) */
    private string $itemKey = '';
    /** @var array<string, string> items of the package's slices named in what is written now: old => item() */
    private array $items = [];

    /**
     * @param array<string, string> $modules     module ids (32 hex, any case; stored lower case)
     * @param array<string, string> $longIds     other long ids: constants, objects and subobjects,
     *                                           items, settings objects (string2id)
     * @param array<int, int>       $views       view.id
     * @param array<int, int>       $emails      email.id
     * @param array<string, string> $groups      constant group names (core groups are never mapped)
     * @param array<string, string> $collections alerts_collection.id
     */
    public function __construct(
        array $modules = [],
        array $longIds = [],
        array $views = [],
        array $emails = [],
        private readonly array $groups = [],
        private readonly array $collections = [],
    ) {
        $this->modules = self::longIdMap($modules);
        $this->longIds = $this->modules;
        foreach (self::longIdMap($longIds) as $old => $new) {
            if (($this->longIds[$old] ?? $new) !== $new) {
                throw new InvalidArgumentException("Long id $old is mapped twice");
            }
            $this->longIds[$old] = $new;
        }
        $this->numbers = ['view' => self::numberMap($views), 'email' => self::numberMap($emails), 'filter' => []];
        self::check($groups, self::GROUP_RE, 'constant group');
        foreach ($groups as $old => $new) {
            if (!self::renamable((string) $old, $new)) {
                throw new InvalidArgumentException("Constant group $old cannot be renamed to $new");
            }
        }
        self::check($collections, self::COLLECTION_RE, 'alerts collection');
    }

    /** the group may be mapped to $new: the same name, or a new name spliced safely into templates, core
     *  groups never */
    public static function renamable(string $old, string $new): bool
    {
        return ($new === $old) || (preg_match(self::GROUP_RE, $old) && preg_match(self::NEW_GROUP_RE, $new)
            && !in_array($old, self::CORE_GROUPS, true) && !in_array($new, self::CORE_GROUPS, true));
    }

    /** a group name an app may carry: no `[`-list, no `#sLiCe-<id>` (items of a slice), no core group */
    public static function isAppGroup(string $name): bool
    {
        return ($name !== '') && ($name[0] !== '[') && !str_starts_with($name, ReferenceRewriter::RELATION_MARK) && !in_array($name, self::CORE_GROUPS, true);
    }

    /** the numbers the rows got from AUTO_INCREMENT; `filter`: alerts_filter.id - no text refers to a
     *  filter, alerts_collection_filter.filterid does
     *  @param string $kind 'view' | 'email' | 'filter' @param array<int, int> $numbers */
    public function withNumbers(string $kind, array $numbers): self
    {
        if (!isset($this->numbers[$kind])) {
            throw new InvalidArgumentException("Unknown number map $kind");
        }
        $clone                 = clone $this;
        $clone->numbers[$kind] = self::numberMap($numbers);
        return $clone;
    }

    /** the map of a copy in the same AA: item ids derived from $key - one key per run, so a second copy
     *  of the same items gets other ids */
    public function withItemKey(string $key): self
    {
        if (!preg_match('/^[0-9a-f]{32}\z/', $key)) {
            throw new InvalidArgumentException('An item key is 32 lower-case hex');
        }
        $clone          = clone $this;
        $clone->itemKey = $key;
        $clone->items   = [];
        return $clone;
    }

    /** the key of the item ids; '' = they stay */
    public function itemKey(): string
    {
        return $this->itemKey;
    }

    /** the target id of an item of the package's slices */
    public function item(string $old): string
    {
        $old = strtolower($old);
        return ($this->itemKey === '') ? $old : string2id($this->itemKey . $old);
    }

    /** the map with these source ids known as items of the package's slices - longId() maps them by item()
     *  @param list<string> $ids */
    public function withPackageItems(array $ids): self
    {
        $clone = clone $this;
        foreach ($ids as $id) {
            if (!preg_match('/^[0-9a-f]{32}\z/i', $id)) {
                throw new InvalidArgumentException("Invalid item id $id");
            }
            if (!isset($this->longIds[strtolower($id)])) {
                $clone->items[strtolower($id)] = $this->item($id);
            }
        }
        return $clone;
    }

    public function module(string $old): ?string
    {
        return $this->modules[strtolower($old)] ?? null;
    }

    public function longId(string $old): ?string
    {
        return $this->longIds[strtolower($old)] ?? $this->items[strtolower($old)] ?? null;
    }

    public function view(int $old): ?int
    {
        return $this->numbers['view'][$old] ?? null;
    }

    public function email(int $old): ?int
    {
        return $this->numbers['email'][$old] ?? null;
    }

    public function filter(int $old): ?int
    {
        return $this->numbers['filter'][$old] ?? null;
    }

    public function group(string $old): ?string
    {
        return $this->groups[$old] ?? null;
    }

    public function collection(string $old): ?string
    {
        return $this->collections[$old] ?? null;
    }

    /** @return array<string, string> */
    public function modules(): array
    {
        return $this->modules;
    }

    /** @return array<string, string> all long ids, modules and the package items included, lower case */
    public function longIds(): array
    {
        return $this->longIds + $this->items;
    }

    /** @return array<int, int> */
    public function views(): array
    {
        return $this->numbers['view'];
    }

    /** @return array<int, int> */
    public function emails(): array
    {
        return $this->numbers['email'];
    }

    /** @return array<int, int> */
    public function filters(): array
    {
        return $this->numbers['filter'];
    }

    /** @return array<string, string> */
    public function groups(): array
    {
        return $this->groups;
    }

    /** @return array<string, string> */
    public function collections(): array
    {
        return $this->collections;
    }

    /** the map for a record that outlives the request (the install run); fromJson() reads it back */
    public function toJson(): string
    {
        return json_encode([
            'modules'     => $this->modules,
            'longIds'     => array_diff_key($this->longIds, $this->modules),
            'views'       => $this->numbers['view'],
            'emails'      => $this->numbers['email'],
            'filters'     => $this->numbers['filter'],
            'groups'      => $this->groups,
            'collections' => $this->collections,
            'itemKey'     => $this->itemKey,
        ], JSON_THROW_ON_ERROR);
    }

    /** checked as a new map - the stored text is no more trusted than a package */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        $part = static fn(string $key): array => is_array($data[$key] ?? null) ? $data[$key] : throw new InvalidArgumentException("Id map without $key");
        $map  = (new self($part('modules'), $part('longIds'), $part('views'), $part('emails'), $part('groups'), $part('collections')))
            ->withNumbers('filter', $part('filters'));
        $key  = is_string($data['itemKey'] ?? null) ? $data['itemKey'] : throw new InvalidArgumentException('Id map without itemKey');
        return ($key === '') ? $map : $map->withItemKey($key);
    }

    /** Lower case both sides. Exactly 32 hex on both sides - the rewrite must not change the
     *  length, or serialize()d texts (site.structure) break.
     *  @param array<mixed, mixed> $map
     *  @return array<string, string> */
    private static function longIdMap(array $map): array
    {
        self::check($map, '/^[0-9a-f]{32}\z/i', 'long id');
        $lower = [];
        foreach ($map as $old => $new) {
            $old = strtolower((string) $old);
            if (($lower[$old] ?? strtolower($new)) !== strtolower($new)) {
                throw new InvalidArgumentException("Long id $old is mapped twice");   // AAAA… and aaaa… are one id
            }
            $lower[$old] = strtolower($new);
        }
        return $lower;
    }

    /** @param array<mixed, mixed> $map
     *  @return array<int, int> */
    private static function numberMap(array $map): array
    {
        foreach ($map as $old => $new) {
            if (!is_int($old) || !is_int($new) || $old < 1 || $new < 1) {
                throw new InvalidArgumentException("Invalid id mapping $old => " . var_export($new, true));
            }
        }
        return $map;
    }

    /** @param array<mixed, mixed> $map */
    private static function check(array $map, string $re, string $what): void
    {
        foreach ($map as $old => $new) {
            if (!is_string($new) || !preg_match($re, (string) $old) || !preg_match($re, $new)) {
                throw new InvalidArgumentException("Invalid $what mapping $old => " . var_export($new, true));
            }
        }
    }
}
