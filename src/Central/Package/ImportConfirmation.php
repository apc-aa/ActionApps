<?php

declare(strict_types=1);

namespace AA\Central\Package;

use LogicException;

/** The explicit confirmation of the S findings: a package with code that reads files, calls URLs or
 *  carries passwords is written only with the token the preview handed out, in this admin session, for
 *  exactly these findings in exactly this package to exactly this user.
 *
 *  token() = HMAC-SHA256 under session_id() . AA_ID of the user, SHA-256 of serialize(ImportPlan::$package)
 *  and the sorted S findings. The new ids are not in: the UI may plan again for the write.
 *  AA_ID alone is no secret (config.php.default ships one, Centrals store it); the session id is.
 *  No session (CLI) and no explicit secret: token() throws - no package with S findings installs there.
 *  It is NO protection against CSRF (the caller checks the admin FormToken), nor proof the user read the
 *  findings (the UI passes it only when the confirmation box was ticked). */
final class ImportConfirmation
{
    /** @param string|null $secret the HMAC key; null = session_id() . AA_ID (tests pass their own) */
    public function __construct(private readonly ?string $secret = null) {}

    /** null: no S finding - nothing to confirm @throws LogicException S findings and no session (no secret given) */
    public function token(ImportPlan $plan, string $user): ?string
    {
        $security = $plan->ofLevel(LintFinding::SECURITY);
        if (!$security) {
            return null;
        }
        $lines = array_map(static fn(array $f): string => self::fields($f[1]->level, $f[1]->code, $f[0], $f[1]->ref, (string) $f[1]->offset), $security);
        sort($lines, SORT_STRING);
        $data = self::fields('aa-app-install-confirmation', $user, hash('sha256', serialize($plan->package)), ...$lines);
        return hash_hmac('sha256', $data, $this->key());
    }

    /** the plan needs no confirmation, or $token is the one token() gives
     *  @throws LogicException S findings and no session (token()) */
    public function confirms(ImportPlan $plan, string $user, ?string $token): bool
    {
        $expected = $this->token($plan, $user);
        return ($expected === null) || (($token !== null) && hash_equals($expected, $token));
    }

    /** @throws LogicException no session and no secret */
    private function key(): string
    {
        if ($this->secret !== null) {
            return $this->secret;
        }
        $session = (string) session_id();
        if ($session === '') {
            throw new LogicException('No session - the confirmation of the S findings needs the admin session of the preview');
        }
        return $session . constant('AA_ID');
    }

    /** length-prefixed - no separator inside package text can make two lists one */
    private static function fields(string ...$fields): string
    {
        return implode('', array_map(static fn(string $f): string => strlen($f) . ':' . $f, $fields));
    }
}
