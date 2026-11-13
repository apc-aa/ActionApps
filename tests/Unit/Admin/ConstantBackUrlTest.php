<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The Cancel button and the redirect after saving of the constant pages lead to return_url, which comes
 *  with the request. The pages need a session, so the tests read their source. */
final class ConstantBackUrlTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function pages(): array
    {
        return [
            'constants'        => ['admin/se_constant.php'],
            'constants import' => ['admin/se_constant_import.php'],
        ];
    }

    #[DataProvider('pages')]
    public function test_return_url_is_followed_only_when_navigable(string $file): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $file);
        $this->assertSame(1, preg_match_all('~\$back_url\s*=~', $src), 'one place builds the back URL');
        $this->assertSame(1, preg_match('~\$back_url\s*= \(\(\$return_url && IsNavigableUrl\(\$return_url\)\) \?~', $src));
        // the URL goes to the Location header and to the Cancel button, which escapes it itself
        $this->assertStringContainsString("get_url(\$return_url, ['fid' => \$fid])", $src);
        $this->assertStringNotContainsString('con_url($return_url', $src);
    }
}
