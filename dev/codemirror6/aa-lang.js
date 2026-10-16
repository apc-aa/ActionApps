/**
 * AA template syntax in the code editor: {#…} comments greyed out, as the expander drops them.
 * Decorations over whatever language the editor has, so comments show inside HTML attributes too.
 */
import { ViewPlugin, Decoration } from '@codemirror/view';
import { RangeSetBuilder } from '@codemirror/state';
import { scan } from './aa-syntax.js';

const commentMark = Decoration.mark({ class: 'cm-aa-comment' });

function commentDecorations(doc) {
    const builder = new RangeSetBuilder();
    let end = -1;
    // the whole text: a comment may open far above the visible part
    for (const span of scan(doc.toString())) {
        if ((span.kind === 'comment') && (span.from >= end)) {    // one inside a comment is covered already
            builder.add(span.from, span.to, commentMark);
            end = span.to;
        }
    }
    return builder.finish();
}

const comments = ViewPlugin.fromClass(class {
    constructor(view) {
        this.decorations = commentDecorations(view.state.doc);
    }

    update(update) {
        if (update.docChanged) {
            this.decorations = commentDecorations(update.state.doc);
        }
    }
}, { decorations: plugin => plugin.decorations });

/** @return {import('@codemirror/state').Extension} */
export function aaSyntax() {
    return [comments];
}
