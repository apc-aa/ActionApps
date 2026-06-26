<?php

const DATOVKA_CONF = [
    'instances' => [
        1000 => ['url'=>'https://www.dataplan.info/aa-dev/'],    // id of the datovka - 1000-9999 - use it in config.php as AA_DATOVKA_ID
        1001 => ['url'=>'https://adamsbeauty.ecn.cz/aa-dev/'],   // url MUST be exactly the same as AA_INSTALL_URL in config.php
        ], // number
    'test' => [   // public test environment (Veřejný test ISDS)
        'cert'              => AA_BASE_PATH.'/config/certs/myTestCert.crt',
        'atsId'             => '',                                           // something like 249c562b87ac81a3
        // Test domain migrated czebox.cz -> datovka-test.gov.cz (same servers; verified via DNS + DIA EV TLS cert). Old czebox.cz still resolves for now.
        'url_login'         => 'https://www.datovka-test.gov.cz/as/login',
        'url_GetCredential' => 'https://cert.datovka-test.gov.cz/asws/extIs2Endpoint',
        'url_SetConcept'    => 'https://cert.datovka-test.gov.cz/asws/konceptEndpoint',
        'url_ViewConcept'   => 'https://www.datovka-test.gov.cz/as/koncept/view',
        'wsdl_GetCredential'=> __DIR__.'/wsdls/GetCredential.wsdl',
        'wsdl_SetConcept'   => __DIR__.'/wsdls/SetConcept.wsdl',
        ],
    'standard'     => [   // PRODUCTION ISDS - real messages
        'cert'              => AA_BASE_PATH.'/config/certs/myRealCert.crt',
        'atsId'             => '',                                           // something like 659c562b87ac8115
        // Primary ISDS domain migrated mojedatovaschranka.cz -> datovka.gov.cz (effective 25.6.2026; same servers, verified via DNS + DIA EV TLS cert).
        // Old www/cert.mojedatovaschranka.cz still works for a limited time. Also reachable on the public-admin network (KIVS/CMS2) via datovka.cms2.cz.
        'url_login'         => 'https://www.datovka.gov.cz/as/login',
        'url_GetCredential' => 'https://cert.datovka.gov.cz/asws/extIs2Endpoint',
        'url_SetConcept'    => 'https://cert.datovka.gov.cz/asws/konceptEndpoint',
        'url_ViewConcept'   => 'https://www.datovka.gov.cz/as/koncept/view',
        'wsdl_GetCredential'=> __DIR__.'/wsdls/GetCredential.wsdl',
        'wsdl_SetConcept'   => __DIR__.'/wsdls/SetConcept.wsdl',
        ],
    ];

