# t3kit_upgrade
WIP extension upgrade t3kit based installation from v8 to v10

Notes:
Imported DB upgraded to TYPO3 version 10.

Plan:

Add to project repo composer.json "repositories" section.
```
{
    "type": "vcs",
    "url": "git@github.com:t3kit/t3kit_upgrade.git",
    "no-api": true
},
```

And to "require-dev":

```
"t3kit/t3kit-upgrade": "dev-main",
```

And run something like:

```
php vendor/helhum/typo3-console/typo3cms database:updateschema
php vendor/helhum/typo3-console/typo3cms cache:flush

php vendor/helhum/typo3-console/typo3cms extension:activate t3kit_upgrade
php vendor/helhum/typo3-console/typo3cms upgrade:prepare

php vendor/helhum/typo3-console/typo3cms upgrade:run t3kitUpgrade_gridelementsUpdateWizard
php vendor/helhum/typo3-console/typo3cms upgrade:run t3kitUpgrade_backendLayoutUpdateWizard
php vendor/helhum/typo3-console/typo3cms upgrade:run t3kitUpgrade_contentElementUpdateWizard

php vendor/helhum/typo3-console/typo3cms extension:deactivate t3kit_upgrade
```
