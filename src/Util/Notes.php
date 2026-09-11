<?php

declare(strict_types=1);

namespace AA\Util;

/** Renders notes (AA\Util\Note) and the forms that post to admin/aa_note.php:
 *  the "Notes" panel every admin page carries, and the "Mark resolved" form
 *  of a State Check finding.
 */
final class Notes
{
    public const TOKEN = 'aa_note';

    /** the collapsed Notes section of a resource: the notes and an add form */
    public static function panel(string $resource, string $return_url): string
    {
        $notes = Note::forResource($resource);
        $html  = '';
        foreach ($notes as $note) {
            $html .= self::render($note, $return_url);
        }
        $add = '<textarea name="text" rows="2" cols="60" required placeholder="' . _m('Note text (a little HTML is fine)') . '"></textarea> '
            . '<select name="audience">'
            . '<option value="' . Note::AUDIENCE_ALL . '">' . _m('for everyone') . '</option>'
            . '<option value="' . Note::AUDIENCE_ME . '">' . _m('only for me') . '</option>'
            . '</select> ';
        $html .= self::form('add', $resource, $return_url, $add, _m('Add note'));
        return '<details class="aa-page-section aa-notes"><summary><h2 class="aa-tab-title">' . _m('Notes') . ' (' . count($notes) . ')</h2></summary>' . $html . '</details>';
    }

    /** one note: who, when, for whom, the text, and delete / unresolve for whoever may */
    public static function render(Note $note, string $return_url): string
    {
        $resolved = ($note->state === Note::STATE_RESOLVED);
        $head     = safe($note->authorName()) . ', ' . date('Y-m-d H:i', (int) $note->created);
        if ($note->audience === Note::AUDIENCE_ME) {
            $head .= ' (' . _m('only for me') . ')';
        }
        if ($resolved) {
            $head .= ' - ' . _m('resolved') . ' ' . ($note->until ? _m('until %1', [date('Y-m-d', (int) $note->until)]) : _m('forever'));
        }
        $superadmin = IsSuperadmin();
        $id_field   = '<input type="hidden" name="id" value="' . safe($note->getId()) . '">';
        $actions    = '';
        if ($resolved && $superadmin) {
            $actions .= self::form('unresolve', $note->resource, $return_url, $id_field, _m('Unresolve'));
        }
        if ($superadmin || ($note->author === AuthGetUid())) {
            $actions .= self::form('delete', $note->resource, $return_url, $id_field, _m('Delete'));
        }
        return '<div class="aa-note"><small>' . $head . '</small><div>' . $note->textHtml() . '</div>' . $actions . '</div>';
    }

    /** "Mark resolved" for a State Check finding: until when, and why */
    public static function resolveForm(string $resource, string $return_url): string
    {
        $labels  = ['today' => _m('today'), 'week' => _m('a week'), 'month' => _m('a month'), 'forever' => _m('forever'), 'date' => _m('a date')];
        $options = '';
        foreach (Note::UNTIL_CHOICES as $choice) {
            $options .= '<option value="' . $choice . '">' . $labels[$choice] . '</option>';
        }
        $inner = '<label>' . _m('Hide for') . ' <select name="until">' . $options . '</select></label> <input type="date" name="until_date"> '
            . '<input type="text" name="text" size="30" placeholder="' . _m('Why (optional)') . '"> ';
        return '<details class="aa-statecheck-inline"><summary class="aa-button-run">' . _m('Mark resolved') . '</summary>' . self::form('resolve', $resource, $return_url, $inner, _m('Resolve')) . '</details>';
    }

    private static function form(string $action, string $resource, string $return_url, string $inner, string $button): string
    {
        return '<form method="post" action="' . safe(get_admin_url('aa_note.php')) . '" class="aa-statecheck-inline">'
            . FormToken::field(self::TOKEN)
            . '<input type="hidden" name="action" value="' . $action . '">'
            . '<input type="hidden" name="resource" value="' . safe($resource) . '">'
            . '<input type="hidden" name="return" value="' . safe($return_url) . '">'
            . $inner . '<button type="submit" class="aa-button-run">' . $button . '</button></form>';
    }
}
