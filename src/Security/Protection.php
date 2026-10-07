<?php

declare(strict_types=1);

namespace AA\Security;

/**
 * A protection of the install which a superadmin may switch off for an old site that relies on the
 * hole it closes (admin/aa_security.php). Every protection is on unless switched off in Settings.
 * A new protection is a new case here plus Settings::isOn() where the hole is.
 */
enum Protection: string
{
    case SignedExpressions = 'signed_expressions';

    public function label(): string
    {
        return match ($this) {
            self::SignedExpressions => _m('Forms run only expressions signed by AA'),
        };
    }

    /** where the protection acts, in the words a template author searches for */
    public function scope(): string
    {
        return match ($this) {
            self::SignedExpressions => _m('ret_code and ok_url sent to filler.php'),
        };
    }

    /**
     * How to convert a form that still uses the older way, so the protection can stay on. Written
     * for the template author; it does not describe what the protection prevents, the admin pages
     * are no place to explain an attack.
     */
    public function migration(): string
    {
        return match ($this) {
            self::SignedExpressions => _m('Forms that AA generates with {form} or {ajax}, and the form for adding a related item, are signed and need no change. Text, _#ALIASES and fields such as {headline........} in ret_code or ok_url keep working as well. If your own form or link sends another {expression} there, generate the form with {form} or {ajax}, or replace the expression with aliases.'),
        };
    }

    /** null when AA cannot tell whether a site needs the protection - the page then says so */
    public function event(): ?string
    {
        return self::events()[$this->value] ?? null;
    }

    /** @return array<string, string> log.type of the rows that show a protection at work; one missing here is not measured */
    private static function events(): array
    {
        return [
            'signed_expressions' => SignedExpression::EVENT,
        ];
    }
}
