<?php

namespace AA\Manageraction;

use AA;
use AA\Manager;
use zids;

/** Manager action Central_Sqlupdate - Runs sql_update.php script on selected
 *  AAs
 */
class Central_Sqlupdate extends AbstractManageraction {

    /** sql_update action - dotest|update*/
    var $update_action;

    /** Constructor - fills the information about the optimize method */
    function __construct($id, $action) {
        $this->update_action = $action;
        parent::__construct($id);
    }

    /** Name of this Manager's action */
    public function getName(): string {
        return _m('Update DB (sql_update)') . ' - ' . $this->update_action;
    }

    /** @inheritDoc */
    function perform(Manager $manager, zids $item_zids, $action_param) {
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
            $status   = substr($response, 0, 3);
            $toggle   = '{htmltoggle:&gt;&gt;:' . QuoteColons($file) . ':&lt;&lt;:' . QuoteColons($response) . '}';
            $ret      .= AA::Un($status . ' ' . $toggle);
        }
        freeDB($db);
        return $ret;                                     // OK - no error
    }

    /** Checks if the user have enough permission to perform the action
     * @param \AA\Manager $manager
     * @return bool
     */
    function isPerm4Action($manager) {
        return IsSuperadmin();
    }
}