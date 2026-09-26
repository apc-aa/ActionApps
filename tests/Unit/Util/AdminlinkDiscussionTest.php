<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use AA\Util\Adminlink;
use PHPUnit\Framework\TestCase;

/**
 * Adminlink::discussionCells() - a row of the discussion search in the AA finder
 * (admin/aafinder.php). Comments are posted by anonymous visitors and the finder is a
 * superadmin page, so every value must come out escaped; only the item link is markup.
 */
final class AdminlinkDiscussionTest extends TestCase
{
    private const SLICE  = '0123456789abcdef0123456789abcdef';
    private const ITEM   = 'fedcba9876543210fedcba9876543210';
    private const ID     = 'aaaabbbbccccddddaaaabbbbccccdddd';
    private const PARENT = '11112222333344441111222233334444';

    /** a row as `SELECT slice_id, discussion.*` returns it - ids packed */
    private static function row(array $over = []): array
    {
        return array_merge([
            'slice_id'        => hex2bin(self::SLICE),
            'id'              => hex2bin(self::ID),
            'parent'          => hex2bin(self::PARENT),
            'item_id'         => hex2bin(self::ITEM),
            'date'            => '1700000000',
            'subject'         => 'Hello',
            'author'          => 'Anna',
            'e_mail'          => 'anna@example.org',
            'body'            => 'text',
            'state'           => '0',
            'flag'            => '0',
            'url_address'     => 'https://example.org/',
            'url_description' => 'site',
            'remote_addr'     => '192.0.2.1',
            'free1'           => '',
            'free2'           => '',
        ], $over);
    }

    private static function stubLink(): callable
    {
        return static fn(string $item_id, string $slice_id): string => "<a href=\"itemedit.php?id=$item_id&amp;change_id=$slice_id\">$item_id</a>";
    }

    public function test_script_in_visitor_fields_is_escaped(): void
    {
        $cells = Adminlink::discussionCells(self::row([
            'subject' => '<script>alert(1)</script>',
            'author'  => '"><img src=x onerror=alert(2)>',
            'body'    => "<b onmouseover='alert(3)'>hi</b>",
            'e_mail'  => '<svg onload=alert(4)>',
            'free1'   => '<iframe src=javascript:alert(5)>',
        ]), self::stubLink());

        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', $cells['subject']);
        $this->assertSame('&quot;&gt;&lt;img src=x onerror=alert(2)&gt;', $cells['author']);
        $this->assertSame('&lt;b onmouseover=&apos;alert(3)&apos;&gt;hi&lt;/b&gt;', $cells['body']);
        $this->assertSame('&lt;svg onload=alert(4)&gt;', $cells['e_mail']);

        $html = implode('', $cells);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<svg', $html);
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringNotContainsString('<b ', $html);
    }

    public function test_every_visitor_value_is_escaped(): void
    {
        $evil  = '<x>&"\'';
        $cells = Adminlink::discussionCells(self::row(array_fill_keys(['subject', 'author', 'e_mail', 'body', 'state', 'flag', 'url_address', 'url_description', 'remote_addr', 'free1', 'free2'], $evil)), self::stubLink());

        foreach (['subject', 'author', 'e_mail', 'body', 'state', 'flag', 'url_address', 'url_description', 'remote_addr', 'free1', 'free2'] as $col) {
            $this->assertSame('&lt;x&gt;&amp;&quot;&apos;', $cells[$col], $col);
        }
    }

    public function test_a_column_not_known_today_is_escaped_too(): void
    {
        $cells = Adminlink::discussionCells(self::row(['new_column' => '<script>x</script>']), self::stubLink());

        $this->assertSame('&lt;script&gt;x&lt;/script&gt;', $cells['new_column']);
    }

    public function test_item_link_is_built_from_the_unpacked_ids_and_kept_as_markup(): void
    {
        $cells = Adminlink::discussionCells(self::row(['subject' => '<script>x</script>']), self::stubLink());

        $this->assertSame('<a href="itemedit.php?id=' . self::ITEM . '&amp;change_id=' . self::SLICE . '">' . self::ITEM . '</a>', $cells['item_id']);
    }

    public function test_ids_are_unpacked_and_the_date_formatted(): void
    {
        $cells = Adminlink::discussionCells(self::row(), self::stubLink());

        $this->assertSame(self::SLICE, $cells['slice_id']);
        $this->assertSame(self::ID, $cells['id']);
        $this->assertSame(self::PARENT, $cells['parent']);
        $this->assertSame(date('Y-m-d H:i:s', 1700000000), $cells['date']);
    }

    public function test_the_columns_keep_their_order(): void
    {
        $row = self::row();

        $this->assertSame(array_keys($row), array_keys(Adminlink::discussionCells($row, self::stubLink())));
    }
}
