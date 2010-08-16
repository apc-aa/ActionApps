<?php
/**
 * File contains definition of linkobj class - handles one link
 *
 * Should be included to other scripts
 *
 * @package Links
 * @version $Id$
 * @author Honza Malik <honza.malik@ecn.cz>
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 */
/*
Copyright (C) 1999, 2000 Association for Progressive Communications
http://www.apc.org/

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program (LICENSE); if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

function problem_report() {
    return func_get_args();
}

function print_problem($problem) {
    global $prblms;
    $prblms[$problem]++;
    echo "<div class=\"problem\">$problem</div>";
}

$GENERAL_TRANSFORM_RULES[] = array ('condition'=>'^1,2$',     'action'=>'add_supergeneral' );
$GENERAL_TRANSFORM_RULES[] = array ('condition'=>'^1,2,4$',   'action'=>'add_supergeneral' );
$GENERAL_TRANSFORM_RULES[] = array ('condition'=>'^1,2,983$', 'action'=>'add_supergeneral' );
$GENERAL_TRANSFORM_RULES[] = array ('condition'=>'^1,2,984$', 'action'=>'add_supergeneral' );
$GENERAL_TRANSFORM_RULES[] = array ('condition'=>'^1,2,985$', 'action'=>'add_supergeneral' );
$GENERAL_TRANSFORM_RULES[] = array ('condition'=>'^1,2,986$', 'action'=>'add_supergeneral' );
$GENERAL_TRANSFORM_RULES[] = array ('condition'=>'^1,2,987$', 'action'=>'add_supergeneral' );
$GENERAL_TRANSFORM_RULES[] = array ('condition'=>'^1,2,.*',   'action'=>'add_general' ); // kormidlo
$GENERAL_TRANSFORM_RULES[] = array ('condition'=>'^1$',       'action'=>'ignore' );
$GENERAL_TRANSFORM_RULES[] = array ('condition'=>'.*',        'action'=>'add' );  // for categories outside Kormidlo

/** Link to Category assignments - stores where links should appear */
class linkassignment {
    var $category;
    var $link;
    var $base;
    var $state;
    var $proposal;
    var $proposal_delete;
    var $priority;
    var $id;

    /** just constructor - variable assignments */
    function linkassignment($category, $link, $base, $state=null, $proposal=null, $proposal_delete=null, $priority=null, $id=null) {
        $this->category        = $category;
        $this->link            = $link;
        $this->base            = $base=='y';
        $this->state           = $state;
        $this->proposal        = $proposal=='y';
        $this->proposal_delete = $proposal_delete=='y';
        $this->priority        = $priority;
        $this->id              = $id;
    }

    /** Copies all assignment data (except ID) to new assignment object
     *  and possibly sets category
     */
    function create_new($cid=null, $priority=null) {
        return new linkassignment(isset($cid) ? $cid : $this->category, $this->link, $this->base, $this->state, $this->proposal, $this->proposal_delete, isset($priority) ? $priority : $this->priority);
    }

    /** Returns if the assignment is OK - if category exists */
    function checkExistence() {
        cattree::global_instance();  // makes sure $cattree instance is created
        global $cattree;
        return $cattree->exists($this->category);
    }

    /** Fixes the state based on state of the base assignment
     *  $base assignment MUST be set before we call this function
     */
    function fixState( $base ) {
        if ( !$base ) {
            $problem[] = problem_report('base_not_defined_for_fixState',$this->category);
            return false;
        }
        $base_state    = $base->getState();
        $base_proposal = $base->isProposal();

        if ( ($this->state == 'hidden') AND ( ($base_state != 'hidden') AND !$base_proposal )) {
            $problem[]      = problem_report('hidden_when_base_is_approved', $this->category);
            $this->state    = 'visible';
        }
        if ( ($this->state == 'hidden') AND !$this->proposal ) {
            $problem[]      = problem_report('hidden_and_not_proposal',$this->category);
            $this->proposal = true;
        }
        if ( ($this->state != 'hidden')  AND ($this->state != 'visible') AND ($this->state != 'highlight') ) {
            $problem[]      = problem_report('unknown_state',$this->state);
            $this->state    = 'visible';
        }
        if ( $this->base AND ($this->state == 'hidden') ) {
            $problem[]      = problem_report('hidden_and_base',$this->category);
            $this->state    = 'visible';
            $this->proposal = true;
        }
        return $problem;
    }


    /** $base_state - state of base assignment - good for repair of some kind of problems */
    function check_problems($base) {
        cattree::global_instance();  // makes sure $cattree instance is created
        global $cattree;
        $problem=array();
        if ( !$cattree->exists($this->category) ) {
            $problem[] = problem_report('assigned_cat_not_exist',$this->category);
        }
        if ( ($this->state == 'hidden') AND ( !$base OR
                                              ($base AND ($base->getState() != 'hidden') AND !$base->isProposal() ))) {
            $problem[] = problem_report($base ? 'hidden_when_base_is_approved': 'hidden_and_no_base',$this->category);
            DoSQL("UPDATE links_link_cat SET state='visible' WHERE a_id='".$this->id."'");
        }
        if ( ($this->state == 'hidden') AND !$this->proposal ) {
            $problem[] = problem_report('hidden_and_not_proposal',$this->category);
        }
        if ( ($this->state != 'hidden')  AND
             ($this->state != 'visible') AND
             ($this->state != 'highlight') ) {
            $problem[] = problem_report('unknown_state',$this->state);
        }
        if ( $this->base AND ($this->state == 'hidden') ) {
            $problem[] = problem_report('hidden_and_base',$this->category);
            DoSQL("UPDATE links_link_cat SET state='visible', proposal='y' WHERE a_id='".$this->id."'");
        }
        return $problem;
    }

