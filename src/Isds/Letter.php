<?php

declare(strict_types=1);

namespace AA\Isds;

use AA\Isds\Body\HtmlBody;
use AA\Upload\UploadDir;

/**
 * What a mass sending sends to every recipient: the subject, "to hands", the letter (Dopis.html) and
 * the attachments - checked as a data message is, so that a sending ISDS would refuse for everyone is
 * refused before it starts.
 */
final readonly class Letter
{
    /** any well-formed box: the trial message checks the content, not the recipient */
    private const TRIAL_BOX = 'aaaaaaa';

    /** dmAnnotation, trimmed as Message keeps it */
    public string $annotation;
    /** dmToHands, '' = none */
    public string $toHands;
    /** @var list<File> the letter (File::MAIN) first, then the attachments */
    public array $files;

    /**
     * @param array<File> $files
     * @throws \InvalidArgumentException a data message cannot carry it; the message may quote a file
     *                                   name - escape it when shown
     */
    public function __construct(string $annotation, array $files, string $toHands = '')
    {
        $trial            = new Message(self::TRIAL_BOX, $annotation, $files, '', $toHands);
        $this->annotation = $trial->annotation;
        $this->toHands    = $trial->toHands;
        $this->files      = $trial->files;
    }

    /**
     * The letter of a campaign item: the letterhead above the body as Dopis.html, then the files of
     * its attachment field
     * @param string    $letterhead lines of plain text (Setup::$letterhead) - the sender, an address
     * @param UploadDir $uploads    of the campaign slice: images of the body and the attachments
     * @throws \InvalidArgumentException|\RuntimeException what to fix in the campaign; the message
     *                                   quotes file names and URLs - escape it when shown
     */
    public static function of(\AA_Content $campaign, string $letterhead, UploadDir $uploads): self
    {
        $subject = (string) $campaign->getValue(Schema::CAMPAIGN_SUBJECT);
        // getFlag() of the content warns about a field the item has no value of
        $html    = ($campaign->getAaValue(Schema::CAMPAIGN_BODY)->getFlag() & FLAG_HTML) !== 0;
        $files   = [(new HtmlBody($uploads))->render($subject, (string) $campaign->getValue(Schema::CAMPAIGN_BODY), $html, $letterhead)];
        $bytes   = $files[0]->size();
        $policy  = new AttachmentPolicy($uploads);
        foreach ($campaign->getValuesArray(Schema::CAMPAIGN_FILES) as $url) {
            $url = trim((string) $url);
            // past one message the trial refuses it anyway: read no more of up to 20 MB each
            if ((count($files) > Message::MAX_FILES) || ($bytes > Message::MAX_BYTES)) {
                break;
            }
            if ($url !== '') {
                $file    = $policy->fromUrl($url);
                $files[] = $file;
                $bytes  += $file->size();
            }
        }
        return new self($subject, $files, (string) $campaign->getValue(Schema::CAMPAIGN_TO_HANDS));
    }
}
