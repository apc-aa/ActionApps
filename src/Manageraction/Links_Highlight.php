<?php

namespace AA\Manageraction;

use AA;
use AA\Manager;
use zids;

/** Marks given link as checked (means visited and content checked) */
class Links_Highlight extends AbstractManageraction
{
    protected $highlight;

    /** Constructor - fills the information about the target bin */
    public function __construct($id, $highlight)
    {
        parent::__construct($id);
        $this->highlight = $highlight ? 'highlight' : 'visible';
    }

    /** Name of this Manager's action */
    public function getName(): string
    {
        return ($this->highlight === 'highlight') ? _m('Highlight Link') : _m('Dehighlight Link');
    }

    /** @inheritDoc */
    public function perform(Manager $manager, zids $item_zids, $action_param): string|false
    {
        global $r_state;
        foreach ($item_zids as $lid) {
            if (!($aid = Links_GetAsociationId($lid, $r_state['cat_id']))) {
                continue;    // return _m('Can\'t find link in given category');  // error
            }
            AA::Metabase()->doUpdate('links_link_cat', ['a_id' => $aid, 'state' => $this->highlight]);
        }
        return false;                                     // OK - no error
    }

    /** Checks if the user have enough permission to perform the action
     *
     * @param \AA\Manager  $manager
     *
     * @return bool
     */
    public function isPerm4Action($manager): bool
    {
        return !$GLOBALS['r_state']['show_subtree'] && IsCatPerm(PS_LINKS_HIGHLIGHT_LINK, $GLOBALS['r_state']['cat_path']);
    }
}
