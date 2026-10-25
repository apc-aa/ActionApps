<?php

declare(strict_types=1);

namespace AA\Isds;

/**
 * One ISDS account of misc/datovka/datovka_conf.php, the sites it sends for and its pair of slices
 * (Accounts) - names and ids only, no secret.
 */
final class Binding
{
    /**
     * @param string       $name          the account as the file names it: NSZM
     * @param string       $environment   Account::TEST or Account::PRODUCTION
     * @param list<string> $sites         site module ids in small letters; [] when missing or broken
     * @param string       $campaignSlice unpacked id in small letters; '' when the slices are missing or broken
     * @param string       $messageSlice  as $campaignSlice
     * @param list<string> $problems      what to fix in the file, naming its keys; [] = usable
     * @param string       $kind          the login auth names: Account::BASIC, CERT or HOSTED; '' = none of them
     * @param list<string> $testBoxes     test_boxes, box ids as ISDS knows them; [] when missing or broken
     * @param bool         $paidTests     paid_tests is true: a test message of production may be paid
     */
    public function __construct(
        public readonly string $name,
        public readonly string $environment,
        public readonly array $sites,
        public readonly string $campaignSlice,
        public readonly string $messageSlice,
        public readonly array $problems,
        public readonly string $kind,
        public readonly array $testBoxes = [],
        public readonly bool $paidTests = false,
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
