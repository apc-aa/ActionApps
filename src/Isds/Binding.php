<?php

declare(strict_types=1);

namespace AA\Isds;

/**
 * One ISDS account of include/config.php, the sites it sends for and its pair of slices (Accounts) -
 * names and ids only, no key.
 */
final class Binding
{
    /**
     * @param string       $name          the account as the constants name it: NSZM
     * @param string       $environment   Account::TEST or Account::PRODUCTION
     * @param list<string> $sites         site module ids in small letters; [] when missing or broken
     * @param string       $campaignSlice unpacked id in small letters; '' when the slices are missing or broken
     * @param string       $messageSlice  as $campaignSlice
     * @param list<string> $problems      what to fix in config.php, naming the constants; [] = usable
     * @param string       $kind          the login the constants mean: Account::BASIC, CERT or HOSTED
     */
    public function __construct(
        public readonly string $name,
        public readonly string $environment,
        public readonly array $sites,
        public readonly string $campaignSlice,
        public readonly string $messageSlice,
        public readonly array $problems,
        public readonly string $kind,
    ) {}

    public function usable(): bool
    {
        return $this->problems === [];
    }

    /** whether it binds exactly this site and pair; ids in small letters */
    public function binds(string $site, string $campaignSlice, string $messageSlice): bool
    {
        return in_array($site, $this->sites, true) && ($this->campaignSlice !== '')
            && ($this->campaignSlice === $campaignSlice) && ($this->messageSlice === $messageSlice);
    }
}
