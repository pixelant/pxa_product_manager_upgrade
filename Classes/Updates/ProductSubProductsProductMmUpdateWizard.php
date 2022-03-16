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

class ProductSubProductsProductMmUpdateWizard implements UpgradeWizardInterface, ChattyInterface
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
        return 'productmanager_ProductSubProductsProductMmUpdateWizard';
    }

    /**
     * Return the speaking name of this wizard
     *
     * @return string
     */
    public function getTitle(): string
    {
        return 'Migrate Product SubProducts to Product Parent Product.';
    }

    /**
     * Return the description for this wizard
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Migrates Product SubProducts to Product Parent Product.';
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
        // Not been able to test yet, upgraded project sub_products where not same product_type as parent.
        // $this->migrateSubProducts();

        $cnt = $this->countMissingRecords();
        $notMigratable = count($this->fetchProductSubProductsProductMmRecords()) - $cnt;

        if ($notMigratable > 0) {
            $this->output->writeln('There are ' . $notMigratable . ' sub products that can\'t be migrated due to different product_types.');
        }

        return $cnt == 0;
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
        return $this->countMissingRecords() > 0;
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
     * Migrate related products.
     *
     * @return void
     */
    protected function migrateSubProducts(): void
    {
        $mmRecords = $this->fetchProductSubProductsProductMmRecords();
        foreach ($mmRecords as $mmRecord) {
            if (empty($mmRecord['tparm_uid_local'])) {
                $this->createNewMmRelation($mmRecord);
            }
        }

        $subProducts = $this->fetchProductSubProductsProductMmRecords();
        $migrationData = [];

        // Build array of related products per product.
        foreach ($subProducts as $row) {
            // Don't set parent product unless product types are the same.
            if ($row['parent_product_type'] === $row['child_product_type']) {
                $this->updateProductParent($row['child_uid'], $row['parent_uid']);
            }
        }
    }

    protected function countMissingRecords(): int
    {
        $cnt = 0;
        $mmRecords = $this->fetchProductSubProductsProductMmRecords();
        foreach ($mmRecords as $mmRecord) {
            if ($mmRecord['parent_product_type'] === $mmRecord['child_product_type']) {
                $cnt++;
            }
        }

        return $cnt;
    }

    /**
     * Fetch AttributeSet Attribute MM Records with join between new and old table.
     *
     * @return array
     */
    protected function fetchProductSubProductsProductMmRecords(): array
    {
        $fields = [
            'tpaam.uid_local as parent_uid',
            'tpaam.uid_foreign as child_uid',
            'parent.product_type as parent_product_type',
	        'child.product_type as child_product_type',
        ];

        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_pxaproductmanager_product_subproducts_product_mm');
        $queryBuilder->getRestrictions()->removeAll();

        $records = $queryBuilder->select(...$fields)
            ->from('tx_pxaproductmanager_product_subproducts_product_mm', 'tpaam')
            ->join(
                'tpaam',
                'tx_pxaproductmanager_domain_model_product',
                'parent',
                $queryBuilder->expr()->eq(
                    'parent.uid',
                    $queryBuilder->quoteIdentifier('tpaam.uid_local')
                )
            )
            ->join(
                'tpaam',
                'tx_pxaproductmanager_domain_model_product',
                'child',
                $queryBuilder->expr()->eq(
                    'child.uid',
                    $queryBuilder->quoteIdentifier('tpaam.uid_foreign')
                )
            )
            ->where(
                $queryBuilder->expr()->eq(
                    'parent.deleted',
                    $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    'child.deleted',
                    $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)
                ),
                $queryBuilder->expr()->neq(
                    'child.parent',
                    $queryBuilder->quoteIdentifier('parent.uid')
                )
            )
            ->orderBy('tpaam.uid_local')
            ->addOrderBy('tpaam.sorting')
            ->execute()
            ->fetchAllAssociative();

        return $records ?? [];
    }


    /**
     * Update product accessories.
     *
     * @param string $childId
     * @param string $parentId
     * @return void
     */
    protected function updateProductParent(string $childId, string $parentId): void
    {
        $data['tx_pxaproductmanager_domain_model_product'][$childId]['parent'] = 'tx_pxaproductmanager_domain_model_product_' . $parentId;

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
}
