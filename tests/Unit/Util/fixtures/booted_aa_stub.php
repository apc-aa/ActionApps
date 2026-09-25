<?php

declare(strict_types=1);

// What FatalLogger asks of a booted AA - AuthGetUid() (include/locsess.php) and AA_Log (include/logs.php,
// which needs config.php): stubs, for a test in a separate process only

if (!function_exists('AuthGetUid')) {
    function AuthGetUid(): string
    {
        return 'test-uid';
    }
}

if (!class_exists('AA_Log', false)) {
    final class AA_Log {}
}
