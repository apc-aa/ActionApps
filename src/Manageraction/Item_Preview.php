<?php

namespace AA\Manageraction;

/** Manager action */
class Item_Preview extends AbstractManageraction {

    /** Name of this Manager's action */
    public function getName(): string {
        return _m('Preview');
    }

    // uses setOpenUrl() method to open preview window
    // perm are always true, so no need to rewrite it
}