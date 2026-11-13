<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/** admin/mailman_create_list.php writes a constant and a line of the Mailman command file;
 *  the page has no testable seam, so the test reads its source. */
final class MailmanCreateListTest extends TestCase
{
    private static function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/admin/mailman_create_list.php');
    }

    private static function at(string $regex): int
    {
        $found = preg_match($regex, self::source(), $m, PREG_OFFSET_CAPTURE);
        self::assertSame(1, $found, "the page does not match $regex");
        return $m[0][1];
    }

    public function test_list_is_created_only_from_the_own_form_with_valid_values(): void
    {
        $gate = self::at('~if \(ResponderPolicy::isForeignBrowserRequest\(\$_SERVER\) \|\| !FormToken::checkPost\(MAILMAN_TOKEN, \$token, \$_SERVER\)\) \{~');
        $name = self::at('~\} elseif \(!AA_Mailman::isListName\(\$list_name\)\) \{~');
        $mail = self::at('~\} elseif \(!preg_match\(\'/\^\\\\S\+\\\\z/\', \$admin_email\) \|\| !filter_var\(\$admin_email, FILTER_VALIDATE_EMAIL\)\) \{~');
        $pass = self::at('~\} elseif \(!preg_match\(\'/\^\\\\S\+\\\\z/\', \$admin_password\)\) \{\s*echo[^;]*;\s*\} else \{\s*add_mailman_list\(~');
        $this->assertLessThan($name, $gate);
        $this->assertLessThan($mail, $name);
        $this->assertLessThan($pass, $mail);
        $this->assertSame(1, preg_match_all('~(?<!function )add_mailman_list\(~', self::source()), 'one call, behind the checks');
        $this->assertStringContainsString("' . FormToken::field(MAILMAN_TOKEN) . '", self::source(), 'the form sends the token');
    }

    public function test_form_values_are_escaped(): void
    {
        $src = self::source();
        $this->assertStringContainsString('value="\' . safe($list_name) . \'"', $src);
        $this->assertStringContainsString('value="\' . safe($admin_email) . \'"', $src);
    }
}
