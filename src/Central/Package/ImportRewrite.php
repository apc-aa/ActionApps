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

    /** @param string|null $groupColumn the value is of this ReferenceRewriter::groupColumn() - its group is rewritten in place
     *  @throws RewriteFailed */
    public function known(string|RawBytes $value, ?string $groupColumn = null): string|RawBytes
    {
        $text = $this->rewriter->rewriteGroups($this->rewriter->rewriteLongIds(ValueCodec::bytes($value)));
        if ($groupColumn !== null) {
            $text = $this->rewriter->rewriteGroupColumn($groupColumn, $text);
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
