<?php

declare(strict_types=1);

namespace AA\Isds;

use AA\Upload\UploadDir;
use AA\Util\ZipDirectory;

/**
 * Which files may go into a data message, and the one way to make a File of an AA upload. ISDS
 * checks every file - its extension is on the list, the MIME type is one listed for it and the
 * content is what both say - and refuses the whole message for one that fails
 * (WS_manipulace_s_datovymi_zpravami.pdf 2.1.2). A campaign sends the same attachments to
 * everyone, so such a file would fail every message: here it is refused before sending.
 * The number and size limits of a message are Message's. Exception messages quote file names
 * and URLs - escape them when shown.
 */
final readonly class AttachmentPolicy
{
    /**
     * Extension => MIME types ISDS accepts for it, the one to declare first (WS_manipulace_s_datovymi_zpravami.pdf 3,
     * "Seznam povolených přípon příloh zprávy"; the Provozní řád, 6, points to the same list of vyhláška 194/2009 Sb.).
     */
    public const TYPES = [
        'asice'  => ['application/vnd.etsi.asic-e+zip'],
        'asics'  => ['application/vnd.etsi.asic-s+zip'],
        'cer'    => ['application/x-x509-ca-cert'],
        'crt'    => ['application/x-x509-ca-cert'],
        'csv'    => ['text/csv'],
        'ddd'    => ['application/octet-stream'],
        'der'    => ['application/x-x509-ca-cert'],
        'doc'    => ['application/msword'],
        'docx'   => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', self::ENCRYPTED],
        'dbf'    => ['application/octet-stream'],
        'dgn'    => ['application/octet-stream'],
        'dwg'    => ['image/vnd.dwg'],
        'edi'    => ['application/edifact', 'application/edi-x12', 'application/edi-consent', 'text/plain', 'text/xml', 'application/xml'],
        'fo'     => ['application/vnd.software602.filler.form+xml', 'application/xml'],
        'gfs'    => ['application/xml', 'text/xml'],
        'gif'    => ['image/gif'],
        'gml'    => ['application/xml', 'text/xml'],
        'heic'   => ['image/heic', 'image/heic-sequence'],
        'heif'   => ['image/heif', 'image/heif-sequence'],
        'html'   => ['text/html'],
        'htm'    => ['text/html'],
        'isdoc'  => ['text/isdoc', 'application/xml'],
        'isdocx' => ['text/isdocx'],
        'jfif'   => ['image/jpeg', 'image/pjpeg'],
        'jpeg'   => ['image/jpeg', 'image/pjpeg'],
        'jpg'    => ['image/jpeg', 'image/pjpeg'],
        'json'   => ['application/json'],
        'mpeg'   => ['video/mpeg', 'video/mpeg1', 'video/mpeg2', 'video/mpg'],
        'mpeg1'  => ['video/mpeg', 'video/mpeg1', 'video/mpeg2', 'video/mpg'],
        'mpeg2'  => ['video/mpeg', 'video/mpeg1', 'video/mpeg2', 'video/mpg'],
        'mpg'    => ['video/mpeg', 'video/mpeg1', 'video/mpeg2', 'video/mpg'],
        'mp2'    => ['audio/mpeg'],
        'mp3'    => ['audio/mpeg'],
        'mp4'    => ['audio/mp4', 'video/mp4'],
        'm4a'    => ['audio/mp4'],
        'm4p'    => ['audio/mp4', 'video/mp4'],
        'm4v'    => ['video/mp4'],
        'odp'    => ['application/vnd.oasis.opendocument.presentation'],
        'ods'    => ['application/vnd.oasis.opendocument.spreadsheet'],
        'odt'    => ['application/vnd.oasis.opendocument.text'],
        'pdf'    => ['application/pdf'],
        'pk7'    => ['application/pkcs7-mime', 'application/x-pkcs7-mime'],
        'png'    => ['image/png', 'image/x-png'],
        'ppt'    => ['application/vnd.ms-powerpoint'],
        'pptx'   => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', self::ENCRYPTED],
        'prj'    => ['application/octet-stream'],
        'p7b'    => ['application/pkcs7-certificates', 'application/pkcs7-mime', 'application/x-pkcs7-certificates'],
        'p7c'    => ['application/pkcs7-mime', 'application/x-pkcs7-mime'],
        'p7f'    => ['application/pkcs7-signature'],
        'p7m'    => ['application/pkcs7-mime', 'application/x-pkcs7-mime'],
        'p7s'    => ['application/pkcs7-signature', 'application/x-pkcs7-signature'],
        'qix'    => ['application/octet-stream'],
        'rtf'    => ['application/msword', 'text/rtf', 'application/rtf'],
        'sbn'    => ['application/octet-stream'],
        'sbx'    => ['application/octet-stream'],
        'sce'    => ['application/vnd.etsi.asic-e+zip'],
        'scs'    => ['application/vnd.etsi.asic-s+zip'],
        'shp'    => ['application/octet-stream'],
        'shx'    => ['application/octet-stream'],
        'tiff'   => ['image/tiff'],
        'tif'    => ['image/tiff'],
        'tst'    => ['application/timestamp-reply'],
        'tsr'    => ['application/timestamp-reply'],
        'txt'    => ['text/plain'],
        'wav'    => ['audio/wav', 'audio/wave', 'audio/x-wav'],
        'xls'    => ['application/vnd.ms-excel'],
        'xlsx'   => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', self::ENCRYPTED],
        'xml'    => ['application/xml', 'text/xml'],
        'xsd'    => ['application/xml', 'text/xml'],
        'zfo'    => ['application/vnd.software602.filler.form-xml-zip'],
        'zip'    => ['application/zip', 'application/x-compressed', 'application/x-zip-compressed'],
    ];

    /**
     * ZIP and ASiC: ISDS checks what they hold - no encryption or nesting, 1 to 1000 entries, at most
     * 4 levels, every file on the list (WS_manipulace_s_datovymi_zpravami.pdf 3.1) - which cannot be
     * checked here without unpacking them.
     */
    private const ARCHIVES = ['zip', 'asice', 'asics', 'sce', 'scs'];
    /** a message carries no HTML - the letter goes as a PDF -, and nothing would clean an attached one */
    private const HTML = ['html', 'htm'];
    /** ISDS names these only application/octet-stream: any content but ACTIVE */
    private const ANY_CONTENT = ['dbf', 'ddd', 'dgn', 'prj', 'qix', 'sbn', 'sbx', 'shp', 'shx'];
    /** pages a browser runs the scripts of; a message carries no HTML */
    private const ACTIVE = ['text/html', 'image/svg+xml'];
    /** plain text is any text - a list, a source file, a saved mail, JSON - except HTML */
    private const ANY_TEXT = ['txt'];
    /** texts finfo does not call text/ */
    private const OTHER_TEXT = ['message/rfc822', 'application/json'];

    /** what finfo may call the formats of ALSO_DETECTED */
    private const ZIP    = ['application/zip'];
    private const OLE    = ['application/CDFV2', 'application/x-ole-storage', 'application/vnd.ms-office'];
    private const BINARY = ['application/octet-stream'];
    /** a certificate or signature in its text armour */
    private const PEM   = ['text/plain', 'application/x-pem-file'];
    private const PKCS7 = ['application/pkcs7-signature', 'application/pkcs7-mime'];
    /** XML without its declaration */
    private const XML_TEXT = ['text/plain'];
    private const MPEG4    = ['audio/x-m4a', 'video/x-m4v'];

    /**
     * finfo looks only at the first local headers of a ZIP: it calls a bare ZIP the container of a
     * document, a truncated document a document, and may not know one that some archivers write
     * (7-Zip). ISDS refuses the whole message for an attachment that is not what its extension
     * says, so the structure of these is checked here (isDocument()).
     */
    private const ZIP_DOCUMENT = [...self::ZIP, ...self::BINARY];
    /** OOXML: the folder of the main part - the one finfo looks for, too */
    private const OOXML = ['docx' => 'word/', 'xlsx' => 'xl/', 'pptx' => 'ppt/'];
    /** ODF: the first entry mimetype holds the first type of TYPES */
    private const ODF = ['odt', 'ods', 'odp'];
    /** what finfo calls an OOXML document under a password: an OLE file, no ZIP */
    private const ENCRYPTED = 'application/encrypted';

    /**
     * What finfo calls content that is still the format of the extension: the container of a
     * ZIP-based or an OLE format, text without the marks of its format, a DER that libmagic does not
     * know. The MIME type declared for such a file is then the first one of TYPES.
     */
    private const ALSO_DETECTED = [
        'docx'   => self::ZIP_DOCUMENT,
        'xlsx'   => self::ZIP_DOCUMENT,
        'pptx'   => self::ZIP_DOCUMENT,
        'odt'    => self::ZIP_DOCUMENT,
        'ods'    => self::ZIP_DOCUMENT,
        'odp'    => self::ZIP_DOCUMENT,
        'isdocx' => self::ZIP_DOCUMENT,
        'zfo'    => [...self::ZIP, ...self::BINARY, ...self::PKCS7],
        'doc'    => self::OLE,
        'xls'    => self::OLE,
        'ppt'    => self::OLE,
        'cer'    => [...self::BINARY, ...self::PEM],
        'crt'    => [...self::BINARY, ...self::PEM],
        'der'    => self::BINARY,
        'pk7'    => [...self::BINARY, ...self::PEM, ...self::PKCS7],
        'p7b'    => [...self::BINARY, ...self::PEM, ...self::PKCS7],
        'p7c'    => [...self::BINARY, ...self::PEM, ...self::PKCS7],
        'p7m'    => [...self::BINARY, ...self::PEM, ...self::PKCS7],
        'p7f'    => [...self::BINARY, ...self::PEM],
        'p7s'    => [...self::BINARY, ...self::PEM],
        'tst'    => self::BINARY,
        'tsr'    => self::BINARY,
        'csv'    => ['text/plain', 'application/csv'],
        'json'   => ['text/plain'],
        'xml'    => self::XML_TEXT,
        'xsd'    => self::XML_TEXT,
        'gml'    => self::XML_TEXT,
        'gfs'    => self::XML_TEXT,
        'fo'     => ['text/xml', ...self::XML_TEXT],
        'isdoc'  => ['text/xml', ...self::XML_TEXT],
        'mp4'    => self::MPEG4,
        'm4p'    => self::MPEG4,
        'm4a'    => ['audio/x-m4a'],
        'm4v'    => ['video/x-m4v'],
    ];

    public function __construct(private UploadDir $uploads) {}

    /**
     * A file of the message, checked against the ISDS list: $name must have an allowed extension,
     * and finfo must find the content to be of that format - an office document must also hold what
     * its format does. The declared MIME type is the detected one when ISDS lists it for the
     * extension, else the first of TYPES.
     * @param ?string $detected detect() of $content, when the caller asked it already
     * @throws \InvalidArgumentException
     */
    public static function file(string $name, string $content, string $metaType = File::ENCLOSURE, ?string $detected = null): File
    {
        [$name, $extension] = self::checkName($name);
        return new File($name, self::type($name, $extension, $content, $detected), $metaType, $content);
    }

    /** MIME type of $content by what it is (finfo), not by its name */
    public static function detect(string $content): string
    {
        return (new \finfo(FILEINFO_MIME_TYPE))->buffer($content) ?: 'application/octet-stream';
    }

    /**
     * An upload as a file of the message, its name checked before it is read
     * @param string $name     shown to the recipient; '' = the name of the stored file
     * @param string $metaType File::MAIN for a letter written as a file
     * @throws \InvalidArgumentException not an upload of the directory, or not a file ISDS takes
     * @throws \RuntimeException the file does not exist or cannot be read
     */
    public function fromUrl(string $url, string $name = '', string $metaType = File::ENCLOSURE): File
    {
        [$name, $extension] = self::checkName(($name === '') ? $this->uploads->name($url) : $name);
        $content            = $this->uploads->read($url, Message::MAX_BYTES);
        return new File($name, self::type($name, $extension, $content), $metaType, $content);
    }

    /** a file of $extension (lower case) may be attached, if its content is of that type: listed, and no archive or HTML */
    public static function allows(string $extension): bool
    {
        return isset(self::TYPES[$extension]) && !in_array($extension, [...self::ARCHIVES, ...self::HTML], true);
    }

    /**
     * @return array{string, string} the name trimmed, its extension in lower case
     * @throws \InvalidArgumentException
     */
    private static function checkName(string $name): array
    {
        $name = Text::check('A file name', $name, File::MAX_NAME);
        if (str_contains($name, '/') || str_contains($name, '\\')) {
            throw new \InvalidArgumentException("The file name \"$name\" must not contain a path");
        }
        $dot       = strrpos($name, '.');
        $extension = ($dot === false) ? '' : strtolower(substr($name, $dot + 1));
        if (($dot === 0) || !self::allows($extension)) {
            throw new \InvalidArgumentException(match (true) {
                ($dot !== 0) && in_array($extension, self::ARCHIVES, true) => "The archive \"$name\" cannot be checked before sending - attach the files in it one by one",
                ($dot !== 0) && in_array($extension, self::HTML, true)     => "The HTML file \"$name\" cannot be attached - put its text into the letter, or attach it as a PDF",
                default                                                    => "The file \"$name\" is not of a type a data message may carry",
            });
        }
        return [$name, $extension];
    }

    /**
     * The MIME type to declare for $content
     * @throws \InvalidArgumentException
     */
    private static function type(string $name, string $extension, string $content, ?string $detected = null): string
    {
        if ($content === '') {
            throw new \InvalidArgumentException("The file \"$name\" is empty");
        }
        if (strlen($content) > Message::MAX_BYTES) {
            throw new \InvalidArgumentException("The file \"$name\" is larger than a data message may be");
        }
        $listed     = self::TYPES[$extension];
        $detected ??= self::detect($content);
        $isListed   = in_array($detected, $listed, true);
        if (!$isListed && !self::isAlso($extension, $detected)) {
            throw new \InvalidArgumentException("The content of the file \"$name\" is $detected, not .$extension");
        }
        if (!self::isDocument($extension, $content, $detected)) {
            throw new \InvalidArgumentException("The content of the file \"$name\" is not a .$extension document");
        }
        return $isListed ? $detected : $listed[0];
    }

    /** $content of a ZIP-based document holds the parts of its format; true for other formats */
    private static function isDocument(string $extension, string $content, string $detected): bool
    {
        return match (true) {
            isset(self::OOXML[$extension])        => ($detected === self::ENCRYPTED) || self::isOoxml(self::OOXML[$extension], $content),
            in_array($extension, self::ODF, true) => self::isOdf(self::TYPES[$extension][0], $content),
            $extension === 'isdocx'               => self::isIsdocx($content),
            default                               => true,
        };
    }

    /** the parts every OOXML package has, and a part in the folder of $main */
    private static function isOoxml(string $main, string $content): bool
    {
        $names = ZipDirectory::read($content)?->names;
        if ($names === null) {
            return false;
        }
        // part names are case-insensitive (OPC, ECMA-376 part 2)
        $names = array_map(strtolower(...), $names);
        if (array_diff(['[content_types].xml', '_rels/.rels'], $names) !== []) {
            return false;
        }
        foreach ($names as $name) {
            // a folder entry alone is no part
            if (str_starts_with($name, $main) && !str_ends_with($name, '/')) {
                return true;
            }
        }
        return false;
    }

    /** ODF names its type in the entry mimetype, first, stored at the start of the file (ODF 1.2 part 3) */
    private static function isOdf(string $mimetype, string $content): bool
    {
        $zip = ZipDirectory::read($content);
        return (($zip?->names[0] ?? '') === 'mimetype') && ($zip?->leadingStored() === $mimetype);
    }

    /** an ISDOCX holds the invoice as a .isdoc file */
    private static function isIsdocx(string $content): bool
    {
        foreach (ZipDirectory::read($content)->names ?? [] as $name) {
            if (str_ends_with(strtolower($name), '.isdoc')) {
                return true;
            }
        }
        return false;
    }

    /** $detected, though not listed for $extension, is still its content */
    private static function isAlso(string $extension, string $detected): bool
    {
        return match (true) {
            in_array($extension, self::ANY_CONTENT, true) => !in_array($detected, self::ACTIVE, true),
            in_array($extension, self::ANY_TEXT, true)    => (str_starts_with($detected, 'text/') || in_array($detected, self::OTHER_TEXT, true)) && ($detected !== 'text/html'),
            default                                       => in_array($detected, self::ALSO_DETECTED[$extension] ?? [], true),
        };
    }
}
