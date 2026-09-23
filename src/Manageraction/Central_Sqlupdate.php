<?php

namespace AA\Manageraction;

use AA\Central\RemoteHtml;
use AA\Manager;
use zids;

/** Manager action Central_Sqlupdate - Runs sql_update.php script on selected
 *  AAs
 */
class Central_Sqlupdate extends AbstractManageraction
{
    /** sql_update action - dotest|update*/
    public $update_action;

    /** Constructor - fills the information about the optimize method */
    public function __construct($id, $action)
    {
        $this->update_action = $action;
        parent::__construct($id);
    }

    /** Name of this Manager's action */
    public function getName(): string
    {
        return _m('Update DB (sql_update)') . ' - ' . $this->update_action;
    }

    /** @inheritDoc */
    public function perform(Manager $manager, zids $item_zids, $action_param): string|false
    {
        $item_ids = $item_zids->shortids();

        if (count($item_ids) < 1) {
            return false;                                     // OK - no error
        }
        set_time_limit(360);
        $db = getDB();

        $SQL = "SELECT * FROM central_conf WHERE id IN ('" . join_and_quote("','", $item_ids) . "')";
        $db->query($SQL);
        $ret = '';
        while ($db->next_record()) {
            $params   = 'dbpw5=' . substr($db->f('db_pwd'), 0, 5) . '&silent=1&fire=1&' . $this->update_action . '=1';
            $file     = $db->f('AA_HTTP_DOMAIN') . $db->f('AA_BASE_DIR') . "service/sql_update.php?$params";
            $response = file_get_contents($file);
            $response = ($response === false) ? '<p>' . safe(_m('Request failed')) . '</p>' : $response;
            // the remote answer is untrusted HTML - never through AA::Un() or into the page itself
            $ret      .= RemoteHtml::sandboxed($db->f('ORG_NAME') . ' (' . $db->f('AA_HTTP_DOMAIN') . $db->f('AA_BASE_DIR') . ')', $response);
        }
        freeDB($db);
        return $ret;                                     // OK - no error
    }

    /** Checks if the user have enough permission to perform the action
     *
     * @param \AA\Manager  $manager
     *
     * @return bool
     */
    public function isPerm4Action($manager): bool
    {
        return IsSuperadmin();
    }
}
