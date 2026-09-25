<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Util\Secret;

/** AA_SECRET in config.php. Without it AA\Util\Secret derives its keys from the database
 *  password, which is only as secret as that password is strong.
 */
class CheckSecret extends AbstractOptimize
{
    /** shorter database passwords are guessable offline from a signed token */
    private const MIN_DB_PASSWORD = 12;

    public static function name(): string
    {
        return _m('Server secret');
    }

    public static function description(): string
    {
        return _m('Checks that AA_SECRET is set in config.php - the key for login tokens, mail forms and password reset links.');
    }

    public static function types(): array
    {
        return [self::CHECK_BASIC];
    }

    public static function category(): string
    {
        return 'security';
    }

    public static function actions(): array
    {
        return ['test'];
    }

    public function findings(): array
    {
        return [self::assess(defined('AA_SECRET') ? (string) constant('AA_SECRET') : '', (string) constant('DB_PASSWORD'))];
    }

    public static function assess(string $secret, string $db_password): Finding
    {
        if (strlen($secret) >= Secret::MIN_LENGTH) {
            return new Finding(Finding::OK, _m('AA_SECRET is set'));
        }
        $advice = _m("Add to include/config.php: define('AA_SECRET', '...'); with any long random string, e.g. the output of php -r \"echo bin2hex(random_bytes(32));\". Do it when you update AA, so the Centrals authenticate to this AA again only once. Without AA_SECRET a change of DB_PASSWORD has the same effect as a new secret. Centrals keep the database passwords of their AAs in central_conf - another reason for AA_SECRET.");
        if ($secret !== '') {
            return new Finding(Finding::ERROR, _m('AA_SECRET is shorter than %1 bytes, so it is ignored', [Secret::MIN_LENGTH]), advice: $advice);
        }
        if (strlen($db_password) < self::MIN_DB_PASSWORD) {
            return new Finding(Finding::ERROR, _m('AA_SECRET is not set and the database password is short, so login tokens, mail forms and password reset links could be guessed offline'), advice: $advice);
        }
        return new Finding(Finding::WARNING, _m('AA_SECRET is not set - the keys are derived from the database password'), advice: $advice);
    }
}
