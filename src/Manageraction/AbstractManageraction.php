<?php

namespace AA\Manageraction;

use AA\Manager;
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
abstract class AbstractManageraction extends AA_Storable
{
    public $id;

    protected string $open_url = '';

    protected string $open_url_add = '';

    /** constructor - assigns identifier of action */
    public function __construct($id, string $open_url = '', string $open_url_add = '')
    {
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
    public static function getClassProperties(): array
    {
        //  id             name                              type    multi  persistent - validator, required, help, morehelp, example
        return [
            'id'           => new AA_Property('id', _m('Action ID'), 'text', false, true),
            'open_url'     => new AA_Property('open_url', _m('URL to open'), 'text', false, true),
            'open_url_add' => new AA_Property('open_url_add', _m('Additional URL parameters'), 'text', false, true),
        ];
    }

    /** Name of this Manager's action
     * @return string
     */
    public function getName(): string
    {
        return '';
    }

    /** ID of this Manager's action
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /** Should this action open new window? And if so, which one?
     * @return string
     */
    public function getOpenUrl(): string
    {
        return $this->open_url;
    }

    /** Any addition to url
     * @return string
     */
    public function getOpenUrlAdd(): string
    {
        return $this->open_url_add;
    }

    /** if you want to generate button to manager, redefine this method
     * @return array   ['label'=>, 'attr'=>, 'cond'] */
    protected function getButtonDef(): array
    {
        //return ['label'=>_m('Edit'), attr=>['class'=>'aa-button-edit', 'onclick' =>"return confirm('". _m("Do you really want to delete this object?"). "')"]];
        return [];
    }

    /** True → button is rendered in the per-row "..." overflow menu instead of inline.
     *  Override in subclasses for actions that should not clutter the primary button row.
     */
    public function isSecondaryButton(): bool
    {
        return false;
    }

    /** for adding button to manager redefine rather getButtonDef method */
    public function getButton(string $manager_url, string $id_alias, string $module_alias): array
    {
        $ret = [];
        $b = $this->getButtonDef();
        if ($b && !$b['attr']['class']) {
            $b['attr']['class'] = 'aa-button';
        }
        if ($b) {
            $code = a_href(get_url($manager_url, ["chb[x$id_alias]", 'akce=' . $this->id, "module_id=$module_alias",], true), $b['label'], $b['attr'] ?? []);
            if (array_key_exists('cond', $b)) {
                $code = "{if1:{$b['cond']}:{$code}}";
            }
            $ret = [$this->id => $code];
        }
        return $ret;
    }

    /**
     * @param string $url
     * @param string $add
     */
    public function setOpenUrl(string $url, string $add = ''): void
    {
        $this->open_url     = $url;
        $this->open_url_add = $add;
    }

    /** Perform the action - main function
     *
     * @param Manager         $manager      which Manager
     * @param zids                $item_zids    with what Items
     * @param string|array        $action_param additional parameters to the action
     *
     * @return string|false false is OK, string contain Error message
     */
    public function perform(Manager $manager, zids $item_zids, $action_param): string|false
    {
        return false;
    }

    /** Checks if the user have enough permission to PERFORM the action on items
     *
     * @param Manager $manager
     * @param                     $item_zids
     * @param                     $action_param
     *
     * @return bool
     */
    public function isPerm4Perform($manager, $item_zids, $action_param): bool
    {
        return $this->isPerm4Action($manager);  // in most cases it is enough
    }

    /** Checks if the user have enough permission to allow him/her to SELECT the action
     *
     * @param Manager $manager
     *
     * @return bool
     */
    public function isPerm4Action($manager): bool
    {
        return true;
    }

    /** Does this action have some settings, which should be displayed? */
    public function isSetting(): bool
    {
        return is_callable([$this, 'htmlSettings']);
    }
}
