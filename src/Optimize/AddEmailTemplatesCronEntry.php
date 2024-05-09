<?php

namespace AA\Optimize;

use AA;
use AA\IO\DB\DB_AA;
use function _m;

/** Adds email templates for Alerts module **/
class AddEmailTemplatesCronEntry extends \AA\Optimize\AbstractOptimize {

    /**  {@inheritdoc} Implementation of \AA\Util\NamedInterface */
    public static function name(): string {
        return _m("Adds email templates for Alerts module");
    }

    /**  {@inheritdoc} Implementation of \AA\Util\NamedInterface */
    public static function description(): string {
        return _m("");
    }

    /** checks if this Optimize class belongs to specified type (like "sql_update") */
    public static function types(): array { return ['sql_update']; }


    /** Test function @return bool */
    public function test(): bool {
        $ret = true;

        $row_count = DB_AA::select1('count', 'SELECT count(*) as count FROM email', [['type', 'alerts welcome']]);

        if ($row_count < 1) {
            $this->message(_m('The e-mail templates are not complete'));
            $ret = false;
        }
        return $ret;
    }

    /** Main update function @return bool */
    public function repair(): bool {
        $std_row = AA::Metabase()->getEmptyRowArray('email');
        $std_row = array_merge($std_row, [
            'lang' => 'en',
            'html' => 1,
        ]);
        unset($std_row['id']); // auto incremented key

        if (!DB_AA::test('email', [['type', 'alerts welcome']])) {
            $this->message(_m('Replacing the e-mail templates'));
            $row = [
                'description' => 'Generic Alerts Welcome',
                'subject'     => 'Welcome to Econnect Alerts',
                'body'        => 'Somebody requested to receive regularly new items from our web site \r\n<a href=\"https://www.ecn.cz\">www.ecn.cz</a>\r\n{switch({_#HOWOFTEN})instant:at the moment they are added\r\n:daily:once a day\r\n:weekly:once a week\r\n:monthly:once a month}.<br>\r\n<br>\r\nYou will not receive any emails until you confirm your subscription.\r\nTo confirm it or to change your personal info, please go to<br>\r\n<a href=\"_#COLLFORM\">_#COLLFORM</a>.<br><br>\r\nThank you for reading our alerts,<br>\r\nThe Econnect team\r\n',
                'header_from' => 'somebody@example.cz',
                'type'        => 'alerts welcome',
            ];
            AA::Metabase()->doInsert('email', array_merge($std_row, $row));
        }

        if (!DB_AA::test('email', [['type', 'alerts alert']])) {
            $row = [
                'description' => 'Generic Alerts Alert',
                'subject'     => '{switch({_#HOWOFTEN})instant:News from Econnect::_#HOWOFTEN digest from Econnect}',
                'body'        => '_#FILTERS_\r\n<br><hr>\r\nTo change your personal info, please go to<br>\r\n<a href=\"_#COLLFORM\">_#COLLFORM</a>.<br><br>\r\nThank you for reading our alerts,<br>\r\nThe Econnect team\r\n',
                'header_from' => 'ecn@example.cz',
                'type'        => 'alerts alert',
            ];
            AA::Metabase()->doInsert('email', array_merge($std_row, $row));
        }

        $this->message(_m('The e-mail templates updated'));
        return true;
    }
}