    /** get name of general category, if it is general category */
    function General() {
        cattree::global_instance();  // makes sure $cattree instance is created
        global $cattree;
        return $cattree->isGeneral($this->category);
    }

    function downgrade_general() {
        cattree::global_instance();  // makes sure $cattree instance is created
        global $cattree;
        while ( $this->General() ) {
            $this->category = $cattree->getParent($this->category);
        }
        return $this->category;      // it returns false if prent is not found
    }

    function printobj($type='long', $class='linkassignment') {
        cattree::global_instance();  // makes sure $cattree instance is created
        global $cattree;
        echo "<div class=\"$class\">";
        echo "<span title=\"category\">".$this->category."</span>";
        if ( $this->base )            echo "<span title=\"base\"><b>base</b></span>";
        if ( $this->proposal )        echo "<span title=\"base\"><b>proposal</b></span>";
        if ( $this->proposal_delete ) echo "<span title=\"base\"><b>proposal_delete</b></span>";
        echo "<span title=\"state\">".$this->state."</span>";
        echo "<span title=\"cat_name\" ". ($this->General() ? "class=\"general\"":"").">".
              $cattree->getNamePath( $this->category )."</span>";
        echo "<span title=\"id\">(".$this->id.")</span>";
        echo "</div>\n";
    }

    function getCategoryName() {
        cattree::global_instance();  // makes sure $cattree instance is created
        global $cattree;
        return $cattree->getName($this->category);
    }

    function isPerm($action) {
        cattree::global_instance();  // makes sure $cattree instance is created
        global $cattree;
        return IsCatPerm( $action, $cattree->getPath($this->category) );
    }

    /** Returns new assignment to general category */
    function transform($cid,$general) {
        global $GENERAL_TRANSFORM_RULES;
        cattree::global_instance();  // makes sure $cattree instance is created
        global $cattree;
        $path   = $cattree->getPath($cid);
        $action = 'ignore';
        foreach ((array) $GENERAL_TRANSFORM_RULES as $winner => $rule ) {
            $condition = $rule['condition'];
            // find first matching condition and do the $action for this $path
            if (preg_match("/$condition/i", $path )) {
                $action = $rule['action'];
                break;
            }
        }
        switch($action) {
            case 'ignore':           // do not add
                break;
            case 'add':              // normal add (for link outside Kormidlo)
                return $this;
            case 'add_general':      // add to category > general
                $sub_cid = $cattree->ensureExists($cid, $general);
                return $this->create_new(get_if($sub_cid,$cid), Links_GlobalCatPriority($general));
            case 'add_supergeneral': // add to category > group > general
                $super_cid = $cattree->ensureExists($cid,       Links_GlobalCatSuper($general));
                $sub_cid   = $cattree->ensureExists($super_cid, $general);
                return $this->create_new(get_if($sub_cid,$cid), Links_GlobalCatPriority($general));
        }
        return false;
    }

    /** Copies the link to all subcategories based on setting of general
     *  categories.
     *  Returns array of new assignments
     */
    function generalize($general) {
        cattree::global_instance();  // makes sure $cattree instance is created
        global $cattree;

        if ( !$general ) {
            return array($this);
        }
        $ret = array();
        $cur_cid = $this->getCategory();
        while ( $cur_cid ) {
            if ( $new_ass = $this->transform($cur_cid,$general) ) {
                debug('transform(', $cur_cid, $general, ' ---> ', $new_ass);
                $ret[] = $new_ass;
            }
            $cur_cid = $cattree->getParent($cur_cid);
        }
        debug($ret);
        return $ret;
    }


    function dbSave() {
        $db = getDB();
        if ( !$this->category OR !$this->link ) return false;
        $varset = new Cvarset();

        $varset->addArray(array('category_id', 'what_id', 'base', 'state', 'priority', 'proposal', 'proposal_delete'));
        $varset->setFromArray(array ( 'category_id'    => $this->category,
                                      'what_id'        => $this->link,
                                      'base'           => $this->base ? 'y' : 'n',
                                      'state'          => $this->state,
                                      'priority'       => $this->priority,
                                      'proposal'       => $this->proposal ? 'y' : 'n',
                                      'proposal_delete'=> $this->proposal_delete ? 'y' : 'n'));

        $SQL = 'INSERT INTO links_link_cat '. $varset->makeINSERT();
        $db->tquery($SQL);
        freeDB($db);
    }

