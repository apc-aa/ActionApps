<?php

declare(strict_types=1);

namespace AA\Isds;

/**
 * One document of a data message (dmFile). ISDS checks that the name's extension, the MIME type
 * and the real content agree, and refuses the whole message when they do not.
 */
final readonly class File
{
    public const MAIN      = 'main';
    public const ENCLOSURE = 'enclosure';

    /** dmFileDescr (WS_manipulace_s_datovymi_zpravami.pdf 2.1.2) */
    public const MAX_NAME = 255;

    /** ZIP and ASiC, by MIME type and extension (WS_manipulace_s_datovymi_zpravami.pdf 3) */
    private const CONTAINER_TYPES      = ['application/zip', 'application/x-zip-compressed', 'application/vnd.etsi.asic-e+zip', 'application/vnd.etsi.asic-s+zip'];
    private const CONTAINER_EXTENSIONS = ['zip', 'asice', 'asics', 'sce', 'scs'];

    /** shown to the recipient as the file name, with its extension */
    public string $name;

    /**
     * @param string $metaType MAIN for the first document of a message, ENCLOSURE for the others
     * @param string $content  the bytes, not base64
     * @throws \InvalidArgumentException
     */
    public function __construct(string $name, public string $mimeType, public string $metaType, public string $content)
    {
        $this->name = Text::check('A file name', $name, self::MAX_NAME);
        if ($this->name === '') {
            throw new \InvalidArgumentException('A file needs a name');
        }
        if (preg_match('~^[a-z0-9.+-]+/[a-z0-9.+-]+\z~i', $mimeType) !== 1) {
            throw new \InvalidArgumentException('A file needs a MIME type like application/pdf');
        }
        if (!in_array($metaType, [self::MAIN, self::ENCLOSURE], true)) {
            throw new \InvalidArgumentException('A file is "' . self::MAIN . '" or "' . self::ENCLOSURE . '"');
        }
    }

    public function size(): int
    {
        return strlen($this->content);
    }

    /** a ZIP or an ASiC - a message may carry only a few */
    public function isContainer(): bool
    {
        return in_array(strtolower($this->mimeType), self::CONTAINER_TYPES, true)
            || in_array(strtolower(pathinfo($this->name, PATHINFO_EXTENSION)), self::CONTAINER_EXTENSIONS, true);
    }
}
