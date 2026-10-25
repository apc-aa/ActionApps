<?php

declare(strict_types=1);

namespace AA\Isds;

use AA\Later\BatchRun;

/**
 * What a person on the page of a mass sending reads of a code (ActionResult): plain text in the
 * language of the page, how to go on - never what went wrong inside AA, never a path or a setting
 * of datovka_conf.php: an editor reads it, the administrator fixes it.
 */
final class Advice
{
    /** the codes of text() that name a value - a count, an id, a size - they cannot go without */
    private const NAMES_A_VALUE = [
        Actions::QUEUED, Actions::TEST_SENT, Actions::TEST_UNCERTAIN, TestResult::SAME_LETTER,
        SendStart::COUNT_CHANGED, SendStart::TOO_MANY,
        // the result puts what is wrong after its colon
        SendStart::CONTENT,
    ];

    /** what BatchRun fails or waits with on its own, as it names it */
    private const NO_PROGRESS = 'NO_PROGRESS';
    private const STEP_DIED   = 'STEP_DIED';
    private const FINAL_CHECK = 'FINAL_CHECK';

    /** the codes ofRun() has a text of */
    private const RUN_CODES = [
        SendRun::REFUSED, SendRun::LOOKING, SendRun::WATCHING, SendRun::MAINTENANCE, SendRun::ACCOUNT_CHANGED,
        Outcome::UNAVAILABLE, Outcome::LOGIN, Outcome::BLOCKED, Outcome::CONFIG, Snapshot::CHANGED,
        BatchRun::NO_TRANSACTION, self::NO_PROGRESS, self::STEP_DIED, self::FINAL_CHECK, Actions::INTERNAL,
        SendRun::SETTINGS, SendRun::MEMORY,
    ];