    function getCategory()          { return $this->category;        }
    function getLink()              { return $this->link;            }
    function getState()             { return $this->state;           }
    function getPriority()          { return $this->priority;        }
    function getId()                { return $this->id;              }
    function isBase()               { return $this->base;            }
    function isProposal()           { return $this->proposal;        }
    function isProposal_delete()    { return $this->proposal_delete; }
    function setLink($lid)          { $this->link = $lid;            }
    function setBase($base)         { $this->base = ($base ? true : false); }
    function setProposal($v)        { $this->proposal        = $v;   }
    function setProposal_delete($v) { $this->proposal_delete = $v;   }
}

/** AssignmentSet */
class assignmentset {
    var $lid;         // link id
    var $assignments; // stores in which categories links should appear

    function assignmentset($lid=null) {
        $this->lid = $lid;
    }

    /** Unset all current values (if any) */
    function clear() {
        unset($this->lid);
        unset($this->assignments);
    }

    /** Adds assignment to the assignmentset */
    function add($category, $link, $base, $state=null, $proposal=null, $proposal_delete=null, $priority=null, $id=null) {
        $this->assignments[] = new linkassignment($category, $link, $base, $state, $proposal, $proposal_delete, $priority, $id);
    }

    /** Load category assignments for current link */
    function load( $force=false ) {
        if ( !$force AND isset($this->assignments) AND is_array($this->assignments) ) return;
        $lid = $this->lid;

        $db = getDB();
        $SQL="SELECT * FROM links_link_cat WHERE what_id='$lid' ORDER BY priority";
        $db->query($SQL);
        while ($db->next_record()) {
            $this->assignments[] = new linkassignment(
                                        $db->f('category_id'),
                                        $db->f('what_id'),
                                        $db->f('base'),
                                        $db->f('state'),
                                        $db->f('proposal'),
                                        $db->f('proposal_delete'),
                                        $db->f('priority'),
                                        $db->f('a_id'));
        }
        freeDB($db);
    }

    /** Load category set from Input Form */
    function loadFromForm() {
        global $selcatCount;   // selcatSelect* and selcatState* are global too!!!
        $new_ass = new assignmentset();
        $base = true;          // mark first assignment as base
        for ( $i=0; $i<$GLOBALS['selcatCount']; $i++) {
            $cid    = $GLOBALS["selcatSelect$i"];
//            $cstate = $GLOBALS["selcatSelect$i"];
            if ( strrpos($cid, ',') ) {   // get category id if in path
                 $cid = GetCategoryFromPath( $path );
            }
            if ( $cid ) {
                $new_ass->add($cid, null, $base);
                $base = false;
            }
        }
        unset($this->assignments);
        // now try to assign the links (set as proposals, where we do not have
        // permissions
        $this->change($new_ass);
    }


    function count() {
        $this->load();
        if ( !isset($this->assignments) ) return 0;
        return count($this->assignments);
    }

    /** Normalizes link assignments - if the link is badly assigned, then it
     *  repairs it.
     */
    function normalize($general=null) {
        $this->downgrade_general();   // Make all General assignments Thematic
        $this->remove_duplicity();
        $base = $this->define_base();
        $this->fix_assignments($base);
    }

    /** Make all assignments Thematic (base > Kormidlo > Enviro > Organizations
     *  are downgraded to the first thematic: base > Kormidlo > Enviro
     */
    function downgrade_general() {
        if ( !isset($this->assignments) ) return;
        foreach ( $this->assignments as $k => $ass ) {
            // do not use $ass - it is just copy of object in $this->assignments
            if ( !$this->assignments[$k]->downgrade_general() ) {
                $remove[$k] = true;  // if you can't downgrade (category have no parent), then mark it for removal
            }
        }
        $this->remove($remove);
    }

    /** Remove assignments to the same category and also assignments
     *  to non existing category
     */
    function remove_duplicity() {
        if ( !isset($this->assignments) ) return;
        debug('remove_duplicity begin:', $this->assignments);
        $already_assigned = array();
        foreach ( $this->assignments as $k => $ass ) {
            // first we check, if the assignment is valid (is category exists)
            if ( !$this->assignments[$k]->checkExistence() ) {
                // category do not exist - remove assignment
                $remove[$k] = true;
                continue;
            }
            // do not use $ass - it is just copy of object in $this->assignments
            $cat = $this->assignments[$k]->getCategory();
            if ( isset($already_assigned[$cat]) ) {
                // already assigned => remove current category (if it is not base)
                if ( !$this->assignments[$k]->isBase() OR
                      $this->assignments[$already_assigned[$cat]]->isBase() ) {
                    // do not remove base (remove it only if there are more
                    // bases which is database error, so we repair it this way)
                    $remove[$k] = true;
                } else {
                    $remove[$already_assigned[$cat]] = true;
                    $already_assigned[$cat] = $k;
                }
            } else {
                $already_assigned[$cat] = $k;
            }
        }
        debug('remove_duplicity remove:', $remove);
        debug('remove_duplicity already_assigned:', $already_assigned);
        $this->remove($remove);
        debug('remove_duplicity end:', $this->assignments);
    }

    /** Set specified caregory assignment as base */
    function set_base($category_id) {
        if ( !isset($this->assignments) ) return false;
        $base = false;   // not found yet
        foreach ( $this->assignments as $k => $ass ) {
            $this->assignments[$k]->setBase( $this->assignments[$k]->getCategory()==$category_id );
        }
        return true;
    }

