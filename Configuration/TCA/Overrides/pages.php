<?php

defined('TYPO3_MODE') || die;

(function (): void {
    $columns = [
        'pm_mapped_category' => [
            'exclude' => false,
            'label' => 'Product Manager - Upgrade Wizard Map Product Page to Cateory id.',
            'config' => [
                'type' => 'input',
                'size' => 30,
            ],
        ],
    ];

    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns(
        'pages',
        $columns
    );

    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes(
        'pages',
        'pm_mapped_category',
        '',
        ''
    );
})();
