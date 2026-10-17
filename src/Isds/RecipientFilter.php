<?php

declare(strict_types=1);

namespace AA\Isds;

/**
 * Whom a campaign goes to, as its item stores it (Schema::CAMPAIGN_FILTER):
 *     {"types":["mesto"],"membership":"all","include":[<item id>,...],"exclude":[<item id>,...]}
 * The recipients are the items of the types and the membership, plus include, minus exclude
 * (Recipients). The ids come from the browser - Recipients reads only those of the source slice.
 */
final readonly class RecipientFilter
{
    public const ALL        = 'all';
    public const MEMBERS    = 'members';
    public const NONMEMBERS = 'nonmembers';

    /** room for the most ids include and exclude may have, quoted and separated, spaces too */
    public const MAX_BYTES = 2 * Setup::MAX_RECIPIENTS * 40 + 4096;

    private const MEMBERSHIPS = [self::ALL, self::MEMBERS, self::NONMEMBERS];

    /** all are required: a misspelt or missing key would widen the recipients unnoticed */
    private const KEYS = ['types', 'membership', 'include', 'exclude'];

    /**
     * @param list<string> $types   among those the page offers
     * @param list<string> $include long ids in small letters
     * @param list<string> $exclude long ids in small letters
     */
    private function __construct(
        public array $types,
        public string $membership,
        public array $include,
        public array $exclude,
    ) {}

    /** the filter of a campaign that has not stored one */
    public static function default(Setup $setup): self
    {
        return new self($setup->defaultTypes, self::ALL, [], []);
    }

    /**
     * @throws \InvalidArgumentException not a filter the page offers
     */
    public static function fromJson(string $json, Setup $setup): self
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('The recipient filter is too long');
        }
        // aa_json_decode() gives the texts in the encoding of AA, as Setup has its types
        $values = aa_json_decode($json, true);
        if (!is_array($values) || (count($values) !== count(self::KEYS)) || array_diff(self::KEYS, array_keys($values))) {
            throw new \InvalidArgumentException('The recipient filter is a JSON object of ' . implode(', ', self::KEYS));
        }
        $types = self::texts($values['types'], 'types');
        if ($unknown = array_diff($types, array_keys($setup->types))) {
            throw new \InvalidArgumentException('The page does not offer the types ' . implode(', ', $unknown));
        }
        $membership = $values['membership'];
        if (!in_array($membership, self::MEMBERSHIPS, true)) {
            throw new \InvalidArgumentException('membership is one of ' . implode(', ', self::MEMBERSHIPS));
        }
        if (($membership !== self::ALL) && ($setup->memberField === '')) {
            throw new \InvalidArgumentException('The page tells no members');
        }

        return new self($types, $membership, self::ids($values['include'], 'include'), self::ids($values['exclude'], 'exclude'));
    }

    /** what fromJson() reads back */
    public function toJson(): string
    {
        return json_encode(
            ['types' => $this->types, 'membership' => $this->membership, 'include' => $this->include, 'exclude' => $this->exclude],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return list<string> each once
     * @throws \InvalidArgumentException not a list of texts
     */
    private static function texts(mixed $list, string $key): array
    {
        if (!is_array($list) || !array_is_list($list) || array_filter($list, static fn(mixed $v): bool => !is_string($v))) {
            throw new \InvalidArgumentException("$key is a list of texts");
        }

        return array_values(array_unique($list));
    }

    /**
     * @return list<string> in small letters, each once
     * @throws \InvalidArgumentException not a list of long ids, or too many
     */
    private static function ids(mixed $list, string $key): array
    {
        $ids = array_values(array_unique(array_map(
            static fn(string $id): string => ModuleId::normalize($id) ?? throw new \InvalidArgumentException("$key is a list of item ids - 32 hexadecimal digits"),
            self::texts($list, $key),
        )));
        if (count($ids) > Setup::MAX_RECIPIENTS) {
            throw new \InvalidArgumentException("$key has more than " . Setup::MAX_RECIPIENTS . ' items');
        }

        return $ids;
    }
}
