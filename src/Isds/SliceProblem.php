<?php

declare(strict_types=1);

namespace AA\Isds;

/** One thing to fix before AA opens the slices of a mass sending for a site (SliceRules) */
final readonly class SliceProblem
{
    /**
     * @param string $text   what to fix, translated; quotes names and ids raw - escape it (safe()) when shown
     * @param string $module the slice or site to fix; '' when there is none to open
     * @param string $page   the admin page that fixes it, a path under AA_INSTAL_PATH; '' when none does
     */
    public function __construct(public string $text, public string $module = '', public string $page = '') {}
}