    /**
     * @param list<int|string> $params what the text names, as ActionResult::$params
     * @return string '' for a code it does not know
     */
    public static function text(string $code, array $params = []): string
    {
        $first  = $params[0] ?? '';
        $second = $params[1] ?? '';

        return match ($code) {
            Actions::EXPIRED         => _m('The form has expired, or it does not belong to this page. Load the page again.'),
            Actions::TOO_LARGE       => ((int) $first > 0)
                ? _m('The form sent is larger than the server takes (%1). Nothing was saved - make the attachments smaller.', [self::size((int) $first)])
                : _m('The form sent is larger than the server takes. Nothing was saved - make the attachments smaller.'),
            Actions::INTERNAL        => _m('The action failed on an internal error of AA and may have done part of its work. Load the page again and look before you try it once more.'),
            Actions::BUSY            => _m('Someone else, or another window, is working with this now. Try it again in a while.'),
            Actions::FORBIDDEN       => _m('You may not do this.'),
            Actions::UNKNOWN         => _m('This sending is not on this page.'),
            Actions::WRONG_STATE     => _m('The sending does not wait for this now.'),
            Actions::LEASE           => _m('A step of the sending is still running. Try it again in a few minutes.'),
            Actions::RESUMED         => _m('The sending goes on.'),
            Actions::STOPPING        => _m('The sending is stopping - no further message will go.'),
            Actions::ALL_SENT        => _m('Every message has been sent or tried already - there is nothing left to stop.'),
            Actions::ABANDONED       => _m('The sending has been ended for good. Any message of unknown fate stays for you to verify.'),
            Actions::CONFIRM_ABANDON => _m('The sending has already stopped on an error, so Stop changes nothing here. You can choose Continue, or end the sending for good - messages of unknown fate and deliveries not yet read will then no longer be looked up.'),
            Actions::INPUT           => self::input((string) $first, (string) $second),
            Actions::SAVED           => _m('The draft is saved.'),
            Actions::FILTER_CHANGED  => _m('The letter is saved, but not the choice of the recipients: it changed in the meantime - in another window, or a recipient you picked is gone. The page shows the recipients saved now; choose them again if needed.'),
            Actions::CLONED          => _m('The copy is ready as a new draft.'),
            Actions::NONE_STOPPED    => _m('The sending left no recipient unsent - there is nothing to copy.'),
            Actions::NOT_RESENDABLE  => _m('The letter can be sent to this recipient again only if its message failed, or the sending stopped before it. This message has gone, may still go, or was left out - or it is not known whether it went: verify it once the page offers it (not while AA still looks it up), and then it can be sent again.'),
            Actions::VERIFIED        => _m('Recorded: the message did not go. The letter can be sent to that recipient again in a new campaign.'),
            Actions::NOT_UNCERTAIN   => _m('The fate of this message is known - there is nothing to verify.'),
            Actions::UNKNOWN_MESSAGE => _m('This message does not belong to the sending.'),
            Actions::FIXED           => _m('The data box of the recipient is taken from the register.'),
            Actions::NOT_OFFERED     => _m('The register does not offer this data box for the recipient surely enough to take it here. An editor of the slice of recipients compares it on its page Data boxes from the register, or fixes the item.'),
            Actions::NO_REGISTER     => _m('The register of data boxes has not been downloaded, or it is old. An editor of the slice of recipients downloads it on its page Data boxes from the register.'),
            Actions::UNKNOWN_RECIPIENT => _m('This recipient is not among those paired with the register: it was deleted, put in the trash or given another type. Load the page again.'),
            Actions::TEST_ENV        => _m('A page in the test environment changes no recipient.'),
            Actions::QUEUED          => _m('The sending is queued - recipients: %1. It goes on by itself, you may close the page.', [$first]),
            Actions::TEST_SENT       => _m('The test message has gone (ID %1 in ISDS, reference number %2).', [$first, $second]),
            Actions::TEST_UNCERTAIN  => _m('It is not known whether the test message went. Look for it among the sent messages of the data box by its reference number %1.', [$first]),
            Actions::TEST_FAILED     => self::join(_m('ISDS refused the test message.'), self::isdsOrStatus((string) $first, true)),
            Actions::TEST_BUSY       => _m('Another test message of this page is on its way, or one stopped a moment ago. Try it again in a few minutes.'),
            SendStart::NOT_DRAFT, SendStart::ALREADY => _m('The sending has been queued or sent already, so it cannot be changed or sent again. To send the letter once more, make a copy of it.'),
            SendStart::NO_FILTER     => _m('Choose the recipients and save the draft.'),
            SendStart::BAD_FILTER    => _m('This page no longer offers the recipients saved with the draft, so it shows no choice of them. Choose the recipients anew and save the draft - the new choice replaces the one saved.'),
            SendStart::COUNT_CHANGED => _m('The number of recipients has changed since you confirmed %1. Check the choice and send it again.', [$first]),
            SendStart::TOO_MANY      => _m('There are more recipients than this page may send to (%1). Narrow the choice.', [$first]),
            SendStart::NOTHING       => _m('The letter would reach no one: none of the recipients chosen has a data box it can go to.'),
            SendStart::ACCOUNT       => _m('No data box account is set up for this page. Ask the administrator.'),
            SendStart::SETTINGS      => _m('This page or its slices are not ready to send. Ask the administrator.'),
            SendStart::CONTENT       => _m('The letter cannot go as a data message:'),
            SendStart::SERVER        => _m('The server could not make the letter now - there is nothing to fix in the draft. Try it again later, or ask the administrator.'),
            SendStart::MEMORY        => ((int) $first > 0) ? _m('The server has too little memory for the letter and its attachments (%1 MB needed). Ask the administrator.', [$first]) : _m('The server has too little memory for the letter and its attachments. Ask the administrator.'),
            SendStart::NO_TRANSACTION => _m('The database cannot queue a sending safely. Ask the administrator.'),
            Outcome::LOGIN           => _m('ISDS refused the login of the account, nothing was sent. Ask the administrator to check the login.'),
            Outcome::BLOCKED         => _m('ISDS has blocked the logins from this server, nothing was sent. A new password does not help: AA tries to log in again a day after the block - try it again tomorrow, or ask the administrator.'),
            Outcome::CONFIG          => _m('The settings of the account are not complete, nothing was sent. Ask the administrator.'),
            Outcome::UNAVAILABLE     => _m('ISDS does not answer, nothing was sent. Try it again in a while.'),
            SendRun::REFUSED         => self::join(_m('ISDS refused to tell whose the login of the account is, nothing was sent.'), self::isdsOrStatus((string) $first)),
            TestResult::NO_TEST_BOX  => _m('No test data box is set up for this page - in production the administrator of the server sets it.'),
            TestResult::NOT_TEST_BOX => _m('Choose one of the test data boxes of this page.'),
            TestResult::OWN_BOX      => _m('A test message cannot go to the data box it is sent from. Choose another test box.'),
            TestResult::MAINTENANCE  => ((int) $first > 0) ? _m('ISDS is being maintained until %1. Try it again then.', [self::time((int) $first)]) : _m('ISDS is being maintained. Try it again later.'),
            TestResult::SAME_LETTER  => ((int) $second > 0)
                ? _m('This letter has already gone, or may have gone, to this test box on %2 (reference number %1). Change the draft to test it again.', [$first, self::time((int) $second)])
                : _m('This letter has already gone, or may have gone, to this test box (reference number %1). Change the draft to test it again.', [$first]),
            TestResult::PAID         => _m('A message to this data box would be paid (a postal data message), and in production a test message goes only free of charge. Test with the data box of an authority.'),
            TestResult::PAID_UNKNOWN => self::join(_m('It could not be checked that the message would be free of charge, nothing was sent. Check the test box and try it again in a while.'), self::isdsOrStatus((string) $first, true)),
            TestResult::CHARGED      => _m('The test message went as a paid postal data message.'),
            TestResult::MAY_BE_CHARGED => _m('The test message may have cost money: it may have gone as a paid postal data message.'),
            default                  => '',
        };
    }

