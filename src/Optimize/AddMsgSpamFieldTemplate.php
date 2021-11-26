<?php

namespace AA\Optimize;

use function _m;

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AddMsgSpamFieldTemplate extends \AA\Optimize\AddSeoFieldTemplate {

    public static function name(): string { return _m("Add _msg_spam field template"); }

    /** the field identifier  */
    protected function fieldType(): string { return '_msg_spam'; }

    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return [
            'name'       => 'Message Spam',
            'input_help' => 'Message shown to user, when the SPAM detection filter in the comments decides the post is spam',
        ];
    }
}