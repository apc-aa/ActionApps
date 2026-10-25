<?php

declare(strict_types=1);

namespace AA\Isds;

/**
 * What a person on the page of a mass sending reads of a code (ActionResult): plain text in the
 * language of the page, how to go on - never what went wrong inside AA, never a path or a setting
 * of datovka_conf.php: an editor reads it, the administrator fixes it.
 */
final class Advice
{
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
            Actions::TOO_LARGE       => _m('The form sent is larger than the server takes (%1). Nothing was saved - make the attachments smaller.', [self::size((int) $first)]),
            Actions::INTERNAL        => _m('The action failed on an internal error of AA and may have done part of its work. Load the page again and look before you try it once more.'),
            Actions::BUSY            => _m('Someone else, or another window, is working with the sending now. Try it again in a while.'),
            Actions::FORBIDDEN       => _m('You may not do this.'),
            Actions::UNKNOWN         => _m('This sending is not on this page.'),
            Actions::WRONG_STATE     => _m('The sending does not wait for this now.'),
            Actions::LEASE           => _m('A step of the sending is still running. Try it again in a few minutes.'),
            Actions::RESUMED         => _m('The sending goes on.'),
            Actions::STOPPING        => _m('The sending is stopping - no further message will go.'),
            Actions::ALL_SENT        => _m('Every message has been sent or tried already - there is nothing left to stop.'),
            Actions::ABANDONED       => _m('The sending has been ended for good. Any message of unknown fate stays for you to verify.'),
            Actions::CONFIRM_ABANDON => _m('The sending has already stopped on an error, so Stop changes nothing here. You can choose Continue, or end the sending for good - messages of unknown fate and deliveries not yet read will then no longer be looked up.'),
            Actions::INPUT           => self::input((string) $first),
            Actions::SAVED           => _m('The draft is saved.'),
            Actions::QUEUED          => _m('The sending is queued - recipients: %1. It goes on by itself, you may close the page.', [$first]),
            Actions::TEST_SENT       => _m('The test message has gone (ID %1 in ISDS, reference number %2).', [$first, $second]),
            Actions::TEST_UNCERTAIN  => _m('It is not known whether the test message went. Look for it among the sent messages of the data box by its reference number %1.', [$first]),
            Actions::TEST_FAILED     => self::join(_m('ISDS refused the test message.'), self::isdsOrStatus((string) $first, true)),
            Actions::TEST_BUSY       => _m('Another test message of this page is on its way, or one stopped a moment ago. Try it again in a few minutes.'),
            SendStart::NOT_DRAFT, SendStart::ALREADY => _m('The sending has been queued or sent already, so it cannot be changed or sent again. To send the letter once more, make a copy of it.'),
            SendStart::NO_FILTER     => _m('Choose the recipients and save the draft.'),
            SendStart::BAD_FILTER    => _m('This page no longer offers the recipients saved with the draft. Choose them again and save the draft.'),
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

    /** what to fix in a field of the form (Drafts) */
    private static function input(string $field): string
    {
        return match ($field) {
            Drafts::SUBJECT    => _m('The subject cannot be saved: it may have at most %1 characters, and no tab or other control character. Nothing of the draft was saved.', [Message::MAX_ANNOTATION]),
            Drafts::TO_HANDS   => _m('"To hands" cannot be saved: it may have at most %1 characters, and no tab or other control character. Nothing of the draft was saved.', [Message::MAX_TO_HANDS]),
            Drafts::BODY       => _m('The text of the letter cannot be saved: it is larger than %1, or it is not valid text. Nothing of the draft was saved.', [self::size(Drafts::MAX_BODY)]),
            Drafts::RECIPIENTS => _m('The choice of the recipients cannot be saved: this page does not offer it. Nothing of the draft was saved - choose them once more and save it.'),
            Drafts::TOO_MANY   => _m('The choice of the recipients cannot be saved: at most %1 of them may be chosen, and as many left out, one by one. Nothing of the draft was saved.', [Setup::MAX_RECIPIENTS]),
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
