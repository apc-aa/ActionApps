<?php

declare(strict_types=1);

// misc/datovka/datovka.php without the boot of AA: include/util.php would bring the real go_url(), which
// exits. Load it only in a test of its own process - it declares go_url() and new_id().

/** what go_url() would send; an Error, so that the catch (Exception) of Datovka::run() lets it through */
final class DatovkaRedirect extends \Error
{
    public function __construct(public readonly mixed $url, public readonly mixed $params)
    {
        parent::__construct('go_url');
    }
}

function go_url($url, $add_param = '', $usejs = false, $code = 302): never
{
    throw new DatovkaRedirect($url, $add_param);
}

function new_id($mark = 0): string
{
    return '0123456789abcdef0123456789abcdef';
}

require_once __DIR__ . '/../../../../misc/datovka/datovka.php';

/** Datovka with the settings the test gives, whose SOAP calls are recorded and answered as ISDS answers them; its state stays off the database */
final class RecordingDatovka extends Datovka
{
    public static ?AA\Isds\DatovkaConf $settings = null;

    /** @var list<array{wsdl: mixed, options: mixed, method: mixed, params: mixed}> */
    public static array $calls = [];

    protected static function conf(): AA\Isds\DatovkaConf
    {
        return self::$settings ?? throw new \LogicException('no settings');
    }

    public function credentials(): object
    {
        return $this->getCredentials();
    }

    public function concept(string $timeLimitedId): object
    {
        return $this->prepareConcept($timeLimitedId);
    }

    public function buildMessage(): array
    {
        return ['dmEnvelope' => [], 'dmFiles' => []];
    }

    protected function setState(int $state, string $stateTxt = ''): void {}

    protected function soapCall($wsdl, $options, $method, $params)
    {
        self::$calls[] = ['wsdl' => $wsdl, 'options' => $options, 'method' => $method, 'params' => $params];

        return match ($method) {
            'authConfirmation' => (object) ['status' => 'OK', 'attributes' => (object) ['attribute' => [(object) ['name' => 'timeLimitedId', 'value' => 'T08-limited']]]],
            'SetConcept'       => (object) ['dmID' => '4711', 'dmStatus' => (object) ['dmStatusCode' => '0000', 'dmStatusMessage' => 'OK']],
        };
    }
}