    /**
     * text() of a code a template names on its own, with nothing the text names
     * @return string '' for a code it does not know, and for one of NAMES_A_VALUE
     */
    public static function alone(string $code): string
    {
        return in_array($code, self::NAMES_A_VALUE, true) ? '' : self::text($code);
    }

    /** whether ofRun() has a text of the code */
    public static function knowsRun(string $code): bool
    {
        return in_array($code, self::RUN_CODES, true);
    }

    /**
     * What a code of a sending (its error, or what it waits for: BatchRun::lastFailure()) means on its
     * page - the cause and how to go on. The same code may make the sending wait and try again, or stop
     * it till Continue; the page tells which by the state, so the text fits both. Unlike text(), which
     * tells what an action of the page did: before a sending is queued nothing was sent, in it some was.
     * @return string '' for a code not in RUN_CODES
     */
    public static function ofRun(string $code): string
    {
        return match ($code) {
            SendRun::REFUSED          => self::join(
                _m('ISDS refused several messages in a row with the same status, or refused a question of the sending - whose the login of the account is, or which messages were sent.'),
                _m('After refused messages Continue sends one more, and the sending stops again if ISDS refuses that one too; after a refused question Continue asks again.'),
            ),
            SendRun::LOOKING          => _m('Waiting until ISDS lists the messages whose fate is not known yet. The sending looks them up by itself.'),
            SendRun::WATCHING         => _m('The sending is over - which does not mean every message went, the figures tell. The delivery is followed in rounds; the next one comes by itself.'),
            SendRun::MAINTENANCE      => _m('ISDS is being maintained. The sending waits till the end and goes on by itself.'),
            SendRun::ACCOUNT_CHANGED  => _m('The data box account of this page is no longer the one the sending was queued with. Ask the administrator.'),
            Outcome::UNAVAILABLE      => _m('ISDS does not answer. AA tries again by itself; if the sending stopped on it, choose Continue once ISDS answers.'),
            Outcome::LOGIN            => _m('ISDS refuses the login of the account. Ask the administrator to check the login, then choose Continue.'),
            Outcome::BLOCKED          => _m('ISDS has blocked the logins from this server. A new password does not help: choose Continue tomorrow, or ask the administrator.'),
            Outcome::CONFIG           => _m('The settings of the account are not complete. Ask the administrator.'),
            Snapshot::CHANGED         => _m('What was frozen for the sending - the letter, its attachments or the recipients - is missing or changed, so it cannot go on. Ask the administrator.'),
            BatchRun::NO_TRANSACTION  => _m('The database cannot record the sending safely. Ask the administrator.'),
            self::NO_PROGRESS         => _m('A step of the sending kept making no progress. Choose Continue; if it stops again, ask the administrator.'),
            self::STEP_DIED           => _m('A step of the sending stopped before its end. AA tries again by itself; if the sending stopped on it, ask the administrator.'),
            self::FINAL_CHECK         => _m('The end of the sending could not be recorded. AA tries again by itself; if the sending stopped on it, ask the administrator.'),
            Actions::INTERNAL         => _m('A step of the sending failed on an internal error of AA. Choose Continue; if it fails again, ask the administrator.'),
            // say nothing of what was sent, so they fit a sending too
            SendRun::SETTINGS, SendRun::MEMORY => self::text($code),
            default                   => '',
        };
    }

