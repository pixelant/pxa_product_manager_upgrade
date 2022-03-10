<?php

defined('TYPO3_MODE') || die;

(function (): void {
    $columns = [
        'pm_mapped_category' => [
            'exclude' => false,
            'label' => 'Product Manager - Upgrade Wizard Map Product Type to Cateory id.',
            'config' => [
                'type' => 'input',
                'size' => 30,
            ],
        ],
    ];

    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns(
        'tx_pxaproductmanager_domain_model_producttype',
        $columns
    );

    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes(
        'tx_pxaproductmanager_domain_model_producttype',
        'pm_mapped_category',
        '',
        ''
    );
})();
