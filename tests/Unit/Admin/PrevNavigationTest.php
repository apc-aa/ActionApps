<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/** The OK button of the item preview leads to return_url, which comes with the request. The page needs
 *  a session, so the tests read its source. */
final class PrevNavigationTest extends TestCase
{
    private static function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/admin/prev_navigation.php');
    }

    public function test_return_url_is_followed_only_when_navigable(): void
    {
        $this->assertSame(1, preg_match(
            '~\} elseif \( \$return_url && IsNavigableUrl\(\$return_url\) \) \{\s*\$go2url = \$return_url;~',
            self::source(),
        ));
    }

    public function test_ok_link_is_escaped(): void
    {
        $src = self::source();
        $this->assertStringContainsString('<a href="\'. safe($go2url) .\'"', $src);
        $this->assertStringNotContainsString('<a href="\'. $go2url', $src);
    }
}
