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

class ProductRelatedProductsProductMmUpdateWizard implements UpgradeWizardInterface, ChattyInterface
{
    protected const KEY_MIGRATED_PAGE = '_migratedToPage';

    protected const KEY_CHILD_CATEGORIES = '_childs';

    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * Return the identifier for this wizard
     * This should be the same string as used in the ext_localconf class registration
     *
     * @return string
     */
    public function getIdentifier(): string
    {
        return 'productmanager_ProductRelatedProductsProductMmUpdateWizard';
    }

    /**
     * Return the speaking name of this wizard
     *
     * @return string
     */
    public function getTitle(): string
    {
        return 'Migrate Product Related Products MM table update wizard.';
    }

    /**
     * Return the description for this wizard
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Migrates Product Related Products MM table.';
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
        $this->migrateRelatedProducts();
        $this->removeMigratedProductProductMmRelations();

        return $this->fetchCountOfNoneMigratedRelatedProducts() == 0;
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
        return $this->fetchCountOfNoneMigratedRelatedProducts() > 0;
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

    /**
     * Fetch AttributeSet Attribute MM Records with join between new and old table.
     *
     * @return int
     */
    protected function fetchCountOfNoneMigratedRelatedProducts(): int
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_pxaproductmanager_product_product_mm');

        return $queryBuilder
            ->count('mm.uid_local')
            ->from('tx_pxaproductmanager_product_product_mm', 'mm')
            ->join(
                'mm',
                'tx_pxaproductmanager_domain_model_product',
                'prod1',
                $queryBuilder->expr()->eq(
                    'prod1.uid',
                    $queryBuilder->quoteIdentifier('mm.uid_local')
                )
            )
            ->join(
                'mm',
                'tx_pxaproductmanager_domain_model_product',
                'prod2',
                $queryBuilder->expr()->eq(
                    'prod2.uid',
                    $queryBuilder->quoteIdentifier('mm.uid_foreign')
                )
            )
            ->where(
                $queryBuilder->expr()->eq(
                    'mm.tablenames',
                    $queryBuilder->createNamedParameter('', \PDO::PARAM_STR)
                ),
                $queryBuilder->expr()->eq(
                    'mm.fieldname',
                    $queryBuilder->createNamedParameter('', \PDO::PARAM_STR)
                ),
                $queryBuilder->expr()->eq(
                    'prod1.deleted',
                    $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    'prod2.deleted',
                    $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)
                )
            )
            ->execute()
            ->fetchOne();
    }

    /**
     * Migrate related products.
     *
     * @return void
     */
    protected function migrateRelatedProducts(): void
    {
        $relatedProductsMm = $this->fetchUnmigratedRelatedProducts();
        $migrationData = [];

        // Build array of related products per product.
        foreach ($relatedProductsMm as $row) {
            $migrationData[$row['uid_local']][] = 'tx_pxaproductmanager_domain_model_product_' . $row['uid_foreign'];
        }

        // Update product related products.
        foreach ($migrationData as $uid => $relatedProducts) {
            $this->updateProductRelations($uid, $relatedProducts);
        }
    }

    /**
     * Fetches unmigrated product product mm records.
     *
     * @return array
     */
    protected function fetchUnmigratedRelatedProducts(): array
    {
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_pxaproductmanager_product_product_mm');
        $queryBuilder->getRestrictions()->removeAll();
        $records = $queryBuilder->select('mm.*')
            ->from('tx_pxaproductmanager_product_product_mm', 'mm')
            ->join(
                'mm',
                'tx_pxaproductmanager_domain_model_product',
                'prod1',
                $queryBuilder->expr()->eq(
                    'prod1.uid',
                    $queryBuilder->quoteIdentifier('mm.uid_local')
                )
            )
            ->join(
                'mm',
                'tx_pxaproductmanager_domain_model_product',
                'prod2',
                $queryBuilder->expr()->eq(
                    'prod2.uid',
                    $queryBuilder->quoteIdentifier('mm.uid_foreign')
                )
            )
            ->where(
                $queryBuilder->expr()->eq(
                    'mm.tablenames',
                    $queryBuilder->createNamedParameter('', \PDO::PARAM_STR)
                ),
                $queryBuilder->expr()->eq(
                    'mm.fieldname',
                    $queryBuilder->createNamedParameter('', \PDO::PARAM_STR)
                ),
                $queryBuilder->expr()->eq(
                    'prod1.deleted',
                    $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    'prod2.deleted',
                    $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)
                )
            )
            ->orderBy('mm.uid_local')
            ->addOrderBy('mm.sorting')
            ->execute()
            ->fetchAllAssociative();

        return $records ?? [];
    }

    /**
     * Update product related products.
     *
     * @param string $uid
     * @param array $relatedProducts
     * @return void
     */
    protected function updateProductRelations(string $uid, array $relatedProducts): void
    {
        $data['tx_pxaproductmanager_domain_model_product'][$uid]['related_products'] = implode(',', $relatedProducts);

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

    /**
     * Removes migrated product product mm records.
     *
     * @return void
     */
    protected function removeMigratedProductProductMmRelations(): void
    {
        $mmToRemove = $this->fetchMigratedProductProductMmRecordsToRemove();

        if(!empty($mmToRemove)) {
            foreach ($mmToRemove as $row) {
                $this->deleteMigratedProductProductMmRelation(
                    $row['uid_local'],
                    $row['uid_foreign'],
                    $row['sorting']
                );
            }
        }
    }

    /**
     * Fetch "old" product product mm records that have been migrated.
     * Note opposite uid_local and uid_foreign.
     *
     * @return array
     */
    protected function fetchMigratedProductProductMmRecordsToRemove(): array
    {
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_pxaproductmanager_product_product_mm');
        $queryBuilder->getRestrictions()->removeAll();
        $records = $queryBuilder->select('mm_old.*')
            ->from('tx_pxaproductmanager_product_product_mm', 'mm_old')
            ->join(
                'mm_old',
                'tx_pxaproductmanager_product_product_mm',
                'mm_new',
                $queryBuilder->expr()->andX(
                    $queryBuilder->expr()->eq(
                        'mm_new.uid_local',
                        $queryBuilder->quoteIdentifier('mm_old.uid_foreign')
                    ),
                    $queryBuilder->expr()->eq(
                        'mm_new.uid_foreign',
                        $queryBuilder->quoteIdentifier('mm_old.uid_local')
                    )
                )
            )
            ->where(
                $queryBuilder->expr()->eq(
                    'mm_old.tablenames',
                    $queryBuilder->createNamedParameter('', \PDO::PARAM_STR)
                ),
                $queryBuilder->expr()->eq(
                    'mm_old.fieldname',
                    $queryBuilder->createNamedParameter('', \PDO::PARAM_STR)
                ),
                $queryBuilder->expr()->eq(
                    'mm_new.tablenames',
                    $queryBuilder->createNamedParameter('tx_pxaproductmanager_domain_model_product', \PDO::PARAM_STR)
                ),
                $queryBuilder->expr()->eq(
                    'mm_new.fieldname',
                    $queryBuilder->createNamedParameter('related_products', \PDO::PARAM_STR)
                )
            )
            ->orderBy('mm_old.uid_local')
            ->addOrderBy('mm_old.sorting')
            ->execute()
            ->fetchAllAssociative();

        return $records ?? [];
    }

    /**
     * Delete product product mm record by uid_local, uid_foreign and sorting.
     * Can only delete records where tablenames and fieldname is empty.
     *
     * @param int $uidLocal
     * @param int $uidForeign
     * @param int $sorting
     * @return void
     */
    protected function deleteMigratedProductProductMmRelation(int $uidLocal, int $uidForeign, int $sorting): void
    {
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_pxaproductmanager_product_product_mm');
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->delete('tx_pxaproductmanager_product_product_mm')
            ->where(
                $queryBuilder->expr()->eq(
                    'uid_local',
                    $queryBuilder->createNamedParameter($uidLocal, \PDO::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    'uid_foreign',
                    $queryBuilder->createNamedParameter($uidForeign, \PDO::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    'sorting',
                    $queryBuilder->createNamedParameter($sorting, \PDO::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    'tablenames',
                    $queryBuilder->createNamedParameter('', \PDO::PARAM_STR)
                ),
                $queryBuilder->expr()->eq(
                    'fieldname',
                    $queryBuilder->createNamedParameter('', \PDO::PARAM_STR)
                )
            )
            ->execute();
    }
}
