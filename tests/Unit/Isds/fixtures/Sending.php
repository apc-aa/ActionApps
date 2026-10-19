<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Fixtures;

use AA\Isds\DatovkaConf;
use AA\Isds\Setup;
use PHPUnit\Framework\Assert;

/** The modules, logins, datovka_conf.php and the territory the tests of a mass sending share */
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
    public const ICO    = 'text...........2';                      // the IČO, when the settings name it

    public const LOGIN    = 'nszm01login';
    public const PASSWORD = 'Test-Pa55-never-shown';

    /**
     * @param list<string>         $pair
     * @param array<string, mixed> $over keys to set instead; null leaves one out
     * @return array<string, mixed> a test account of datovka_conf.php bound to the site and the pair
     */
    public static function account(string $site = self::SITE, array $pair = [self::CAMPAIGN, self::MESSAGE], array $over = []): array
    {
        return array_filter(array_replace([
            'environment' => 'test',
            'auth'        => 'basic',
            'sites'       => [$site],
            'slices'      => $pair,
            'login'       => self::LOGIN,
            'password'    => self::PASSWORD,
        ], $over), static fn(mixed $value): bool => !is_null($value));
    }

    /**
     * datovka_conf.php with the test account NSZM bound to the site and the pair
     * @param list<string> $pair
     */
    public static function bound(string $site = self::SITE, array $pair = [self::CAMPAIGN, self::MESSAGE]): DatovkaConf
    {
        return self::conf(['NSZM' => self::account($site, $pair)]);
    }

    /** @param array<array-key, mixed> $accounts DATOVKA_CONF['accounts'] */
    public static function conf(array $accounts): DatovkaConf
    {
        return DatovkaConf::fromArray([DatovkaConf::ACCOUNTS => $accounts]);
    }

    /** the settings of a file that is no valid PHP, or of a link to one that is gone - as PHP sees one it cannot read */
    public static function fileConf(bool $broken): DatovkaConf
    {
        $dir = sys_get_temp_dir() . '/aa-sendrun-conf-' . bin2hex(random_bytes(6));
        mkdir($dir);
        try {
            if ($broken) {
                file_put_contents("$dir/datovka_conf.php", "<?php\nconst DATOVKA_CONF = [\n");
            } else {
                symlink("$dir/gone.php", "$dir/datovka_conf.php");
            }
            $conf = DatovkaConf::fromFile("$dir/datovka_conf.php");
            Assert::assertSame([$broken, !$broken], [$conf->broken(), $conf->unreadable()]);

            return $conf;
        } finally {
            unlink("$dir/datovka_conf.php");
            rmdir($dir);
        }
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
