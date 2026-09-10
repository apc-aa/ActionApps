/**
 * Build input for javascript/codemirror6.min.js - see build.sh in this directory.
 *
 * CodeMirror 6 cannot be loaded from a CDN piecemeal: codemirror@6.0.2 and
 * @codemirror/lang-html@6 resolve to five different @codemirror/view versions
 * and four @codemirror/state versions, and CM6 needs exactly one instance of
 * each (facet and StateField identity). Bundling here pins one of each.
 *
 * Everything AA needs goes through createEditor(); the raw CodeMirror API is
 * deliberately not re-exported, so the bundle stays the only thing that has to
 * know about CodeMirror internals.
 */
import { EditorView, basicSetup } from 'codemirror';
import { EditorState, Compartment } from '@codemirror/state';
import { html } from '@codemirror/lang-html';
import { javascript } from '@codemirror/lang-javascript';
import { css } from '@codemirror/lang-css';

const LANGUAGES = { html, javascript, css };

/**
 * Mounts an editor after `textarea` and keeps the textarea's value in sync.
 *
 * The textarea stays in the DOM on purpose: it remains the value that gets
 * submitted, so plain form posts and AA_SendWidgetLive/Ajax (which serialize
 * with jQuery and only see native form controls) keep working unchanged.
 *
 * @param {HTMLTextAreaElement} textarea
 * @param {{language?: string, onChange?: (value: string) => void}} [options]
 */
export function createEditor(textarea, { language = 'html', onChange } = {}) {
    const languageConf = new Compartment();

    const view = new EditorView({
        state: EditorState.create({
            doc: textarea.value,
            extensions: [
                basicSetup,
                languageConf.of((LANGUAGES[language] || LANGUAGES.html)()),
                EditorView.lineWrapping,
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
        focus: () => view.focus(),
        destroy: () => view.destroy(),
    };
}
