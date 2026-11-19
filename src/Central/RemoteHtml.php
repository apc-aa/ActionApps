<?php

declare(strict_types=1);

namespace AA\Central;

use AA\Util\Txt;

/** Shows HTML that came from a remote AA (Central) without trusting it. */
class RemoteHtml
{
    /** Blocks every fetch from the report (remote images/CSS would reveal the admin's IP); inline style stays. */
    private const CSP = '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; img-src data:">';

    /** The look of a report: the admin CSS does not reach into the iframe, and the CSP loads nothing.
     *  An Optimize report is a table of steps (name | OK/Problem | messages for a test, name | messages
     *  for a repair), the messages a one-column table headed "txt". */
    private const STYLE = '<style>'
        . ':root{font:14px/1.45 "Open Sans",system-ui,sans-serif;color:#222;background:#fff}'
        . 'body{margin:0;padding:.3em .6em}'
        . 'table{border-collapse:collapse;width:100%}'
        . 'th,td{padding:.3em .5em;text-align:left;vertical-align:top}'
        . 'body>table>tbody>tr>td{border-bottom:1px solid #e6e1d3}'
        . 'body>table>tbody>tr>td:first-child{font-weight:600;width:28%}'
        . 'body>table>tbody>tr>td:nth-child(2):not(:last-child){white-space:nowrap}'
        . 'table table th,table table td{padding:0 0 .2em}'
        . '.aa-tbl-header:has(>th:only-child){display:none}'
        . 'pre{white-space:pre-wrap}'
        . '</style>';

    /** The remote report stays readable, but runs in a sandboxed iframe: no script, and a unique
     *  origin, so it cannot reach the Central admin page or its session. A remote AA may be
     *  compromised - never echo its HTML into the page itself.
     *  @param string $title what the iframe is called for a screen reader - the page shows its own heading */
    public static function sandboxed(string $title, string $html): string
    {
        if (trim($html) === '') {
            return '<p>' . Txt::safe(_m('(no output)')) . '</p>';
        }
        return '<iframe sandbox class="aa-remote-html" srcdoc="' . Txt::safe(self::CSP . self::STYLE . $html) . '" title="' . Txt::safe($title) . '"></iframe>';
    }
}
