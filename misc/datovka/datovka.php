<?php

require_once __DIR__."/datovka_conf.php";

/** extends aa_object for the possibility to store it to object store */
class Datovka extends AA_Object {

    protected int $datovka_id;              // mmmmmmmmmmmaaaa   message_id.aa_datovka_id

    protected string $return_url = '';      // return url for the datovka

    protected string $state = '';           // state of the sending process - just for logging purposes - 1:started, 20:timeLimitedId received, 30:concept prepared, 80:sent, 900:Wrong server answer , 920:timeLimitedId not received, 930:concept not prepared, 980:concept not sent
                                            // we use bit flags to keep track of the process starting from 16 and continue with 2**n where we use n=4,7,10,14,15 (to have reserve) 15 if for errors, the first bites +1 +2.. used for different errors
    protected string $stateTxt = '';        // description of last state of the sending process - just for logging purposes - 1:started, 20:timeLimitedId received, 30:concept prepared, 80:sent, 900:error, 920:timeLimitedId not received, 930:concept not prepared, 980:concept not sent
    protected string $stateTime = '';       // time of the last state change

    protected string $related_to = '';      // may be ItemId to identify the message

    // must be protected or public - AA_Object needs to read it
    protected string $conf = 'standard';    // 'test' | 'standard'

    protected string $attachments;            // file paths/names
    protected string $dbIDRecipient;        // $recipientId,            required
    protected string $dmAnnotation;         // "Testovaci zprava - PHP" - like Subject, max 255
    protected string $dmToHands;            // "K rukam p.Novaka",
    protected string $dmRecipientRefNumber; // "vcj. 253",
    protected string $dmRecipientIdent;     // "VZ-147",
    protected string $dmRecipientOrgUnit;   // "Vase org jednotka",
    protected string $dmRecipientOrgUnitNum; // null,
    protected string $dmSenderRefNumber;    // "ncj. 589",
    protected string $dmSenderIdent;        // "NZ-557",
    protected string $dmSenderOrgUnit;      // "Nase org jednotka",
    protected string $dmSenderOrgUnitNum;   // null,
    protected string $dmLegalTitleLaw;      // "300",
    protected string $dmLegalTitleYear;     // "2008",
    protected string $dmLegalTitleSect;     // "2",
    protected string $dmLegalTitlePar;      // "1",
    protected string $dmLegalTitlePoint;    // "a",
    protected bool $dmPersonalDelivery = false;
    protected bool $dmAllowSubstDelivery = false;

    public function __construct(string $conf = 'standard', string $to = '', string $annotation = '', string $attachments = '', array $params = [], string $return_url='', string $module_id='', string $related_to='') {
        $system_id = array_keys(array_filter(DATOVKA_CONF['instances'], fn($v) => $v['url'] === AA_INSTAL_URL))[0] ?? 1000;
        $this->datovka_id            = random_int(10000000000, 99999999999) * 10000 + $system_id;   // mmmmmmmmmmmaaaa   message_id.aa_did
        $this->setNew(new_id(), $module_id, "DS {$this->datovka_id} ".date("Y-m-d H:i:s"));

        $this->conf                  = $conf;
        $this->return_url            = $return_url;
        $this->state                 = 2**4;  // started  16
        $this->stateTxt              = 'Start';
        $this->stateTime             = time();
        $this->related_to            = $related_to;
        $this->attachments           = $attachments;
        $this->dbIDRecipient         = $to;
        $this->dmAnnotation          = $annotation;
        $this->dmToHands             = $params['dmToHands'] ?? '';
        $this->dmRecipientRefNumber  = $params['dmRecipientRefNumber'] ?? '';
        $this->dmRecipientIdent      = $params['dmRecipientIdent'] ?? '';
        $this->dmRecipientOrgUnit    = $params['dmRecipientOrgUnit'] ?? '';
        $this->dmRecipientOrgUnitNum = $params['dmRecipientOrgUnitNum'] ?? '';
        $this->dmSenderRefNumber     = $params['dmSenderRefNumber'] ?? '';
        $this->dmSenderIdent         = $params['dmSenderIdent'] ?? '';
        $this->dmSenderOrgUnit       = $params['dmSenderOrgUnit'] ?? '';
        $this->dmSenderOrgUnitNum    = $params['dmSenderOrgUnitNum'] ?? '';
        $this->dmLegalTitleLaw       = $params['dmLegalTitleLaw'] ?? '';
        $this->dmLegalTitleYear      = $params['dmLegalTitleYear'] ?? '';
        $this->dmLegalTitleSect      = $params['dmLegalTitleSect'] ?? '';
        $this->dmLegalTitlePar       = $params['dmLegalTitlePar'] ?? '';
        $this->dmLegalTitlePoint     = $params['dmLegalTitlePoint'] ?? '';
        $this->dmPersonalDelivery    = $params['dmPersonalDelivery'] ?? false;
        $this->dmAllowSubstDelivery  = $params['dmAllowSubstDelivery'] ?? false;
    }

