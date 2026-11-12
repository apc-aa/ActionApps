<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/** admin/write_mail.php sends a bulk email to the readers of a slice; the page has no testable seam,
 *  so the tests read its source: who gets what must be settled before anything is stored or sent. */
final class WriteMailTest extends TestCase
{
    private static function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/admin/write_mail.php');
    }

    /** offset of the first match of $regex in the page */
    private static function at(string $regex): int
    {
        $found = preg_match($regex, self::source(), $m, PREG_OFFSET_CAPTURE);
        self::assertSame(1, $found, "write_mail.php does not match $regex");
        return $m[0][1];
    }

    /** the first POST from the Item Manager only opens the page; the email goes from its own form */
    public function test_send_needs_the_form_token(): void
    {
        $gate = self::at('~if \(ResponderPolicy::isForeignBrowserRequest\(\$_SERVER\) \|\| !FormToken::checkPost\(WRITE_MAIL_TOKEN, \$token, \$_SERVER\)\) \{[^}]*break;~');
        $this->assertGreaterThan(self::at('~\} else \{\s*\$items\s*= \$r_wm_state\[\'items\'\];[^\n]*\n(?:\s*//[^\n]*\n)*\s*do \{~'), $gate, 'the gate opens the send branch');
        foreach (['Files::uploadFile\(', 'doInsert\(\'email\'', 'AA_Mail::sendTemplate\(', 'AA_Mail::sendToReader\('] as $write) {
            $this->assertLessThan(self::at("~$write~"), $gate, "$write must not run before the gate");
        }
        $this->assertGreaterThan($gate, self::at('~<form name="mailform" method="post" enctype="multipart/form-data">\';\s*echo FormToken::field\(WRITE_MAIL_TOKEN\);~'));
    }

    /** sendToReader() itself takes readers of any slice - {mail} sends to several - so the page limits them */
    public function test_picked_recipients_are_limited_to_the_slice(): void
    {
        $picked = self::at('~\$zids = getZidsFromGroupSelect\(\$group, \$items, \$searchbar\);~');
        $filter = self::at('~if \(\(string\) \$group === \'sel_item\'\) \{\s*(?://[^\n]*\n\s*)*\$zids = \$zids->itemFilter\(\[AA::\$module_id\]\);~');
        $this->assertGreaterThan($picked, $filter);
        $this->assertLessThan(self::at('~AA_Mail::sendToReader\(\$mail_id, \$zids,~'), $filter);
    }
}
