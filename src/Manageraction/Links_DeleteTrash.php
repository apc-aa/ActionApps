<?php

namespace AA\Manageraction;

use AA\Manager;
use zids;

/**  */
class Links_DeleteTrash extends AbstractManageraction {

    /** @inheritDoc */
    function perform(Manager $manager, zids $item_zids, $action_param) {
        $db = getDB();

        // first delete the trashed links
        $SQL = 'DELETE links_links FROM links_links WHERE links_links.folder=3';
        $db->query($SQL);

        // now we fix all incionsistences in the database (most of them caused by previous deletion)

        // delete all proposals which do not have its counterpart in list of links
        $SQL = 'DELETE p FROM links_links AS p INNER JOIN links_changes ON p.id = links_changes.proposal_link_id LEFT JOIN links_links AS s ON s.id = links_changes.changed_link_id WHERE s.id IS NULL';
        $db->query($SQL);

        // delete propasal - link relation
        $SQL = 'DELETE links_changes FROM links_changes LEFT JOIN links_links ON links_changes.changed_link_id = links_links.id WHERE links_links.id IS NULL';
        $db->query($SQL);

        // delete all unmatched language relations
        $SQL = 'DELETE links_link_lang FROM links_link_lang LEFT JOIN links_links ON links_link_lang.link_id = links_links.id WHERE links_links.id IS NULL';
        $db->query($SQL);

        // delete all unmatched region relations
        $SQL = 'DELETE links_link_reg FROM links_link_reg LEFT JOIN links_links ON links_link_reg.link_id = links_links.id WHERE links_links.id IS NULL';
        $db->query($SQL);

        // and now remove all category assignments for deleted links
        $SQL = 'DELETE links_link_cat FROM links_link_cat LEFT JOIN links_links ON links_link_cat.what_id = links_links.id WHERE links_links.id IS NULL';
        $db->query($SQL);

        freeDB($db);
        return false;     // OK - no errors
    }

    /** Checks if the user have enough permission to perform the action
     * @param \AA\Manager $manager
     * @return bool
     */
    function isPerm4Action($manager) {
        return IsSuperadmin();
    }
}