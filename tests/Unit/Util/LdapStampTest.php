<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use AA\Util\LdapStamp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The credential stamp of an LDAP user from its entry, as ldap_get_attributes() returns it. */
final class LdapStampTest extends TestCase
{
    public function test_the_stamp_is_modify_timestamp(): void
    {
        $this->assertSame('20260925101500Z', LdapStamp::fromAttributes([
            'modifyTimestamp' => ['count' => 1, 0 => '20260925101500Z'],
            0                 => 'modifyTimestamp',
            'count'           => 1,
        ]));
    }

    public function test_the_attribute_name_case_does_not_matter(): void
    {
        $this->assertSame('20260925101500Z', LdapStamp::fromAttributes(['modifytimestamp' => ['count' => 1, 0 => '20260925101500Z']]));
    }

    public function test_user_password_is_never_the_stamp(): void
    {
        $this->assertNull(LdapStamp::fromAttributes(['userPassword' => ['count' => 1, 0 => '{SSHA}hash'], 'count' => 1]));
        $this->assertSame('20260925101500Z', LdapStamp::fromAttributes([
            'userPassword'    => ['count' => 1, 0 => '{SSHA}hash'],
            'modifyTimestamp' => ['count' => 1, 0 => '20260925101500Z'],
        ]));
    }

    /** @return array<string, array{0: array<int|string, mixed>}> */
    public static function noStamp(): array
    {
        return [
            'no entry'        => [[]],
            'no timestamp'    => [['cn' => ['count' => 1, 0 => 'Central'], 'count' => 1]],
            'empty timestamp' => [['modifyTimestamp' => ['count' => 1, 0 => '']]],
            'no value'        => [['modifyTimestamp' => ['count' => 0]]],
        ];
    }

    /** @param array<int|string, mixed> $attributes */
    #[DataProvider('noStamp')]
    public function test_no_stamp_without_a_timestamp(array $attributes): void
    {
        $this->assertNull(LdapStamp::fromAttributes($attributes));
    }
}
