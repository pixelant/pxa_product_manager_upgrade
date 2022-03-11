<?php

namespace Pixelant\PxaProductManagerUpgrade\Updates;

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

class CategoryToSingleViewPageUpdateWizard implements UpgradeWizardInterface, ChattyInterface
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
        return 'productmanager_CategoryToSingleViewPagesUpdateWizard';
    }

    /**
     * Return the speaking name of this wizard
     *
     * @return string
     */
    public function getTitle(): string
    {
        return 'Migrate Product Categories to Single View Pages update wizard.';
    }

    /**
     * Return the description for this wizard
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Migrates Product Categories to Single View Pages Pages.';
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
            foreach ($this->mappings as $productId => $mapping) {
                $this->updateProductSingleviewPage(
                    $productId,
                    implode(',', array_unique($mapping['category_singleview_page']))
                );
            }
        }

        $this->clearMappings();
        $cnt = $this->getProductsToMigrate();

        return ($cnt === 0);

        // Category to "single view pages"
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
        $migratedProductPages = $this->fetchMigratedProductPages();
        if (empty($migratedProductPages)) {
            return true;
        }

        $cnt = $this->getProductsToMigrate();
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

    protected function getProductsToMigrate(): int
    {
        if ($this->mappings === null) {
            $this->collectAndPrepareMigrationData();
        }

        $mappingsToUpdate = array_filter($this->mappings, function ($item) {
            return $item['update'] == 1;
        });

        return count($mappingsToUpdate);
    }

    protected function collectAndPrepareMigrationData(): void
    {
        $this->migratedProductPages = $this->fetchMigratedProductPages();
        if (empty($this->migratedProductPages)) {
            return;
        }
        $this->productCategories = $this->fetchAllProductsAttachedToCategories();

        // Build array of categoryId to PageId mappings.
        if (is_array($this->migratedProductPages) && count($this->migratedProductPages) > 0) {
            foreach ($this->migratedProductPages as $row) {
                $categoryId = $row['pm_mapped_category'];
                $this->categoryToPageMappings[$categoryId] = $row['uid'];
            }
        }

        //Go through product categories array and build array of mappings.
        if (is_array($this->productCategories) && count($this->productCategories) > 0) {
            foreach ($this->productCategories as $row) {
                $categoryId = $row['categoryId'];
                $productId = $row['productId'];

                if (empty($this->categoryToPageMappings[$categoryId])) {
                    throw new \Exception('Category to page mapping is missing for categoryId:' . $categoryId, 1);
                }

                $this->mappings[$productId]['category_singleview_page'][] = $this->categoryToPageMappings[$categoryId];
                $this->mappings[$productId]['singleview_page'] = $this->fetchProductPagesByProduct($productId);
            }
        }

        // Check status
        if (is_array($this->mappings) && count($this->mappings) > 0) {
            foreach ($this->mappings as $key => $mapping) {
                $existing = $mapping['singleview_page'];
                $fromCategory = array_unique($mapping['category_singleview_page']);
                $this->mappings[$key]['update'] = (implode(',', $existing) !== implode(',', $fromCategory));
            }
        }
    }

    /**
     * Fetch all products assigned to any category.
     *
     * @return array
     */
    protected function fetchAllProductsAttachedToCategories(): array
    {
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('sys_category_record_mm');
        $queryBuilder->getRestrictions()->removeAll();

        $records = $queryBuilder->select('prod.uid as productId', 'sc.uid as categoryId')
            ->from('sys_category_record_mm', 'scrm')
            ->join(
                'scrm',
                'tx_pxaproductmanager_domain_model_product',
                'prod',
                $queryBuilder->expr()->eq(
                    'prod.uid',
                    $queryBuilder->quoteIdentifier('scrm.uid_foreign')
                )
            )
            ->join(
                'scrm',
                'sys_category',
                'sc',
                $queryBuilder->expr()->eq(
                    'sc.uid',
                    $queryBuilder->quoteIdentifier('scrm.uid_local')
                )
            )
            ->where(
                $queryBuilder->expr()->eq(
                    'tablenames',
                    $queryBuilder->createNamedParameter('tx_pxaproductmanager_domain_model_product', \PDO::PARAM_STR)
                ),
                $queryBuilder->expr()->eq(
                    'fieldname',
                    $queryBuilder->createNamedParameter('categories', \PDO::PARAM_STR)
                ),
                $queryBuilder->expr()->eq(
                    'prod.sys_language_uid',
                    $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    'prod.deleted',
                    $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    'sc.sys_language_uid',
                    $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    'sc.deleted',
                    $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)
                )
            )
            ->groupBy('prod.uid', 'sc.uid')
            ->orderBy('prod.uid')
            ->orderBy('sc.uid')
            ->execute()
            ->fetchAllAssociative();

        return $records ?? [];
    }

    protected function fetchMigratedProductPages(): array
    {
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->getRestrictions()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $records = $queryBuilder->select('uid', 'pm_mapped_category')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->neq(
                    'pm_mapped_category',
                    $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)
                )
            )
            ->execute()
            ->fetchAllAssociative();

        return $records ?? [];
    }

    protected function updateProductSingleviewPage(int $productId, string $singleviewPages): void
    {
        $data['tx_pxaproductmanager_domain_model_product'][$productId] = [
            'singleview_page' => $singleviewPages,
        ];

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

    protected function fetchProductPagesByProduct(int $productId): array
    {
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_pxaproductmanager_product_pages_mm');
        $queryBuilder->getRestrictions()->removeAll();

        $records = $queryBuilder->select('uid_foreign')
            ->from('tx_pxaproductmanager_product_pages_mm')
            ->where(
                $queryBuilder->expr()->eq(
                    'tablenames',
                    $queryBuilder->createNamedParameter('pages', \PDO::PARAM_STR)
                ),
                $queryBuilder->expr()->eq(
                    'fieldname',
                    $queryBuilder->createNamedParameter('doktype', \PDO::PARAM_STR)
                ),
                $queryBuilder->expr()->eq(
                    'uid_local',
                    $queryBuilder->createNamedParameter($productId, \PDO::PARAM_INT)
                ),
            )
            ->orderBy('sorting')
            ->execute()
            ->fetchAllAssociative();

        return array_column($records, 'uid_foreign') ?? [];
    }

    /**
     * Clear mapping arrays.
     *
     * @return void
     */
    protected function clearMappings(): void
    {
        $this->categoryToPageMappings = null;
        $this->migratedProductPages = null;
        $this->productCategories = null;
        $this->mappings = null;
    }
}
