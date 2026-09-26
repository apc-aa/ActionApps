<?php

declare(strict_types=1);

/**
 * TransferLink: where "Copy an app between AAs" sends the admin - the install page of the target, which
 * pulls the app from the source. The address of another AA comes from its Central row only.
 */

namespace Tests\Unit\Central;

use AA\Central\Actionapps;
use AA\Central\TransferLink;
use ItemContent;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class TransferLinkTest extends TestCase
{
    private const HERE = 'This AA (https://here.example/aa/)';

    private static function aa(int $id, string $name, string $domain, string $dir = 'aa/'): Actionapps
    {
        $content = new ItemContent();
        $content->setValue('id', (string) $id);
        $content->setValue('ORG_NAME', $name);
        $content->setValue('AA_HTTP_DOMAIN', $domain);
        $content->setValue('AA_BASE_DIR', $dir);
        return new Actionapps($content);
    }

    private static function link(int $source, int $target): TransferLink
    {
        $rows = [
            5  => self::aa(5, 'Source', 'https://source.example/'),
            7  => self::aa(7, 'Target', 'https://target.example/', '/apc-aa'),
            8  => self::aa(8, 'Upper', 'HTTP://upper.example/'),
            20 => self::aa(20, 'Script', 'javascript:alert(1)//'),
            21 => self::aa(21, 'Empty', ''),
            22 => self::aa(22, 'Relative', 'target.example/'),
            23 => self::aa(23, 'No host', 'https://', '/aa/'),
            24 => self::aa(24, 'Joined', 'https://example.org', 'aa/'),
        ];
        return TransferLink::resolve($source, $target, static fn(int $id): ?Actionapps => $rows[$id] ?? null, self::HERE);
    }

    public function testThisToThisIsTheLocalInstallPage(): void
    {
        $link = self::link(TransferLink::THIS_AA, TransferLink::THIS_AA);
        $this->assertSame('installapp.php', $link->url);
        $this->assertSame([], $link->params);
        $this->assertFalse($link->remote);
    }

    public function testRowToThisPassesTheRowId(): void
    {
        $link = self::link(5, TransferLink::THIS_AA);
        $this->assertSame('installapp.php', $link->url);
        $this->assertSame(['aa' => 5], $link->params);
        $this->assertFalse($link->remote);
    }

    public function testThisToRowLinksTheTargetAndNamesThisAaAsTheSource(): void
    {
        $link = self::link(TransferLink::THIS_AA, 7);
        $this->assertSame('https://target.example/apc-aa/central/installapp.php', $link->url);
        $this->assertSame([], $link->params, 'no row id: it means nothing there');
        $this->assertTrue($link->remote);
        $this->assertSame('Target (https://target.example//apc-aa)', $link->target);
        $this->assertSame(self::HERE, $link->source);
    }

    public function testRowToRowNamesTheSourceRow(): void
    {
        $link = self::link(5, 7);
        $this->assertSame('https://target.example/apc-aa/central/installapp.php', $link->url);
        $this->assertTrue($link->remote);
        $this->assertSame('Source (https://source.example/aa/)', $link->source);
    }

    public function testSameRowIsACopyInsideThatAa(): void
    {
        $link = self::link(5, 5);
        $this->assertSame('https://source.example/aa/central/installapp.php', $link->url);
        $this->assertTrue($link->remote);
        $this->assertSame('', $link->source, 'that AA itself - no Central row needed there');
    }

    public function testTheSchemeIsCaseInsensitive(): void
    {
        $this->assertSame('HTTP://upper.example/aa/central/installapp.php', self::link(5, 8)->url);
    }

    /** @return array<string, array{int, int}> */
    public static function unknown(): array
    {
        return [
            'source'         => [4, TransferLink::THIS_AA],
            'target'         => [TransferLink::THIS_AA, 4],
            'source, remote' => [4, 7],
            'negative'       => [-1, TransferLink::THIS_AA],
        ];
    }

    #[DataProvider('unknown')]
    public function testNoSuchRowIsRefused(int $source, int $target): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::link($source, $target);
    }

    /** @return array<string, array{int}> */
    public static function badAddress(): array
    {
        return [
            'javascript:'           => [20],
            'empty domain'          => [21],
            'relative domain'       => [22],
            'no host'               => [23],
            'domain without slash'  => [24],
        ];
    }

    #[DataProvider('badAddress')]
    public function testATargetWithNoValidAddressIsRefusedApart(int $target): void
    {
        $this->expectException(UnexpectedValueException::class);
        self::link(5, $target);
    }

    public function testASourceWithABadAddressDoesNotMatterHere(): void
    {
        $this->assertSame(['aa' => 24], self::link(24, TransferLink::THIS_AA)->params, 'installapp.php checks the source when it logs in');
    }

    /** @return array<string, array{string, int}> */
    public static function ids(): array
    {
        return [
            'this AA' => ['0', 0],
            'a row'   => ['15', 15],
        ];
    }

    #[DataProvider('ids')]
    public function testAnIdIsDigitsOnly(string $value, int $id): void
    {
        $this->assertSame($id, TransferLink::id($value));
    }

    /** @return array<string, array{string}> */
    public static function badIds(): array
    {
        return [
            'empty'    => [''],
            'word'     => ['abc'],
            'negative' => ['-1'],
            'suffix'   => ['5a'],
            'space'    => [' 5'],
            'float'    => ['5.0'],
            'too long' => ['12345678901'],
        ];
    }

    #[DataProvider('badIds')]
    public function testAnythingElseIsNoId(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        TransferLink::id($value);
    }
}