    public static function possiblyRedirectToAa($appToken) {
        $system_id = $appToken % 10000;
        $required_install_url = DATOVKA_CONF['instances'][$system_id]['url'];
        if (AA_INSTAL_URL !== $required_install_url) {
            go_url(DATOVKA_CONF['instances'][$system_id]['url'] . 'misc/datovka/service.php?' . http_build_query($_GET));
        }
    }

    public function getDatovkaId(): int {
        return $this->datovka_id;
    }

    public function getAtsId(): string {
        return DATOVKA_CONF[$this->conf]['atsId'];
    }

    public static function getFromToken(string $appToken): ?Datovka {
        $datovka_id                  = $appToken;
        $system_id                   = $datovka_id % 10000;
        $zids = Datovka::querySet('Datovka', new AA_Set(null, new AA_Condition('datovka_id', '==', $datovka_id)));
        if ($zids->count() !== 1) {
            echo 'Datovka not found';
            return null;
        }
        /** @var Datovka */
        return Datovka::load($zids->id(),'Datovka');
    }


    /** allows storing datovka objects to the object_* database
     *  AA_Object's method
     *  description of the values dm* - https://info.mojedatovaschranka.cz/info/cs/80.html - WS_manipulace_s_datovymi_zpravami.pdf 2.1
     */
    public static function getClassProperties(): array {
        return [ //           id           name                type        multi  persist validator, required, help, morehelp, example
                 'datovka_id'            => new AA_Property('datovka_id', _m("Datovka ID"), 'int'),  // main msg id to search for
                 'return_url'            => new AA_Property('return_url', _m("Return URL"), 'string'),
                 'attachments'           => new AA_Property('attachments', _m("Attachments"), 'string'), // file paths/names
                 'conf'                  => new AA_Property('conf', _m("Configuration"), 'string', false, true, '', false, _m('standard|test')),
                 'state'                 => new AA_Property('state', _m("State"), 'int'),
                 'stateTxt'              => new AA_Property('stateTxt', _m("State text"), 'string'),
                 'stateTime'             => new AA_Property('stateTime', _m("State time"), 'int'),
                 'related_to'            => new AA_Property('related_to', _m("Related to"), 'string', false, true, '', false),

                 'dbIDRecipient'         => new AA_Property('dbIDRecipient', _m("Recipient ID"), 'string', false, true, ['text', ['maxlength'=>7]]),                                                                // $recipientId,            required
                 'dmAnnotation'          => new AA_Property('dmAnnotation', _m("Annotation"), 'string', false, true, ['text', ['maxlength'=>255]]),                       // Textová poznámka (Věc, Předmět, Anotace)                                                       | "Testovaci zprava - PHP" - like Subject, max 255
                 'dmToHands'             => new AA_Property('dmToHands', _m("To hands"), 'string', false, true, ['text', ['maxlength'=>255]]),                            // K rukám - pomocný údaj                                                                         | "K rukam p.Novaka",
                 'dmRecipientRefNumber'  => new AA_Property('dmRecipientRefNumber', _m("Recipient reference number"), 'string', false, true, ['text', ['maxlength'=>50]]),// Číslo jednací za strany příjemce; nepovinné                                                    | "vcj. 253",
                 'dmRecipientIdent'      => new AA_Property('dmRecipientIdent', _m("Recipient identifier"), 'string', false, true, ['text', ['maxlength'=>50]] ),                   // Spisová značka ze strany příjemce; nepovinné                                                   | "VZ-147",
                 'dmRecipientOrgUnit'    => new AA_Property('dmRecipientOrgUnit', _m("Recipient org unit"), 'string', false, true, ['text', ['maxlength'=>255]]),         // Organizační jednotka příjemce slovně, nepovinné, možné upřesnění příjemce při podání z portálu | "Vase org jednotka",
                 'dmRecipientOrgUnitNum' => new AA_Property('dmRecipientOrgUnitNum', _m("Recipient org unit number"), 'int', false, true, ['int', ['min'=>1]]),           // Organizační jednotka příjemce hodnotou z číselníku; ESS, nepovinné                             | null
                 'dmSenderRefNumber'     => new AA_Property('dmSenderRefNumber', _m("Sender reference number"), 'string', false, true, ['text', ['maxlength'=>50]]),      // Číslo jednací za strany odesílatele; nepovinné                                                 | "ncj. 589",
                 'dmSenderIdent'         => new AA_Property('dmSenderIdent', _m("Sender identifier"), 'string', false, true, ['text', ['maxlength'=>50]]),                // Spisová značka a strany odesílatele; nepovinné                                                 | "NZ-557",
                 'dmSenderOrgUnit'       => new AA_Property('dmSenderOrgUnit', _m("Sender org unit"), 'string', false, true, ['text', ['maxlength'=>255]]),               // Organizační jednotka odesílatele slovně; nepovinné, mohou doplňovat ESS                        | "Nase org jednotka",
                 'dmSenderOrgUnitNum'    => new AA_Property('dmSenderOrgUnitNum', _m("Sender org unit number"), 'int', false, true, ['int', ['min'=>1]]),                 // Organizační jednotka odesílatele hodnotou z číselníku; ESS, nepovinné                          | null,
                 'dmLegalTitleLaw'       => new AA_Property('dmLegalTitleLaw', _m("Legal title law"), 'int', false, true, ['int', ['min'=>1]]),                           // Zmocnění - číslo zákona; ESS                                                                   | "300",
                 'dmLegalTitleYear'      => new AA_Property('dmLegalTitleYear', _m("Legal title year"), 'int', false, true, ['int', ['min'=>1]]),                         // Zmocnění - rok vydání zákona; ESS                                                              | "2008",
                 'dmLegalTitleSect'      => new AA_Property('dmLegalTitleSect', _m("Legal title section"), 'string', false, true, ['text', ['maxlength'=>255]]),          // Zmocnění - paragraf v zákoně; ESS                                                              | "2",
                 'dmLegalTitlePar'       => new AA_Property('dmLegalTitlePar', _m("Legal title paragraph"), 'string', false, true, ['text', ['maxlength'=>255]]),         // Zmocnění - odstavec v paragrafu; ESS                                                           | "1",
                 'dmLegalTitlePoint'     => new AA_Property('dmLegalTitlePoint', _m("Legal title point"), 'string', false, true, ['text', ['maxlength'=>255]]),           // Zmocnění - písmeno v odstavci; ESS                                                             | "a",
                 'dmPersonalDelivery'    => new AA_Property('dmPersonalDelivery', _m("Personal delivery"), 'bool'),                                                       // Příznak Do vlastních rukou - může číst pouze adresát nebo osoba s explicitně daným oprávněním  | false,
                 'dmAllowSubstDelivery'  => new AA_Property('dmAllowSubstDelivery', _m("Allow substitute delivery"), 'bool'),                                             // Náhradní doručení povoleno/nepovoleno - pouze pro některé subjekty (např. soudy)               | false
        ];

    }

