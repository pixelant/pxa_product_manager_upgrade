<?php

namespace Pixelant\PxaProductManagerUpgrade\Updates;

use PhpParser\Node\Expr\Cast\String_;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;
use TYPO3\CMS\Install\Updates\ChattyInterface;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

class CategoryToProductTypeUpdateWizard implements UpgradeWizardInterface, ChattyInterface
{

    protected const KEY_MIGRATED_PAGE = '_migratedToPage';

    protected const KEY_CHILD_CATEGORIES = '_childs';

    protected const ROWDESCRIPTION_PREFIX = 'product_manager_category|';

    /**
     * @var array
     */
    protected $categoryToPageMappings = null;

    /**
     * @var array
     */
    protected $migratedProductPages = null;

    /**
     * @var array
     */
    protected $productCategories = null;

    /**
     * @var array
     */
    protected $mappings = null;

    /**
     * Return the identifier for this wizard
     * This should be the same string as used in the ext_localconf class registration
     *
     * @return string
     */
    public function getIdentifier(): string
    {
        return 'productmanager_CategoryToProductTypeUpdateWizard';
    }

    /**
     * Return the speaking name of this wizard
     *
     * @return string
     */
    public function getTitle(): string
    {
        return 'Migrate Product Categories to Product Type update wizard.';
    }

    /**
     * Return the description for this wizard
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Migrates Product Categories to Product Type Pages Pages.';
    }

    /**
     * Execute the update
     *
     * Called when a wizard reports that an update is necessary
     *
     * @return bool
     */
    public function executeUpdate(): bool
    {
        $this->collectAndPrepareMigrationData();

        if (is_array($this->mappings) && count($this->mappings) > 0) {
            foreach ($this->mappings as $productTypeId => $mapping) {
                $this->updateProductType($productTypeId, $mapping);
            }
        }

        $this->clearMappings();
        $cnt = $this->getProductTypesToMigrate();

        return ($cnt === 0);
    }

    /**
     * Is an update necessary?
     *
     * Is used to determine whether a wizard needs to be run.
     * Check if data for migration exists.
     *
     * @return bool Whether an update is required (TRUE) or not (FALSE)
     */
    public function updateNecessary(): bool
    {
        $cnt = $this->getProductTypesToMigrate();
        $this->clearMappings();

        return $cnt > 0;
    }

    /**
     * Returns an array of class names of prerequisite classes
     *
     * This way a wizard can define dependencies like "database up-to-date" or
     * "reference index updated"
     *
     * @return string[]
     */
    public function getPrerequisites(): array
    {
        return [
            DatabaseUpdatedPrerequisite::class
        ];
    }

    /**
     * @param OutputInterface $output
     */
    public function setOutput(OutputInterface $output): void
    {
        $this->output = $output;
    }

    protected function getProductTypesToMigrate(): int
    {
        if ($this->mappings === null) {
            $this->collectAndPrepareMigrationData();
        }

        return count($this->mappings);
    }

    protected function collectAndPrepareMigrationData(): void
    {
        $categoriesWithAttributeSets = $this->getCategoriesWithAttributeSets();
        $mappedProductTypes = $this->fetchMappedProductTypes();

        // Build array of categoryId to PageId mappings.
        if (is_array($categoriesWithAttributeSets) && count($categoriesWithAttributeSets) > 0) {
            foreach ($categoriesWithAttributeSets as $categoryId => $categoryData) {
                if (empty($mappedProductTypes[$categoryId])) {
                    $uid = StringUtility::getUniqueId('NEW');
                    $this->mappings[$uid] = [
                        'pid' => $categoryData['pid'],
                        'name' => $categoryData['title'],
                        'pm_mapped_category' => $categoryId,
                        'attribute_sets' => implode(',', array_unique($categoryData['attribute_sets'] ?? [])),
                    ];
                } else {
                    $uid = $mappedProductTypes[$categoryId];
                    $categoryAttributes = implode(',', array_unique($categoryData['attribute_sets'] ?? []));
                    $productTypeAttributes = implode(',', array_unique($mappedProductTypes[$categoryId]['attribute_sets'] ?? []));
                    if ($categoryAttributes !== $productTypeAttributes) {
                        $this->mappings[$uid] = [
                            'pid' => $categoryData['pid'],
                            'name' => $categoryData['title'],
                            'pm_mapped_category' => $categoryId,
                            'attribute_sets' => implode(',', array_unique($categoryData['attribute_sets'] ?? [])),
                        ];
                    }                }
            }
        }

        if ($this->mappings === null) {
            $this->mappings = [];
        }
    }

