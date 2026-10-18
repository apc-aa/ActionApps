<?php

declare(strict_types=1);

namespace AA\Isds;

/** One record of the list of sent messages (GetListOfSentMessages) */
final readonly class SentMessage
{
    /** dmMessageStatus of a message the antivirus check refused, or took over 240 s on: never delivered, a final state */
    public const AV_REFUSED = 3;

    /**
     * @param int    $status      dmMessageStatus 1-10 (WS_manipulace_s_datovymi_zpravami.pdf 1.5)
     * @param string $senderRef   dmSenderRefNumber, '' when the message had none - how a message whose
     *                            sending got no answer is found again
     * @param string $recipient   dbIDRecipient
     * @param ?int   $deliveredAt unix seconds it reached the recipient's box, null not yet
     * @param ?int   $acceptedAt  unix seconds of the acceptance (login or 10 days), null not yet
     * @param string $annotation  dmAnnotation, the subject as ISDS keeps it (Text::isdsKey())
     */
    public function __construct(
        public string $dmId,
        public int $status,
        public string $senderRef,
        public string $recipient,
        public ?int $deliveredAt,
        public ?int $acceptedAt,
        public string $annotation,
    ) {}
}
