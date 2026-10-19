<?php

declare(strict_types=1);

namespace AA\Isds;

/**
 * The settings of a mass sending in a template are not ready (Setup::fromTemplate()). The problems
 * say how to fix them and quote names and ids raw - escape them (safe()) when shown.
 */
final class SetupException extends \RuntimeException
{
    /**
     * @param list<SliceProblem> $problems      at least one
     * @param string             $campaignSlice the campaign slice the settings name, in small letters; '' when they name none
     */
    public function __construct(public readonly array $problems, public readonly string $campaignSlice = '')
    {
        parent::__construct(implode('; ', array_map(static fn(SliceProblem $p): string => $p->text, $problems)));
    }
}
