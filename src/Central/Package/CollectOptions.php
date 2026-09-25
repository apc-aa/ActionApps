<?php

declare(strict_types=1);

namespace AA\Central\Package;

use InvalidArgumentException;

/** What Collector puts into meta and what the package is for. AA stores no app_description, app_version
 *  or app_publish, so the caller passes them; the source attributes too, so the collector reads no clock. */
final readonly class CollectOptions
{
    public const PUBLISH = ['no', 'users', 'all'];

    /** a clean app: definitions only */
    public const INSTALL = 'install';
    /** a copy or move of the modules with their data: items in batches (Collector::itemBatch()) and the
     *  state of the source installation - owner, reading password, domains, 2FA secrets. Hands out
     *  password hashes and secrets: the caller lets only the source's superadmin run it. */
    public const TRANSFER = 'transfer';

    /** items of one batch */
    public const MAX_ITEMS = 500;
    /** item data of one batch (Collector::VALUE_MARKUP per value included) - a batch stays within
     *  XmlGuard::MAX_BYTES even if all of it went as base64 */
    public const MAX_BATCH_BYTES = 4 * 1024 * 1024;

    /**
     * @param array<string, string> $source   meta/source attributes: url, aa-version, exported; aa-build
     *                                        optional (`site` is added by fromSite())
     * @param string                $mode     self::INSTALL | self::TRANSFER
     * @param bool                  $allBins  items of all bins; false = the active bin, status_code 1 -
     *                                        pending and expired items included (their dates decide)
     * @param string|null           $name     meta/name; null = the name of the site / first module
     * @param string|null           $publish  no | users | all - users/all turn every W finding into E; a
     *                                        transfer carries passwords and personal data: null or no only
     */
    public function __construct(
        public array $source,
        public string $mode = self::INSTALL,
        public bool $allBins = false,
        public ?string $name = null,
        public ?string $description = null,
        public ?string $version = null,
        public ?string $publish = null,
        public ?string $requires = null,
    ) {
        if (($publish !== null) && !in_array($publish, self::PUBLISH, true)) {
            throw new InvalidArgumentException("Unknown publish value $publish");
        }
        if (!in_array($mode, [self::INSTALL, self::TRANSFER], true)) {
            throw new InvalidArgumentException("Unknown mode $mode");
        }
        if (($mode === self::TRANSFER) && $this->published()) {
            throw new InvalidArgumentException('A transfer is never published');
        }
    }

    public function transfer(): bool
    {
        return $this->mode === self::TRANSFER;
    }

    /** publish = users | all: every W finding is an error */
    public function published(): bool
    {
        return in_array($this->publish, ['users', 'all'], true);
    }
}
