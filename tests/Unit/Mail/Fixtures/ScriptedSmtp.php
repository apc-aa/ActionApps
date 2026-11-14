<?php

declare(strict_types=1);

namespace Tests\Unit\Mail\Fixtures;

use AA\Mail\SmtpTransport;

/**
 * A socket that answers with the scripted lines and records what was written - the real Net_SMTP
 * and SmtpTransport run over it. A line left out is an answer that never came (Net_SMTP reads '').
 */
final class ScriptedSocket extends \Net_Socket
{
    /** @var list<string> */
    public array $lines = [];
    /** @var list<string> */
    public array $written = [];
    /** a write containing this text fails, as a broken connection */
    public ?string $failWriteOn = null;

    /** a server that greets and offers SIZE $size (0 = no limit), then gives $replies */
    public static function server(int $size, string ...$replies): self
    {
        $socket        = new self();
        $socket->lines = array_merge(['220 smtp.example.org ESMTP', '250-smtp.example.org', '250 SIZE ' . $size], $replies);
        return $socket;
    }

    /** what was written, joined */
    public function wire(): string
    {
        return implode('', $this->written);
    }

    public function connect($addr, $port = 0, $persistent = null, $timeout = null, $options = null)
    {
        return true;
    }

    public function setTimeout($seconds = null, $microseconds = null)
    {
        return true;
    }

    public function disconnect()
    {
        return true;
    }

    public function write($data, $blocksize = null)
    {
        if (($this->failWriteOn !== null) && str_contains((string) $data, $this->failWriteOn)) {
            return new \PEAR_Error('broken pipe');
        }
        $this->written[] = (string) $data;
        return strlen((string) $data);
    }

    public function readLine()
    {
        return array_shift($this->lines) ?? '';
    }
}

/** Net_SMTP over a ScriptedSocket. */
final class ScriptedNetSmtp extends \Net_SMTP
{
    public function __construct(ScriptedSocket $socket)
    {
        parent::__construct('smtp.example.org', 25, 'aa.example.org');
        $this->socket = $socket;
    }
}

/** The real SmtpTransport, its server scripted. Needs AA_SMTP_HOST and AA_SMTP_ENCRYPTION=none. */
final class ScriptedSmtpTransport extends SmtpTransport
{
    public function __construct(public readonly ScriptedSocket $socket)
    {
        parent::__construct();
    }

    protected function newSmtp(string $connectHost, array $sslOptions): \Net_SMTP
    {
        return new ScriptedNetSmtp($this->socket);
    }
}
