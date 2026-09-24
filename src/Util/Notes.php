<?php

declare(strict_types=1);

namespace AA\Util;

/** Renders notes (AA\Util\Note) and the forms that post to admin/aa_note.php:
 *  the note corner every admin page carries (top right, level with the H1)
 *  and the "Mark resolved" popover of a State Check finding. Both open a
 *  .aa-overflow-menu popover (HTML popover API; the positioner is loaded by
 *  AA_Adminpageutil::printHead), so nothing is clipped by tables or sections.
 */
final class Notes
{
    public const TOKEN = 'aa_note';

    /** popovers on one page need distinct ids (one Mark resolved per finding) */
    private static int $counter = 0;

    /** May the current user write notes at all? The same predicate admin/aa_note.php
     *  guards its actions with - a user who would only get "no permissions" back is
     *  not shown the corner.
     */
    public static function mayWrite(): bool
    {
        return IsSuperadmin() || IfSlPerm(PS_EDIT_ALL_ITEMS);
    }

    /** The corner as an admin page emits it (AA_Adminpageutil::printHead() and the
     *  item-edit head in formutil.php both use this one). Registers the popover
     *  positioner itself, because printForm() builds its head without printHead().
     */
    public static function pageCorner(): string
    {
        if ((AuthGetUid() === '') || !self::mayWrite()) {
            return '';
        }
        \AA_Adminpageutil::addOverflowMenuPositioner();
        return self::header(Note::pageResource(), (string) ($_SERVER['REQUEST_URI'] ?? ''));
    }

    /** The note corner of a resource: an icon alone while there is no note, a one-row
     *  strip (count + the newest note's first words) once there is one. Both open the
     *  popover with the notes and the add form.
     */
    public static function header(string $resource, string $return_url): string
    {
        $notes = Note::forResource($resource);
        $id    = 'aa-notes-' . (++self::$counter);
        if ($notes) {
            $newest  = $notes[0];   // forResource() is newest first
            $label   = '<b class="aa-notes-count">' . count($notes) . '</b>'
                     . '<span class="aa-notes-preview">' . safe($newest->authorName()) . ': ' . safe(self::preview((string) $newest->text)) . '</span>';
            $trigger = '<button type="button" class="aa-notes-trigger aa-notes-some" popovertarget="' . $id . '" title="' . safe(_m('Notes on this page')) . '">' . $label . '</button>';
        } else {
            // the button is the bare icon here, so the name must come from aria as well as the tooltip
            $add     = safe(_m('Add a note to this page'));
            $trigger = '<button type="button" class="aa-notes-trigger" popovertarget="' . $id . '" title="' . $add . '" aria-label="' . $add . '"></button>';
        }
        $list = '';
        foreach ($notes as $note) {
            $list .= self::render($note, $return_url);
        }
        if ($list !== '') {
            $list = '<div class="aa-notes-list">' . $list . '</div>';
        }
        $textarea = '<textarea name="text" rows="3" required placeholder="' . safe(_m('Note text (a little HTML is fine)')) . '"></textarea>';
        $audience = '<select name="audience">'
                  . '<option value="' . Note::AUDIENCE_ALL . '">' . _m('for everyone') . '</option>'
                  . '<option value="' . Note::AUDIENCE_ME . '">' . _m('only for me') . '</option>'
                  . '</select>';
        $popover = '<div popover id="' . $id . '" class="aa-overflow-menu aa-notes-pop">' . $list
                 . self::form('add', $resource, $return_url, $textarea, _m('Add note'), 'btn btn-s', $audience) . '</div>';
        return '<div class="aa-notes">' . $trigger . $popover . '</div>';
    }

    /** the first words of a note as plain text, for the one-row strip */
    public static function preview(string $html, int $width = 60): string
    {
        // a tag becomes a space, so two words separated only by markup do not run together
        $text = html_entity_decode((string) preg_replace('/<[^>]*>/', ' ', $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $cut  = mb_strimwidth($text, 0, $width, '', 'UTF-8');
        return ($cut === $text) ? $text : rtrim($cut) . '…';
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
            $actions .= self::form('unresolve', $note->resource, $return_url, $id_field, _m('Unresolve'), 'aa-button-run');
        }
        if ($superadmin || ($note->author === AuthGetUid())) {
            $actions .= self::form('delete', $note->resource, $return_url, $id_field, '✕', 'ico delete');
        }
        return '<div class="aa-note"><div class="aa-note-head">' . $head . '</div><div class="aa-note-text">' . $note->textHtml() . '</div>'
             . ($actions ? '<div class="aa-note-actions">' . $actions . '</div>' : '') . '</div>';
    }

    /** "Mark resolved" for a State Check finding: a button opening a popover with
     *  until when (today by default) and why
     */
    public static function resolveForm(string $resource, string $return_url): string
    {
        $labels  = ['today' => _m('today'), 'week' => _m('a week'), 'month' => _m('a month'), 'forever' => _m('forever'), 'date' => _m('a date')];
        $options = '';
        foreach (Note::UNTIL_CHOICES as $choice) {
            $options .= '<option value="' . $choice . '">' . $labels[$choice] . '</option>';
        }
        $id    = 'aa-resolve-' . (++self::$counter);
        // the date input shows only for "a date" (the popover positioner needs JS anyway)
        $inner = '<div class="aa-notes-row"><label for="' . $id . '-until">' . _m('Hide for') . '</label>'
               . '<select name="until" id="' . $id . '-until" onchange="this.form.until_date.hidden = (this.value !== \'date\')">' . $options . '</select>'
               . '<input type="date" name="until_date" hidden aria-label="' . safe(_m('a date')) . '"></div>'
               . '<input type="text" name="text" placeholder="' . safe(_m('Why (optional)')) . '">';
        // a div, not a span: the popover itself is a div and flow content may not sit inside phrasing content
        return '<div class="aa-notes-resolve"><button type="button" class="aa-button-run" popovertarget="' . $id . '">' . _m('Mark resolved') . '</button>'
             . '<div popover id="' . $id . '" class="aa-overflow-menu aa-notes-pop">' . self::form('resolve', $resource, $return_url, $inner, _m('Resolve')) . '</div></div>';
    }

    /** the form posting to admin/aa_note.php: $inner, then a row with $beside_button
     *  and the submit button
     */
    private static function form(string $action, string $resource, string $return_url, string $inner, string $button, string $class = 'btn', string $beside_button = ''): string
    {
        return '<form method="post" action="' . safe(get_admin_url('aa_note.php')) . '" class="aa-notes-form">'
            . FormToken::field(self::TOKEN)
            . '<input type="hidden" name="action" value="' . $action . '">'
            . '<input type="hidden" name="resource" value="' . safe($resource) . '">'
            . '<input type="hidden" name="return" value="' . safe($return_url) . '">'
            . $inner . '<div class="aa-notes-row">' . $beside_button . '<button type="submit" class="' . $class . '">' . $button . '</button></div></form>';
    }
}