    protected function updateProductType(string $producttypeId, array $fields): void
    {
        $data['tx_pxaproductmanager_domain_model_producttype'][$producttypeId] = $fields;

        // echo PHP_EOL . ' data: ' . print_r($data, true) . PHP_EOL;
        // Disable DataHandler hooks for processing this update.
        if (!empty($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php'])) {
            $dataHandlerHooks = $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php'];
            unset($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']);
        }

        if (!empty($GLOBALS['BE_USER'])) {
            $adminUser = $GLOBALS['BE_USER'];
        }
        // Admin user is required to defined workspace state when working with DataHandler.
        $fakeAdminUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $fakeAdminUser->user = ['uid' => 0, 'username' => '_migration_', 'admin' => 1];
        $fakeAdminUser->workspace = 0;
        $GLOBALS['BE_USER'] = $fakeAdminUser;

        // Process updates.
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($data, []);
        $dataHandler->process_datamap();

        // Restore user and hooks.
        if (!empty($adminUser)) {
            $GLOBALS['BE_USER'] = $adminUser;
        }
        if (!empty($dataHandlerHooks)) {
            $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php'] = $dataHandlerHooks;
        }
    }

    protected function getCategoriesWithAttributeSets(): array
    {
        $categoriesWithAttributeSets = [];

        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_pxaproductmanager_category_attributeset_mm');
        $queryBuilder->getRestrictions()->removeAll();
        $records = $queryBuilder->select('sc.pid as categoryPid', 'sc.uid as categoryId', 'sc.title as categoryTitle', 'tpdma.uid as attributeSetId')
            ->from('tx_pxaproductmanager_category_attributeset_mm', 'tpcam')
            ->join(
                'tpcam',
                'sys_category',
                'sc',
                $queryBuilder->expr()->eq(
                    'sc.uid',
                    $queryBuilder->quoteIdentifier('tpcam.uid_local')
                )
            )
            ->join(
                'tpcam',
                'tx_pxaproductmanager_domain_model_attributeset',
                'tpdma',
                $queryBuilder->expr()->eq(
                    'tpdma.uid',
                    $queryBuilder->quoteIdentifier('tpcam.uid_foreign')
                )
            )
            ->where(
                $queryBuilder->expr()->eq(
                    'sc.deleted',
                    $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    'tpdma.deleted',
                    $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    'sc.sys_language_uid',
                    $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    'tpdma.sys_language_uid',
                    $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)
                )
            )
            ->orderBy('sc.uid')
            ->addOrderBy('tpdma.uid')
            ->execute()
            ->fetchAllAssociative();

        if (!empty($records)) {
            foreach ($records as $record) {
                $categoriesWithAttributeSets[$record['categoryId']]['attribute_sets'][] = $record['attributeSetId'];
                $categoriesWithAttributeSets[$record['categoryId']]['title'] = $record['categoryTitle'];
                $categoriesWithAttributeSets[$record['categoryId']]['pid'] = $record['categoryPid'];
            }
        }

        return $categoriesWithAttributeSets;
    }

    /**
     * Fetch mapped producttypes.
     *
     * Returns array with category id as key and producttype id as value.
     *
     * @return array
     */
    protected function fetchMappedProductTypes(): array
    {
        $mappedProductTypes = [];
        // SELECT * FROM tx_pxaproductmanager_domain_model_producttype tpdmp WHERE pm_mapped_category != 0;
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_pxaproductmanager_domain_model_producttype');
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->getRestrictions()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $records = $queryBuilder->select('tpdmp.uid', 'tpdmp.pm_mapped_category', 'tparm.uid_local')
            ->from('tx_pxaproductmanager_domain_model_producttype', 'tpdmp')
            ->leftJoin(
                'tpdmp',
                'tx_pxaproductmanager_attributeset_record_mm',
                'tparm',
                $queryBuilder->expr()->andX(
                    $queryBuilder->expr()->eq(
                        'tparm.uid_foreign',
                        $queryBuilder->quoteIdentifier('tpdmp.uid')
                    ),
                    $queryBuilder->expr()->eq(
                        'tparm.tablenames',
                        $queryBuilder->createNamedParameter('tx_pxaproductmanager_domain_model_producttype', \PDO::PARAM_STR)
                    ),
                    $queryBuilder->expr()->eq(
                        'tparm.fieldname',
                        $queryBuilder->createNamedParameter('product_type', \PDO::PARAM_STR)
                    )
                )
            )
            ->where(
                $queryBuilder->expr()->neq(
                    'pm_mapped_category',
                    $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)
                )
            )
            ->orderBy('uid')
            ->execute()
            ->fetchAllAssociative();

        if (!empty($records)) {
            foreach ($records as $record) {
                $mappedProductTypes[$record['pm_mapped_category']]['uid'] = $record['uid'];
                $mappedProductTypes[$record['pm_mapped_category']]['attribute_sets'][] = $record['uid_local'];
            }
        }

        return $mappedProductTypes;
    }

    /**
     * Clear mapping arrays.
     *
     * @return void
     */
    protected function clearMappings(): void
    {
        $this->mappings = null;
    }
}
