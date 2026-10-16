<?php

declare(strict_types=1);

namespace AA\Isds;

/**
 * The response element of one ISDS operation (CreateMessageResponse, ...) with its status. Paths are
 * XPath with the prefix isds: for the ISDS namespace. Texts come from outside - escape them when shown.
 */
final class Answer
{
    /** dmStatusCode or dbStatusCode, '' when the answer has none */
    public readonly string $code;
    public readonly string $text;

    public function __construct(private readonly \DOMXPath $xpath, private readonly \DOMElement $element)
    {
        $this->code = $this->value('isds:dmStatus/isds:dmStatusCode | isds:dbStatus/isds:dbStatusCode') ?? '';
        $this->text = $this->value('isds:dmStatus/isds:dmStatusMessage | isds:dbStatus/isds:dbStatusMessage') ?? '';
    }

    /**
     * Trimmed text of the first element at $path, null when there is none or it is xsi:nil
     * @param ?\DOMNode $context where $path starts, the response element by default
     */
    public function value(string $path, ?\DOMNode $context = null): ?string
    {
        $node = Soap::first($this->xpath, $path, $context ?? $this->element);
        if (is_null($node) || in_array($node->getAttributeNS(Soap::XSI, 'nil'), ['true', '1'], true)) {
            return null;
        }
        return trim($node->textContent);
    }

    /** @return list<\DOMElement> */
    public function elements(string $path): array
    {
        $nodes = $this->xpath->query($path, $this->element);
        $out   = [];
        foreach (($nodes === false) ? [] : $nodes as $node) {
            if ($node instanceof \DOMElement) {
                $out[] = $node;
            }
        }
        return $out;
    }
}