    /** Check the link, finds (first) base and remove base from others
     *  assignments (if any). If not base defined, base is set to first
     *  assignment
     */
    function define_base() {
        if ( !isset($this->assignments) ) return false;
        $base = false;   // not found yet
        foreach ( $this->assignments as $k => $ass ) {
            if ( $base ) {
                $this->assignments[$k]->setBase(false);
            } elseif ( $ass->isBase() ) {
                // mark base and prepare it for return
                $base = $this->assignments[$k];
            }
        }
        if ( !$base AND (count($this->assignments)>0) ) {
            reset($this->assignments);
            $this->assignments[key($this->assignments)]->setBase(true);
            $base = $this->assignments[key($this->assignments)];
        }
        return $base;
    }

    /** Remove all assignments to categories with 'nolinks' flag set */
    function remove_nolinks() {
        if ( !isset($this->assignments) ) return;
        cattree::global_instance();  // makes sure $cattree instance is created
        global $cattree;
        foreach ( (array)$this->assignments as $k => $ass ) {
            // check, if category is allowed to have links
            if ( $cattree->isNolinks($this->assignments[$k]->getCategory()) ) {
                $remove[$k] = true;
            }
        }
        $this->remove($remove);
    }

    /** Fixes the state based on stete of the base assignment
     *  $base assignment MUST be set before we call this function
     */
    function fix_assignments($base) {
        if ( !isset($this->assignments) OR !isset($base)) return;
        foreach ( $this->assignments as $k => $ass ) {
            $this->assignments[$k]->fixState( $base );
        }
    }

    /** Returns the assignments object, if the link is assigned to the $cid
     *  category. If not, it returns false
     */
    function isAssigned($cid) {
        foreach ( (array)$this->assignments as $ass ) {
            if ( $ass->getCategory() == $cid ) {
                return $ass;
            }
        }
        return false;
    }


    /** Updates link assignment - changes assignment based on new values
     *  (probably from form)
     */
    function change($new) {

debug('assignmentset.change: this', $this);
debug('assignmentset.change: new',  $new);
        $proposal_deletes = array();
        $achieve_base     = false;

        // first look on old assignments to see, what we can delete
        foreach ( (array)$this->assignments as $old_a ) {
            if ( !$new->isAssigned($old_a->getCategory()) AND !$old_a->isPerm(PS_LINKS_DELETE_LINK)) {
                // we do not have permission to delete this link - just mark it for deletion
                $old_a->setProposal_delete(true);
                $proposal_deletes[] = $old_a;
            }
        }

debug('assignmentset.change: setProposal_delete', $proposal_deletes);

        // check if we can add link to the category
        foreach ( (array)$new->assignments as $k => $new_a ) {
            if ( $new_a->isPerm(PS_LINKS_ADD_LINK) ) {
                // we have permission to the category => confirm all changes
                $new->assignments[$k]->setProposal(false);
                $new->assignments[$k]->setProposal_delete(false);
            } else {
                $old_a = $this->isAssigned($new_a->getCategory());
                if ( $old_a ) {
                    // the link is already assigned to the category. We do
                    // not have permissions to add it, so the state remains
                    // the same
                    $new->assignments[$k]->setProposal(       $old_a->isProposal() );
                    $new->assignments[$k]->setProposal_delete($old_a->isProposal_delete() );
                    $new->assignments[$k]->setBase(           $old_a->isBase() );  // the base nmust remain the same for categories, where we have no permissions
                    // this base must be achieved
                    if ( $old_a->isBase() ) {
                        $achieve_base = $new->assignments[$k]->getCategory();
                    }
                } else {
                    // the link is not assigned to the category => propose
                    $new->assignments[$k]->setProposal( true );
                    $new->assignments[$k]->setProposal_delete( false );  // it is by all means new assignment
                }
            }
        }
        $this->assignments = array_merge($proposal_deletes, $new->assignments);

        if ($achieve_base) {
            $this->set_base($achieve_base);
        }

debug('assignmentset.change: nearly done', $this->assignments);

        // now process the state and base
        $this->remove_nolinks();   // remove assignments to nolinks categories
        $base = $this->define_base();
        $this->fix_assignments($base);
debug('assignmentset.change: completely done', $this->assignments);
    }

    function generalize($general) {
        if ( !$general ) { return; }
        $new_ass = array();
        foreach ( (array)$this->assignments as $ass ) {
            $foo = $ass->generalize($general);
            $new_ass = array_merge($new_ass,$foo);
        }
        $this->assignments = $new_ass;
    }

    function define_lid($lid) {
        foreach ( (array)$this->assignments as $k => $ass ) {
            $this->assignments[$k]->setLink($lid);
        }
    }

    function save($general, $lid=null) {
        if ( $lid ) {
            $this->define_lid($lid);
        }

        if ( $general ) {
        debug('before generalize:', $this);
            $this->generalize($general);
        debug('before remove duplicity:', $this);
            $this->remove_duplicity();
        debug('before define base:', $this);
            $base = $this->define_base();
        debug('before fix assignments:', $this);
            $this->fix_assignments($base);
        }
        debug('Save assignments:', $this);
        foreach ( (array)$this->assignments as $ass ) {
            $ass->dbSave();
        }
    }

