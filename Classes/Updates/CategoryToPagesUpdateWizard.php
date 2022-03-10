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

class CategoryToPagesUpdateWizard implements UpgradeWizardInterface, ChattyInterface
{

    protected const KEY_MIGRATED_PAGE = '_migratedToPage';

    protected const KEY_CHILD_CATEGORIES = '_childs';

    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * @var array
     */
    protected $rootCategories = null;

    /**
     * @var array
     */
    protected $categoryTree = null;

    /**
     * @var array
     */
    protected $categories = null;

    /**
     * Return the identifier for this wizard
     * This should be the same string as used in the ext_localconf class registration
     *
     * @return string
     */
    public function getIdentifier(): string
    {
        return 'productmanager_CategoryToPagesUpdateWizard';
    }

    /**
     * Return the speaking name of this wizard
     *
     * @return string
     */
    public function getTitle(): string
    {
        return 'Migrate Product Categories to Product Pages update wizard.';
    }

    /**
     * Return the description for this wizard
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Migrates Product Categories to Product Pages.';
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
        $this->loadCategoryTree();
        // _migratedToPage key in categories is the page created in migration.
        // migrated pages are connected to categories by field pm_mapped_category:
        // product_manager_category|#categoryUid"
        // so mapping of pages can be done manually.
        // product_manager_category|26

        // Category to "pages"
        // Category to "product types"
        // Category to "single view pages"
        foreach ($this->categoryTree as $rootCategory) {
            $this->addProductPageIfMissingRecursive($rootCategory, $rootCategory['pid']);
        }

        // $this->output->writeln('$categoryTree: ' . print_r($this->categoryTree, true));
        /*
        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
        $sites = $siteFinder->getAllSites();

        if (empty($sites)) {
            $this->output->writeln('No sites configured');
            $this->output->writeln('Configure new sites in the "Sites" module.');
            return false;
        }
        */
        /** @var Site $site */
        /*foreach ($sites as $site) {
            $this->output->writeln('site: ' . $site->getIdentifier());
        }*/
        $this->clearCategories();
        $cnt = $this->getNumberCategoriesToMigrate();
        $this->output->writeln('$cnt: ' . $cnt);

        return $this->getNumberCategoriesToMigrate() === 0;
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
        return $this->getNumberCategoriesToMigrate() !== 0;
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
     * Determine root product categories.
     *
     * @return void
     */
    protected function determineRootProductCategories(): void
    {
        $productCategories = $this->fetchAllCategoriesAttachedToProduct();
        $productCategories = $this->assignAndRemoveRootCategoriesFromList($productCategories);
        $this->traverseCategoriesUp($productCategories);
    }

    /**
     * Assigns root categories to "root categories" and removes them from category list.
     *
     * @param array $categories
     * @return array
     */
    protected function assignAndRemoveRootCategoriesFromList(array $categories): array
    {
        $noneRootCategories = [];
        foreach ($categories as $category) {
            if ($category['parent'] === 0) {
                $this->rootCategories[$category['uid']] = $category;
            } else {
                $noneRootCategories[] = $category;
            }
        }

        return $noneRootCategories;
    }

    /**
     * Traverse categories "up".
     *
     * @param array $categories
     * @return void
     */
    protected function traverseCategoriesUp(array $categories): void
    {
        $categories = $this->fetchCategoriesByList($this->getParentUidList($categories));
        $categories = $this->assignAndRemoveRootCategoriesFromList($categories);
        if (count($categories) > 0) {
            $this->traverseCategoriesUp($categories);
        }
    }

    /**
     * Get array of parent category uid:s.
     *
     * @param array $categories
     * @return array
     */
    protected function getParentUidList(array $categories): array
    {
        return array_unique(array_column($categories, 'parent'));
    }

