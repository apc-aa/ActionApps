<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/** The site editor (modules/site/index.php) changes the spot tree and the spot code, which runs on the
 *  site. The page needs a session and a site module, so the tests read its source. */
final class SiteIndexGuardTest extends TestCase
{
    private static function source(string $file): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $file);
    }

    public function test_guard_clears_every_change_before_the_tree_is_touched(): void
    {
        $src   = self::source('modules/site/index.php');
        $guard = strpos($src, 'ResponderPolicy::isForeignBrowserRequest($_SERVER)');
        $this->assertNotFalse($guard, 'no origin check');
        $this->assertStringContainsString('FormToken::checkPost(AA_Module_Site::SPOT_TOKEN, $token, $_SERVER)', $src);
        $this->assertStringContainsString('$akce = $addcond = $addvar = $delvar = $delcond = $content = $name = \'\';', $src);

        // nothing may change the tree or a spot before the guard
        foreach (['switch ($akce)', '->addCondition(', '->addVariable(', '->removeVariable(', '->removeCondition(', 'doUpdate(', 'doInsert(', '->saveTree(', 'invalidateFor('] as $write) {
            $at = strpos($src, $write);
            $this->assertNotFalse($at, "$write not found");
            $this->assertGreaterThan($guard, $at, "$write runs before the guard");
        }
    }

    public function test_spot_form_carries_the_token(): void
    {
        $src  = self::source('include/slice.class.php');
        $form = strpos($src, "<form method='post' name=fs");
        $this->assertNotFalse($form);
        $end = strpos($src, '</form>', $form);
        $this->assertStringContainsString('FormToken::field(self::SPOT_TOKEN)', substr($src, $form, $end - $form));
    }
}