    /** Removes the assignment identified by its key from this assignmentset
     *  (not from database!!)
     */
    function remove($toremove) {
        if ( isset($toremove)          AND is_array($toremove) AND
             isset($this->assignments) AND is_array($this->assignments) ) {
            foreach ( $this->assignments as $k => $ass ) {
                if ( $toremove[$k] ) continue;
                $newass[] = $ass;
            }
            unset( $this->assignments );
            $this->assignments = $newass;
        }
    }

    function getBase() {
        $this->load();
        foreach ( (array)$this->assignments as $k => $ass ) {
            if ( $ass->isBase() )  return $this->assignments[$k];
        }
        return false;
    }

    function check_problems() {
        $problem = $this->check_assignments();
        $problem = $problem + $this->check_bases();
        return $problem;
    }

    function check_assignments() {
        $this->load();
        $problem = array();
        $base = $this->getBase();
        foreach ( (array)$this->assignments as $ass ) {
            $problem = $problem + $ass->check_problems($base);
        }
        return $problem;
    }

    function check_bases() {
        $this->load();
        if ( isset($this->assignments) )  {
            $bases = 0;
            foreach ( $this->assignments as $ass ) {
                if ( $ass->isBase() ) $bases++;
            }
            if ( $bases==0 ) return problem_report('no_base');
            if ( $bases> 1 ) return problem_report('more_bases',$bases);
        }
        return array();
    }
}

$LINK_DATA_FIELDS = array(
    // we need to define dbfield for name (we can't use 'name' as fieldname
    // because it makes problems in javascript if the input name is 'name'
    // (input_field.form.name didn't return form name then!)
    getAAField(array( 'varname'=>'aa_name',       'name'=>_m('Page name'),           'required'=>true, 'dbfield'=>'name')),
    getAAField(array( 'varname'=>'original_name', 'name'=>_m('Original page name'))),
    getAAField(array( 'varname'=>'description',   'name'=>_m('Description'))),
    getAAField(array( 'varname'=>'initiator',     'name'=>_m('Author'),              'valid'=>'email')),
    getAAField(array( 'varname'=>'url',           'name'=>_m('Url'),                 'valid'=>'url', 'required'=>true)),
//    getAAField(array( 'varname'=>'rate',          'name'=>_m('Rate'),                'valid'=>'float')),
    getAAField(array( 'varname'=>'type',          'name'=>_m('Link type'))),
    getAAField(array( 'varname'=>'org_city',      'name'=>_m('City'))),
    getAAField(array( 'varname'=>'org_street',    'name'=>_m('Street'))),
    getAAField(array( 'varname'=>'org_post_code', 'name'=>_m('Post code'))),
    getAAField(array( 'varname'=>'org_phone',     'name'=>_m('Phone'))),
    getAAField(array( 'varname'=>'org_fax',       'name'=>_m('Fax'))),
    getAAField(array( 'varname'=>'org_email',     'name'=>_m('E-mail'))),
    getAAField(array( 'varname'=>'note',          'name'=>_m('Editor\'s note')))
);

$LINK_INNER_FIELDS = array('id', 'rate', 'votes', 'plus_votes', 'created_by', 'edited_by','checked_by','created','last_edit','checked','voted','flag','folder','validated','valid_codes','valid_rank');

/** Linkobj */
class linkobj {
    var $lid;         // link id
    var $data;        // link data
    var $region;      // array of regions
    var $language;    // array of languages 'lang =>1'
    var $assignments; // stores in which categories links should appear
    var $changes;     // proposed data changes

    function linkobj($lid=null) {
        $this->lid         = $lid;
        $this->assignments = new assignmentset($lid);
    }

    /** unset all current values (if any) */
    function clear() {
        unset($this->lid);
        unset($this->data);
        unset($this->region);
        unset($this->language);
        unset($this->changes);
        $this->assignments->clear();
    }

    function numberOfAssignments() {
        if ( $this->assignments ) {
            return $this->assignments->count();
        }
        return 0;
    }

    /** load link data from database (if not loaded already) */
    function load( $force=false ) {
        if ( !$this->lid ) return;
        if ( !$force AND isset($this->data) AND is_array($this->data) ) return;

        $db = getDB();

        $lid = $this->lid;
        $this->clear();     // unset all current values (if any)
        $this->lid = $lid;
        $this->assignments = new assignmentset($lid);

        // store link data
        $this->data     = GetTable2Array("SELECT * FROM links_links
                                           WHERE id='$lid'",
                                         'aa_first', 'aa_fields');

        // rename 'name' to 'aa_name' (see above $LINK_DATA_FIELDS definition)
        $this->data['aa_name'] = $this->data['name'];
        unset($this->data['name']);

        // link region info
        $this->region   = GetTable2Array("SELECT region_id FROM links_link_reg
                                           WHERE link_id='$lid'",
                                         'region_id', 'aa_mark');
        // link language info
        $this->language = GetTable2Array("SELECT lang_id FROM links_link_lang
                                           WHERE link_id='$lid'",
                                         'lang_id', 'aa_mark');

        // lookup - changes proposal
        $SQL= "SELECT proposal_link_id, rejected FROM links_changes WHERE changed_link_id='$lid'";
        $db->tquery($SQL);
        while ($db->next_record()) {
            debug('changes Record:', $db->Record);
            $ch_link = new linkobj($db->f('proposal_link_id'));
            $ch_link->load();
            $this->changes[] = array( 'rejected'=> $db->f('rejected')=='y',
                                      'link'    => $ch_link);
        }
        debug('load: changes result:', $this->changes);
        freeDB($db);

        // clean the link - remove all general categories (general categories
        // are added at the end (on storing to the database))
        $this->normalize();
        debug('load: changes after normalize()', $this);
    }

