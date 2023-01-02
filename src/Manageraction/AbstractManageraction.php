<?php

namespace AA\Manageraction;

use AA_Property;
use AA_Storable;
use zids;

/** AA\Manageraction\ManageractionXY - Item manager actions. Just create new class and assign
 *  it to your manager
 *
 *  We are extending AA_Storable, because we want to get the state form some
 *  actions. Action selectbox is able to display settings by AJAX call, where
 *  we need to pass all parameters of the object
 */
abstract class AbstractManageraction extends AA_Storable {

    var $id;
    /** @var string */
    var $open_url;
    /** @var string */
    var $open_url_add;
    /** @var string */

    /** constructor - assigns identifier of action */
    function __construct($id, $open_url = null, $open_url_add = null) {
        $this->id = $id;
        if ($open_url) {
            $this->setOpenUrl($open_url, $open_url_add);
        }
    }

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     *
     *  We are extending AA_Storable, because we want to get the state form some
     *  actions. Action selectbox is able to display settings by AJAX call, where
     *  we need to pass all parameters of the object
     * @return array
     */
    static function getClassProperties(): array {          //  id             name                              type    multi  persistent - validator, required, help, morehelp, example
        return [
            'id'           => new AA_Property('id', _m('Action ID'), 'text', false, true),
            'open_url'     => new AA_Property('open_url', _m('URL to open'), 'text', false, true),
            'open_url_add' => new AA_Property('open_url_add', _m('Additional URL parameters'), 'text', false, true),
        ];
    }

    /** Name of this Manager's action
     * @return string
     */
    function getName() { return ''; }

    /** ID of this Manager's action
     * @return string
     */
    function getId() { return $this->id; }

    /** Should this action open new window? And if so, which one?
     * @return string
     */
    function getOpenUrl() { return $this->open_url; }

    /** Any addition to url
     * @return string
     */
    function getOpenUrlAdd() { return $this->open_url_add; }

    /** if you want to generate button to manager, redefine this method */
    protected function getButtonDef(): array {
        //return ['label'=>_m('Edit'), 'class'=>'aa-button-edit', attr=>['onclick' =>"return confirm('". _m("Do you really want to delete this object?"). "')"]];
        return [];
    }

    /** for adding button to manager redefine rather getButtonDef method */
    public function getButton(string $manager_url, string $id_alias, string $module_alias): array {
        return ($b = $this->getButtonDef()) ? [
            $this->id => a_href(get_url($manager_url, [
                "chb[x$id_alias]",
                'akce=' . $this->id,
                "module_id=$module_alias",
            ], true), $b['label'], $b['class'] ?: 'aa-button', $b['attr'] ?: []),
        ] : [];
    }

    /**
     * @param string      $url
     * @param string|null $add
     */
    function setOpenUrl($url, $add = null) {
        $this->open_url     = $url;
        $this->open_url_add = $add;
    }

    /** Perform the action - main function
     * @param \AA\Manager         $manager      which Manager
     * @param zids                $item_zids    with what Items
     * @param array               $action_param additional parameters to the action
     * @return string|false false is OK, string contain Error message
     */
    function perform(\AA\Manager $manager, zids $item_zids, $action_param) {
        return false;
    }

    /** Checks if the user have enough permission to PERFORM the action on items
     * @param \AA\Manager $manager
     * @param                     $item_zids
     * @param                     $action_param
     * @return bool
     */
    function isPerm4Perform($manager, $item_zids, $action_param) {
        return $this->isPerm4Action($manager);  // in most cases it is enough
    }

    /** Checks if the user have enough permission to allow him/her to SELECT the action
     * @param \AA\Manager $manager
     * @return bool
     */
    function isPerm4Action($manager) {
        return true;
    }

    /** Does this action have some settings, which should be displayed? */
    function isSetting() {
        return is_callable([$this, 'htmlSettings']);
    }
}