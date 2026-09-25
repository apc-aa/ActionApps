<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\RawBytes;

/** The reference rewrite of an imported value, shared by the plan and the writer, so what the plan proved
 *  is what the writer writes. numeric() runs once the writer has the view and e-mail numbers.
 *  RawBytes alike: every regex is byte-wise at ASCII delimiters and puts ASCII in, and in the charsets of
 *  ModuleCharset::STATELESS an ASCII byte is always that character. */
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
