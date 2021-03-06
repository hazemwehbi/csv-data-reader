<?php
return [
    // A table name to hold the imported data
    'importTableName' => 'users',

    // A CSV separator
    'csvSeparator' => ',',

    // Meta (DB,Transformer,Validator)
    'columnMapping' => [
        'name' => [
            'type' => 'string',
            'nullable' => false,
            'validator' => ['string' => ['min_length' => 1, 'max_length' => 255]],
            'transformer' => ['lower', 'ucfirst']
        ],
        'surname' => [
            'type' => 'string',
            'nullable' => false,
            'validator' => ['string' => ['min_length' => 1, 'max_length' => 255]],
            'transformer' => ['lower', 'ucfirst']
        ],
        'email' => [
            'type' => 'string',
            'unique' => true,
            'nullable' => false,
            'validator' => ['email'],
            'transformer' => ['lower']
        ]
    ],

    'log' => [
        'dir' => './',
        'filename' => 'uploader.log',
    ]
];
