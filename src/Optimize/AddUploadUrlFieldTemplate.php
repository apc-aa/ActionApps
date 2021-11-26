<?php

namespace AA\Optimize;

use function _m;

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AddUploadUrlFieldTemplate extends \AA\Optimize\AddSeoFieldTemplate {

    public static function name(): string { return _m("Add _upload_url field template"); }

    /** the field identifier  */
    protected function fieldType(): string { return '_upload_url'; }

    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return [
            'name'       => 'Upload URL',
            'input_pri'  => '100',
            'input_help' => 'If you want to have your files stored in your domain, then you can create symbolic link from https://yourdomain.org/upload -> https://your.actionapps.org/IMG_UPLOAD_PATH and fill there "https://yourdomain.org/upload". The url stored in AA will be changed (The file is stored still on the same place).',
        ];
    }
}