    /** @throws SoapFault
     * @throws Exception
     */
    protected function getCredentials(): object {
        $wsdl = DATOVKA_CONF[$this->conf]['wsdl_GetCredential'];               // like 'Provozni_rad/pril_4/GetCredential.wsdl';

        // SOAP client options
        $options = [
            'location'   => DATOVKA_CONF[$this->conf]['url_GetCredential'],    // like 'https://cert.czebox.cz/asws/extIs2Endpoint';
            'local_cert' => DATOVKA_CONF[$this->conf]['cert'],                 // like 'certs/ecntest.crt';
            'trace'      => 1, // For debugging, allows to display last queries and responses
            'exceptions' => true,
        ];

        $params = ['sessionId' => $_REQUEST['datovkaSessionId']];

        // huhl($wsdl, $options, $params);
        return $this->soapCall($wsdl, $options, "authConfirmation", $params);
    }

    /**
     * @throws Exception
     */
    public function buildMessage(): array {  // change to protected!!
        //$this->sanitizeProperties();

        // build envelope
        $props = self::getClassProperties();
        $envelope = [];
        foreach ($props as $prop_id => $prop) {
            if (in_array(substr($prop_id,0,2), ['db', 'dm'])) {
                 $validator = $prop->getValidator();
                 if (!$validator->varempty($this->$prop_id)) {
                     $envelope[$prop_id] = $validator->sanitize($this->$prop_id);
                 }
            }
        }

        // build files
        $dmfiles = [];
        $attachments = AA_Mail::parseAttachmentsFromString($this->attachments, true);
        foreach ( $attachments as $f) {
            $dmfiles[] = [
                'dmMimeType'       => $f['type'],
                'dmFileMetaType'   => 'main',
                'dmFileGuid'       => '',
                'dmUpFileGuid'     => '',
                'dmFileDescr'      => $f['name'],
                'dmFormat'         => '',
                'dmEncodedContent' => $f['data'],
            ];
        }

        if (!array_key_exists('dbIDRecipient',$envelope)) {
            throw new Exception('Missing Recipient parameters for building message.');
        } elseif (!array_key_exists('dmAnnotation',$envelope)) {
            throw new Exception('Missing Annotation parameters for building message.');
        } elseif (!$dmfiles) {
            throw new Exception('Missing Attachments for building message.');
        }
        return ['dmEnvelope' => $envelope, 'dmFiles' => $dmfiles];
    }

