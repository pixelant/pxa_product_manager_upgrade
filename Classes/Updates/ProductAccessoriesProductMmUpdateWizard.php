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

class ProductAccessoriesProductMmUpdateWizard implements UpgradeWizardInterface, ChattyInterface
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
        return 'productmanager_ProductAccessoriesProductMmUpdateWizard';
    }

    /**
     * Return the speaking name of this wizard
     *
     * @return string
     */
    public function getTitle(): string
    {
        return 'Migrate Product Accessories Product MM table update wizard.';
    }

    /**
     * Return the description for this wizard
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Migrates Product Accessories Product MM table.';
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
        $mmRecords = $this->fetchProductAccessoriesProductMmRecords();
        foreach ($mmRecords as $mmRecord) {
            if (empty($mmRecord['tparm_uid_local'])) {
                $this->createNewMmRelation($mmRecord);
            }
        }

        return $this->countMissingRecords() == 0;
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

    protected function countMissingRecords(): int
    {
        $cnt = 0;
        $mmRecords = $this->fetchProductAccessoriesProductMmRecords();
        foreach ($mmRecords as $mmRecord) {
            if (empty($mmRecord['tparm_uid_local'])) {
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
    protected function fetchProductAccessoriesProductMmRecords(): array
    {
        $fields = [
            'tpaam.uid_local as tpaam_uid_local',
            'tpaam.uid_foreign as tpaam_uid_foreign',
            'tpaam.sorting as tpaam_sorting',
            'tpaam.sorting_foreign as tpaam_sorting_foreign',
            'tparm.uid_local as tparm_uid_local',
            'tparm.uid_foreign as tparm_uid_foreign',
            'tparm.tablenames as tparm_tablenames',
            'tparm.fieldname as tparm_fieldname',
            'tparm.sorting as tparm_sorting',
            'tparm.sorting_foreign as tparm_sorting_foreign'
        ];

        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_pxaproductmanager_product_accessories_product_mm');
        $queryBuilder->getRestrictions()->removeAll();
        $records = $queryBuilder->select(...$fields)
            ->from('tx_pxaproductmanager_product_accessories_product_mm', 'tpaam')
            ->leftJoin(
                'tpaam',
                'tx_pxaproductmanager_product_product_mm',
                'tparm',
                $queryBuilder->expr()->andX(
                    $queryBuilder->expr()->eq(
                        'tparm.uid_local',
                        $queryBuilder->quoteIdentifier('tpaam.uid_local')
                    ),
                    $queryBuilder->expr()->eq(
                        'tparm.uid_foreign',
                        $queryBuilder->quoteIdentifier('tpaam.uid_foreign')
                    ),
                    $queryBuilder->expr()->eq(
                        'tparm.tablenames',
                        $queryBuilder->createNamedParameter('tx_pxaproductmanager_domain_model_product', \PDO::PARAM_STR)
                    ),
                    $queryBuilder->expr()->eq(
                        'tparm.fieldname',
                        $queryBuilder->createNamedParameter('accessories', \PDO::PARAM_STR)
                    )
                )
            )
            ->orderBy('tpaam.uid_local')
            ->addOrderBy('tpaam.sorting')
            ->execute()
            ->fetchAllAssociative();

        return $records ?? [];
    }

    protected function createNewMmRelation(array $record): void
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_pxaproductmanager_product_product_mm');
        $queryBuilder
            ->insert('tx_pxaproductmanager_product_product_mm')
            ->values([
                'uid_local' => $record['tpaam_uid_local'],
                'uid_foreign' => $record['tpaam_uid_foreign'],
                'sorting' => $record['tpaam_sorting'],
                'sorting_foreign' => $record['tpaam_sorting_foreign'],
                'tablenames' => 'tx_pxaproductmanager_domain_model_product',
                'fieldname' => 'accessories'
            ])
            ->execute();
    }
}
