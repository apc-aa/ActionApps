<?php
/**
 *
 * PHP version 7.2+
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
 */

namespace AA\Util;


use AA_Serializable;

/**
 * Class Paramwizard
 * @package AA\Util
 */
class Paramwizard {

    /** getParamWizardArr - get array for ParamWizard from widgets
     *  (replaces array in constants_param_wizard.php)
     *  called as AA\Widget\Widget::getParamWizardArr('fld');
     */
    public function getParamWizardArr($type, $class_mask) {
        $ret = [];

        /** @var NamedInterface $class */
        if (class_exists($class = AA_Serializable::constructClassName($type, $class_mask))) {
            $ret['name'] = $class::name();
            $ret['desc'] = $class::description();
            $ret['params'] = [];

            $props = $class::getClassProperties();
            if (key($props) === 'const') { // const or slice is not presented in popup
                array_shift($props);
            }
            foreach ($props as $prop) {
                $ret['params'][] = [
                    'name'    => $prop->getName(),
                    'desc'    => $prop->getHelp(),
                    'type'    => $prop->getType(),
                    'example' => $prop->getExample(),
                ];
            }
        }
        return $ret;
    }
}