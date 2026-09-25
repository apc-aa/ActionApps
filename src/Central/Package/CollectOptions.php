<?php

declare(strict_types=1);

namespace AA\Central\Package;

use InvalidArgumentException;

/** What Collector puts into meta and whether it takes items. AA stores no app_description, app_version
 *  or app_publish, so the caller passes them; the source attributes too, so the collector reads no clock. */
final readonly class CollectOptions
{
    public const PUBLISH = ['no', 'users', 'all'];

    /** items of a package: one item is several elements and the reader takes at most 100 000 */
    public const MAX_ITEMS = 10_000;

    /**
     * @param array<string, string> $source   meta/source attributes: url, aa-version, exported; aa-build
     *                                        optional (`site` is added by fromSite())
     * @param bool                  $items    carry items - for moving a site between AAs by its
     *                                        admins: may hold personal data and password hashes, so never
     *                                        in a public package (publish = all is an error); the caller
     *                                        must not publish it
     * @param bool                  $allBins  items of all bins; false = the active bin, status_code 1 -
     *                                        pending and expired items included (their dates decide)
     * @param int                   $maxItems at most this many items in the package, more = an error
     * @param string|null           $name     meta/name; null = the name of the site / first module
     * @param string|null           $publish  no | users | all - users/all turn every W finding into E
     */
    public function __construct(
        public array $source,
        public bool $items = false,
        public bool $allBins = false,
        public int $maxItems = self::MAX_ITEMS,
        public ?string $name = null,
        public ?string $description = null,
        public ?string $version = null,
        public ?string $publish = null,
        public ?string $requires = null,
    ) {
        if (($publish !== null) && !in_array($publish, self::PUBLISH, true)) {
            throw new InvalidArgumentException("Unknown publish value $publish");
        }
        if ($maxItems < 0) {
            throw new InvalidArgumentException("maxItems $maxItems < 0");
        }
    }

    /** publish = all: the public catalogue - no items */
    public function public(): bool
    {
        return $this->publish === 'all';
    }

    /** publish = users | all: every W finding is an error */
    public function published(): bool
    {
        return in_array($this->publish, ['users', 'all'], true);
    }
}