    /** @throws SoapFault
     *  @throws Exception
     */
    protected function prepareConcept($timeLimitedId) {
        $wsdl = DATOVKA_CONF[$this->conf]['wsdl_SetConcept'];              // like 'Provozni_rad/pril_4/SetConcept.wsdl';

        // SOAP client options
        $options = [
            'location'   => DATOVKA_CONF[$this->conf]['url_SetConcept'],
            // like 'https://cert.czebox.cz/asws/konceptEndpoint';
            'local_cert' => DATOVKA_CONF[$this->conf]['cert'],
            // like 'certs/ecntest.crt';
            'login'      => 'ExtWS',
            'password'   => $timeLimitedId,
            'trace'      => 1,
            // For debugging, allows to display last queries and responses
            'exceptions' => true,
        ];
        $MessageCreateInput = $this->buildMessage();

        // huhl($MessageCreateInput);

        return $this->soapCall($wsdl, $options, "SetConcept", $MessageCreateInput);
    }

    /** shortcut private function for soap calls
     * @throws SoapFault
     * @throws Exception
     */
    protected function soapCall($wsdl, $options, $method, $params) {
        $client = new SoapClient($wsdl, $options);
        $result = $client->__soapCall($method, [$params]);
        // huhl('Result is', $result, 'getLastResponse', $client->__getLastResponse(), 'getLastRequest', $client->__getLastRequest(), 'getLastRequestHeaders', $client->__getLastRequestHeaders());
        if (!is_object($result)) {
            throw new Exception('No result from '. $method);
        }
        return $result;
    }