    /** Fill link data from the form
     *  Returns result of validation of the form data*/
    function loadFromForm(&$err) {
        global $auth, $LINK_DATA_FIELDS;
        $ret = true;
        // load all form data
        foreach ( $LINK_DATA_FIELDS as $aafield ) {
            $field_value = stripslashes(trim($GLOBALS[$aafield->varname()]));
            $ret &= $aafield->validate($field_value, $err);
            $this->data[$aafield->varname()] = $field_value;
        }
        // add default data
        $now_date = now();
        if ( $GLOBALS['folder'] ) {
            $this->data['folder']     = max(1, trim($GLOBALS['folder']));
        }
        $this->data['edited_by']  = $auth->auth["uid"];
        $this->data['last_edit']  = $now_date;
        $this->data['checked_by'] = $auth->auth["uid"];
        $this->data['checked']    = $now_date;
        // fill regions and languages from the form
        foreach ( (array)$GLOBALS['reg'] as $r ) {
            $this->region[$r] = true;
        }
        // and now languages...
        foreach ( (array)$GLOBALS['lang'] as $l ) {
            $this->language[$l] = true;
        }
        // and now get the categories
        $this->assignments = new assignmentset();
        $this->assignments->loadFromForm();

        debug("After load:", $this);

        // check link for errors -
        // - remove duplicity, downgrade general (if present), define base, ...
        $this->normalize();
        return $ret;
    }

    /** Counts Checksum for link's data (not assignments). Used for comarison. */
    function dataChecksum() {
        global $LINK_DATA_FIELDS;
        $str = '';
        // create string of all values
        foreach ( $LINK_DATA_FIELDS as $aafield ) {
            $str .= trim($this->data[$aafield->varname()]);
        }
        // add also languages and regions
        foreach ( (array)$this->language as $lang => $v ) {
            $str .= ($v ? $lang : '');
        }
        foreach ( (array)$this->region as $reg => $v ) {
            $str .= ($v ? $reg : '');
        }
        return md5($str);  // return md5 checksum
    }

    function addProposal($proposal) {
        $proposal->assignments->clear(); // proposal can't have assignments
        unset($proposal->lid);           // proposal will have new id
        $proposal->data['folder']=1;     // proposal are not in trash/holding or so

        $this->changes[] = array( 'rejected'=> false,
                                  'link'    => $proposal );
        $this->removeTheSameProposals();
    }

    /** if the link have proposal, it removes the proposals which are the same
     *  as any other proposal (or base link)
     */
    function removeTheSameProposals() {
        $marked[$this->dataChecksum()] = true;  // mark current link
        foreach ( (array)$this->changes as $change ) {
            $md5 = $change['link']->dataChecksum();
            if ( !$marked[$md5] ) {
                $new_changes[] = $change;
                $marked[$md5] = true;      // mark it
            }
        }
        $this->changes = $new_changes;
    }

    /** Changes the data of the link */
    function changeData($new_link) {
        $this->data     = $new_link->data;
        $this->language = $new_link->language;
        $this->region   = $new_link->region;
        unset( $this->changes );   // all changes are removed
    }

    /** Normalizes link assignments - remove all general categories (general
     *  categories are added at the end (on storing the link to the database).
     *  If the link is badly assigned, then it repairs it
     */
    function normalize() {
        if ($this->numberOfAssignments() > 0) {
            $this->assignments->normalize($this->isGeneral());
            $this->removeTheSameProposals();
        }
    }

    /** Do current logged user has permission for $action to this link? */
    function isPerm($action) {
        if ($this->numberOfAssignments() > 0) {
            $base = $this->assignments->getBase();
            return $base ? $base->isPerm($action) : false;
        }
        // this else option added for changing the unassigned links
        cattree::global_instance();  // makes sure $cattree instance is created
        global $cattree, $r_state;
        return IsCatPerm( $action, $cattree->getPath($r_state['tree_start_path']) );
    }

    /** Get name of general category, if it is general category */
    function isGeneral() {
        return Links_IsGlobalCategory($this->data['type']);
    }

