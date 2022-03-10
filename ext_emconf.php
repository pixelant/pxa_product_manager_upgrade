<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'pxa_product_manager_upgrade',
    'description' => 'pxa_product_manager_upgrade',
    'version' => '10.0.0',
    'category' => 'templates',
    'constraints' => [
        'depends' => [
            'typo3' => '10.4.0-10.4.99',
            'pxa_product_manager' => '10.0.0-10.4.99'
        ],
        'conflicts' => [
            'css_styled_content' => '*',
            'fluid_styled_content' => '*',
        ],
    ],
    'state' => 'beta',
    'uploadfolder' => 0,
    'createDirs' => '',
    'clearCacheOnLoad' => 1,
    'author' => '',
    'author_email' => '',
    'author_company' => '',
    'autoload' => [
        'psr-4' => [
            'Pixelant\\PxaProductManagerUpgrade\\' => 'Classes'
        ],
    ],
];
