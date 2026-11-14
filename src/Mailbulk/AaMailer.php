<?php

declare(strict_types=1);

namespace AA\Mailbulk;

use AA\Mail\PermanentSmtpException;
use AA\Mail\SmtpTransport;
use AA\Mail\TransientSmtpException;
use AA_Mail;
use Closure;
use LogicException;

/**
 * Mailer over AA_Mail. Uses sendOnce(): AA_Mail::send() would queue a mail after a transient failure
 * on its own, and the run would then send it a second time.
 */
final class AaMailer implements Mailer
{
    /** the waits of one SMTP send that may each take the full timeout: connect, greeting, EHLO, STARTTLS,
     *  the TLS handshake, EHLO, AUTH (up to three), MAIL, RCPT, DATA, the body, its answer, QUIT */
    private const SMTP_WAITS = 15;
    /** mail() hands the mail to the local MTA - no timeout of ours applies */
    private const MAIL_SECONDS = 60;

    private ?AA_Mail $mail = null;
    private bool $prepared = false;
    private string $to     = '';
    private string $detail = '';

    /** @param ?Closure(): AA_Mail $newMail a seam for the tests */
    public function __construct(private readonly ?Closure $newMail = null) {}

    public function prepare(Message $message): void
    {
        $mail = ($this->newMail !== null) ? ($this->newMail)() : new AA_Mail();
        // setFromArray() reads every key without a default
        $built = $mail->setFromArray([
            'subject'     => $message->subject,
            'body'        => $message->html,
            'header_from' => $message->from,
            'reply_to'    => $message->from,
            'errors_to'   => $message->from,
            'sender'      => $message->from,
            'lang'        => 'utf-8',
            'html'        => 1,
            'cc'          => '',
            'bcc'         => '',
            'attachments' => '',
        ]);
        if ($built) {
            foreach ($message->headers as $name => $value) {
                $mail->setHeader($name, $value);
            }
            $built = $mail->buildMessage();
        }
        $this->mail     = $built ? $mail : null;
        $this->prepared = true;
        $this->to       = $message->to;
        $this->detail   = $built ? '' : 'empty mail';
    }

    public function send(): Outcome
    {
        if (!$this->prepared) {
            throw new LogicException('AaMailer: nothing prepared');
        }
        $this->prepared = false;
        $mail           = $this->mail;
        $this->mail     = null;
        if ($mail === null) {
            return Outcome::Skipped;
        }
        try {
            $sent = $mail->sendOnce([$this->to]);
        } catch (TransientSmtpException $e) {
            $this->detail = $e->getMessage();
            return match (true) {
                $e->afterBody()   => Outcome::Uncertain,
                $e->sendingWide() => Outcome::Retry,
                default           => Outcome::Deferred,
            };
        } catch (PermanentSmtpException $e) {
            $this->detail = $e->getMessage();
            return $e->sendingWide() ? Outcome::Halt : Outcome::Failed;
        } catch (\Throwable $e) {
            $this->detail = $e->getMessage();
            // once the transport returned the mail may be out (a failed log write); before it, the settings failed
            return ($mail->getLastTransport() === '') ? Outcome::Halt : Outcome::Uncertain;
        }
        $this->detail = $mail->getLastTransportResponse();
        // SMTP refusals are thrown; a 0 is mail() whose local MTA did not take the mail
        return ($sent >= 1) ? Outcome::Sent : Outcome::Retry;
    }

    public function detail(): string
    {
        return $this->detail;
    }

    public function maxSeconds(): int
    {
        if (defined('AA_MAIL_TRANSPORT') && (constant('AA_MAIL_TRANSPORT') === SmtpTransport::TRANSPORT_SMTP)) {
            // +1: Net_Socket counts its deadline in whole seconds
            return self::SMTP_WAITS * (SmtpTransport::timeout() + 1);
        }
        return self::MAIL_SECONDS;
    }
}
