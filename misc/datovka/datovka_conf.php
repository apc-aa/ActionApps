<?php

const DATOVKA_CONF = [
    'instances' => [
        1000 => ['url'=>'https://www.dataplan.info/aa-dev/'],    // id of the datovka - 1000-9999 - use it in config.php as AA_DATOVKA_ID
        1001 => ['url'=>'https://adamsbeauty.ecn.cz/aa-dev/'],   // url MUST be exactly the same as AA_INSTALL_URL in config.php
        ], // number
    'test' => [
        'cert'              => AA_BASE_PATH.'/config/certs/myTestCert.crt',
        'atsId'             => '',                                           // something like 249c562b87ac81a3
        'url_login'         => 'https://www.czebox.cz/as/login',
        'url_GetCredential' => 'https://cert.czebox.cz/asws/extIs2Endpoint',
        'url_SetConcept'    => 'https://cert.czebox.cz/asws/konceptEndpoint',
        'url_ViewConcept'   => 'https://www.czebox.cz/as/koncept/view',
        'wsdl_GetCredential'=> __DIR__.'/wsdls/GetCredential.wsdl',
        'wsdl_SetConcept'   => __DIR__.'/wsdls/SetConcept.wsdl',
        ],
    'standard'     => [
        'cert'              => AA_BASE_PATH.'/config/certs/myRealCert.crt',
        'atsId'             => '',                                           // something like 659c562b87ac8115
        'url_login'         => 'https://www.czebox.cz/as/login',
        'url_GetCredential' => 'https://cert.czebox.cz/asws/extIs2Endpoint',
        'url_SetConcept'    => 'https://cert.czebox.cz/asws/konceptEndpoint',
        'url_ViewConcept'   => 'https://www.czebox.cz/as/koncept/view',
        'wsdl_GetCredential'=> __DIR__.'/wsdls/GetCredential.wsdl',
        'wsdl_SetConcept'   => __DIR__.'/wsdls/SetConcept.wsdl',
        ],
    ];

