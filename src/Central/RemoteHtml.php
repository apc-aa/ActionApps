<?php

declare(strict_types=1);

namespace AA\Central;

use AA\Util\Txt;

/** Shows HTML that came from a remote AA (Central) without trusting it. */
class RemoteHtml
{
    /** Blocks every fetch from the report (remote images/CSS would reveal the admin's IP); inline style stays. */
    private const CSP = '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; img-src data:">';

    /** The remote report stays readable, but runs in a sandboxed iframe: no script, and a unique
     *  origin, so it cannot reach the Central admin page or its session. A remote AA may be
     *  compromised - never echo its HTML into the page itself. */
    public static function sandboxed(string $title, string $html): string
    {
        $title = Txt::safe($title);
        if (trim($html) === '') {
            return "<h3>$title</h3><p>" . Txt::safe(_m('(no output)')) . '</p>';
        }
        return "<h3>$title</h3>"
            . '<iframe sandbox srcdoc="' . Txt::safe(self::CSP . $html) . "\" title=\"$title\" style=\"width:100%;height:16em;resize:vertical\"></iframe>";
    }
}
