<?php

namespace AA\Util;

use Jfcherng\Diff\Differ;
use Jfcherng\Diff\DiffHelper;
use Jfcherng\Diff\Factory\RendererFactory;
use Jfcherng\Diff\Renderer\RendererConstant;

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

class Difftext
{
    private const DIFFER_OPTIONS = [
        // show how many neighbor lines
        // Differ::CONTEXT_ALL can be used to show the whole file
        'context' => 3,
        // ignore case difference
        'ignoreCase' => false,
        // ignore line ending difference
        'ignoreLineEnding' => false,
        // ignore whitespace difference
        'ignoreWhitespace' => false,
        // if the input sequence is too long, it will just gives up (especially for char-level diff)
        'lengthLimit' => 2000,
        // if truthy, when inputs are identical, the whole inputs will be rendered in the output
        'fullContextIfIdentical' => false,
    ];

    // the renderer class options
    private const RENDER_OPTIONS = [
        // how detailed the rendered HTML in-line diff is? (none, line, word, char)
        'detailLevel' => 'word',
        // renderer language: eng, cht, chs, jpn, ...
        // or an array which has the same keys with a language file
        // check the "Custom Language" section in the readme for more advanced usage
        'language' => 'eng',
        // show line numbers in HTML renderers
        'lineNumbers' => true,
        // show a separator between different diff hunks in HTML renderers
        'separateBlock' => true,
        // show the (table) header
        'showHeader' => false,
        // the frontend HTML could use CSS "white-space: pre;" to visualize consecutive whitespaces
        // but if you want to visualize them in the backend with "&nbsp;", you can set this to true
        'spacesToNbsp' => false,
        // HTML renderer tab width (negative = do not convert into spaces)
        'tabSize' => 4,
        // this option is currently only for the Combined renderer.
        // it determines whether a replace-type block should be merged or not
        // depending on the content changed ratio, which values between 0 and 1.
        'mergeThreshold' => 0.8,
        // this option is currently only for the Unified and the Context renderers.
        // RendererConstant::CLI_COLOR_AUTO = colorize the output if possible (default)
        // RendererConstant::CLI_COLOR_ENABLE = force to colorize the output
        // RendererConstant::CLI_COLOR_DISABLE = force not to colorize the output
        'cliColorization' => RendererConstant::CLI_COLOR_AUTO,
        // this option is currently only for the Json renderer.
        // internally, ops (tags) are all int type but this is not good for human reading.
        // set this to "true" to convert them into string form before outputting.
        'outputTagAsString' => false,
        // this option is currently only for the Json renderer.
        // it controls how the output JSON is formatted.
        // see available options on https://www.php.net/manual/en/function.json-encode.php
        'jsonEncodeFlags' => \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        // this option is currently effective when the "detailLevel" is "word"
        // characters listed in this array can be used to make diff segments into a whole
        // for example, making "<del>good</del>-<del>looking</del>" into "<del>good-looking</del>"
        // this should bring better readability but set this to empty array if you do not want it
        'wordGlues' => [' ', '-'],
        // change this value to a string as the returned diff if the two input strings are identical
        'resultForIdenticals' => null,
        // extra HTML classes added to the DOM of the diff container
        'wrapperClasses' => ['diff-wrapper'],
    ];

