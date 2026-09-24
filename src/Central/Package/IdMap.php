<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Optimize\RedefineCoreConstants;
use InvalidArgumentException;

/** Source id => target id maps of one package import (design balicek-v1.md § 7.1). Immutable.
 *
 *  Keys are the ids the package carries (§ 0.4), values the ids on the target. The import plan
 *  (C4.4 step 3) knows every map except views, e-mails and alerts filters - those get their numbers
 *  from AUTO_INCREMENT only when the rows are written, hence withViews()/withEmails()/withFilters().
 *  For an export lint pass an identity map of the app (old id => same id).
 */
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
    /** @var array<int, int> */
    private array $views;
    /** @var array<int, int> */
    private array $emails;
    /** @var array<int, int> */
    private array $filters = [];

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
        $this->views  = self::numberMap($views);
        $this->emails = self::numberMap($emails);
        self::check($groups, self::GROUP_RE, 'constant group');
        foreach ($groups as $old => $new) {
            if (($new !== (string) $old) && (!preg_match(self::NEW_GROUP_RE, $new) || in_array((string) $old, self::CORE_GROUPS, true) || in_array($new, self::CORE_GROUPS, true))) {
                throw new InvalidArgumentException("Constant group $old cannot be renamed to $new");
            }
        }
        self::check($collections, self::COLLECTION_RE, 'alerts collection');
    }

    /** @param array<int, int> $views */
    public function withViews(array $views): self
    {
        $clone        = clone $this;
        $clone->views = self::numberMap($views);
        return $clone;
    }

    /** @param array<int, int> $emails */
    public function withEmails(array $emails): self
    {
        $clone         = clone $this;
        $clone->emails = self::numberMap($emails);
        return $clone;
    }

    /** alerts_filter.id - no text refers to a filter; alerts_collection_filter.filterid does
     *  @param array<int, int> $filters */
    public function withFilters(array $filters): self
    {
        $clone          = clone $this;
        $clone->filters = self::numberMap($filters);
        return $clone;
    }

    public function module(string $old): ?string
    {
        return $this->modules[strtolower($old)] ?? null;
    }

    public function longId(string $old): ?string
    {
        return $this->longIds[strtolower($old)] ?? null;
    }

    public function view(int $old): ?int
    {
        return $this->views[$old] ?? null;
    }

    public function email(int $old): ?int
    {
        return $this->emails[$old] ?? null;
    }

    public function filter(int $old): ?int
    {
        return $this->filters[$old] ?? null;
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

    /** @return array<string, string> all long ids, modules included, lower case */
    public function longIds(): array
    {
        return $this->longIds;
    }

    /** @return array<int, int> */
    public function views(): array
    {
        return $this->views;
    }

    /** @return array<int, int> */
    public function emails(): array
    {
        return $this->emails;
    }

    /** @return array<int, int> */
    public function filters(): array
    {
        return $this->filters;
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