    /** Parses stdClass Object returned by SOAP call to associative array
     *   From:
     * stdClass Object (
     *   [status] => OK
     *   [userRequestIp] => 78.25.230.18
     *   [attributes] => stdClass Object (
     *     [attribute] => [
     *       [0] => stdClass Object ( [name] => timeLimitedId,       [value] => T08-9f75718882b5317d5fc4bfa4f54d62bb )
     *       [1] => stdClass Object ( [name] => appToken,            [value] => 491032843381000 )
     *       [2] => stdClass Object ( [name] => conceptDmId          [value] => )
     *       [3] => stdClass Object ( [name] => conceptStatusCode    [value] => 1205 )
     *       [4] => stdClass Object ( [name] => conceptStatusMessage [value] => Odesílatel není oprávněn odesílat Poštovní datové zprávy (např. kvůli nízkému kreditu).)
     *
     * To:
     * ["timeLimitedId"        => "T08-7d5fc4bfa0f54d52bb9f55718e82b531",
     *  "appToken"             => "492062823391000",
     *  "conceptDmId"          => "",
     *  "conceptStatusCode"    => "1205",
     *  "conceptStatusMessage" => "Odesílatel není oprávněn odesílat Poštovní datové zprávy (např. kvůli nízkému kreditu).",
     * ]
     * @param stdClass $response - response object from SOAP call
     */
    protected function parseResponseAttributes($response): array {
        $ret = [];
        $attributes = $response->attributes->attribute;
        foreach ($attributes as $attribute) {
            $ret[$attribute->name] = $attribute->value;
        }
        return $ret;
    }

    /**
     * @param  int     $state
     * @param  string  $stateTxt
     *
     * @return void
     * @throws Exception
     */
    protected function setState(int $state, string $stateTxt=''): void {
        $this->state     |= $state;  // we add the state to the existing state to keep track, where we were
        $this->stateTxt  = $stateTxt;
        $this->stateTime = time();
        $this->update(['state', 'stateTxt', 'stateTime']);
    }

    /**
     */
    public function run() {
        try {
            $result = $this->getCredentials();
            $resp   = $this->parseResponseAttributes($result);
            if ( !is_object($result) OR !$resp ) {
                $this->setState(2**15, 'Err - Wrong server answer'); // 32768
                throw new Exception('Wrong server answer');
            }

            if (array_key_exists('timeLimitedId', $resp) AND !array_key_exists('conceptStatusCode', $resp)) {
                $this->setState(2**7, "timeLimitedId received: $resp[timeLimitedId]");  // 128 - step 2

                // create concept
                $result = $this->prepareConcept($resp['timeLimitedId']);
                if ($result->dmStatus->dmStatusCode == '0000') {
                    $this->setState(2**10, 'concept prepared');  // 1024 - step 3

                    $dmID = $result->dmID;
                    go_url(DATOVKA_CONF[$this->conf]['url_ViewConcept'], ['konceptId' => $dmID]);    // DATOVKA_CONF[$this->conf]['atsId'], 'appToken' => $this->getDatovkaId()]);  // redirect to service - https://www.czebox.cz/as/login?atsId=7981e09c632b4bac&appToken=123
                    //echo '<a href="https://www.czebox.cz/as/koncept/view?konceptId=' . $dmID . '">https://www.czebox.cz/as/koncept/view?konceptId=' . $dmID . '</a><br>';
                } else {
                    $this->setState(2**15+1, 'Err - Concept not prepared');
                    throw new Exception('Concept not created: ' . $result->dmStatus->dmStatusMessage . ' (' . $result->dmStatus->dmStatusCode . ')');
                }
            } else {
                if ($result->status == 'OK' AND array_key_exists('conceptStatusCode', $resp) AND $resp['conceptStatusCode'] == '0000') {
                    $this->setState(2**14, 'sent: '. $resp['conceptStatusMessage']);  // 16384  step 4 - final
                    go_url($this->return_url, ['ds_id' => $this->datovka_id, 'ds_status' => 'OK']); // 'sent';
                }
                $this->setState(2**15+2, "Err - Concept not sent: $resp[conceptStatusCode] ($resp[conceptStatusMessage])");
                throw new Exception("Not sent: {$resp['conceptStatusMessage']} ({$resp['conceptStatusCode']})"); // the error codes and messages are in different position in response, than in timeLimitedId response
            }
        } catch (Exception $e) {
            go_url($this->return_url, ['ds_id' => $this->datovka_id, 'ds_status' => 'ERR', 'ds_msg'=>$e->getMessage()]);
        }
    }

    public function startWithLogin() {
        go_url(DATOVKA_CONF[$this->conf]['url_login'], ['atsId' => DATOVKA_CONF[$this->conf]['atsId'], 'appToken' => $this->getDatovkaId()]);  // redirect to service - https://www.czebox.cz/as/login?atsId=7981e09c632b4bac&appToken=123
    }
}
