<?php

declare(strict_types=1);

namespace AA\Optimize;

/** AA_SETUP_ENABLED in config.php opens admin/setup.php, which creates a superadmin without any login.
 *  It is meant to be on only while an installation is set up, so a forgotten one is reported.
 */
class CheckSetupPage extends AbstractOptimize
{
    public static function name(): string
    {
        return _m('Setup page');
    }

    public static function description(): string
    {
        return _m('Checks that admin/setup.php is switched off (AA_SETUP_ENABLED in config.php).');
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
        return [self::assess(defined('AA_SETUP_ENABLED') && constant('AA_SETUP_ENABLED') === true)];
    }

    public static function assess(bool $enabled): Finding
    {
        if (!$enabled) {
            return new Finding(Finding::OK, _m('The setup page is switched off'));
        }
        return new Finding(
            Finding::ERROR,
            _m('The setup page is switched on - admin/setup.php creates a superadmin without login whenever the permission system finds none'),
            advice: _m("Remove define('AA_SETUP_ENABLED', true); from include/config.php once the superadmin account exists."),
        );
    }
}
