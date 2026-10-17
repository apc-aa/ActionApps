<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Fixtures;

use AA\Isds\Setup;

/** The modules, logins, include/config.php and the territory the tests of a mass sending share */
final class Sending
{
    public const SITE     = '51de0000000000000000000000000001';
    public const SITE2    = '51de0000000000000000000000000002';
    public const CAMPAIGN = 'cafe0000000000000000000000000001';
    public const MESSAGE  = 'beef0000000000000000000000000002';
    public const SOURCE   = '50ce0000000000000000000000000004';
    public const OTHER    = '0e4e0000000000000000000000000005';
    public const READERS  = 'eade0000000000000000000000000003';   // a Reader Management slice
    public const SET      = '5e70000000000000000000000000000a';   // a set of its readers
    public const READER   = 'aaaa0000000000000000000000000007';   // the login of a reader
    public const USER     = '17';                                  // the login of a user of AA

    // the fields of the source slice; its settings of data boxes name the first three
    public const NAME   = 'headline........';
    public const TYPE   = 'switch.........1';
    public const DS     = 'text..........11';
    public const MEMBER = 'switch.........9';                      // tells a member

    /** @return array<string, string> a test account of include/config.php bound to the site and the pair */
    public static function bound(string $site = self::SITE, string $pair = self::CAMPAIGN . ',' . self::MESSAGE): array
    {
        return [
            'AA_APIKEY_ISDSTEST_NSZM_SITE'     => $site,
            'AA_APIKEY_ISDSTEST_NSZM_SLICES'   => $pair,
            'AA_APIKEY_ISDSTEST_NSZM_LOGIN'    => 'nszm01login',
            'AA_APIKEY_ISDSTEST_NSZM_PASSWORD' => 'Test-Pa55-never-shown',
        ];
    }

    /** a checked page offering cities, statutory cities and city districts, cities chosen at first */
    public static function page(string $memberField = self::MEMBER): Setup
    {
        return Setup::fromSnapshot(self::SITE, self::CAMPAIGN, self::MESSAGE, self::SOURCE, ['mesto' => 'Cities', 'mestostatut' => 'Statutory cities', 'mestocast' => 'City districts'], ['mesto'], $memberField);
    }

    /**
     * A recipient filter as a campaign stores it - all cities unless $over says else
     * @param array<string, mixed> $over
     */
    public static function filter(array $over = []): string
    {
        return (string) json_encode($over + ['types' => ['mesto'], 'membership' => 'all', 'include' => [], 'exclude' => []]);
    }

    /**
     * An item of the source slice, as FakeRecipients takes it
     * @return array{slice: string, fields: array<string, string>}
     */
    public static function item(string $name, string $type, string $member, string $box): array
    {
        return ['slice' => self::SOURCE, 'fields' => [self::NAME => $name, self::TYPE => $type, self::MEMBER => $member, self::DS => $box]];
    }
}
