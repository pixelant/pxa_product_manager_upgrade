# t3kit_upgrade
WIP extension upgrade pxa_product_manager v8 data to v10


Plan:

Add to project repo composer.json "repositories" section.
```
{
    "type": "vcs",
    "url": "git@github.com:pixelant/pxa_product_manager_upgrade.git",
    "no-api": true
},
```

And to "require-dev":

```
"pixelant/pxa_product_manager_upgrade": "dev-master",
```

And run something like:

```
php vendor/helhum/typo3-console/typo3cms database:updateschema
php vendor/helhum/typo3-console/typo3cms cache:flush

php vendor/helhum/typo3-console/typo3cms extension:activate pxa_product_manager_upgrade
php vendor/helhum/typo3-console/typo3cms upgrade:prepare

php vendor/helhum/typo3-console/typo3cms upgrade:run productmanager_CategoryToPagesUpdateWizard
php vendor/helhum/typo3-console/typo3cms upgrade:run productmanager_CategoryToSingleViewPagesUpdateWizard
php vendor/helhum/typo3-console/typo3cms upgrade:run productmanager_AttributeSetAttributeMmUpdateWizard
php vendor/helhum/typo3-console/typo3cms upgrade:run productmanager_CategoryToProductTypeUpdateWizard
php vendor/helhum/typo3-console/typo3cms upgrade:run productmanager_ProductTypeFromCategoryUpdateWizard
php vendor/helhum/typo3-console/typo3cms upgrade:run productmanager_ProductAccessoriesProductMmUpdateWizard
php vendor/helhum/typo3-console/typo3cms upgrade:run productmanager_ProductSubProductsProductMmUpdateWizard

php vendor/helhum/typo3-console/typo3cms productmanager:fixduplicateattributevalues

php vendor/helhum/typo3-console/typo3cms extension:deactivate pxa_product_manager_upgrade
```
