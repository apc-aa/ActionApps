<?php

declare(strict_types=1);

namespace AA\Isds;

use AA\Isds\Registry\Box;

/**
 * One data message for one recipient, checked against the limits ISDS refuses on (so a message
 * that passes here fails at ISDS only for its content or the recipient's box).
 * Limits: WS_manipulace_s_datovymi_zpravami.pdf 2.1 and 2.1.2.
 */
final readonly class Message
{
    public const MAX_FILES      = 100;
    public const MAX_CONTAINERS = 10;
    /** "20 MB" (2.1.2), a limit "not quite firm" (2.3): the decimal one is the safe side */
    public const MAX_BYTES      = 20_000_000;
    public const MAX_ANNOTATION = 255;
    public const MAX_REF        = 50;
    /** the XSD has no limit, the documentation says 30 */
    public const MAX_TO_HANDS = 30;

    /** the recipient's data box id, trimmed and lower-case */
    public string $recipient;
    /** dmAnnotation, the subject */
    public string $annotation;
    /**
     * dmSenderRefNumber, '' = none; printable ASCII, so that the message is found by it again. Like
     * every text here it is trimmed: store this property as the key to look the message up by, not
     * the value the constructor got.
     */
    public string $senderRef;
    /** dmToHands, '' = none */
    public string $toHands;
    /** @var list<File> */
    public array $files;

    /**
     * @param array<File> $files             the first one MAIN, the others ENCLOSURE
     * @param ?bool       $allowSubstDelivery null leaves the ISDS default (delivery after 10 days); only
     *                                        an authority's box may forbid it
     * @throws \InvalidArgumentException
     */
    public function __construct(string $recipient, string $annotation, array $files, string $senderRef = '', string $toHands = '', public bool $personalDelivery = false, public ?bool $allowSubstDelivery = null)
    {
        $this->recipient  = Box::normalizeId($recipient);
        $this->annotation = Text::check('The subject', $annotation, self::MAX_ANNOTATION);
        // ISDS keeps none of it: the message would be listed without a subject
        if (Text::isdsKey($this->annotation) === '') {
            throw new \InvalidArgumentException('The subject is empty');
        }
        $this->senderRef = Text::check('The reference number', $senderRef, self::MAX_REF, ascii: true);
        $this->toHands   = Text::check('To hands', $toHands, self::MAX_TO_HANDS);
        $this->files     = array_values($files);
        self::checkFiles($this->files);
        if ($this->size() > self::MAX_BYTES) {
            throw new \InvalidArgumentException('The files of a message are larger than ' . self::MAX_BYTES . ' bytes');
        }
    }

    /** bytes of all files - what the size limit counts */
    public function size(): int
    {
        return array_sum(array_map(static fn(File $f): int => $f->size(), $this->files));
    }

    /**
     * @param list<File> $files
     * @throws \InvalidArgumentException
     */
    private static function checkFiles(array $files): void
    {
        if (($files === []) || (count($files) > self::MAX_FILES)) {
            throw new \InvalidArgumentException('A message has 1 to ' . self::MAX_FILES . ' files');
        }
        $mains = array_keys(array_filter($files, static fn(File $f): bool => $f->metaType === File::MAIN));
        if ($mains !== [0]) {
            throw new \InvalidArgumentException('The first file of a message is the main one, and only the first');
        }
        if (count(array_filter($files, static fn(File $f): bool => $f->isContainer())) > self::MAX_CONTAINERS) {
            throw new \InvalidArgumentException('A message has at most ' . self::MAX_CONTAINERS . ' ZIP or ASiC files');
        }
    }
}
