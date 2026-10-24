<?php

declare(strict_types=1);

namespace AA\Isds;

use AA\Http\Client as HttpClient;
use AA\Http\ClientCertificate;
use AA\Http\ClientInterface;
use AA\Http\ResponseTooLargeException;
use AA\Http\TransportException as HttpTransportException;
use AA\Isds\Registry\Box;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The ISDS web services AA calls: send a message, follow what happened to sent ones, check a box, ask
 * whether a message to it would be paid, read the account. Times are unix seconds. An answer that cannot
 * be read whole is a TransportException, never a shorter list. A certificate that cannot work -
 * unreadable, locked, not of its key, out of its validity - is a ConfigException before anything is sent.
 */
final class Client
{
    /**
     * The only operations this client calls, with their endpoint (<host>/DS/<service>, soap:address of
     * the WSDL). Never one over received messages: a login with a system certificate has every
     * privilege, and listing the received messages delivers them by that login (WS_manipulace_s_
     * datovymi_zpravami.pdf 1.8.1) - the box's legal deadlines would start without anyone having read them.
     */
    public const OPERATIONS = [
        'CreateMessage'          => 'dz',          // dm_operations.wsdl
        'GetMessageStateChanges' => 'dx',          // dm_info.wsdl
        'GetListOfSentMessages'  => 'dx',          // dm_info.wsdl
        'CheckDataBox'           => 'df',          // db_search.wsdl
        'PDZSendInfo'            => 'df',          // db_search.wsdl
        'GetUserInfoFromLogin2'  => 'DsManage',    // db_access.wsdl
        'GetOwnerInfoFromLogin2' => 'DsManage',    // db_access.wsdl
        'GetPasswordInfo'        => 'DsManage',    // db_access.wsdl
    ];

    /**
     * The right of the user each operation needs (UserInfo, WS_manipulace_s_datovymi_zpravami.pdf 1.4);
     * 0 = none: the account questions, and PDZSendInfo any user of the box may ask (WS_vyhledavani_datovych_schranek.pdf 2.7)
     */
    public const RIGHTS = [
        'CreateMessage'          => UserInfo::CREATE_DM,
        'GetMessageStateChanges' => UserInfo::VIEW_INFO,
        'GetListOfSentMessages'  => UserInfo::VIEW_INFO,
        'CheckDataBox'           => UserInfo::SEARCH_DB,
        'PDZSendInfo'            => 0,
        'GetUserInfoFromLogin2'  => 0,
        'GetOwnerInfoFromLogin2' => 0,
        'GetPasswordInfo'        => 0,
    ];

    /** checkDataBox(): ISDS has no box of that id */
    public const NO_BOX = -1;
    /** checkDataBox(): ISDS answered without the state of the box - 0 or none is its own error (2.3),
     *  and a state it never documented says no more */
    public const NO_STATE = 0;

    /** records of one sentMessages() call. ISDS gives more on request, the answer size limit of limited() fits this */
    public const MAX_LIST = 1000;

    /**
     * Seconds of createMessage(): a 20 MB message (27 MB of base64) has to get up on a slow line with
     * no answer until the last byte, so the idle limit is the whole time. A sending waits this long
     * before it takes a step for dead - a call cut short could have delivered the message.
     */
    public const CREATE_TIMEOUT = 300;

    /** seconds of every other call: they answer in seconds, and a dead ISDS must not hold the sending or the State Check for minutes */
    public const TIMEOUT = 30;

    private const TIMEOUTS = ['CreateMessage' => self::CREATE_TIMEOUT];

