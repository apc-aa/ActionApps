<?php

declare(strict_types=1);

namespace AA\Upload;

/**
 * An anonymous upload refused for the visitor's own image (AA\Util\Imgfunc::reencode() set $refusal).
 * Its own class, so that only the fixed texts of reencode() reach the browser - never the message of
 * another exception thrown on the way.
 */
final class RefusedImage extends \RuntimeException
{
    /** @param string $reason 'image' or 'size', as reencode() set it */
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
