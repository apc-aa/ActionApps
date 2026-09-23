<?php

/**
 * Helpers shared by the Central characterization tests (tests/Unit/Legacy/Central*Test.php).
 */

declare(strict_types=1);

namespace Tests\Unit\Legacy\Fixtures;

trait CentralTestHelpers
{
    /** a directory standing in for a remote AA - its central/responder.php is the answer */
    private ?string $remote_dir = null;

    /** Runs $fn with warnings/notices/deprecations collected instead of reported.
     *  @param list<string> $warnings */
    private static function collectingWarnings(callable $fn, ?array &$warnings = null): mixed
    {
        $warnings = [];
        set_error_handler(static function (int $no, string $message) use (&$warnings): bool {
            $warnings[] = $message;
            return true;
        });
        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }

    /** A "remote AA" that answers every request with $serialized_answer (null: no answer - an
     *  unreadable URL). AA_Http::request() is file_get_contents(), so the real AA_Request::ask()
     *  reads a local file. Call removeLocalResponder() in tearDown().
     *  @return string the AA_HTTP_DOMAIN to use (AA_BASE_DIR '') */
    private function localResponder(?string $serialized_answer): string
    {
        $this->remote_dir = sys_get_temp_dir() . '/aa_central_test_' . bin2hex(random_bytes(6));
        mkdir($this->remote_dir . '/central', 0o700, true);
        if ($serialized_answer !== null) {
            file_put_contents($this->remote_dir . '/central/responder.php', $serialized_answer);
        }
        return $this->remote_dir . '/';
    }

    private function removeLocalResponder(): void
    {
        if ($this->remote_dir !== null) {
            @unlink($this->remote_dir . '/central/responder.php');
            @rmdir($this->remote_dir . '/central');
            @rmdir($this->remote_dir);
            $this->remote_dir = null;
        }
    }
}