    /**
     * What a certificate cannot do, said without its files: a template may show the text, the State
     * Check names the files (ClientCertificate::problem())
     */
    private const CERTIFICATE_PROBLEMS = [
        ClientCertificate::UNREADABLE        => 'PHP cannot read the certificate of the ISDS account',
        ClientCertificate::KEY_UNREADABLE    => 'PHP cannot read the private key of the ISDS account',
        ClientCertificate::NO_OPENSSL        => 'PHP has no openssl extension, which a login with a certificate needs',
        ClientCertificate::NOT_A_CERTIFICATE => 'The certificate file of the ISDS account holds no certificate',
        ClientCertificate::KEY_LOCKED        => 'The private key of the ISDS account does not open: it is missing, or the passphrase does not fit',
        ClientCertificate::KEY_MISMATCH      => 'The private key of the ISDS account does not belong to its certificate',
        ClientCertificate::WEAK_KEY          => 'The private key of the ISDS account is too short for a secure connection',
        ClientCertificate::NOT_YET_VALID     => 'The certificate of the ISDS account is not valid yet',
        ClientCertificate::EXPIRED           => 'The certificate of the ISDS account has expired',
    ];

    /** @var array<int, ClientInterface> seconds => the HTTP client under that limit */
    private array $clients = [];

    /** the certificate passed ClientCertificate::problem() */
    private bool $certificateChecked = false;

    private readonly LoginLatch $latch;

    /**
     * @param ?ClientInterface     $http      answers every operation as it is, under no limit of this client - for tests
     *                                        of a name and a password: it would skip the certificate
     * @param ?HttpClientInterface $transport the connection under the limits of http(); another one for tests only
     * @param ?LoginLatch          $latch     the refused logins; share one among the clients of a run
     * @throws \InvalidArgumentException $http for an account with a certificate
     */
    public function __construct(private readonly Account $account, private readonly ?ClientInterface $http = null, private readonly ?HttpClientInterface $transport = null, ?LoginLatch $latch = null)
    {
        if (!is_null($http) && !is_null($account->certificate)) {
            throw new \InvalidArgumentException('A login with a certificate goes through the HTTP client of AA, which sends the certificate - give a transport, not a client');
        }
        $this->latch = $latch ?? new LoginLatch();
    }

    /**
     * Lets a login ISDS refused, or the blocked address of the server, try once more - only when a
     * person asks for it, never by itself: every refused attempt extends the block.
     * @return bool a refusal or a block was forgotten
     * @throws ConfigException the certificate cannot work - there is nothing to try
     */
    public function forgetRefusal(): bool
    {
        $this->checkCertificate();

        return $this->latch->forget($this->account);
    }

    /**
     * Sends $message (CreateMessage). On any IsdsException ask mayHaveBeenSent() before ever sending
     * it again: true means look it up among the sent messages (by Message::$senderRef) first.
     * @throws IsdsException as itself refused, e.g. 1233 to a box that accepts only paid messages;
     *                       AuthException, UnavailableException, TransportException see above
     * @throws ConfigException           the certificate cannot work - nothing is sent
     */
    public function createMessage(Message $message): SendResult
    {
        $answer = $this->call(self::messageRequest($message));
        $id     = $answer->value('isds:dmID');
        return new SendResult($this->messageId($id, 'ISDS accepted the message, but'), $answer->code, $answer->text);
    }

    /**
     * The state of a data box (CheckDataBox): 1 accepts messages; 2 made inaccessible for a while, 3
     * not yet accessible, 4 inaccessible for good, 5 deleted (WS_vyhledavani_datovych_schranek.pdf 2.3);
     * NO_BOX when there is no such box, NO_STATE when ISDS did not say
     * @throws \InvalidArgumentException not a box id - nothing is sent
     * @throws IsdsException             no state read
     * @throws ConfigException           the certificate cannot work - nothing is sent
     */
    public function checkDataBox(string $box): int
    {
        try {
            $answer = $this->call(Soap::request('CheckDataBox', ['dbID' => Box::normalizeId($box)]));
        } catch (IsdsException $e) {
            if (($e->isdsCode() === '5001') && !$e->mayHaveBeenSent()) {
                return self::NO_BOX;
            }
            throw $e;
        }
        $state = $answer->value('isds:dbState');
        return in_array($state, ['1', '2', '3', '4', '5'], true) ? (int) $state : self::NO_STATE;
    }

