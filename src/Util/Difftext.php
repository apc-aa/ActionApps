<?php

namespace AA\Util;

use Horde_Text_Diff;
use Horde_Text_Diff_Renderer_Inline;

/**
 *
 * PHP versions 5.3, ...
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
 * Uses: Horde - framework/Text_Diff/Diff.php.
 * Thanks!
 */
// tested also finediff - https://github.com/gorhill/PHP-FineDiff/blob/master/finediff.php version from 29.1.2013, but it do not work for UTF-8 strings. Honza

// Usage: AA\Util\Difftext\AA_Difftext::renderHtml($from_text, $to_text);
// ------------------------------------------------------
// $from_text = "děkujeme, že jste otevřeli tuto Stránku s odhodlánim věnovat alespoň 15 minut vyplnění dotazníku. Věřte, že tak významně přispíváte k diskusi o budoucnosti OSB a Prahy 2. Jde o interní podklad k další diskusi v rámci sdružení ke čtyřem tematickým okruhům.
// U odpovědí, kde je více možností (jsou ote v závěru uvést své jméno a příjmení a e-mailovou adresu, pčípadně členský číslo, cí známku 1 -5. U odpověpokud jej znáte.
// Výsledky budou pouze jako souhrnné a to již.
// toto je druhy text\nxx\nabcd";
//
// $to_text = "děkujeme, že jste otevřeli tuto stránku s odhodlánim věnovat alespoň 15 minut vyplnění dotazníku. Věřte, že tak významně přispíváte k diskusi o budoucnosti OSB a Prahy 2. Jde o interní podklad k další diskusi v rámci sdružení ke čtyřem tematickým okruhům.
// U odpovědí, kde je více možností (jsou označeny malým písmenem) přiřaďte hodnotící známku 1 -5. U odpovědí označených velkým písmenem volte jen jednu možnost. Budem Vám vděčni i za stručná vyjádření, podněty a návrhy, pro něž je prostor v několika polích pro text.
// Anketa není anonymní. Prosíme, nezapomeňte v závěru uvést své jméno a příjmení a e-mailovou adresu, případně členské číslo, pokud jej znáte.
// Výsledky budou prezentovánu pouze jako souhrnné a to již.
// toto je prvni text\nxx\nabc";
//
// echo AA\Util\Difftext\AA_Difftext::renderHtml($from_text, $to_text);

class Difftext {
    static function renderHtml($from_text, $to_text) {
        $diff     = new Horde_Text_Diff('auto', [[$from_text], [$to_text]]);
        $renderer = new Horde_Text_Diff_Renderer_Inline();
        return '<div class="difftext">' . $renderer->render($diff) . '</div>';
    }
}
