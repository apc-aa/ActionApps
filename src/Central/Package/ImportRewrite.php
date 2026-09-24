<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\RawBytes;

/** The reference rewrite of an imported value (design balicek-v1.md § 7.2 step 5, § 8) - ONE function
 *  per step for the plan's checks (TargetCharset) and the writer (part 4), so what the plan proved is
 *  what the writer writes:
 *  - known():   what the plan knows - long ids and alerts field ids (the same length), group names in
 *               texts, the group of field.input_show_func (rewriteInputShowFunc(), `$widget`);
 *  - numeric(): views and e-mails, once the writer has their AUTO_INCREMENT numbers (IdMap::withViews()
 *               / withEmails()) - the length grows, the writer checks it again (TargetCharset).
 *  The writer: numeric(known($value)), then ModuleCharset::fromUtf8() for a text; RawBytes as they are.
 *  Texts (UTF-8) and RawBytes alike: every regex of ReferenceRewriter is byte-wise and matches ASCII
 *  delimiters and ASCII runs, and puts ASCII in (new group names are [A-Za-z0-9_.-]). In the charsets of
 *  ModuleCharset::STATELESS an ASCII byte is always that character, never a byte of another, so the
 *  rewrite of the source bytes of RawBytes changes only what it changes in a text. Structured columns
 *  (slice.vid, emailid_*, uses, ...) the writer maps directly; serialised text never occurs (site.structure
 *  is built from the tree). */
final class ImportRewrite
{
    private readonly ReferenceRewriter $rewriter;

    public function __construct(IdMap $map)
    {
        $this->rewriter = new ReferenceRewriter($map);
    }

    /** true: the column is a field widget definition - its group parameter is rewritten in place */
    public static function isWidget(string $element, string $column): bool
    {
        return ($element === 'field') && ($column === 'input_show_func');
    }

    /** @throws RewriteFailed */
    public function known(string|RawBytes $value, bool $widget = false): string|RawBytes
    {
        $text = $this->rewriter->rewriteGroups($this->rewriter->rewriteLongIds(ValueCodec::bytes($value)));
        if ($widget) {
            $position = ReferenceRewriter::CONST_POSITION[strtolower(trim(explode(':', $text, 2)[0]))] ?? null;
            $text     = ($position === null) ? $text : $this->rewriter->rewriteInputShowFunc($text, $position);
        }
        return ($value instanceof RawBytes) ? new RawBytes($text) : $text;
    }

    /** @throws RewriteFailed */
    public function numeric(string|RawBytes $value): string|RawBytes
    {
        $text = $this->rewriter->rewriteNumeric(ValueCodec::bytes($value));
        return ($value instanceof RawBytes) ? new RawBytes($text) : $text;
    }
}