    /**
     * Whether a message from this account to $box would go as a postal data message (PDZSendInfo with
     * PDZType Normal), which someone pays for. False when the recipient or the sender is an authority, the
     * box does not accept messages or PDZ, the user may not send, or nothing would pay for one
     * (WS_vyhledavani_datovych_schranek.pdf 2.7). True does not say who would pay: a contract or the
     * credit of the sender, or another box.
     * @throws \InvalidArgumentException not a box id - nothing is sent
     * @throws IsdsException             no answer read; TransportException for a result other than xs:boolean
     *                                   true/false/1/0 - nil or left out (dbTypes.xsd allows it) says nothing
     * @throws ConfigException           the certificate cannot work - nothing is sent
     */
    public function wouldBePaid(string $box): bool
    {
        $result  = $this->call(Soap::request('PDZSendInfo', ['dbId' => Box::normalizeId($box), 'PDZType' => 'Normal']))->value('isds:PDZsiResult');
        $unclear = is_null($result) ? 'ISDS did not say whether a message would be paid' : 'ISDS answered whether a message would be paid "' . $this->quote($result, 32) . '"';
        return match ($result) {
            'true', '1'  => true,
            'false', '0' => false,
            // a guess either way could send a paid message
            default => throw TransportException::uncertain($unclear),
        };
    }

    /**
     * State changes of sent messages between $from and $to (GetMessageStateChanges). ISDS keeps them
     * 15 days; overlap the windows, a change may be recorded a moment after its time. Acceptances
     * after 10 days (state 5) are recorded after midnight, dated 23:59:59.999 of the day before
     * (WS_manipulace_s_datovymi_zpravami.pdf 1.8.3.1, 2.9.3) - a window that has ended by then
     * misses them.
     * @return list<StateChange>
     * @throws \InvalidArgumentException the window ends before it starts - nothing is sent
     * @throws IsdsException             no list read
     * @throws ConfigException           the certificate cannot work - nothing is sent
     */
    public function stateChanges(int $from, int $to): array
    {
        self::checkWindow($from, $to);
        $answer  = $this->call(Soap::request('GetMessageStateChanges', ['dmFromTime' => Soap::dateTime($from), 'dmToTime' => Soap::dateTime($to)]));
        $changes = [];
        foreach ($answer->elements('isds:dmRecords/isds:dmRecord') as $record) {
            $id = $this->messageId($answer->value('isds:dmID', $record), 'ISDS answered a state change, but');
            $changes[] = new StateChange(
                $id,
                $this->number($answer->value('isds:dmMessageStatus', $record), "the state of message $id"),
                Soap::timestamp($answer->value('isds:dmEventTime', $record) ?? '', $this->account->redact(...)),
            );
        }
        return $changes;
    }

