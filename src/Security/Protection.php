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
    case UrlValuesAsText = 'url_values_as_text';
    case LoginThrottle = 'login_throttle';

    public function label(): string
    {
        return match ($this) {
            self::SignedExpressions => _m('Forms run only expressions signed by AA'),
            self::UrlValuesAsText   => _m('Values from the URL are text'),
            self::LoginThrottle     => _m('Repeated failed logins are slowed down'),
        };
    }

    /** where the protection acts, in the words a template author searches for */
    public function scope(): string
    {
        return match ($this) {
            self::SignedExpressions => _m('ret_code and ok_url sent to filler.php'),
            self::UrlValuesAsText   => _m('als[] and the state of site pages, taken from the URL'),
            self::LoginThrottle     => _m('Logins to the administration and to reader accounts, counted per address of the visitor'),
        };
    }

    public function migrationTitle(): string
    {
        return match ($this) {
            self::SignedExpressions => _m('How to convert older forms'),
            self::UrlValuesAsText   => _m('How to convert older links'),
            self::LoginThrottle     => _m('When a script or a whole office is slowed down'),
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
            self::UrlValuesAsText   => _m('Text, numbers, ids and _#ALIASES in als[] and in the state of site pages keep working; an {expression} there is shown as text. Write the expression in the template that builds the link, so it runs there before the link is sent, or pass an id and compute the rest in the page that receives it.'),
            self::LoginThrottle     => _m('After 20 failed logins from one address in 12 hours, or 5 with one user name, the next login waits half a minute to a minute, twice as long after each further failure, at most an hour. A script that keeps logging in with an old password waits too: find its address in the Log (event LOGIN) and give it the current password. If every visitor seems to come from one address, AA runs behind a proxy missing in AA_TRUSTED_PROXIES - State Check names it; add it in config.php rather than switching this protection off.'),
        };
    }

    /** the kind of a ProtectionLog row in a few words - State Check names a group of such rows by it */
    public function loggedKind(bool $refused): string
    {
        return match ($this) {
            self::SignedExpressions => $refused ? _m('Unsigned expression refused') : _m('Unsigned expression ran, protection off'),
            self::UrlValuesAsText   => $refused ? _m('Expression in a link shown as text') : _m('Expression in a link ran, protection off'),
            self::LoginThrottle     => $refused ? _m('Logins slowed down') : _m('Repeated failed logins, protection off'),
        };
    }

    /** the State Check title of a ProtectionLog row */
    public function loggedTitle(bool $refused, string $page, string $param): string
    {
        $page = ($page === '') ? _m('an unknown page') : $page;
        return match ($this) {
            self::SignedExpressions => $refused
                ? _m('A form on %1 sent an unsigned expression in %2; it was refused', [$page, $param])
                : _m('A form on %1 sent an unsigned expression in %2; it ran because the protection was off', [$page, $param]),
            self::UrlValuesAsText   => $refused
                ? _m('A link to %1 sent an expression in %2; it was shown as text', [$page, $param])
                : _m('A link to %1 sent an expression in %2; it ran because the protection was off', [$page, $param]),
            self::LoginThrottle     => $refused
                ? _m('Logins from %1 were slowed down after repeated failures', [$param])
                : _m('Logins from %1 failed repeatedly; they were not slowed down because the protection was off', [$param]),
        };
    }

    /** what the second part of a ProtectionLog row's selector is - State Check labels it so */
    public function loggedParam(): string
    {
        return match ($this) {
            self::SignedExpressions, self::UrlValuesAsText => _m('Parameter'),
            self::LoginThrottle                            => _m('Address'),
        };
    }

    /** whether a ProtectionLog row asks the admin to act - a refused login only shows the protection at work */
    public function loggedNeedsAction(bool $refused): bool
    {
        return match ($this) {
            self::SignedExpressions, self::UrlValuesAsText => true,
            self::LoginThrottle                            => !$refused,
        };
    }

    /** State Check advice to a ProtectionLog row */
    public function loggedAdvice(): string
    {
        return $this->migration() . ' ' . match ($this) {
            self::SignedExpressions, self::UrlValuesAsText => _m('If you do not know the page, the request did not come from your pages and nothing needs to change.'),
            self::LoginThrottle                            => _m('An address you do not know needs nothing: only its own logins are slowed down.'),
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
            'url_values_as_text' => UrlValue::EVENT,
            'login_throttle'     => LoginThrottle::EVENT,
        ];
    }
}
