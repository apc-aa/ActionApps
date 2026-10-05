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

    /** what anyone can do while the protection is off */
    public function risk(): string
    {
        return match ($this) {
            self::SignedExpressions => _m('Anyone can send a form an AA expression of their own, and AA runs it. Such an expression reads files of the server, the database password among them.'),
        };
    }

    /** what may stop working while it is on, and how to fix the template */
    public function effect(): string
    {
        return match ($this) {
            self::SignedExpressions => _m('Expressions AA puts into its own forms ({ajax}, {form}, adding a related item) are signed and keep working, and so do plain text, _#ALIASES and simple field references. A hand-written form or link which sends its own {expression} in ret_code gets an empty answer instead. Generate such a form with {form} or {ajax}, or send only text and aliases.'),
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