    /**
     * Messages sent between $from and $to in every state, newest first (GetListOfSentMessages). ISDS
     * selects and sorts them by the time they reached the recipient's box (dmDeliveryTime, WS_manipulace_s_
     * datovymi_zpravami.pdf 2.9.2); one the antivirus check refused (state 3) by the time it was
     * submitted. A message in state 1 or 2 - waiting for its time stamp or the antivirus check, at most
     * 240 s - has no delivery time yet: it may be listed anywhere, or not at all.
     * @param int $offset the first record, from 1
     * @param int $limit  1 to MAX_LIST; more is refused, not cut - a caller paging until it gets fewer
     *                    records than it asked for would stop too early
     * @return list<SentMessage>
     * @throws \InvalidArgumentException a window, offset or limit out of range - nothing is sent
     * @throws IsdsException             no list read
     * @throws ConfigException           the certificate cannot work - nothing is sent
     */
    public function sentMessages(int $from, int $to, int $offset = 1, int $limit = self::MAX_LIST): array
    {
        self::checkWindow($from, $to);
        if (($offset < 1) || ($limit < 1) || ($limit > self::MAX_LIST)) {
            throw new \InvalidArgumentException('The list of sent messages starts at 1 and has 1 to ' . self::MAX_LIST . ' records');
        }
        $answer = $this->call(Soap::request('GetListOfSentMessages', [
            'dmFromTime'         => Soap::dateTime($from),
            'dmToTime'           => Soap::dateTime($to),
            'dmSenderOrgUnitNum' => null,
            'dmStatusFilter'     => -1,     // every state
            'dmOffset'           => $offset,
            'dmLimit'            => $limit,
        ]));
        $messages = [];
        foreach ($answer->elements('isds:dmRecords/isds:dmRecord') as $record) {
            // a record skipped could be the very message a caller looks for
            $id = $this->messageId($answer->value('isds:dmID', $record), 'ISDS listed a sent message, but');
            $messages[] = new SentMessage(
                $id,
                $this->number($answer->value('isds:dmMessageStatus', $record), "the state of message $id"),
                $answer->value('isds:dmSenderRefNumber', $record) ?? '',
                $this->boxId($answer->value('isds:dbIDRecipient', $record), "the recipient of message $id"),
                $this->time($answer->value('isds:dmDeliveryTime', $record)),
                $this->time($answer->value('isds:dmAcceptanceTime', $record)),
                $answer->value('isds:dmAnnotation', $record) ?? '',
            );
        }
        return $messages;
    }

    /**
     * The logged-in user (GetUserInfoFromLogin2)
     * @throws IsdsException 2102 for a login with a certificate, or no user read
     * @throws ConfigException the certificate cannot work - nothing is sent
     */
    public function userInfo(): UserInfo
    {
        $answer = $this->call(Soap::request('GetUserInfoFromLogin2', ['dbDummy' => '']));
        if ($answer->elements('isds:dbUserInfo') === []) {
            throw TransportException::uncertain('ISDS answered without the user');
        }
        $privileges = $answer->value('isds:dbUserInfo/isds:userPrivils');
        // nil or left out (dbTypes.xsd): the rights are not known, which is no "none"
        return new UserInfo(is_null($privileges) ? null : $this->number($privileges, 'the privileges'), $answer->value('isds:dbUserInfo/isds:userType') ?? '');
    }

    /**
     * Unix seconds when the password expires, null when it does not (GetPasswordInfo): pswExpDate nil
     * (WS_souvisejici_s_pristupem_do_ISDS.pdf 1.8) or left out, which dbTypes.xsd allows
     * (tGetPasswInfoOutput, minOccurs="0" nillable="true").
     * @throws IsdsException e.g. TransportException for an empty element or one that is no date
     * @throws ConfigException the certificate cannot work - nothing is sent
     */
    public function passwordExpiry(): ?int
    {
        $expiry = $this->call(Soap::request('GetPasswordInfo', ['dbDummy' => '']))->value('isds:pswExpDate');
        return is_null($expiry) ? null : Soap::timestamp($expiry, $this->account->redact(...));
    }

    /**
     * The id of the data box the account logs in to (GetOwnerInfoFromLogin2)
     * @throws IsdsException no id read
     * @throws ConfigException the certificate cannot work - nothing is sent
     */
    public function ownerBox(): string
    {
        return $this->boxId($this->call(Soap::request('GetOwnerInfoFromLogin2', ['dbDummy' => '']))->value('isds:dbOwnerInfo/isds:dbID'), 'the data box of the login');
    }