    private const CSS = <<<CSS
        .diff-wrapper.diff {
          --tab-size: 4;
          border-collapse: collapse;
          border-spacing: 0;
          empty-cells: show;
          font-family: monospace;
          width: 100%;
          word-break: break-all;
        }
        .diff-wrapper.diff.diff-html {
          white-space: pre-wrap;
          tab-size: var(--tab-size);
        }
        .diff-wrapper.diff.diff-html .ch {
          line-height: 1em;
          background-clip: border-box;
          background-repeat: repeat-x;
          background-position: left center;
        }
        .diff-wrapper.diff.diff-html .ch.sp {
          background-image: url('data:image/svg+xml,%3Csvg preserveAspectRatio="xMinYMid meet" viewBox="0 0 12 24" xmlns="http://www.w3.org/2000/svg"%3E%3Cpath d="M4.5 11C4.5 10.1716 5.17157 9.5 6 9.5C6.82843 9.5 7.5 10.1716 7.5 11C7.5 11.8284 6.82843 12.5 6 12.5C5.17157 12.5 4.5 11.8284 4.5 11Z" fill="rgba%2860, 60, 60, 50%25%29"/%3E%3C/svg%3E');
          background-size: 1ch 1.25em;
        }
        .diff-wrapper.diff.diff-html .ch.tab {
          background-image: url('data:image/svg+xml,%3Csvg preserveAspectRatio="xMinYMid meet" viewBox="0 0 12 24" xmlns="http://www.w3.org/2000/svg"%3E%3Cpath d="M9.5 10.44L6.62 8.12L7.32 7.26L12.04 11V11.44L7.28 14.9L6.62 13.9L9.48 11.78H0V10.44H9.5Z" fill="rgba%2860, 60, 60, 50%25%29"/%3E%3C/svg%3E');
          background-size: calc(var(--tab-size) * 1ch) 1.25em;
          background-position: 2px center;
        }
        .diff-wrapper.diff.diff-html .change.change-eq .old,
        .diff-wrapper.diff.diff-html .change.change-eq .new {
          background: transparent;
        }
        .diff-wrapper.diff.diff-html .change {
          color: inherit;
        }
        .diff-wrapper.diff.diff-html .change .old {
          background: #fbe1e1;
        }
        .diff-wrapper.diff.diff-html .change .new {
          background: #e1fbe1;
        }
        .diff-wrapper.diff.diff-html .change .rep {
          background: #fef6d9;
        }
        .diff-wrapper.diff.diff-html .change .old.none,
        .diff-wrapper.diff.diff-html .change .new.none,
        .diff-wrapper.diff.diff-html .change .rep.none {
          background: transparent;
          cursor: not-allowed;
        }
        .diff-wrapper.diff.diff-html .change ins,
        .diff-wrapper.diff.diff-html .change del {
          font-weight: bold;
          text-decoration: none;
        }
        .diff-wrapper.diff.diff-html .change ins {
          background: #94f094;
        }
        .diff-wrapper.diff.diff-html .change del {
          background: #f09494;
        }
    CSS;


    public static function renderHtml($from_text, $to_text): string
    {
        // // renderer class name:
        // //     Text renderers: Context, JsonText, Unified
        // //     HTML renderers: Combined, Inline, JsonHtml, SideBySide
        // $rendernames = ['Context', 'JsonText', 'Unified', 'Combined', 'Inline', 'JsonHtml', 'SideBySide',];
        $rendername = 'Combined';
        //$result = DiffHelper::calculate($old, $new, $rendername, $differOptions, $rendererOptions);
        //$renderer = RendererFactory::make($rendername, $rendererOptions);
        $differ = new Differ(explode("\n", $from_text), explode("\n", $to_text), self::DIFFER_OPTIONS);
        $renderer = RendererFactory::make($rendername, self::RENDER_OPTIONS); // or your own renderer object
        $result = $renderer->render($differ);

        $jsonResult = DiffHelper::calculate($from_text, $to_text, 'Json'); // may store the JSON result in your database
        $htmlRenderer = RendererFactory::make('Combined', self::RENDER_OPTIONS);
        $result = $htmlRenderer->renderArray(json_decode($jsonResult, true));



        return '<style>' . self::CSS . '</style><div class="difftext">' . $result . '</div>';
    }

    //    static function renderHtml($from_text, $to_text) {
    //        $diff     = new Horde_Text_Diff('auto', [[$from_text], [$to_text]]);
    //        $renderer = new \Horde_Text_Diff_Renderer_Inline();
    //        return '<div class="difftext">' . $renderer->render($diff) . '</div>';
    //    }
}
