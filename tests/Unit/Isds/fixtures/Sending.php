<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Fixtures;

/** The modules, logins and include/config.php the tests of a mass sending share */
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
    public const MEMBER   = 'switch.........9';                    // the field of the source that tells a member

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
}
