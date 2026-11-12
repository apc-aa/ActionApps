<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/** modules/alerts/synchro.php points an Alerts collection to a reader slice and adds or deletes fields there;
 *  the page has no testable seam, so the tests read its source: every write needs the checks before it. */
final class AlertsSynchroTest extends TestCase
{
    private static function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/modules/alerts/synchro.php');
    }

    /** offset of the first match of $regex in the page */
    private static function at(string $regex): int
    {
        $found = preg_match($regex, self::source(), $m, PREG_OFFSET_CAPTURE);
        self::assertSame(1, $found, "synchro.php does not match $regex");
        return $m[0][1];
    }

    /** @return list<int> offsets of the writes */
    private static function writes(): array
    {
        return array_map(self::at(...), [
            '~add_fields_2_slice\(~',
            '~delete_fields_from_slice\(~',
            '~doUpdate\(\'alerts_collection\'~',
            '~UPDATE alerts_collection~',
        ]);
    }

    /** the menu offers the page only with this right */
    public function test_page_needs_the_users_right(): void
    {
        $perm = self::at('~if \(!IfSlPerm\(PS_USERS\)\) \{\s*MsgPage\([^;]*;\s*exit;~');
        $this->assertLessThan(self::at('~GetCollection\(~'), $perm);
        foreach (self::writes() as $write) {
            $this->assertLessThan($write, $perm);
        }
    }

    public function test_writes_need_the_form_token(): void
    {
        $gate = self::at('~if \(ResponderPolicy::isForeignBrowserRequest\(\$_SERVER\) \|\| !FormToken::checkPost\(SYNCHRO_TOKEN, \$token, \$_SERVER\)\) \{[^}]*\$add_fields = \$change_to_cmd = false;~');
        foreach (self::writes() as $write) {
            $this->assertLessThan($write, $gate);
        }
        $this->assertSame(2, substr_count(self::source(), '. FormToken::field(SYNCHRO_TOKEN) .'), 'both forms carry the token');
    }

    /** the select offers the Reader Management slices of the user */
    public function test_target_slice_is_one_the_form_offers(): void
    {
        $check = self::at('~elseif \(\$change_to_cmd && !array_key_exists\(\$change_to, getReaderManagementSlices\(\)\)\) \{[^}]*\$change_to_cmd = false;~');
        foreach (self::writes() as $write) {
            $this->assertLessThan($write, $check);
        }
        self::at('~FrmSelectEasy\("change_to", getReaderManagementSlices\(\)~');
    }
}
