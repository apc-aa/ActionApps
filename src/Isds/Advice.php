<?php

declare(strict_types=1);

namespace AA\Isds;

/**
 * What a person on the page of a mass sending reads of a code (ActionResult): plain text in the
 * language of the page, how to go on - never what went wrong inside AA.
 */
final class Advice
{
    /**
     * @param list<int> $params the numbers the text names, as ActionResult::$params
     * @return string '' for a code it does not know
     */
    public static function text(string $code, array $params = []): string
    {
        return match ($code) {
            Actions::EXPIRED         => _m('The form has expired, or it does not belong to this page. Load the page again.'),
            Actions::TOO_LARGE       => _m('The form sent is larger than the server takes (%1). Nothing was saved - make the attachments smaller.', [self::size($params[0] ?? 0)]),
            Actions::INTERNAL        => _m('The action failed on an internal error of AA. Look at the state of the sending before you try it again.'),
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
            default                  => '',
        };
    }

    /** bytes as a person reads them: 8 MB, 1.5 MB, 512 kB */
    private static function size(int $bytes): string
    {
        return ($bytes >= 1024 * 1024) ? round($bytes / (1024 * 1024), 1) . ' MB' : max(1, (int) round($bytes / 1024)) . ' kB';
    }
}
