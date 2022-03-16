<?php

defined('TYPO3_MODE') || die();

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ext/install']['update']['productmanager_CategoryToPagesUpdateWizard']
    = Pixelant\PxaProductManagerUpgrade\Updates\CategoryToPagesUpdateWizard::class;

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ext/install']['update']['productmanager_CategoryToSingleViewPagesUpdateWizard']
    = Pixelant\PxaProductManagerUpgrade\Updates\CategoryToSingleViewPageUpdateWizard::class;

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ext/install']['update']['productmanager_CategoryToProductTypeUpdateWizard']
    = Pixelant\PxaProductManagerUpgrade\Updates\CategoryToProductTypeUpdateWizard::class;

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ext/install']['update']['productmanager_AttributeSetAttributeMmUpdateWizard']
    = Pixelant\PxaProductManagerUpgrade\Updates\AttributeSetAttributeMmUpdateWizard::class;

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ext/install']['update']['productmanager_ProductTypeFromCategoryUpdateWizard']
    = Pixelant\PxaProductManagerUpgrade\Updates\ProductTypeFromCategoryUpdateWizard::class;

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ext/install']['update']['productmanager_ProductAccessoriesProductMmUpdateWizard']
    = Pixelant\PxaProductManagerUpgrade\Updates\ProductAccessoriesProductMmUpdateWizard::class;

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ext/install']['update']['productmanager_ProductSubProductsProductMmUpdateWizard']
    = Pixelant\PxaProductManagerUpgrade\Updates\ProductSubProductsProductMmUpdateWizard::class;

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ext/install']['update']['productmanager_ProductRelatedProductsProductMmUpdateWizard']
    = Pixelant\PxaProductManagerUpgrade\Updates\ProductRelatedProductsProductMmUpdateWizard::class;
