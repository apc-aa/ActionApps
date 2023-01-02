<?php

namespace AA\Manageraction;

/** Manager action */
class Item_Preview extends AbstractManageraction {

    /** Name of this Manager's action */
    function getName() {
        return _m('Preview');
    }

    // uses setOpenUrl() method to open preview window
    // perm are always true, so no need to rewrite it
}