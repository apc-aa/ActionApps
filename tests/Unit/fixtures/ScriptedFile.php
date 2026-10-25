<?php

declare(strict_types=1);

namespace Tests\Unit\Fixtures;

/**
 * A regular file at scripted://<anything> whose content the test scripts: what each open serves, and
 * what it goes on serving after that, as a file someone keeps writing to - while stat() tells only the
 * size it had. Read-only; register() before use, reset() between tests.
 */
final class ScriptedFile
{
    public const SCHEME = 'scripted';

    /** @var \Closure(int): string content of the n-th open, from 1 */
    public static ?\Closure $content = null;

    /** served again and again after the content, up to $limit bytes in all; '' = the file ends */
    public static string $growth = '';

    /** bytes served in all, at most - the guard of a test that would read for ever */
    public static int $limit = 32 * 1024 * 1024;

    /** from this byte on a read fails as on EIO: false, and the stream ends; null = never */
    public static ?int $failAt = null;

    public static int $opens = 0;

    public static int $served = 0;

    /** @var resource|null set by PHP */
    public $context;

    private string $data = '';

    private int $at = 0;

    private bool $failed = false;

    public static function register(): void
    {
        if (!in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }
        self::reset();
    }

    public static function reset(): void
    {
        self::$content = null;
        self::$growth  = '';
        self::$limit   = 32 * 1024 * 1024;
        self::$failAt  = null;
        self::$opens   = 0;
        self::$served  = 0;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        if (!str_starts_with($mode, 'r')) {
            return false;
        }
        $this->data = (self::$content ?? static fn(int $n): string => '')(++self::$opens);
        return true;
    }

    public function stream_read(int $count): string|false
    {
        if ((self::$failAt !== null) && ($this->at >= self::$failAt)) {
            $this->failed = true;
            return false;
        }
        $count = min($count, (self::$failAt ?? PHP_INT_MAX) - $this->at);
        if ($this->at < strlen($this->data)) {
            $chunk = substr($this->data, $this->at, $count);
        } elseif ((self::$growth !== '') && (self::$served < self::$limit)) {
            $chunk = str_repeat(self::$growth, max(1, intdiv(min($count, self::$limit - self::$served), strlen(self::$growth))));
        } else {
            return '';
        }
        $this->at     += strlen($chunk);
        self::$served += strlen($chunk);
        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->failed || (($this->at >= strlen($this->data)) && ((self::$growth === '') || (self::$served >= self::$limit)));
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return ['mode' => 0o100644, 'size' => strlen($this->data), 'mtime' => time()];
    }

    /** @return array<string, int> */
    public function url_stat(string $path, int $flags): array
    {
        return ['mode' => 0o100644, 'size' => 0, 'mtime' => time()];
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    public function stream_close(): void {}
}