    /** Changes this link according to new link (new link is probably submitted
     *  change from user)
     */
    function tryChange($new_link) {
        if ( $this->isPerm(PS_LINKS_EDIT_LINKS) ) {
            $this->changeData($new_link);
            $ret = 'CHANGED';
        } else {
            $this->addProposal($new_link);
            $ret = 'PROPOSAL';
        }

        if (!$this->assignments) { $this->assignments = new assignmentset(); }

        $this->assignments->change($new_link->assignments);
        debug('TryChange() - ass done',$this);
        return $ret;
    }

    // returns if the link is empty or if it have filled any data
    function isEmpty() {
        global $LINK_DATA_FIELDS;
        if ( isset($this->data) AND is_array($this->data) ) {
            // search for any data in link data
            foreach ( $LINK_DATA_FIELDS as $aafield ) {
                if ( trim($this->data[$aafield->varname()]) ) {
                    return false;
                }
            }
        }
        return true;
    }


    function save() {
        global $auth, $varset, $r_state, $LINK_DATA_FIELDS, $LINK_INNER_FIELDS, $debug;

        debug("SAVE: ", $this);
        $this->load();     // make sure the data are filled (used mainly for changes)
debug("SAVE (loaded): ", $this);

        // if the link is empty (no data filled), do not store it to the database
        if ( $this->isEmpty() ) {
            return false;
        }


        $varset = new Cvarset();
        $db = getDB();

        debug("link->save() - Before normalization:", $this);
        // remove duplicity in assignments, ...
        $this->normalize();

        debug("link->save() - After normalization:", $this);

        // save data
        foreach ( $LINK_DATA_FIELDS as $aafield ) {
            $varset->add($aafield->getDbfield(), 'text', $this->data[$aafield->varname()]);
        }
        foreach ( $LINK_INNER_FIELDS as $innerfield ) {  // copy current data, if present
            if ( $this->data[$innerfield] ) {
                $varset->add($innerfield, 'text', $this->data[$innerfield]);
            }
        }

        $now_date = now();
        $varset->add("checked_by", "quoted", $auth->auth["uid"]);
        $varset->add("checked",    "date",   $now_date);
        $varset->add("edited_by",  "quoted", $auth->auth["uid"]);
        $varset->add("last_edit",  "quoted", $now_date);
        if ( $this->data['folder'] ) {
            $varset->add("folder",  "quoted", max(1,$this->data['folder']));
        }

        if ( !$this->lid ) {  // insert
            $r_state['linkedit']['last_autors_email'] = $this->data['initiator'];
            $r_state['linkedit']['last_rate']         = $this->data['rate'];
            $varset->add("created_by", "quoted", $auth->auth["uid"]);
            $varset->add("created",    "date",   $now_date);
            // default setting for votes
            $varset->add("rate",       "number", 4);
            $varset->add("votes",      "number", 2);
            $varset->add("plus_votes", "number", 1);

            $db->tquery("INSERT INTO links_links ". $varset->makeINSERT());
            // get inserted link id
            $db->tquery( "SELECT LAST_INSERT_ID() as id" );
            if (!$db->next_record()) {
                huh("Error - Last inserted ID is lost");
                exit;
            }
            $this->lid = $db->f('id');
        } else {            // insert
            $varset->addkey("id",       "number", $this->lid);
            $db->tquery($varset->makeINSERTorUPDATE('links_links'));
            // now you can delete all old link settings
            $this->dbDeleteSetting();
        }

        // Regions
        foreach ( (array)$this->region as $id => $foo ) {
            $SQL = 'INSERT INTO links_link_reg (link_id, region_id) VALUES ('.$this->lid.", $id)";
            $db->tquery($SQL);
        }

        // Languages
        foreach ( (array)$this->language as $id => $foo ) {
            $SQL = 'INSERT INTO links_link_lang (link_id, lang_id) VALUES ('.$this->lid.", $id)";
            $db->tquery($SQL);
        }

        // Proposals
        foreach ( (array)$this->changes as $change ) {
            if ( !$change->rejected ) {  // do not save rejected (rejected is old feature - now unsupported (never was))
                $ch_id = $change['link']->save();
                if ( $ch_id ) {   // change link could be empty => not stored => skip
                    $SQL = 'INSERT INTO links_changes (changed_link_id, proposal_link_id, rejected) VALUES ('.$this->lid.', '.$ch_id.', \'n\')';
                    $db->tquery($SQL);
                }
            }
        }

        debug('<br>before save assignments:',$this);
        // Categories
        if ($this->numberOfAssignments() > 0) {
            $this->assignments->save($this->isGeneral(), $this->lid);
        }
        freeDB($db);
        return $this->lid;
    }

/*
    function old_printobj($type='long', $class='linkobj') {
        //  $this->load();
        echo "<div class=\"$class\">";
        if ( isset($this->data) AND is_array($this->data) ) {
            if ( $type=='long' ) {
                echo '<span title="folder" '.(Links_IsGlobalCategory($this->data['type']) ? 'class="general"': '').'>'.$this->data['folder'].' </span>';
                foreach ( $this->data as $k => $v ) {
                    echo "<span title=\"$k\" nowrap>$v</span>";
                }
            } else {
                echo "<span title=\"lid\">".$this->lid."</span>";
                echo "<span title=\"name\">".$this->data['name']."</span>";
            }
        }
        if ( ($type=='long') AND isset($this->language) AND is_array($this->language) ) {
            echo "<span title=\"language\">";
            foreach ( $this->language as $k => $v ) {
                echo "$k<br>";
            }
            echo "</span>";
        }
        if ( ($type=='long') AND isset($this->region) AND is_array($this->region) ) {
            echo "<span title=\"language\">";
            foreach ( $this->region as $k => $v ) {
                echo "$k<br>";
            }
            echo "</span>";
        }
        echo "</div>\n";
        if ( $type=='long' ) {
            foreach ( (array)$this->assignments as $ass ) {
                $ass->printobj($type,$class."ass");
            }
        }
        foreach ( (array)$this->changes as $chang ) {
            $chang['link']->printobj($type,$class."change");
        }
    }
*/

