<?php

declare(strict_types=1);

namespace AA\Mailbulk;

use AA\Mail\PermanentSmtpException;
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
            return $e->afterBody() ? Outcome::Uncertain : Outcome::Retry;
        } catch (PermanentSmtpException $e) {
            $this->detail = $e->getMessage();
            return Outcome::Failed;
        }
        $this->detail = $mail->getLastTransportResponse();
        return ($sent >= 1) ? Outcome::Sent : Outcome::Failed;
    }

    public function detail(): string
    {
        return $this->detail;
    }
}
