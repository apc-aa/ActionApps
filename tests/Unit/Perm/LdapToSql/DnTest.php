<?php

declare(strict_types=1);

namespace AA\Tests\Unit\Perm\LdapToSql;

use AA\Perm\LdapToSql\Dn;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DnTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function variants(): array
    {
        return [
            'case'                          => ['uid=Kalen01,ou=People,ou=AA', 'uid=kalen01,ou=people,ou=aa'],
            'space before comma'            => ['uid=fesprag1 ,ou=People,ou=AA', 'uid=fesprag1,ou=people,ou=aa'],
            'space after equals'            => ['uid= heidi01,ou=People,ou=AA', 'uid=heidi01,ou=people,ou=aa'],
            'escaped trailing space'        => ['uid=fesprag1\20,ou=People,ou=AA', 'uid=fesprag1,ou=people,ou=aa'],
            'spaces everywhere'             => ['UID = Zora , OU=People , OU=AA', 'uid=zora,ou=people,ou=aa'],
            'inner space kept'              => ['cn=AA Admins,ou=AA', 'cn=aa admins,ou=aa'],
            'escaped comma'                 => ['uid=a\,b,ou=People,ou=AA', 'uid=a\2cb,ou=people,ou=aa'],
            'hex comma'                     => ['uid=a\2cb,ou=People,ou=AA', 'uid=a\2cb,ou=people,ou=aa'],
            'utf-8 lowercase'               => ['uid=Šimon,ou=People,ou=AA', 'uid=šimon,ou=people,ou=aa'],
            'hex utf-8 bytes'               => ['uid=\C5\A0imon,ou=People,ou=AA', 'uid=šimon,ou=people,ou=aa'],
            'escaped backslash'             => ['uid=a\\\\b,ou=AA', 'uid=a\5cb,ou=aa'],
            'escaped equals is plain value' => ['uid=a\=b,ou=AA', 'uid=a=b,ou=aa'],
            'uppercase hex comma'           => ['uid=a\2Cb,ou=AA', 'uid=a\2cb,ou=aa'],
            'escaped leading space'         => ['uid=\20x,ou=AA', 'uid=x,ou=aa'],
            'inner spaces collapse'         => ['cn=AA  \20 Admins,ou=AA', 'cn=aa admins,ou=aa'],
            'invalid utf-8, ascii only'     => ['uid=AB\C5,ou=AA', "uid=ab\xC5,ou=aa"],
            // Malformed input must still map to one stable key, never throw.
            'rdn without equals'            => ['uid=x, Garbage ,ou=AA', 'uid=x,garbage,ou=aa'],
            'trailing backslash is literal' => ['uid=a\\', 'uid=a\5c'],
            'empty rdns dropped'            => ['uid=x,,ou=AA,', 'uid=x,ou=aa'],
            'escaped-space rdn dropped'     => ['uid=x,\20,ou=AA', 'uid=x,ou=aa'],
            'empty'                         => ['', ''],
        ];
    }

    #[DataProvider('variants')]
    public function test_normalize(string $dn, string $expected): void
    {
        $this->assertSame($expected, Dn::normalize($dn));
    }

    #[DataProvider('variants')]
    public function test_normalize_isIdempotent(string $dn, string $expected): void
    {
        $this->assertSame($expected, Dn::normalize($expected));
    }

    public function test_normalize_keepsDistinctInvalidUtf8Apart(): void
    {
        // cp1250 'Š' and 'š' would both become '?' under mb_strtolower.
        $this->assertNotSame(Dn::normalize("uid=\x8Aimon,ou=AA"), Dn::normalize("uid=\x9Aimon,ou=AA"));
        $this->assertNotSame(Dn::normalize('uid=\C5,ou=AA'), Dn::normalize('uid=\C4,ou=AA'));
    }

    /** @return array<string, array{string, bool}> */
    public static function values(): array
    {
        return [
            'user dn'              => ['uid=x,ou=AA', true],
            'group dn'             => ['cn=G,ou=AA', true],
            'spaced upper case'    => [' UID = x , ou=AA', true],
            'number'               => ['12', false],
            'importer id'          => ['9999999999', false],
            'hex32 id'             => ['0123456789abcdef0123456789abcdef', false],
            'plain name'           => ['nobody', false],
            'space before comma'   => ['uid=fesprag1 ,ou=People,ou=AA', true],
            'space after equals'   => ['uid= heidi01,ou=People,ou=AA', true],
            'empty'                => ['', false],
            'no comma'             => ['uid=x', false],
            'only escaped comma'   => ['uid=a\,b', false],
            'other attribute'      => ['ou=People,ou=AA', false],
        ];
    }

    #[DataProvider('values')]
    public function test_isDn(string $value, bool $expected): void
    {
        $this->assertSame($expected, Dn::isDn($value));
    }

    public function test_firstValue(): void
    {
        $this->assertSame('Kalen01', Dn::firstValue('uid=Kalen01,ou=People,ou=AA', 'uid'));
        $this->assertSame('Kalen01', Dn::firstValue(' UID = Kalen01\\20 ,ou=AA', 'uid'));
        $this->assertSame('a,b', Dn::firstValue('uid=a\\2cb,ou=AA', 'uid'));
        $this->assertNull(Dn::firstValue('cn=x,ou=AA', 'uid'));
        $this->assertNull(Dn::firstValue('ou=AA,uid=x', 'uid'));
    }
}