    /**
     * What a status ISDS refused a message with means, and how to go on - for the statuses whose
     * meaning AA knows; the full list is only in the documentation of ISDS
     * @param bool $test a test message: what to test with instead
     * @return string '' for a status it does not know
     */
    public static function isds(string $status, bool $test = false): string
    {
        return match ($status) {
            '1233'  => self::join(_m('The data box of the recipient takes only paid postal data messages, and the sending data box may not send them.'), $test ? _m('Test with the data box of an authority.') : ''),
            '1201'  => _m('The sending data box has been made inaccessible - no message can go from it now.'),
            '1225'  => _m('The subject, "to hands" or the name of an attachment holds a character ISDS does not take.'),
            default => '',
        };
    }

    /** the text of a status ISDS answered with, or the status itself when AA knows no text of it */
    private static function isdsOrStatus(string $status, bool $test = false): string
    {
        return ($status === '') ? '' : (self::isds($status, $test) ?: _m('ISDS answered with status %1.', [$status]));
    }

    /**
     * what to fix in a field of the form (Drafts)
     * @param string $file the attachment refused, in safe letters
     */
    private static function input(string $field, string $file): string
    {
        return match ($field) {
            Drafts::SUBJECT    => _m('The subject cannot be saved: it may have at most %1 characters, and no tab or other control character. Nothing of the draft was saved.', [Message::MAX_ANNOTATION]),
            Drafts::TO_HANDS   => _m('"To hands" cannot be saved: it may have at most %1 characters, and no tab or other control character. Nothing of the draft was saved.', [Message::MAX_TO_HANDS]),
            Drafts::BODY       => _m('The text of the letter cannot be saved: it is larger than %1, or it is not valid text. Nothing of the draft was saved.', [self::size(Drafts::MAX_BODY)]),
            Drafts::RECIPIENTS => _m('The choice of the recipients cannot be saved: this page does not offer it. Nothing of the draft was saved - choose them once more and save it.'),
            Drafts::TOO_MANY   => _m('The choice of the recipients cannot be saved: at most %1 of them may be chosen, and as many left out, one by one. Nothing of the draft was saved.', [Setup::MAX_RECIPIENTS]),
            Drafts::TOO_MANY_FILES => _m('A data message carries at most %1 attachments beside the letter. Nothing of the draft was saved - remove some of them first.', [Message::MAX_FILES - 1]),
            Drafts::FILE_TYPE    => _m('The file %1 cannot be attached: this page takes only files of the types %2. Save it as a PDF, or attach the files of an archive one by one. Nothing of the draft was saved.', [$file, implode(', ', Drafts::attachable())]),
            Drafts::FILE_CONTENT => _m('The file %1 cannot be attached: its content is not of the type its extension names. Nothing of the draft was saved.', [$file]),
            Drafts::FILE_SIZE    => _m('The file %1 is larger than one file may be here (%2). Nothing of the draft was saved. All the attachments and the letter together may have at most %3 - a test message checks that.', [$file, self::size(Drafts::maxFileBytes()), self::size(Message::MAX_BYTES)]),
            Drafts::FILE_EMPTY   => _m('The file %1 is empty. Nothing of the draft was saved.', [$file]),
            Drafts::FILE_LOST    => _m('The file %1 did not arrive whole. Try it again. Nothing of the draft was saved.', [$file]),
            default            => _m('A value of the form cannot be taken. Load the page again and try it once more.'),
        };
    }

    /** the texts that are not empty, as one */
    public static function join(string ...$texts): string
    {
        return implode(' ', array_filter($texts, static fn(string $text): bool => $text !== ''));
    }

    /** a unix time as a person on any page reads it - in the time zone PHP of the installation runs in */
    private static function time(int $time): string
    {
        return date('Y-m-d H:i', $time);
    }

    /** bytes as a person reads them: 8 MB, 1.5 MB, 512 kB */
    private static function size(int $bytes): string
    {
        return ($bytes >= 1024 * 1024) ? round($bytes / (1024 * 1024), 1) . ' MB' : max(1, (int) round($bytes / 1024)) . ' kB';
    }
}