    /**
     * CreateMessage of $message. Envelope elements in the XSD's order (gMessageEnvelopeSub), all
     * present - the empty ones nil, as in the documentation's example (2.1.3). Left out: dmOVM (ignored
     * since there are no "promoted" boxes, 2.1), dmPublishOwnID (optional) and the dmType attribute
     * (ISDS picks the type of message itself).
     */
    private static function messageRequest(Message $message): \DOMElement
    {
        $operation = Soap::request('CreateMessage', ['dmEnvelope' => [
            'dmSenderOrgUnit'       => null,
            'dmSenderOrgUnitNum'    => null,
            'dbIDRecipient'         => $message->recipient,
            'dmRecipientOrgUnit'    => null,
            'dmRecipientOrgUnitNum' => null,
            'dmToHands'             => self::orNil($message->toHands),
            'dmAnnotation'          => $message->annotation,
            'dmRecipientRefNumber'  => null,
            'dmSenderRefNumber'     => self::orNil($message->senderRef),
            'dmRecipientIdent'      => null,
            'dmSenderIdent'         => null,
            'dmLegalTitleLaw'       => null,
            'dmLegalTitleYear'      => null,
            'dmLegalTitleSect'      => null,
            'dmLegalTitlePar'       => null,
            'dmLegalTitlePoint'     => null,
            'dmPersonalDelivery'    => $message->personalDelivery,
            'dmAllowSubstDelivery'  => $message->allowSubstDelivery,
        ]]);
        $files = Soap::add($operation, 'dmFiles', []);
        foreach ($message->files as $file) {
            $element = Soap::add($files, 'dmFile', ['dmEncodedContent' => base64_encode($file->content)]);
            $element->setAttribute('dmMimeType', $file->mimeType);
            $element->setAttribute('dmFileMetaType', $file->metaType);
            $element->setAttribute('dmFileDescr', $file->name);
        }
        return $operation;
    }

    /**
     * Never a login ISDS refused before, nor from an address it blocked (LoginLatch); a refusal or a
     * block now latches it.
     * @param \DOMElement $operation of Soap::request() - nothing else may hold its document
     * @throws ConfigException the certificate cannot work - nothing was sent
     */
    private function call(\DOMElement $operation): Answer
    {
        $op = (string) $operation->localName;
        $this->checkCertificate();
        if (!is_null($blocked = $this->latch->blockedAt($this->account->environment))) {
            throw new AddressBlockedException('ISDS blocked logins from the address of this server at ' . date('Y-m-d H:i', $blocked) . ' - AA tries again ' . LoginLatch::BLOCK_HOURS . ' hours later, or when an administrator checks it again on the State Check page');
        }
        if (!is_null($refused = $this->latch->refusedAt($this->account))) {
            $changes = ($this->account->kind === Account::BASIC) ? 'the login or the password changes' : 'the certificate changes';
            throw new AuthException('ISDS refused this login at ' . date('Y-m-d H:i', $refused) . " - AA does not try it again until $changes, or an administrator checks it again on the State Check page");
        }
        $xml = Soap::envelope($operation);
        // the DOM of a message is as large as its XML, which is all the request needs
        unset($operation);
        $redact = $this->account->redact(...);
        try {
            $response = $this->http($op)->request('POST', $this->account->url(self::OPERATIONS[$op]), $xml, ['Content-Type' => 'text/xml; charset=utf-8', 'SOAPAction' => '""'] + $this->account->headers());
        } catch (HttpTransportException $e) {
            // e.g. a key the TLS library finds too weak; its text names the file
            if ($e->kind() === HttpTransportException::CLIENT_CERTIFICATE) {
                throw new ConfigException('This server could not use the certificate of the ISDS account for the connection - nothing was sent; the State Check tells more', 0, $e);
            }
            throw TransportException::fromHttp($e, $redact);
        } catch (ResponseTooLargeException $e) {
            throw TransportException::uncertain("The answer of ISDS to $op is too large", '', $e);
        }
        try {
            $answer = Soap::answer($response, $op, $redact, $this->account->longestSecret());
        } catch (AddressBlockedException $e) {
            // the address, not the login: a fingerprint latched here would outlast the block
            $this->latch->block($this->account->environment);
            throw $e;
        } catch (AuthException $e) {
            $this->latch->refuse($this->account);
            throw $e;
        } catch (IsdsException $e) {
            // a status of ISDS: it took the login; a gateway or an unreadable answer does not tell
            if (!($e instanceof TransportException) && !($e instanceof UnavailableException)) {
                $this->latch->accepted($this->account);
            }
            throw $e;
        }
        $this->latch->accepted($this->account);

        return $answer;
    }

