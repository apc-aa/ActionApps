<?php

declare(strict_types=1);

namespace AA\Isds\Body;

/**
 * The body of a letter shows nothing: a campaign then sends its attachments alone - unless the letter had
 * something the editor would miss: a header that shows, or anything HtmlBody left out (an image of another
 * site shows in the editor, not in the PDF)
 */
final class EmptyLetter extends \InvalidArgumentException
{
    /** @param list<string> $omitted HtmlBody::omitted() of the header and the body; they quote the letter - escape them when shown */
    public function __construct(public readonly bool $header, public readonly array $omitted)
    {
        parent::__construct('The letter is empty');
    }
}
