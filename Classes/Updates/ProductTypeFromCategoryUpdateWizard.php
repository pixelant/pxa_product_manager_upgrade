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

class ProductTypeFromCategoryUpdateWizard implements UpgradeWizardInterface, ChattyInterface
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
        return 'productmanager_ProductTypeFromCategoryUpdateWizard';
    }

    /**
     * Return the speaking name of this wizard
     *
     * @return string
     */
    public function getTitle(): string
    {
        return 'Migrate Product Product Type from Categories update wizard.';
    }

    /**
     * Return the description for this wizard
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Migrates Product Product Type from Categories.';
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
        $mappings = $this->fetchMappingRecords();
        foreach ($mappings as $mapping) {
            if (!empty($mapping['product_type_id'])) {
                $this->updateProductProductType($mapping['product_id'], $mapping['product_type_id']);
            }
        }

        $mappings = $this->fetchMappingRecords();

        return count($mappings) == 0;
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
        $mappings = $this->fetchMappingRecords();

        return count($mappings) > 0;
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

    protected function fetchMappingRecords(): array
    {
        /*
        SELECT
            sc.uid as category_id,
            prod.uid as product_id,
            prod.product_type as prod_product_type,
            tpdmp.uid as product_type_id
        FROM tx_pxaproductmanager_category_attributeset_mm tpcam
        INNER JOIN sys_category sc
            ON sc.uid = tpcam.uid_local
        INNER JOIN tx_pxaproductmanager_domain_model_attributeset tpdma
            ON tpdma.uid = tpcam.uid_foreign
        INNER JOIN sys_category_record_mm scrm
            ON scrm.uid_local = sc.uid
            AND scrm.tablenames = 'tx_pxaproductmanager_domain_model_product'
            AND scrm.fieldname = 'categories'
        INNER JOIN tx_pxaproductmanager_domain_model_product prod
            ON prod.uid = scrm.uid_foreign
        LEFT JOIN tx_pxaproductmanager_domain_model_producttype tpdmp
            ON tpdmp.pm_mapped_category = sc.uid
        WHERE (prod.product_type != tpdmp.uid OR tpdmp.uid is null)
        ORDER BY
            prod.uid, sc.uid
        ;
        */
        $fields = [
            'sc.uid as category_id',
            'prod.uid as product_id',
            'prod.product_type as prod_product_type',
            'tpdmp.uid as product_type_id'
        ];

        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_pxaproductmanager_category_attributeset_mm');
        $queryBuilder->getRestrictions()->removeAll();

        $records = $queryBuilder->select(...$fields)
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
            ->join(
                'sc',
                'sys_category_record_mm',
                'scrm',
                $queryBuilder->expr()->andX(
                    $queryBuilder->expr()->eq(
                        'scrm.uid_local',
                        $queryBuilder->quoteIdentifier('sc.uid')
                    ),
                    $queryBuilder->expr()->eq(
                        'scrm.tablenames',
                        $queryBuilder->createNamedParameter('tx_pxaproductmanager_domain_model_product', \PDO::PARAM_STR)
                    ),
                    $queryBuilder->expr()->eq(
                        'scrm.fieldname',
                        $queryBuilder->createNamedParameter('categories', \PDO::PARAM_STR)
                    )
                )
            )
            ->join(
                'scrm',
                'tx_pxaproductmanager_domain_model_product',
                'prod',
                $queryBuilder->expr()->eq(
                    'prod.uid',
                    $queryBuilder->quoteIdentifier('scrm.uid_foreign')
                )
            )
            ->leftJoin(
                'sc',
                'tx_pxaproductmanager_domain_model_producttype',
                'tpdmp',
                $queryBuilder->expr()->eq(
                    'tpdmp.pm_mapped_category',
                    $queryBuilder->quoteIdentifier('sc.uid')
                )
            )
            ->where(
                $queryBuilder->expr()->orX(
                    $queryBuilder->expr()->neq(
                        'prod.product_type',
                        'tpdmp.uid'
                    ),
                    $queryBuilder->expr()->isNull('tpdmp.uid')
                )
            )
            ->execute()
            ->fetchAllAssociative();

        return $records ?? [];
    }

    protected function updateProductProductType(int $productId, int $productTypeId): void
    {
        $data['tx_pxaproductmanager_domain_model_product'][$productId] = [
            'product_type' => $productTypeId,
        ];

        echo PHP_EOL . 'data: ' . print_r($data, true) . PHP_EOL;

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
