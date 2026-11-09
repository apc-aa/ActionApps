<?php

declare(strict_types=1);

namespace AA\Isds;

use AA\Isds\Body\EmptyLetter;
use AA\Isds\Body\HtmlBody;
use AA\Isds\Body\PdfBody;
use AA\Isds\Body\ServerException;
use AA\Upload\UploadDir;

/**
 * What a mass sending sends to every recipient: the subject, "to hands", the letter (Dopis.pdf) and
 * the attachments, or the attachments alone - checked as a data message is, so that a sending ISDS
 * would refuse for everyone is refused before it starts.
 */
final readonly class Letter
{
    /** what to fix in a campaign without a text */
    public const FRAME_ONLY = 'The letter has a header or a footer but no text - write the text, or empty the header and the footer to send the attachments alone';
    public const LEFT_OUT   = 'The letter shows nothing - it lost: ';
    public const NOTHING    = 'The letter has no text and no attachment';

    /** any well-formed box: the trial message checks the content, not the recipient */
    private const TRIAL_BOX = 'aaaaaaa';

    /** dmAnnotation, trimmed as Message keeps it */
    public string $annotation;
    /** dmToHands, '' = none */
    public string $toHands;
    /** @var list<File> the main file (File::MAIN) first - Dopis.pdf, or the first attachment of a campaign without a text -, then the attachments */
    public array $files;

    /**
     * @param array<File> $files
     * @param string      $source what the main file is made of, 32 hexadecimal digits: a test message
     *                            compares letters by it - Dopis.pdf by its document, not by bytes that may
     *                            differ each time it is made; an attachment by its bytes. '' = not known - a
     *                            letter of a snapshot
     * @throws \InvalidArgumentException a data message cannot carry it; the message may quote a file
     *                                   name - escape it when shown
     */
    public function __construct(string $annotation, array $files, string $toHands = '', public string $source = '')
    {
        $trial            = new Message(self::TRIAL_BOX, $annotation, $files, '', $toHands);
        $this->annotation = $trial->annotation;
        $this->toHands    = $trial->toHands;
        $this->files      = $trial->files;
    }

    /**
     * The letter of a campaign item: its header above the body and its footer on every page as Dopis.pdf,
     * then the files of its attachment field. Without a text the attachments go alone, the first one the
     * main document - a letter the sender made as a file of its own.
     * @param UploadDir             $uploads  of the campaign slice: images of the header, the body and the attachments
     * @param \Closure(int): string $makeRoom memory for the PDF, given the bytes it needs: '' = there is
     *                                        room, else why not - the message of \OverflowException
     * @throws \OverflowException no memory for the PDF
     * @throws ServerException the server could not make it - no temporary directory, a limit of PCRE
     * @throws \InvalidArgumentException|\RuntimeException what to fix in the campaign; the message
     *                                   quotes file names and URLs - escape it when shown
     */
    public static function of(\AA_Content $campaign, UploadDir $uploads, \Closure $makeRoom): self
    {
        $subject  = (string) $campaign->getValue(Schema::CAMPAIGN_SUBJECT);
        $footer   = PdfBody::footer((string) $campaign->getValue(Schema::CAMPAIGN_FOOTER));
        $files    = [];
        $bytes    = 0;
        $document = null;
        $source   = null;
        try {
            $document = (new HtmlBody($uploads))->document($subject, (string) $campaign->getValue(Schema::CAMPAIGN_BODY), self::isHtml($campaign, Schema::CAMPAIGN_BODY), (string) $campaign->getValue(Schema::CAMPAIGN_HEADER), PdfBody::CSS, self::isHtml($campaign, Schema::CAMPAIGN_HEADER));
        } catch (EmptyLetter $e) {
            // the editor sees them in the draft: they must not go missing silently
            if ($e->omitted !== []) {
                throw new \InvalidArgumentException(self::LEFT_OUT . implode('; ', $e->omitted));
            }
            if ($e->header || ($footer !== [])) {
                throw new \InvalidArgumentException(self::FRAME_ONLY);
            }
        }
        if (!is_null($document)) {
            $short = $makeRoom(PdfBody::memory($document));
            if ($short !== '') {
                throw new \OverflowException($short);
            }
            $files  = [(new PdfBody())->render($document, $footer)];
            $bytes  = $files[0]->size();
            $source = PdfBody::source($document, $footer);
            // up to 20 MB of images, before the attachments are read
            unset($document);
        }
        $policy = new AttachmentPolicy($uploads);
        foreach (Drafts::attachments($campaign->getValuesArray(Schema::CAMPAIGN_FILES)) as $url) {
            // past one message the trial refuses it anyway: read no more of up to 20 MB each
            if ((count($files) > Message::MAX_FILES) || ($bytes > Message::MAX_BYTES)) {
                break;
            }
            $file    = $policy->fromUrl($url, '', ($files === []) ? File::MAIN : File::ENCLOSURE);
            $files[] = $file;
            $bytes  += $file->size();
        }
        if ($files === []) {
            throw new \InvalidArgumentException(self::NOTHING);
        }
        return new self($subject, $files, (string) $campaign->getValue(Schema::CAMPAIGN_TO_HANDS), $source ?? hash('xxh128', $files[0]->content));
    }

    /** a field of rich text, as the editor of the slice stores it */
    private static function isHtml(\AA_Content $campaign, string $field): bool
    {
        // getFlag() of the content warns about a field the item has no value of
        return ($campaign->getAaValue($field)->getFlag() & FLAG_HTML) !== 0;
    }
}
