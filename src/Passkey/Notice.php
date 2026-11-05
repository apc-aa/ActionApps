<?php

declare(strict_types=1);

namespace AA\Passkey;

use AA;
use AA\Util\Txt;
use AA_Log;
use AA_Logentry;
use AA_Mail;

/** A passkey added or deleted: a row in the log and a mail to the owner - who did not do it learns of it at once. */
final class Notice
{
    /** log.type - selector add|delete|reset:uid of the account, params host:name of the passkey (reset: count) */
    public const LOG = 'PASSKEY';

    public static function added(Credential $credential): void
    {
        self::write('add', $credential->uid, [$credential->rpId, $credential->name]);
        [$login, $to] = self::owner($credential->uid);
        self::mail($to, _m('A passkey was added to your account'), _m('The passkey "%1" was added to your account %2 on %3. It logs you in on %4.', [$credential->name, $login, date('Y-m-d H:i'), $credential->rpId]));
    }

    public static function deleted(Credential $credential): void
    {
        self::write('delete', $credential->uid, [$credential->rpId, $credential->name]);
        [$login, $to] = self::owner($credential->uid);
        self::mail($to, _m('A passkey was deleted from your account'), _m('The passkey "%1" was deleted from your account %2 on %3.', [$credential->name, $login, date('Y-m-d H:i')]));
    }

    /** all passkeys of the account deleted by a user manager - the password logs in again */
    public static function reset(string $uid, int $count): void
    {
        self::write('reset', $uid, [(string) $count]);
        [$login, $to] = self::owner($uid);
        self::mail($to, _m('The passkeys of your account were deleted'), _m('%1 deleted all passkeys of your account %2 on %3. You log in by your password again; you can add a new passkey after the login.', [AA::$perm->getUserName(AuthGetUid()), $login, date('Y-m-d H:i')]));
    }

    /** @param list<string> $params */
    private static function write(string $action, string $uid, array $params): void
    {
        AA_Log::write(self::LOG, ParamImplode([$action, $uid]), Txt::utf8Cut(ParamImplode($params), AA_Logentry::PARAMS_MAX));
    }

    /** @return array{0: string, 1: string} login name and e-mail of the account */
    private static function owner(string $uid): array
    {
        $info = AA::$perm->getIDsInfo($uid);
        return is_array($info) ? [(string) ($info['login'] ?? $uid), (string) (($info['mails'] ?? [])[0] ?? ($info['mail'] ?? ''))] : [$uid, ''];
    }

    private static function mail(string $to, string $subject, string $text): void
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $admin = (string) constant('ERROR_REPORTING_EMAIL');
        $body  = $text . "\n\n" . _m('If it was not you, tell the administrator of ActionApps at %1 at once.', [$admin])
            . "\n\n" . _m('Address: %1, browser: %2', [(string) ($_SERVER['REMOTE_ADDR'] ?? ''), (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')]);
        $mail = new AA_Mail();
        if ($mail->setFromArray([
            'subject'     => str_replace(["\r", "\n"], ' ', constant('ORG_NAME') . ': ' . $subject),
            'body'        => $body,
            'html'        => false,
            'lang'        => 'utf-8',
            'header_from' => $admin,
            'reply_to'    => '',
            'errors_to'   => $admin,
            'sender'      => $admin,
            'cc'          => '',
            'bcc'         => '',
            'attachments' => '',
        ])) {
            $mail->send([$to]);
        }
    }
}