    /**
     * Fetch all categories assigned to a product.
     *
     * @return array
     */
    protected function fetchAllCategoriesAttachedToProduct(): array
    {
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('sys_category_record_mm');
        $queryBuilder->getRestrictions()->removeAll();

        $records = $queryBuilder->select('sc.uid', 'sc.title', 'sc.parent')
            ->from('sys_category_record_mm', 'scrm')
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
                    'scrm.tablenames',
                    $queryBuilder->createNamedParameter('tx_pxaproductmanager_domain_model_product', \PDO::PARAM_STR)
                ),
                $queryBuilder->expr()->eq(
                    'scrm.fieldname',
                    $queryBuilder->createNamedParameter('categories', \PDO::PARAM_STR)
                ),
                $queryBuilder->expr()->eq(
                    'sc.sys_language_uid',
                    $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)
                )
            )
            ->groupBy('sc.uid', 'sc.title', 'sc.parent')
            ->execute()
            ->fetchAllAssociative();

        return $records ?? [];
    }

    /**
     * Fetch categories by list of uid:s.
     *
     * @param array $listOfCategoryIds
     * @return array
     */
    protected function fetchCategoriesByList(array $listOfCategoryIds): array
    {
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('sys_category');
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->getRestrictions()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $records = $queryBuilder->select('sc.uid', 'sc.title', 'sc.parent')
            ->from('sys_category', 'sc')
            ->where(
                $queryBuilder->expr()->in(
                    'uid',
                    $queryBuilder->createNamedParameter(
                        $listOfCategoryIds,
                        \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY
                    )
                )
            )
            ->groupBy('sc.uid', 'sc.title', 'sc.parent')
            ->execute()
            ->fetchAllAssociative();

        return $records ?? [];
    }

    /**
     * Load category tree.
     *
     * @return void
     */
    protected function loadCategoryTree(): void
    {
        if ($this->rootCategories === null) {
            $this->determineRootProductCategories();
        }

        if (is_array($this->rootCategories) && count($this->rootCategories) > 0) {
            foreach ($this->rootCategories as $index => $category) {
                $this->categoryTree[$index] = $this->getCategoriesRecursive($category['uid']);
            }
        }
    }

    /**
     * Count nubmer of categories to migrate.
     *
     * @return int
     */
    protected function getNumberCategoriesToMigrate(): int
    {
        $cnt = 0;

        if ($this->categories === null) {
            $this->loadCategoryTree();
        }

        if (is_array($this->categories) && count($this->categories) > 0) {
            foreach ($this->categories as $uid => $category) {
                if (count($category[self::KEY_MIGRATED_PAGE]) === 0) {
                    $cnt++;
                }
            }
        }

        return $cnt;
    }

    /**
     * Get categories recursive.
     *
     * @param int $uid
     * @return array
     */
    protected function getCategoriesRecursive(int $uid): array
    {
        $category = $this->fetchCategoryById($uid);
        $category[self::KEY_MIGRATED_PAGE] = $this->fetchMigratedPageByRowDescription($category);

        $this->categories[$uid] = $category;

        $category[self::KEY_CHILD_CATEGORIES] = [];
        $subCategories = $this->fetchChildCategoriesByParent($uid);
        if (is_array($subCategories) && count($subCategories) > 0) {
            foreach ($subCategories as $subCategory) {
                $category[self::KEY_CHILD_CATEGORIES][] = $this->getCategoriesRecursive($subCategory);
            }
        }

        return $category;
    }

    /**
     * Fetch categories by parent.
     *
     * @param int $parentUid
     * @return array
     */
    protected function fetchChildCategoriesByParent(int $parentUid): array
    {
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('sys_category');

        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->getRestrictions()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $records = $queryBuilder->select('uid')
            ->from('sys_category')
            ->where(
                $queryBuilder->expr()->eq(
                    'parent',
                    $queryBuilder->createNamedParameter($parentUid, \PDO::PARAM_INT)
                )
            )
            ->orderBy('title')
            ->execute()
            ->fetchAllAssociative();

        return array_column($records, 'uid') ?? [];
    }

    /**
     * Fetch category by id.
     *
     * @param int $uid
     * @return array
     */
    protected function fetchCategoryById(int $uid): array
    {
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('sys_category');

        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->getRestrictions()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        return $queryBuilder->select('*')
            ->from('sys_category')
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($uid, \PDO::PARAM_INT)
                )
            )
            ->execute()
            ->fetchAllAssociative()[0];
    }

    /**
     * Fetch page by pm_mapped_category.
     *
     * @param int $uid
     * @return array
     */
    protected function fetchMigratedPageByRowDescription(array $category): array
    {
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages');

        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->getRestrictions()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        return $queryBuilder->select('*')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq(
                    'pm_mapped_category',
                    $queryBuilder->createNamedParameter(
                        $category['uid'],
                        \PDO::PARAM_STR
                    )
                )
            )
            ->execute()
            ->fetchAllAssociative()[0] ?? [];
    }

    protected function addProductPageIfMissingRecursive(array $category, int $pid, int $level = 0): void
    {
        if (count($category[self::KEY_MIGRATED_PAGE]) === 0) {
            // Create new page and update array.
            $this->createPageFromCategory($category, $pid);
            // Update migrated page for category.
            $category[self::KEY_MIGRATED_PAGE] = $this->fetchMigratedPageByRowDescription($category);
        }

        if (is_array($category[self::KEY_CHILD_CATEGORIES]) && count($category[self::KEY_CHILD_CATEGORIES]) > 0) {
            $pid = $category[self::KEY_MIGRATED_PAGE]['uid'] ?? 0;
            if ($pid === 0) {
                $childCategories = implode(',', array_column($category[self::KEY_CHILD_CATEGORIES], 'uid'));
                throw new \Exception(
                    'Can\'t create pages of child categories ' . $childCategories . ' no PID: ' . $pid,
                    1646833483
                );
            }
            foreach ($category[self::KEY_CHILD_CATEGORIES] as $childCategory) {
                $this->addProductPageIfMissingRecursive($childCategory, $pid, $level++);
            }
        }
    }

    protected function createPageFromCategory(array $category, int $pid): void
    {
        $newId = StringUtility::getUniqueId('NEW');

        $data['pages'][$newId] = [
            'pid' => $pid,
            'title' => $category['title'],
            'doktype' => 9,
            'seo_title' => $category['seo_title'],
            'description' => $category['seo_description'],
            'slug' => $category['slug'],
            'hidden' => $category['hidden'],
            'pm_mapped_category' => $category['uid'],
        ];

        // echo PHP_EOL . 'data: ' . print_r($data, true) . PHP_EOL;

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
     * Clear category arrays.
     *
     * @return void
     */
    protected function clearCategories(): void
    {
        $this->rootCategories = null;
        $this->categoryTree = null;
        $this->categories = null;
    }
}