    private function http(string $op): ClientInterface
    {
        if (!is_null($this->http)) {
            return $this->http;
        }
        $timeout = self::TIMEOUTS[$op] ?? self::TIMEOUT;

        return $this->clients[$timeout] ??= self::limited($timeout, $this->transport, $this->account->certificate);
    }

    /**
     * The HTTP client for ISDS: a list of 1000 sent messages (~2 MB) has to fit the answer limit.
     * Never a reused connection: see TransportException::fromHttp().
     */
    private static function limited(int $timeout, ?HttpClientInterface $transport, ?ClientCertificate $certificate): ClientInterface
    {
        if ($timeout <= 0) {
            // 0 is no limit at all to the HTTP library
            throw new \LogicException('An ISDS call needs a time limit');
        }

        return new HttpClient(timeout: $timeout, idle: $timeout, http: $transport, maxBytes: 8 * 1024 * 1024, reuse: false, certificate: $certificate);
    }

    /**
     * Once per client, before its first request: inside the TLS handshake a certificate that cannot
     * work fails like the network - a message that never left would count as maybe sent, and the curl
     * error would carry the path of the key.
     * @throws ConfigException
     */
    private function checkCertificate(): void
    {
        if ($this->certificateChecked || is_null($this->account->certificate)) {
            return;
        }
        $problem = $this->account->certificate->problem();
        if (!is_null($problem)) {
            throw new ConfigException((self::CERTIFICATE_PROBLEMS[$problem] ?? 'The certificate of the ISDS account cannot be used') . ' - nothing was sent; the State Check tells more');
        }
        $this->certificateChecked = true;
    }

    /** a text of ISDS for an exception message, without the login or the password */
    private function quote(string $text, int $bytes): string
    {
        return Soap::quote($this->account->redact($text), $bytes);
    }

    /**
     * dmID: at most 20 characters (tIdDm in dmBaseTypes.xsd), digits - ISDS numbers messages from a
     * sequence (WS_manipulace_s_datovymi_zpravami.pdf 2.1.3)
     */
    private function messageId(?string $id, string $context): string
    {
        if (preg_match('/^\d{1,20}\z/', (string) $id) !== 1) {
            throw TransportException::uncertain(is_null($id) ? "$context did not say the message id" : "$context answered the message id \"" . $this->quote($id, 32) . '"');
        }
        return (string) $id;
    }

    private function boxId(?string $id, string $what): string
    {
        try {
            return Box::normalizeId((string) $id);
        } catch (\InvalidArgumentException $e) {
            throw TransportException::uncertain("ISDS answered $what \"" . $this->quote((string) $id, 32) . '"', '', $e);
        }
    }

    private function number(?string $value, string $what): int
    {
        if (preg_match('/^\d{1,9}\z/', (string) $value) !== 1) {
            throw TransportException::uncertain("ISDS answered $what \"" . $this->quote((string) $value, 32) . '"');
        }
        return (int) $value;
    }

    private static function checkWindow(int $from, int $to): void
    {
        if ($from > $to) {
            throw new \InvalidArgumentException('The time window ends before it starts');
        }
    }

    private static function orNil(string $value): ?string
    {
        return ($value === '') ? null : $value;
    }

    private function time(?string $value): ?int
    {
        return (($value === null) || ($value === '')) ? null : Soap::timestamp($value, $this->account->redact(...));
    }
}
