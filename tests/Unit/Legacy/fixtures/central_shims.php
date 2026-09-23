<?php

declare(strict_types=1);

/**
 * Global stand-in for the one include/formutil.php helper AA_Sync_Action::printToForm() calls
 * (formutil.php cannot load in the unit suite: it pulls stringexpand, perm_* and the boot).
 * Mirrors getFrmChBoxEasy(): name and value are escaped, $add is not.
 * Tests using it must not load include/formutil.php in the same run - that would redeclare it.
 */
if (!function_exists('FrmChBoxEasy')) {
    function FrmChBoxEasy($name, $checked = true, $add = '', $value = '', $id = ''): void
    {
        echo '<input type="checkbox" name="' . safe($name) . '" ' . $add
            . ($value ? ' value="' . safe($value) . '"' : '')
            . ($checked ? ' checked>' : '>');
    }
}