    /**      */
    function check_problems() {
        $this->load();
        $this->problem = $this->assignments->check_problems(); // array
        $this->check_changes();
        $this->check_general_categories();
    }

    function report_problems() {
        $this->load();
        if ( count($this->problem)<1 ) {
            $this->printobj('short', 'linkok');
        } else {
            echo "<div class=\"link\">";
            foreach ( $this->problem as $v ) {
                print_problem($v[0]);
            }
            $this->printobj('long', 'linkerr');
            echo "</div>";
        }
    }

    function check_changes() {
        $this->load();
        foreach ( (array)$this->changes as $change ) {
            if ( $change['rejected'] ) {
                $this->problem[] = problem_report('rejected_change');
            }
            $ch_ass = $change['link']->numberOfAssignments();
            if ( $ch_ass > 0 ) {
                $this->problem[] = problem_report('changes_have_assignments',$ch_ass);
            }
        }
    }

    function check_general_categories() {
        $this->load();

        // TODO - next row is wrong - assignments is not array
        if ( !isset($this->assignments) OR !is_array($this->assignments) ) return;
        cattree::global_instance();  // makes sure $cattree instance is created
        global $cattree;
        $names    = array();
        $thematic = 0;
        $other    = 0;
        foreach ( $this->assignments as $ass ) {
            $general = $ass->General();   // get name of general category, if it is general category
            $path    = $cattree->getPath($ass->getCategory());
            if ( $general ) {
                $names[$general]++;
            } elseif (  substr($path,0,12) == '1,2,4,39,71,' ) {  //Ekolink statni sprava
                $names['Státní správa a samospráva']++;
            } elseif ( (substr($path,0,4) == '1,2,') AND
                       (substr($path,0,9) != '1,2,1222,') AND
                       (substr($path,0,9) != '1,2,1223,') AND
                       (substr($path,0,9) != '1,2,1224,') ) {
                $thematic++;
            } else {
                $other++;
            }
        }
        if ( count($names)>1 ) {
            $this->problem[] = problem_report('category_assigned_to_more_general_categories',$names,$thematic);
        }
        if ( $thematic AND $this->data['type'] ) {
            $this->problem[] = problem_report('general_link_assigned_to_thematic',$names,$thematic);
        }
        if ( (count($names)==1) AND !$this->data['type'] AND !$thematic) {
            $SQL = "UPDATE links_links SET type='".key($names)."' WHERE id='".$this->lid."'";
            $this->problem[] = problem_report('thematic_link_assigned_in_general_repairable',$names,$thematic);
            echo "<br>$SQL";
            $db = getDB();
            $db->tquery($SQL);
            freeDB($db);
        }
        elseif ( (count($names)>0) AND !$this->data['type'] ) {
            $this->problem[] = problem_report('thematic_link_assigned_in_general',$names,$thematic);
      }
    }


    /** Deletes link itself. This function should be used only in conjunction
     *  with deleteSetting()
     */
    function dbDeleteData() {
        $db = getDB();
        $lid = $this->lid;
        if ( !$lid ) return;
        // delete the main link data
        $db->tquery("DELETE from links_links where id='$lid'");
        freeDB($db);
    }

    /** Delete all link data except link itself
     * (used for update -> not change link_id)
     */
    function dbDeleteSetting() {
        $db = getDB();

        $lid = $this->lid;
        if ( !$lid ) return;

        $to_delete = GetTable2Array("SELECT proposal_link_id from links_changes
                                      WHERE changed_link_id='$lid'",
                                      '', 'proposal_link_id');
        // remove links changes
        $db->tquery("DELETE FROM links_changes   WHERE changed_link_id='$lid'");
        // remove links changing
        $db->tquery("DELETE FROM links_changes   WHERE proposal_link_id='$lid'");
        // remove assignments
        $db->tquery("DELETE from links_link_cat  WHERE what_id='$lid'");
        // remove languages assignments
        $db->tquery("DELETE from links_link_lang WHERE link_id='$lid'");
        // remove languages assignments
        $db->tquery("DELETE from links_link_reg  WHERE link_id='$lid'");

        foreach ( (array)$to_delete as $link2del ) {
            $link_2_delete = new linkobj($link2del);
            $link_2_delete->dbDelete();
        }
        freeDB($db);
    }

    /** Deletes all the link including assignments, changes, ... */
    function dbDelete() {
        $this->dbDeleteSetting();
        $this->dbDeleteData();
    }
}
?>
