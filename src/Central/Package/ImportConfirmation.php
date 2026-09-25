<?php

declare(strict_types=1);

namespace AA\Central\Package;

use LogicException;

/** The explicit confirmation of the S findings (design balicek-v1.md § 3.3 point 3, § 7.2 step 2): a
 *  package with code that reads files, calls URLs or carries passwords is written only with the token the
 *  preview handed out, in this admin session, for exactly these findings in exactly this package to
 *  exactly this user.
 *
 *  token() = HMAC-SHA256 under session_id() . AA_ID (as FormToken; the preview and the write run in one
 *  admin session) of
 *  - the user;
 *  - SHA-256 of the package as planned (serialize() of ImportPlan::$package: the reader's model with the
 *    FixUps applied - the same bytes read again give the same model; the new ids are NOT in: the UI may
 *    plan again for the write, the ids then differ);
 *  - the S findings: level, code, path, ref and offset of each, sorted (a `+N more` row counts too).
 *  AA_ID alone is no secret (templates print it - {aa_id}, _#AA_ID___ -, config.php.default ships one);
 *  the session id is: a token is good in the session of the preview only. No session (CLI) and no
 *  explicit secret: token() throws - a package with S findings is not installed there.
 *  A plan without S findings needs none: token() null, confirms() true for any token.
 *  The token binds the confirmation to the findings the user saw in this session. It is NO protection
 *  against CSRF: that is the admin FormToken of the form posting the install, which the caller checks.
 *  Nor does it prove the user read the findings: the UI passes it to Importer::write() only when the
 *  confirmation box of the preview was ticked. */
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
