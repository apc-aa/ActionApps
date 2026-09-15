<?php

declare(strict_types=1);

use AA\Mail\SmtpTransport;

/**
 * SMTP smoke test for the AA_MAIL_TRANSPORT='smtp' path.
 *
 * Sends ONE test message through the real htmlMimeMail -> SmtpTransport ->
 * pear/net_smtp path, against a configured relay, and prints a verbose report
 * (envelope, recipients, Message-ID, transport result, and the SMTP error text
 * on failure). The point is to confirm DKIM/SPF/DMARC alignment end-to-end:
 * after it lands, check the received message's Authentication-Results header.
 *
 * CLI only. Settings come from environment variables so no config file needs
 * editing and credentials stay out of any file:
 *
 *   AA_SMTP_HOST=smtp.relay.tld AA_SMTP_PORT=587 AA_SMTP_ENCRYPTION=tls \
 *   AA_SMTP_USER=login AA_SMTP_PASS=secret AA_MAIL_HOSTNAME=yourdomain.tld \
 *   php misc/smtp_smoketest.php --to=you@gmail.com --from=noreply@yourdomain.tld
 *
 * Optional: --subject="..."  AA_SMTP_HELO=...  AA_SMTP_TIMEOUT=15  AA_SMTP_CAFILE=/path/ca.pem
 *
 * The password is never echoed. Run it on a host that can reach the relay
 * (typically the AA server itself).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../vendor/autoload.php';

/* --- gather options ---------------------------------------------------- */

$opts = getopt('', ['to:', 'from:', 'subject::']);
$to   = $opts['to']   ?? '';
$from = $opts['from'] ?? '';
$subj = str_replace(["\r", "\n"], ' ', $opts['subject'] ?? 'AA SMTP smoke test ' . date('c'));   // strip CR/LF: $subj is concatenated into a raw SMTP header below

if ($to === '' || $from === '') {
    fwrite(STDERR, "Usage: php misc/smtp_smoketest.php --to=<addr> --from=<addr> [--subject=...]\n");
    fwrite(STDERR, "SMTP settings come from AA_SMTP_* environment variables (see file header).\n");
    exit(2);
}

/* Define the AA_SMTP_* constants from the environment unless config already
 * defined them (so this also works when run inside a configured install). */
$envConst = static function (string $name, ?string $default = null): void {
    if (defined($name)) {
        return;
    }
    $val = getenv($name);
    if ($val !== false) {
        define($name, $val);
    } elseif ($default !== null) {
        define($name, $default);
    }
};

$envConst('AA_MAIL_TRANSPORT', 'smtp');
$envConst('AA_SMTP_HOST');
$envConst('AA_SMTP_PORT', '587');
$envConst('AA_SMTP_ENCRYPTION', 'tls');
$envConst('AA_SMTP_USER', '');
$envConst('AA_SMTP_PASS', '');
$envConst('AA_SMTP_HELO', '');
$envConst('AA_SMTP_TIMEOUT', '15');
$envConst('AA_SMTP_CAFILE', '');
$envConst('AA_MAIL_HOSTNAME', '');

require_once __DIR__ . '/../include/htmlMimeMail/htmlMimeMail.php';

/* --- report configuration (password masked) ---------------------------- */

$host = defined('AA_SMTP_HOST') ? (string) AA_SMTP_HOST : '';
if ($host === '') {
    fwrite(STDERR, "AA_SMTP_HOST is not set (environment variable or config).\n");
    exit(2);
}
$user = defined('AA_SMTP_USER') ? (string) AA_SMTP_USER : '';

echo "--- SMTP smoke test --------------------------------------------\n";
printf("transport  : %s\n", defined('AA_MAIL_TRANSPORT') ? AA_MAIL_TRANSPORT : '(unset)');
printf("relay      : %s:%s\n", $host, defined('AA_SMTP_PORT') ? AA_SMTP_PORT : '?');
printf("encryption : %s\n", defined('AA_SMTP_ENCRYPTION') ? AA_SMTP_ENCRYPTION : '?');
printf("auth user  : %s\n", $user === '' ? '(none)' : $user . '  (password ' . (AA_SMTP_PASS === '' ? 'EMPTY' : 'set, hidden') . ')');
printf("helo host  : %s\n", SmtpTransport::resolveHostname());
printf("from / env : %s\n", $from);
printf("to         : %s\n", $to);
echo "----------------------------------------------------------------\n";

/* --- build the message, then run send() exactly as production does ------ */

$mail = new htmlMimeMail();
$mail->setFrom($from);
$mail->setReturnPath($from);
$mail->setSubject($subj);
$mail->setText("This is an AA SMTP smoke-test message.\nSent " . date('c') . " via the SMTP transport.\n");

try {
    $sent = $mail->send([$to]);
} catch (\AA\Mail\TransientSmtpException $e) {
    echo "RESULT     : TRANSIENT FAILURE (would be re-queued in production)\n";
    echo "detail     : " . $e->getMessage() . "\n";
    exit(1);
} catch (\Throwable $e) {
    echo "RESULT     : ERROR\n";
    echo "detail     : " . get_class($e) . ': ' . $e->getMessage() . "\n";
    exit(1);
}

if ($sent > 0) {
    echo "RESULT     : SENT ($sent recipient(s) accepted)\n";
    echo "next       : open the received message and check Authentication-Results\n";
    echo "             shows dkim=pass spf=pass dmarc=pass, and Message-ID uses\n";
    echo "             your configured host (not localhost).\n";
    exit(0);
}

/* send() returned 0 = permanent reject with no exception. Re-run the
 * assembly + transport directly to surface the SMTP server's reason. */
echo "RESULT     : PERMANENT REJECT (0 recipients accepted)\n";
$diag = new SmtpTransport();
$result = $diag->sendRaw(
    $mail->extractEmails($from)[0] ?? $from,
    [$to],
    "Subject: " . $subj . "\r\nFrom: " . $from . "\r\nTo: " . $to . "\r\n\r\nsmoke test\r\n",
);
echo "diag code  : $result (" . SmtpTransport::RESULT_PERMANENT . "=permanent, " . SmtpTransport::RESULT_TRANSIENT . "=transient)\n";
$err = $diag->getLastError();
echo "detail     : " . ($err !== '' ? $err : '(no error text from server)') . "\n";
exit(1);
