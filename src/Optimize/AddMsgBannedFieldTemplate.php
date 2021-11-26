<?php

namespace AA\Optimize;

use function _m;

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AddMsgBannedFieldTemplate extends \AA\Optimize\AddSeoFieldTemplate {

    public static function name(): string { return _m("Add _msg_banned field template"); }

    /** the field identifier  */
    protected function fieldType(): string { return '_msg_banned'; }

    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return [
            'name'       => 'Message Banned',
            'input_help' => 'Message shown to user, when the IP address of the comment sender is on list of banned IP addresses',
        ];
    }
}