/**
 * Build input for javascript/codemirror6.min.js - see build.sh in this directory.
 *
 * CodeMirror 6 cannot be loaded from a CDN piecemeal: its packages resolve to
 * several different @codemirror/view and @codemirror/state versions, and CM6 needs
 * exactly one instance of each. Bundling here pins one of each.
 *
 * The extension set matches what CodeMirror 5 was configured with in AA, so the
 * field still reads as a textarea: no line numbers, no fold gutter, no active-line
 * highlight. basicSetup would add all three.
 */
import { EditorView, keymap, drawSelection, dropCursor } from '@codemirror/view';
import { EditorState, Compartment } from '@codemirror/state';
import { defaultKeymap, history, historyKeymap } from '@codemirror/commands';
import { bracketMatching, indentOnInput, syntaxHighlighting, defaultHighlightStyle } from '@codemirror/language';
import { search, searchKeymap, highlightSelectionMatches } from '@codemirror/search';
import { html } from '@codemirror/lang-html';
import { javascript } from '@codemirror/lang-javascript';
import { css } from '@codemirror/lang-css';
import { aaSyntax } from './aa-lang.js';

const LANGUAGES = { html, javascript, css };

/** a disabled textarea is not submitted - its editor must not take typing either */
const editable = disabled => [EditorView.editable.of(!disabled), EditorState.readOnly.of(disabled)];

/**
 * Mounts an editor after `textarea` and keeps the textarea's value in sync.
 *
 * The textarea stays in the DOM: it is what gets submitted, so plain form posts
 * and AA_SendWidgetLive/Ajax keep working.
 *
 * @param {HTMLTextAreaElement} textarea
 * @param {{language?: string, disabled?: boolean, onChange?: (value: string) => void}} [options]
 */
export function createEditor(textarea, { language = 'html', disabled = false, onChange } = {}) {
    const languageConf = new Compartment();
    const editableConf = new Compartment();

    const view = new EditorView({
        state: EditorState.create({
            doc: textarea.value,
            extensions: [
                history(),
                drawSelection(),
                dropCursor(),
                indentOnInput(),
                syntaxHighlighting(defaultHighlightStyle, { fallback: true }),
                bracketMatching(),            // CodeMirror 5's matchBrackets + matchTags
                search(),
                highlightSelectionMatches(),
                keymap.of([...defaultKeymap, ...searchKeymap, ...historyKeymap]),
                languageConf.of((LANGUAGES[language] || LANGUAGES.html)()),
                editableConf.of(editable(disabled)),
                aaSyntax(),                   // any field here may hold AA expressions, whatever its language
                EditorView.lineWrapping,
                EditorView.contentAttributes.of({ spellcheck: 'true' }),
                EditorView.updateListener.of((update) => {
                    if (!update.docChanged) {
                        return;
                    }
                    textarea.value = update.state.doc.toString();
                    onChange?.(textarea.value);
                }),
            ],
        }),
    });
    textarea.after(view.dom);

    return {
        dom: view.dom,
        getValue: () => view.state.doc.toString(),
        setValue(value) {
            view.dispatch({ changes: { from: 0, to: view.state.doc.length, insert: value } });
        },
        setLanguage(name) {
            view.dispatch({ effects: languageConf.reconfigure((LANGUAGES[name] || LANGUAGES.html)()) });
        },
        setDisabled(off) {
            view.dispatch({ effects: editableConf.reconfigure(editable(off)) });
        },
        focus: () => view.focus(),
        destroy: () => view.destroy(),
    };
}
