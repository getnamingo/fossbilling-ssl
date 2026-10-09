<?php
/** Copy OUTSIDE the web root; owner root, readable by the PHP worker. Never commit real secrets. */
return [
    'namingo_issuer' => [
        'label' => 'Namingo managed ACME',
        'base_url' => 'https://issuer.example.com',
        'token' => getenv('NAMINGO_ISSUER_API_TOKEN'),
        'wildcard' => true,
    ],
    // For the bundled HTTPS management simulator, enable this instead:
    // 'remote_simulator' => [
    //     'label' => 'Remote simulation — no certificates', 'emulated' => true,
    //     'base_url' => 'https://localhost:9443', 'token' => getenv('NAMINGO_SIMULATOR_TOKEN'),
    //     'ca_file' => '/absolute/path/to/simulator-ca.pem', 'wildcard' => true,
    // ],
];
