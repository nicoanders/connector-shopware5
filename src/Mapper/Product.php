<?php
/**
 * @copyright 2010-2013 JTL-Software GmbH
 * @package jtl\Connector\Shopware\Controller
 */
namespace jtl\Connector\Shopware\Mapper;

use jtl\Connector\Shopware\Utilities\ProductAttribute;
use jtl\Connector\Shopware\Utilities\Str;
use jtl\Connector\Shopware\Model\ProductVariation;
use jtl\Connector\Shopware\Utilities\Mmc;
use jtl\Connector\Model\Product as JtlProduct;
use jtl\Connector\Model\ProductChecksum;
use jtl\Connector\Shopware\Utilities\VariationType;
use jtl\Connector\Core\Exception\DatabaseException;
use jtl\Connector\Shopware\Utilities\Translation as TranslationUtil;
use jtl\Connector\Core\Logger\Logger;
use jtl\Connector\Model\Identity;
use jtl\Connector\Shopware\Utilities\CustomerGroup as CustomerGroupUtil;
use Doctrine\Common\Collections\ArrayCollection;
use jtl\Connector\Shopware\Utilities\Locale as LocaleUtil;
use Shopware\Bundle\AttributeBundle\Service\ConfigurationStruct;
use Shopware\Bundle\AttributeBundle\Service\CrudService;
use Shopware\Bundle\AttributeBundle\Service\TypeMapping;
use Shopware\Models\Article\Detail as DetailSW;
use Shopware\Models\Article\Article as ArticleSW;
use Shopware\Models\Article\Download as DownloadSW;
use Shopware\Models\Article\Link as LinkSW;
use jtl\Connector\Core\Utilities\Language as LanguageUtil;
use jtl\Connector\Shopware\Utilities\IdConcatenator;
use jtl\Connector\Shopware\Model\Helper\ProductNameHelper;
use jtl\Connector\Formatter\ExceptionFormatter;
use jtl\Connector\Linker\ChecksumLinker;
use jtl\Connector\Shopware\Mapper\ProductPrice as ProductPriceMapper;
use jtl\Connector\Shopware\Model\ProductAttr;
use jtl\Connector\Shopware\Utilities\CategoryMapping as CategoryMappingUtil;
use Shopware\Models\Property\Group;
use Shopware\Models\Property\Option;
use Shopware\Models\Property\Value;
use jtl\Connector\Shopware\Utilities\Shop;

class Product extends DataMapper
{
    const KIND_VALUE_PARENT = 3;
    const KIND_VALUE_DEFAULT = 2;
    const KIND_VALUE_MAIN = 1;

    protected static $masterProductIds = array();

    /**
     * @var boolean
     */
    protected $setMainDetailActive = false;

    /**
     * @return \Doctrine\ORM\EntityRepository
     */
    public function getRepository()
    {
        return Shopware()->Models()->getRepository('Shopware\Models\Article\Article');
    }

    /**
     * @param integer $id
     * @return null|ArticleSW
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     * @throws \Doctrine\ORM\TransactionRequiredException
     */
    public function find($id)
    {
        return (intval($id) == 0) ? null : $this->Manager()->find('Shopware\Models\Article\Article', $id);
    }

    /**
     * @param integer $id
     * @return null|DetailSW
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     * @throws \Doctrine\ORM\TransactionRequiredException
     */
    public function findDetail($id)
    {
        return (intval($id) == 0) ? null : $this->Manager()->find('Shopware\Models\Article\Detail', $id);
    }

    /**
     * @param array $kv
     * @return null|DetailSW
     */
    public function findDetailBy(array $kv)
    {
        return $this->Manager()->getRepository('Shopware\Models\Article\Detail')->findOneBy($kv);
    }


    public function findAll($limit = 100, $count = false)
    {
        if ($count) {
            $query = $this->Manager()->createQueryBuilder()->select('detail')
                ->from('jtl\Connector\Shopware\Model\Linker\Detail', 'detail')
                ->leftJoin('detail.linker', 'linker')
                ->where('linker.hostId IS NULL')
                ->getQuery();

            $paginator = new \Doctrine\ORM\Tools\Pagination\Paginator($query, $fetchJoinCollection = true);

            return $paginator->count();
        }

        /*
        $details = Shopware()->Db()->fetchAll(
            'SELECT d.id
              FROM s_articles_details d
              LEFT JOIN jtl_connector_link_detail l ON l.product_id = d.articleID AND l.detail_id = d.id
              WHERE l.host_id IS NULL
              ORDER BY d.kind
              LIMIT ' . intval($limit)
        );

        if (is_array($details) && count($details) > 0) {
            $ids = [];
            foreach ($details as $detail) {
                $ids[] = $detail['id'];
            }

            $qb = $this->Manager()->createQueryBuilder()->select(
                'detail',
                'article.id',
                'unit',
                'tax',
                'categories',
                'maindetail',
                'detailprices',
                'prices',
                'links',
                'attribute',
                'downloads',
                'supplier',
                'pricegroup',
                'discounts',
                'customergroups',
                'configuratorOptions',
                'propertyvalues'
            )
                ->from('jtl\Connector\Shopware\Model\Linker\Detail', 'detail')
                ->leftJoin('detail.linker', 'linker')
                ->leftJoin('detail.article', 'article')
                ->leftJoin('detail.prices', 'detailprices')
                ->leftJoin('detail.unit', 'unit')
                ->leftJoin('article.tax', 'tax')
                ->leftJoin('article.categories', 'categories')
                ->leftJoin('article.mainDetail', 'maindetail')
                ->leftJoin('maindetail.prices', 'prices')
                ->leftJoin('article.links', 'links')
                ->leftJoin('article.attribute', 'attribute', \Doctrine\ORM\Query\Expr\Join::WITH, 'attribute.articleDetailId = detail.id')
                ->leftJoin('article.downloads', 'downloads')
                ->leftJoin('article.supplier', 'supplier')
                ->leftJoin('article.priceGroup', 'pricegroup')
                ->leftJoin('pricegroup.discounts', 'discounts')
                ->leftJoin('article.customerGroups', 'customergroups')
                ->leftJoin('detail.configuratorOptions', 'configuratorOptions')
                ->leftJoin('article.propertyValues', 'propertyvalues');

            $qb->add('where', $qb->expr()->in('detail.id', ':ids'))->setParameter(':ids', $ids);

            $query = $qb->getQuery()->setHydrationMode(\Doctrine\ORM\AbstractQuery::HYDRATE_ARRAY);

            $products = $query->getArrayResult();

            $shopMapper = Mmc::getMapper('Shop');
            $shops = $shopMapper->findAll(null, null);

            $translationUtil = new TranslationUtil();
            for ($i = 0; $i < count($products); $i++) {
                foreach ($shops as $shop) {
                    $translation = $translationUtil->read($shop['id'], 'article', $products[$i]['articleId']);
                    if (!empty($translation)) {
                        $translation['shopId'] = $shop['id'];
                        $products[$i]['translations'][$shop['locale']['locale']] = $translation;
                    }
                }
            }

            return $products[0];
        }

        return [];
        */

        /** @var \Doctrine\ORM\Query $query */
        $query = $this->Manager()->createQueryBuilder()->select(
            'detail',
            'article',
            'unit',
            'tax',
            'categories',
            'maindetail',
            'detailprices',
            'prices',
            'links',
            'attribute',
            'downloads',
            'supplier',
            'pricegroup',
            'discounts',
            'customergroups',
            'configuratorOptions',
            'propertyvalues',
            '(CASE WHEN detail.kind = 3 THEN 0 ELSE detail.kind END) AS HIDDEN sort'
        )
            ->from('jtl\Connector\Shopware\Model\Linker\Detail', 'detail')
            ->leftJoin('detail.linker', 'linker')
            ->leftJoin('detail.article', 'article')
            ->leftJoin('detail.prices', 'detailprices')
            ->leftJoin('detail.unit', 'unit')
            ->leftJoin('article.tax', 'tax')
            ->leftJoin('article.categories', 'categories')
            ->leftJoin('article.mainDetail', 'maindetail')
            ->leftJoin('maindetail.prices', 'prices')
            ->leftJoin('article.links', 'links')
            //->leftJoin('article.attribute', 'attribute', \Doctrine\ORM\Query\Expr\Join::WITH, 'attribute.articleDetailId = detail.id')
            ->leftJoin('detail.attribute', 'attribute')
            ->leftJoin('article.downloads', 'downloads')
            ->leftJoin('article.supplier', 'supplier')
            ->leftJoin('article.priceGroup', 'pricegroup')
            ->leftJoin('pricegroup.discounts', 'discounts')
            ->leftJoin('article.customerGroups', 'customergroups')
            ->leftJoin('detail.configuratorOptions', 'configuratorOptions')
            ->leftJoin('article.propertyValues', 'propertyvalues')
            ->where('linker.hostId IS NULL')
            ->orderBy('sort', 'ASC')
            ->setFirstResult(0)
            ->setMaxResults($limit)
            ->getQuery()->setHydrationMode(\Doctrine\ORM\AbstractQuery::HYDRATE_ARRAY);

        $paginator = new \Doctrine\ORM\Tools\Pagination\Paginator($query, $fetchJoinCollection = true);

        $products = iterator_to_array($paginator);

        $shopMapper = Mmc::getMapper('Shop');
        $shops = $shopMapper->findAll(null, null);

        $translationUtil = new TranslationUtil();
        for ($i = 0; $i < count($products); $i++) {
            foreach ($shops as $shop) {
                $translation = $translationUtil->read($shop['id'], 'article', $products[$i]['articleId']);
                if($this->isDetailData($products[$i]) && $products[$i]['kind'] === self::KIND_VALUE_DEFAULT) {
                    $translation = array_merge($translation, $translationUtil->read($shop['id'], 'variant', $products[$i]['id']));
                }

                if (!empty($translation)) {
                    $translation['shopId'] = $shop['id'];
                    $products[$i]['translations'][$shop['locale']['locale']] = $translation;
                }
            }
        }

        return $products;
    }

    /**
     * @return integer
     */
    public function fetchCount()
    {
        return (int)Shopware()->Db()->fetchOne(
            'SELECT count(*)
                FROM s_articles_details d
                LEFT JOIN jtl_connector_link_detail l ON l.product_id = d.articleID
                    AND l.detail_id = d.id
                WHERE l.host_id IS NULL'
        );
    }

    /**
     * @param integer $productId
     * @return integer
     */
    public function fetchDetailCount($productId)
    {
        return Shopware()->Db()->fetchOne(
            'SELECT count(*) FROM s_articles_details WHERE articleID = ?',
            array($productId)
        );
    }

    public function deleteDetail($detailId)
    {
        return Shopware()->Db()->delete('s_articles_details', array('id = ?' => $detailId));
    }

    /**
     * @param integer $productId
     * @return integer
     */
    public function getParentDetailId($productId)
    {
        return (int)Shopware()->Db()->fetchOne(
            'SELECT id FROM s_articles_details WHERE articleID = ? AND kind = ' . self::KIND_VALUE_PARENT,
            array($productId)
        );
    }

    public function delete(JtlProduct $product)
    {
        $result = new JtlProduct();

        $this->deleteProductData($product);

        // Result
        $result->setId(new Identity('', $product->getId()->getHost()));

        return $result;
    }

    public function save(JtlProduct $product)
    {
        /** @var ArticleSW $productSW */
        $productSW = null;

        /** @var DetailSW $detailSW */
        $detailSW = null;
        //$result = new ProductModel();
        $result = $product;
        $attrMappings = [];

        /*
        Logger::write(sprintf('>>> Product with id (%s, %s), masterProductId (%s, %s), manufacturerId (%s, %s)',
            $product->getId()->getEndpoint(),
            $product->getId()->getHost(),
            $product->getMasterProductId()->getEndpoint(),
            $product->getMasterProductId()->getHost(),
            $product->getManufacturerId()->getEndpoint(),
            $product->getManufacturerId()->getHost()
        ), Logger::DEBUG, 'database');
        */

        try {
            if ($this->isChild($product)) {
                if (isset(self::$masterProductIds[$product->getMasterProductId()->getHost()])) {
                    $product->getMasterProductId()->setEndpoint(self::$masterProductIds[$product->getMasterProductId()->getHost()]);
                }

                $this->prepareChildAssociatedData($product, $productSW, $detailSW);
                $this->prepareDetailAssociatedData($product, $productSW, $detailSW, true);
                $this->prepareAttributeAssociatedData($product, $productSW, $detailSW, $attrMappings, true);
                $this->preparePriceAssociatedData($product, $productSW, $detailSW);
                $this->prepareUnitAssociatedData($product, $detailSW);
                $this->prepareMeasurementUnitAssociatedData($product, $detailSW);

                // First Child
                if (is_null($productSW->getMainDetail()) || $productSW->getMainDetail()->getKind() === self::KIND_VALUE_PARENT) {
                    $productSW->setMainDetail($detailSW);
                }

                $this->prepareDetailVariationAssociatedData($product, $detailSW);

                $autoMainDetailSelection = (bool)Application()->getConfig()->get('product.push.article_detail_preselection', false);
                if ($autoMainDetailSelection && !$this->isSuitableForMainDetail($productSW->getMainDetail())) {
                    $this->selectSuitableMainDetail($productSW);
                }

            } else {
                $this->prepareProductAssociatedData($product, $productSW, $detailSW);
                $this->prepareCategoryAssociatedData($product, $productSW);
                $this->prepareInvisibilityAssociatedData($product, $productSW);
                $this->prepareTaxAssociatedData($product, $productSW);
                $this->prepareManufacturerAssociatedData($product, $productSW);
                // $this->prepareSpecialPriceAssociatedData($product, $productSW); Can not be fully supported

                $this->prepareDetailAssociatedData($product, $productSW, $detailSW);
                $this->prepareVariationAssociatedData($product, $productSW);
                $this->prepareSpecificAssociatedData($product, $productSW, $detailSW);
                $this->prepareAttributeAssociatedData($product, $productSW, $detailSW, $attrMappings);
                $this->preparePriceAssociatedData($product, $productSW, $detailSW);
                $this->prepareUnitAssociatedData($product, $detailSW);
                $this->prepareMeasurementUnitAssociatedData($product, $detailSW);
                $this->prepareMediaFileAssociatedData($product, $productSW);

                if (is_null($detailSW->getId())) {
                    $kind = $detailSW->getKind();
                    $productSW->setMainDetail($detailSW);
                    $detailSW->setKind($kind);
                    $productSW->setDetails(array($detailSW));
                }

                if ($this->isParent($product) && $productSW !== null) {
                    self::$masterProductIds[$product->getId()->getHost()] = IdConcatenator::link(array($productSW->getMainDetail()->getId(), $productSW->getId()));
                }
            }

            // Save article and detail
            Shop::entityManager()->persist($productSW);
            Shop::entityManager()->persist($detailSW);
            if(!is_null($detailSW->getId())) {
                Shop::entityManager()->refresh($detailSW);
            }
            Shop::entityManager()->flush();

            //Set main detail in-/active hack
            if($this->setMainDetailActive) {
                $productSW->getMainDetail()->setActive($productSW->getActive());
                Shop::entityManager()->persist($productSW->getMainDetail());
                Shop::entityManager()->flush();
            }

            //Change back to entity manager instead of native queries
            if (!$this->isChild($product)) {
                $this->prepareSetVariationRelations($product, $productSW);
                $this->saveVariationTranslationData($product, $productSW);
            }

            $this->saveTranslations($product, $productSW, $detailSW, $attrMappings);

        } catch (\Exception $e) {
            Logger::write(sprintf('Exception from Product (%s, %s)', $product->getId()->getEndpoint(), $product->getId()->getHost()), Logger::ERROR, 'database');
            Logger::write(ExceptionFormatter::format($e), Logger::ERROR, 'database');
        }


        // Result
        $result->setId(new Identity('', $product->getId()->getHost()))
            ->setChecksums($product->getChecksums());
        if ($detailSW !== null && $productSW !== null && (int)$detailSW->getId() > 0 && (int)$productSW->getId() > 0) {
            $result->setId(new Identity(IdConcatenator::link(array($detailSW->getId(), $productSW->getId())), $product->getId()->getHost()))
                ->setChecksums($product->getChecksums());
        }

        return $result;
    }

    /**
     * @param DetailSW $detail
     * @return boolean
     */
    protected function isSuitableForMainDetail(DetailSW $detail)
    {
        $lastStock = (bool)(method_exists($detail, 'getLastStock') ? $detail->getLastStock() : $detail->getArticle()->getLastStock());
        return $detail->getKind() !== self::KIND_VALUE_PARENT && ($detail->getInStock() > 0 || !$lastStock);
    }

    /**
     * @param ArticleSW $article
     * @return void
     */
    protected function selectSuitableMainDetail(ArticleSW $article)
    {
        $mainDetail = $article->getMainDetail();
        // Set new main detail
        /** @var DetailSW $detail */
        foreach ($article->getDetails() as $detail) {
            if ($detail->getKind() === self::KIND_VALUE_PARENT) {
                continue;
            }

            if (!$this->isSuitableForMainDetail($mainDetail) && $this->isSuitableForMainDetail($detail)) {
                $mainDetail = $detail;
            }

            $detail->setKind(self::KIND_VALUE_DEFAULT);
        }

        if ($mainDetail->getKind() !== self::KIND_VALUE_PARENT) {
            $article->setMainDetail($mainDetail);
        }
    }

    /**
     * @param ArticleSW $article
     */
    protected function cleanupConfiguratorSetOptions(ArticleSW $article)
    {
        $setOptions = $article->getConfiguratorSet()->getOptions();
        /** @var \Shopware\Models\Article\Configurator\Group[] $group */
        foreach ($article->getConfiguratorSet()->getGroups() as $group) {
            $options = new ArrayCollection();

            /** @var \Shopware\Models\Article\Configurator\Group[] $groupOptions */
            $groupOptions = $group->getOptions();
            foreach ($groupOptions as $option) {
                if ($options->contains($option)) {
                    continue;
                }

                if ($setOptions->contains($option)) {
                    $options->add($option);
                } else {
                    /** @var DetailSW $detail */
                    foreach ($article->getDetails() as $detail) {
                        if ($detail->getConfiguratorOptions()->contains($option)) {
                            $options->add($option);
                            break;
                        }
                    }
                }

                if (!$options->contains($option)) {
                    $this->Manager()->remove($option);
                }
            }
        }
    }

    protected function prepareChildAssociatedData(JtlProduct &$product, ArticleSW &$productSW = null, DetailSW &$detailSW = null)
    {
        $productId = (strlen($product->getId()->getEndpoint()) > 0) ? $product->getId()->getEndpoint() : null;
        $masterProductId = (strlen($product->getMasterProductId()->getEndpoint()) > 0) ? $product->getMasterProductId()->getEndpoint() : null;

        if (is_null($masterProductId)) {
            throw new \Exception('Master product id is empty');
        }

        list($detailId, $id) = IdConcatenator::unlink($masterProductId);
        $productSW = $this->find($id);
        if (is_null($productSW)) {
            throw new \Exception(sprintf('Cannot find parent product with id (%s)', $masterProductId));
        }

        if (!is_null($productId)) {
            list($detailId, $id) = IdConcatenator::unlink($productId);

            /** @var DetailSW $detail */
            foreach($productSW->getDetails() as $detail) {
                if($detail->getId() === (int)$detailId) {
                    $detailSW = $detail;
                    break;
                }
            }
        }

        if (is_null($detailSW) && strlen($product->getSku()) > 0) {
            $detailSW = Shopware()->Models()->getRepository('Shopware\Models\Article\Detail')->findOneBy(array('number' => $product->getSku()));
        }
    }

    protected function prepareProductAssociatedData(JtlProduct $product, ArticleSW &$productSW = null, DetailSW &$detailSW = null)
    {
        $productId = (strlen($product->getId()->getEndpoint()) > 0) ? $product->getId()->getEndpoint() : null;
        if ($productId !== null) {
            list($detailId, $id) = IdConcatenator::unlink($productId);

            $productSW = $this->find((int) $id);
            if($productSW === null) {
                throw new \Exception(sprintf('Article with id (%s) not found', $productId));
            }

            /** @var DetailSW $detail */
            foreach($productSW->getDetails() as $detail) {
                if($detail->getId() === (int)$detailId) {
                    $detailSW = $detail;
                    break;
                }
            }

            if ($detailSW === null) {
                throw new \Exception(sprintf('Detail (%s) from article (%s) not found', $detailId, $id));
            }
        } elseif (strlen($product->getSku()) > 0) {
            $detailSW = Shopware()->Models()->getRepository('Shopware\Models\Article\Detail')->findOneBy(array('number' => $product->getSku()));
            if ($detailSW) {
                $productSW = $detailSW->getArticle();
            }
        }

        $isNew = false;
        if ($productSW === null) {
            $productSW = new ArticleSW();
            $isNew = true;
        }

        $productSW->setAdded($product->getCreationDate())
            ->setAvailableFrom($product->getAvailableFrom())
            ->setHighlight(intval($product->getIsTopProduct()))
            ->setActive($product->getIsActive());

        // new in stock
        if ($product->getisNewProduct() && !is_null($product->getNewReleaseDate())) {
            $productSW->setAdded($product->getNewReleaseDate());
        }

        // Last stock
        $inStock = 0;
        if ($product->getConsiderStock()) {
            $inStock = $product->getPermitNegativeStock() ? 0 : 1;
        }

        if (is_callable([$productSW, 'setLastStock'])) {
            $productSW->setLastStock($inStock);
        }

        // I18n
        foreach ($product->getI18ns() as $i18n) {
            if ($i18n->getLanguageISO() === LanguageUtil::map(Shopware()->Shop()->getLocale()->getLocale())) {
                $productSW->setDescription($i18n->getMetaDescription())
                    ->setDescriptionLong($i18n->getDescription())
                    ->setKeywords($i18n->getMetaKeywords())
                    ->setMetaTitle($i18n->getTitleTag());
            }
        }

        $helper = ProductNameHelper::build($product);
        $productSW->setName($helper->getProductName());

        if ($isNew) {
            Shop::entityManager()->persist($productSW);
            Shop::entityManager()->flush();
        }
    }

    protected function prepareCategoryAssociatedData(JtlProduct $product, ArticleSW &$productSW)
    {
        $collection = new ArrayCollection();
        $categoryMapper = Mmc::getMapper('Category');
        /** @deprecated Will be removed in a future connector release  $mappingOld */
        $mappingOld = Application()->getConfig()->get('category_mapping', false);
        $useMapping = Application()->getConfig()->get('category.mapping', $mappingOld);
        foreach ($product->getCategories() as $category) {
            if (strlen($category->getCategoryId()->getEndpoint()) > 0) {
                $categorySW = $categoryMapper->find(intval($category->getCategoryId()->getEndpoint()));
                if ($categorySW) {
                    $collection->add($categorySW);

                    // Category Mapping
                    if ($useMapping) {
                        foreach ($product->getI18ns() as $i18n) {
                            if ($i18n->getLanguageISO() !== LanguageUtil::map(Shopware()->Shop()->getLocale()->getLocale()) && strlen($i18n->getName()) > 0) {
                                $categoryMapping = CategoryMappingUtil::findCategoryMappingByParent($categorySW->getId(), $i18n->getLanguageISO());
                                if ($categoryMapping !== null) {
                                    $collection->add($categoryMapping);
                                }
                            }
                        }
                    }
                }
            }
        }

        $productSW->setCategories($collection);
    }

    protected function prepareInvisibilityAssociatedData(JtlProduct $product, ArticleSW &$productSW)
    {
        // Invisibility
        $collection = new ArrayCollection();
        foreach ($product->getInvisibilities() as $invisibility) {
            $customerGroupSW = CustomerGroupUtil::get(intval($invisibility->getCustomerGroupId()->getEndpoint()));
            if ($customerGroupSW === null) {
                $customerGroupSW = CustomerGroupUtil::get(Shopware()->Shop()->getCustomerGroup()->getId());
            }

            if ($customerGroupSW) {
                $collection->add($customerGroupSW);
            }
        }

        $productSW->setCustomerGroups($collection);
    }

    protected function prepareTaxAssociatedData(JtlProduct $product, ArticleSW &$productSW)
    {
        // Tax
        $taxSW = Shopware()->Models()->getRepository('Shopware\Models\Tax\Tax')->findOneBy(array('tax' => $product->getVat()));
        if ($taxSW) {
            $productSW->setTax($taxSW);
        } else {
            throw new DatabaseException(sprintf('Could not find any Tax entity for value (%s)', $product->getVat()));
        }
    }

    protected function prepareManufacturerAssociatedData(JtlProduct $product, ArticleSW &$productSW)
    {
        // Manufacturer
        $manufacturerMapper = Mmc::getMapper('Manufacturer');
        $manufacturerSW = $manufacturerMapper->find((int)$product->getManufacturerId()->getEndpoint());
        if ($manufacturerSW) {
            $productSW->setSupplier($manufacturerSW);
        } else {
            // Work Around - load dummy manufacturer
            $manufacturerSW = $manufacturerMapper->findOneBy(array('name' => '_'));

            if ($manufacturerSW === null) {
                $manufacturerSW = new \Shopware\Models\Article\Supplier();
                $manufacturerSW->setName('_')
                    ->setLink('');

                $manufacturerSW->setDescription('');
                $manufacturerSW->setMetaTitle('');
                $manufacturerSW->setMetaDescription('');
                $manufacturerSW->setMetaKeywords('');

                $this->Manager()->persist($manufacturerSW);
            }

            $productSW->setSupplier($manufacturerSW);
        }
    }

    protected function prepareSpecialPriceAssociatedData(JtlProduct $product, ArticleSW &$productSW)
    {
        // ProductSpecialPrice
        if (is_array($product->getSpecialPrices())) {
            foreach ($product->getSpecialPrices() as $i => $productSpecialPrice) {
                if (count($productSpecialPrice->getItems()) == 0) {
                    continue;
                }

                $collection = array();
                $priceGroupSW = Shopware()->Models()->getRepository('Shopware\Models\Price\Group')->find(intval($productSpecialPrice->getId()->getEndpoint()));
                if ($priceGroupSW === null) {
                    $priceGroupSW = new \Shopware\Models\Price\Group();
                    $this->Manager()->persist($priceGroupSW);
                }

                // SpecialPrice
                foreach ($productSpecialPrice->getItems() as $specialPrice) {
                    $customerGroupSW = CustomerGroupUtil::get(intval($specialPrice->getCustomerGroupId()->getEndpoint()));
                    if ($customerGroupSW === null) {
                        $customerGroupSW = CustomerGroupUtil::get(Shopware()->Shop()->getCustomerGroup()->getId());
                    }

                    $price = null;
                    $priceCount = count($product->getPrices());
                    if ($priceCount == 1) {
                        $price = reset($product->getPrices());
                    } elseif ($priceCount > 1) {
                        foreach ($product->getPrices() as $productPrice) {
                            if ($customerGroupSW->getId() == intval($productPrice->getCustomerGroupId()->getEndpoint())) {
                                $price = $productPrice->getNetPrice();

                                break;
                            }
                        }
                    }

                    if ($price === null) {
                        Logger::write(sprintf('Could not find any price for customer group (%s)', $specialPrice->getCustomerGroupId()->getEndpoint()), Logger::WARNING, 'database');

                        continue;
                    }

                    $priceDiscountSW = Shopware()->Models()->getRepository('Shopware\Models\Price\Discount')->findOneBy(array('groupId' => $specialPrice->getProductSpecialPriceId()->getEndpoint()));
                    if ($priceDiscountSW === null) {
                        $priceDiscountSW = new \Shopware\Models\Price\Discount();
                        $this->Manager()->persist($priceDiscountSW);
                    }

                    $discountValue = 100 - (($specialPrice->getPriceNet() / $price) * 100);

                    $priceDiscountSW->setCustomerGroup($customerGroupSW)
                        ->setDiscount($discountValue)
                        ->setStart(1);

                    $this->Manager()->persist($priceDiscountSW);

                    $collection[] = $priceDiscountSW;
                }

                $this->Manager()->persist($priceGroupSW);

                $priceGroupSW->setName("Standard_{$i}")
                    ->setDiscounts($collection);

                $productSW->setPriceGroup($priceGroupSW)
                    ->setPriceGroupActive(1);
            }
        }
    }

    protected function prepareDetailAssociatedData(JtlProduct $product, ArticleSW &$productSW, DetailSW &$detailSW = null, $isChild = false)
    {
        // Detail
        if ($detailSW === null) {
            $detailSW = new DetailSW();
            //$this->Manager()->persist($detailSW);
        }

        $detailSW->setAdditionalText('');
        $productSW->setChanged();

        $kind = ($isChild && $detailSW->getId() != self::KIND_VALUE_PARENT && $productSW->getMainDetail() !== null && $productSW->getMainDetail()->getId() == $detailSW->getId()) ? self::KIND_VALUE_MAIN : self::KIND_VALUE_DEFAULT;
        $active = $product->getIsActive();
        if (!$isChild) {
            $kind = $this->isParent($product) ? self::KIND_VALUE_PARENT : self::KIND_VALUE_MAIN;
            $active = $this->isParent($product) ? false : $active;
        }

        //$kind = $isChild ? 2 : 1;
        $detailSW->setSupplierNumber($product->getManufacturerNumber())
            ->setNumber($product->getSku())
            ->setActive($active)
            ->setKind($kind)
            ->setStockMin(0)
            ->setPosition($product->getSort())
            ->setWeight($product->getProductWeight())
            ->setInStock(floor($product->getStockLevel()->getStockLevel()))
            ->setStockMin($product->getMinimumQuantity())
            ->setMinPurchase(floor($product->getMinimumOrderQuantity()))
            ->setReleaseDate($product->getAvailableFrom())
            ->setPurchasePrice($product->getPurchasePrice())
            ->setEan($product->getEan());

        $detailSW->setWidth($product->getWidth());
        $detailSW->setLen($product->getLength());
        $detailSW->setHeight($product->getHeight());

        // Delivery time
        $exists = false;
        foreach ($product->getI18ns() as $i18n) {
            if ($i18n->getLanguageISO() === LanguageUtil::map(Shopware()->Shop()->getLocale()->getLocale())) {
                $days = trim(str_replace(['Tage', 'Days', 'Tag', 'Day'], '', $i18n->getDeliveryStatus()));
                if (strlen($days) > 0 && $days !== '0') {
                    $detailSW->setShippingTime($days);
                    $exists = true;
                    break;
                }
            }
        }

        if (!$exists) {
            $detailSW->setShippingTime($product->getSupplierDeliveryTime());
        }

        // Last stock
        $inStock = 0;
        if ($product->getConsiderStock()) {
            $inStock = $product->getPermitNegativeStock() ? 0 : 1;
        }

        if (is_callable([$detailSW, 'setLastStock'])) {
            $detailSW->setLastStock($inStock);
        }

        // Base Price
        $detailSW->setReferenceUnit(0.0);
        $detailSW->setPurchaseUnit($product->getMeasurementQuantity());
        if ($product->getBasePriceDivisor() > 0 && $product->getMeasurementQuantity() > 0) {
            $detailSW->setReferenceUnit(($product->getMeasurementQuantity() / $product->getBasePriceDivisor()));
        }
        //$detailSW->setReferenceUnit($product->getBasePriceQuantity());
        //$detailSW->setPurchaseUnit($product->getMeasurementQuantity());

        $detailSW->setWeight($product->getProductWeight())
            ->setPurchaseSteps($product->getPackagingQuantity())
            ->setArticle($productSW);
    }

    protected function prepareDetailVariationAssociatedData(JtlProduct &$product, DetailSW &$detailSW)
    {
        $groupMapper = Mmc::getMapper('ConfiguratorGroup');
        $optionMapper = Mmc::getMapper('ConfiguratorOption');
        $options = [];
        foreach ($product->getVariations() as $variation) {
            $variationName = null;
            foreach ($variation->getI18ns() as $variationI18n) {
                if ($variationI18n->getLanguageISO() === LanguageUtil::map(Shopware()->Shop()->getLocale()->getLocale())) {
                    $variationName = $variationI18n->getName();
                }
            }

            $groupSW = $groupMapper->findOneBy(array('name' => $variationName));
            if ($groupSW !== null) {
                foreach ($variation->getValues() as $variationValue) {
                    $name = null;
                    foreach ($variationValue->getI18ns() as $variationValueI18n) {
                        if ($variationValueI18n->getLanguageISO() === LanguageUtil::map(Shopware()->Shop()->getLocale()->getLocale())) {
                            $name = $variationValueI18n->getName();
                        }
                    }

                    if ($name === null) {
                        continue;
                    }

                    $optionSW = $optionMapper->findOneBy(array('name' => $name, 'groupId' => $groupSW->getId()));
                    if ($optionSW === null) {
                        continue;
                    }

                    $options[] = $optionSW;
                }
            }
        }
        $detailSW->setConfiguratorOptions(new ArrayCollection($options));
    }

    protected function prepareAttributeAssociatedData(JtlProduct $product, ArticleSW &$productSW, DetailSW &$detailSW, array &$attrMappings, $isChild = false)
    {
        // Attribute
        $attributeSW = $detailSW->getAttribute();
        if ($attributeSW === null) {
            $attributeSW = new \Shopware\Models\Attribute\Article();
            $attributeSW->setArticle($productSW);
            $attributeSW->setArticleDetail($detailSW);

            Shop::entityManager()->persist($attributeSW);
        }

        // Image configuration ignores
        if ($this->isParent($product)) {
            $productAttribute = new ProductAttribute($productSW->getId());
            $productAttribute->delete();
        }

        $attributes = [];
        $mappings = [];
        $attrMappings = [];

        $customPropertySupport = (bool)Application()->getConfig()->get('product.push.enable_custom_properties', false);
        foreach ($product->getAttributes() as $attribute) {
            if (!$customPropertySupport && $attribute->getIsCustomProperty()) {
                continue;
            }

            foreach ($attribute->getI18ns() as $attributeI18n) {
                if ($attributeI18n->getLanguageISO() === LanguageUtil::map(Shopware()->Shop()->getLocale()->getLocale())) {

                    // active
                    if (strtolower($attributeI18n->getName()) === strtolower(ProductAttr::IS_ACTIVE)) {
                        $isActive = (strtolower($attributeI18n->getValue()) === 'false'
                            || strtolower($attributeI18n->getValue()) === '0') ? 0 : 1;
                        if ($isChild) {
                            $detailSW->setActive($isActive);
                        } else {
                            /** @var DetailSW $detail */
                            $productSW->setActive($isActive);
                            $this->setMainDetailActive = true;
                        }

                        continue;
                    }

                    // Notification
                    if (strtolower($attributeI18n->getName()) === strtolower(ProductAttr::SEND_NOTIFICATION)) {
                        $notification = (strtolower($attributeI18n->getValue()) === 'false'
                            || strtolower($attributeI18n->getValue()) === '0') ? 0 : 1;

                        $productSW->setNotification($notification);

                        continue;
                    }

                    // Shipping free
                    if (strtolower($attributeI18n->getName()) === strtolower(ProductAttr::SHIPPING_FREE)) {
                        $shippingFree = (strtolower($attributeI18n->getValue()) === 'false'
                            || strtolower($attributeI18n->getValue()) === '0') ? 0 : 1;

                        $detailSW->setShippingFree($shippingFree);

                        continue;
                    }

                    // Pseudo sales
                    if (strtolower($attributeI18n->getName()) === strtolower(ProductAttr::PSEUDO_SALES)) {
                        $productSW->setPseudoSales((int)$attributeI18n->getValue());

                        continue;
                    }

                    // Image configuration ignores
                    if (strtolower($attributeI18n->getName()) === strtolower(ProductAttr::IMAGE_CONFIGURATION_IGNORES)
                        && $this->isParent($product)) {
                        try {
                            $productAttribute->setKey(ProductAttr::IMAGE_CONFIGURATION_IGNORES)
                                ->setValue($attributeI18n->getValue())
                                ->save(false);
                        } catch (\Exception $e) {
                            Logger::write(ExceptionFormatter::format($e), Logger::ERROR, 'database');
                        }

                        continue;
                    }

                    if (strtolower($attributeI18n->getName()) === strtolower(ProductAttr::IS_MAIN) && $isChild && (bool)$attributeI18n->getValue() === true) {
                        /** @var DetailSW $detail */
                        Shop::entityManager()->refresh($productSW);
                        $details = $productSW->getDetails();
                        foreach ($details as $detail) {
                            if ($detail->getKind() !== self::KIND_VALUE_PARENT) {
                                $detail->setKind(self::KIND_VALUE_DEFAULT);
                            }
                        }
                        $productSW->setMainDetail($detailSW);
                        $this->setMainDetailActive = true;

                        continue;
                    }

                    if($isChild && strtolower($attributeI18n->getName()) === ProductAttr::ADDITIONAL_TEXT) {
                        $detailSW->setAdditionalText($attributeI18n->getValue());
                        continue;
                    }

                    $mappings[$attributeI18n->getName()] = $attribute->getId()->getHost();
                    $attributes[$attributeI18n->getName()] = $attributeI18n->getValue();
                }
            }
        }

        /** @deprecated Will be removed in future connector releases $nullUndefinedAttributesOld */
        $nullUndefinedAttributesOld = (bool)Application()->getConfig()->get('null_undefined_product_attributes_during_push', true);
        $nullUndefinedAttributes = (bool)Application()->getConfig()->get('product.push.null_undefined_attributes', $nullUndefinedAttributesOld);

        // Reset
        $used = [];

        /** @var CrudService $sw_attributes */
        $sw_attributes = Shopware()->Container()->get('shopware_attribute.crud_service')->getList('s_articles_attributes');
        /** @var ConfigurationStruct $sw_attribute */
        foreach ($sw_attributes as $sw_attribute) {
            if (!$sw_attribute->isIdentifier()) {
                $setter = sprintf('set%s', ucfirst(Str::camel($sw_attribute->getColumnName())));
                if (isset($attributes[$sw_attribute->getColumnName()]) && method_exists($attributeSW, $setter)) {
                    $value = $attributes[$sw_attribute->getColumnName()];
                    if(in_array($sw_attribute->getColumnType(), [TypeMapping::TYPE_DATE, TypeMapping::TYPE_DATETIME])) {
                        try {
                            $value = new \DateTime($value);
                        } catch (\Throwable $ex) {
                            $value = null;
                            Logger::write(ExceptionFormatter::format($ex), Logger::ERROR, 'global');
                        }
                    }
                    $attributeSW->{$setter}($value);
                    $used[] = $sw_attribute->getColumnName();
                    $attrMappings[$sw_attribute->getColumnName()] = $mappings[$sw_attribute->getColumnName()];
                    unset($attributes[$sw_attribute->getColumnName()]);
                } else if ($nullUndefinedAttributes && method_exists($attributeSW, $setter)) {
                    $attributeSW->{$setter}(null);
                }
            }
        }

        for ($i = 4; $i <= 20; $i++) {
            $attr = "attr{$i}";
            if (in_array($attr, $used) || $i == 17) {
                continue;
            }

            $setter = "setAttr{$i}";
            if (!method_exists($attributeSW, $setter)) {
                continue;
            }

            $index = null;
            foreach ($attributes as $key => $value) {
                $attributeSW->{$setter}($value);
                $attrMappings[$attr] = $mappings[$key];
                unset($attributes[$key]);
                break;
            }

            if (count($attributes) == 0) {
                break;
            }
        }

        $this->Manager()->persist($attributeSW);

        $detailSW->setAttribute($attributeSW);
        $productSW->setAttribute($attributeSW);
    }

    protected function hasVariationChanges(JtlProduct &$product)
    {
        if (count($product->getVariations()) > 0) {
            if (strlen($product->getId()->getEndpoint()) > 0 && IdConcatenator::isProductId($product->getId()->getEndpoint())) {
                $checksum = ChecksumLinker::find($product, ProductChecksum::TYPE_VARIATION);
                if ($checksum === null) {
                    return false;
                }

                return $checksum->hasChanged();
            } else {
                return true;
            }
        }

        return false;
    }

    protected function prepareVariationAssociatedData(JtlProduct $product, ArticleSW &$productSW)
    {
        // Variations
        if ($this->hasVariationChanges($product)) {
            $confiSet = $productSW->getConfiguratorSet();

            $groups = array();
            $options = array();

            if (!$confiSet) {
                $confiSet = new \Shopware\Models\Article\Configurator\Set();
                $confiSet->setName('Set-' . $product->getSku());
                $this->Manager()->persist($confiSet);
            }

            $groupMapper = Mmc::getMapper('ConfiguratorGroup');
            $optionMapper = Mmc::getMapper('ConfiguratorOption');
            $types = array();
            foreach ($product->getVariations() as $variation) {

                if (strlen(trim($variation->getType())) > 0) {
                    if (!isset($types[$variation->getType()])) {
                        $types[$variation->getType()] = 0;
                    }

                    $types[$variation->getType()]++;
                }

                $variationName = null;
                $variationValueName = null;
                foreach ($variation->getI18ns() as $variationI18n) {
                    if ($variationI18n->getLanguageISO() === LanguageUtil::map(Shopware()->Shop()->getLocale()->getLocale())) {
                        $variationName = $variationI18n->getName();
                    }
                }

                $groupSW = $groupMapper->findOneBy(array('name' => $variationName));
                if ($groupSW === null) {
                    $groupSW = (new \Shopware\Models\Article\Configurator\Group());
                    $groupSW->setName($variationName);
                    $groupSW->setDescription('');
                }

                $groupSW->setPosition($variation->getSort());
                $this->Manager()->persist($groupSW);

                //$groups->add($groupSW);
                $groups[] = $groupSW;

                foreach ($variation->getValues() as $i => $variationValue) {
                    foreach ($variationValue->getI18ns() as $variationValueI18n) {
                        if ($variationValueI18n->getLanguageISO() === LanguageUtil::map(Shopware()->Shop()->getLocale()->getLocale())) {
                            $variationValueName = $variationValueI18n->getName();
                        }
                    }

                    $optionSW = null;
                    if ($groupSW->getId() > 0) {
                        $optionSW = $optionMapper->findOneBy(array('name' => $variationValueName, 'groupId' => $groupSW->getId()));
                    }

                    if ($optionSW === null) {
                        $optionSW = new \Shopware\Models\Article\Configurator\Option();
                    }

                    $optionSW->setName($variationValueName);
                    //$optionSW->setPosition(($i + 1));
                    $optionSW->setPosition($variationValue->getSort());
                    $optionSW->setGroup($groupSW);

                    $this->Manager()->persist($optionSW);

                    //$options->add($optionSW);
                    $options[] = $optionSW;
                }
            }


            $confiSet->setOptions($options)
                ->setGroups($groups)
                ->setType($this->calcVariationType($types));

            $this->Manager()->persist($confiSet);

            $productSW->setConfiguratorSet($confiSet);
        }
    }

    protected function calcVariationType(array $types)
    {
        if (count($types) == 0) {
            return ProductVariation::TYPE_RADIO;
        }

        arsort($types);

        $checkEven = function ($vTypes) {
            if (count($vTypes) > 1) {
                $arr = array_values($vTypes);
                return ($arr[0] == $arr[1]);
            }

            return false;
        };

        reset($types);
        $key = $checkEven($types) ? ProductVariation::TYPE_RADIO : key($types);

        return VariationType::map($key);
    }

    protected function preparePriceAssociatedData(JtlProduct $product, ArticleSW &$productSW, DetailSW &$detailSW)
    {
        // fix
        /*
        $recommendedRetailPrice = 0.0;
        if ($product->getRecommendedRetailPrice() > 0.0) {
            $recommendedRetailPrice = Money::AsNet($recommendedRetailPrice, $product->getVat());
        }
        */

        /*
        // @TODO: MUSS WEG
        foreach ($product->getPrices() as $price) {
            var_dump($price->getCustomerGroupId()->getEndpoint());
            foreach ($price->getItems() as $item) {
                var_dump($item->getNetPrice());
            }
        }
        
        die();
        */

        $collection = ProductPriceMapper::buildCollection(
            $product->getPrices(),
            $productSW,
            $detailSW,
            $product->getRecommendedRetailPrice()
        );

        if (count($collection) > 0) {
            $detailSW->setPrices($collection);
        }
    }

    protected function prepareSpecificAssociatedData(JtlProduct $product, ArticleSW &$productSW, DetailSW $detailSW)
    {
        try {
            $group = null;
            $values = [];
            if (count($product->getSpecifics()) > 0) {
                $group = $productSW->getPropertyGroup();
                $optionIds = $this->getFilterOptionIds($product);
                if (is_null($group) || !$this->isSuitableFilterGroup($group, $optionIds)) {
                    $group = null;

                    /** @var Group $fetchedGroup */
                    foreach (Shopware()->Models()->getRepository(Group::class)->findAll() as $fetchedGroup) {
                        if ($this->isSuitableFilterGroup($fetchedGroup, $optionIds)) {
                            $group = $fetchedGroup;
                            break;
                        }
                    }

                    if (is_null($group)) {
                        $options = Shopware()->Models()->getRepository(Option::class)->findById($optionIds);
                        $groupName = implode('_', array_map(function (Option $option) {
                            return $option->getName();
                        }, $options));
                        $group = (new \Shopware\Models\Property\Group())
                            ->setName($groupName)
                            ->setPosition(0)
                            ->setComparable(1)
                            ->setSortMode(0)
                            ->setOptions($options);

                        Shop::entityManager()->persist($group);
                    }
                }

                $values = Shopware()->Models()->getRepository(Value::class)->findById($this->getFilterValueIds($product));
            }

            $productSW->setPropertyValues(new ArrayCollection($values));
            $productSW->setPropertyGroup($group);
        } catch (\Exception $e) {
            Logger::write(sprintf(
                'Property group (s_articles <--> s_filter) not found! %s',
                ExceptionFormatter::format($e)
            ), Logger::ERROR, 'database');
        }
    }

    /**
     * @param JtlProduct $product
     * @return integer[]
     */
    protected function getFilterOptionIds(JtlProduct $product)
    {
        $ids = array_map(function (\jtl\Connector\Model\ProductSpecific $specific) {
            return $specific->getId()->getEndpoint();
        }, $product->getSpecifics());

        return array_values(array_unique(array_filter($ids, function ($id) {
            return !empty($id);
        })));
    }

    /**
     * @param JtlProduct $product
     * @return integer[]
     */
    protected function getFilterValueIds(JtlProduct $product)
    {
        $ids = array_map(function (\jtl\Connector\Model\ProductSpecific $specific) {
            return $specific->getSpecificValueId()->getEndpoint();
        }, $product->getSpecifics());

        return array_values(array_filter($ids, function ($id) {
            return !empty($id);
        }));
    }

    /**
     * @param Group $group
     * @param integer[] $optionIds
     * @return boolean
     */
    protected function isSuitableFilterGroup(Group $group, array $optionIds)
    {
        $options = $group->getOptions();
        if (count($options) !== count($optionIds)) {
            return false;
        }

        foreach ($options as $option) {
            if (!in_array($option->getId(), $optionIds)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param ProductModel $product
     * @param ArticleSW $article
     * @param DetailSW $detail
     * @param array $attrMappings
     * @throws \Zend_Db_Adapter_Exception
     * @throws \jtl\Connector\Core\Exception\LanguageException
     */
    protected function saveTranslations(ProductModel $product, ArticleSW $article, DetailSW $detail, array $attrMappings)
    {
        $type = 'article';
        $key = $article->getId();
        $merge = false;
        if($this->isChild($product)) {
            if($detail !== $article->getMainDetail()) {
                $type = 'variant';
                $key = $detail->getId();
            } else {
                $merge = true;
            }
            $translations = $this->createArticleDetailTranslations($product, $attrMappings);
        }
        else {
            if($product->getIsMasterProduct()) {
                $merge = true;
            }
            $translations = $this->createArticleTranslations($product, $attrMappings);
        }

        /** @var \jtl\Connector\Shopware\Mapper\Shop $shopMapper */
        $shopMapper = Mmc::getMapper('Shop');
        $transUtil = new \Shopware_Components_Translation();

        foreach($translations as $langIso => $translation) {
            /** @var \Shopware\Models\Shop\Locale $locale */
            $locale = LocaleUtil::getByKey(LanguageUtil::map(null, null, $langIso));
            if (is_null($locale)) {
                Logger::write(sprintf('Could not find any locale for (%s)', $langIso), Logger::WARNING, 'database');
                continue;
            }

            $shops = $shopMapper->findByLocale($locale->getLocale());
            if (is_null($shops) || !is_array($shops) || count($shops) == 0) {
                Logger::write(
                    sprintf('Could not find any shop with locale (%s) and iso (%s)',
                        $locale->getLocale(),
                        $langIso
                    ), Logger::WARNING, 'database');

                continue;
            }

            /** @var \Shopware\Models\Shop\Shop $shop */
            foreach($shops as $shop) {
                if($merge) {
                    $savedTranslation = $transUtil->read($shop->getId(), $type, $key);
                    $translation = array_merge($savedTranslation, $translation);
                }
                $transUtil->write($shop->getId(), $type, $key, $translation);
            }
        }

    }

    /**
     * @param ProductModel $product
     * @param array $attrMappings
     * @return string[]
     * @throws \jtl\Connector\Core\Exception\LanguageException
     */
    protected function createArticleTranslations(ProductModel $product, array $attrMappings)
    {
        $detailTranslations = [];
        if(!$product->getIsMasterProduct()) {
            $detailTranslations = $this->createArticleDetailTranslations($product, $attrMappings);
        }

        $data = [];
        foreach($product->getI18ns() as $i18n) {
            $langIso = $i18n->getLanguageISO();
            if ($langIso === LanguageUtil::map(Shop::locale()->getLocale())) {
                continue;
            }

            if(!isset($data[$langIso])) {
                $data[$langIso] = $this->initArticleTranslation();
            }

            $data[$langIso] = [
                'name' => ProductNameHelper::build($product, $langIso)->getProductName(),
                'descriptionLong' => $i18n->getDescription(),
                'metaTitle' => $i18n->getTitleTag(),
                'description' => $i18n->getMetaDescription(),
                'keywords' => $i18n->getMetaKeywords(),
            ];
        }

        foreach($detailTranslations as $langIso => $translation) {
            if(!isset($data[$langIso])) {
                $data[$langIso] = [];
            }

            $data[$langIso] = array_merge($data[$langIso], $translation);
        }

        return $data;
    }

    /**
     * @param ProductModel $product
     * @param array $attrMappings
     * @return array
     * @throws \jtl\Connector\Core\Exception\LanguageException
     */
    protected function createArticleDetailTranslations(ProductModel $product, array $attrMappings)
    {
        $data = [];
        foreach($product->getAttributes() as $attribute) {
            foreach ($attribute->getI18ns() as $attrI18n) {
                $langIso = $attrI18n->getLanguageISO();
                if ($langIso === LanguageUtil::map(Shop::locale()->getLocale())) {
                    continue;
                }

                if (!isset($data[$langIso])) {
                    $data[$langIso] = $this->initVariantTranslation();
                }

                if(strtolower($attrI18n->getName()) === ProductAttr::ADDITIONAL_TEXT) {
                    $data[$langIso]['additionalText'] = $attrI18n->getValue();
                }
                elseif (($index = array_search($attribute->getId()->getHost(), $attrMappings)) !== false) {
                    $i = "__attribute_{$index}";
                    $data[$langIso][$i] = $attrI18n->getValue();
                }
            }
        }

        // Unit
        if ($product->getUnitId()->getHost() != 0) {
            $unitMapper = Mmc::getMapper('Unit');
            $unitSW = $unitMapper->findOneBy(array('hostId' => $product->getUnitId()->getHost()));
            if (!is_null($unitSW)) {
                foreach ($unitSW->getI18ns() as $unitI18n) {
                    $langIso = $unitI18n->getLanguageIso();
                    if ($langIso === LanguageUtil::map(Shop::locale()->getLocale())) {
                        continue;
                    }
                }

                if (!isset($data[$langIso])) {
                    $data[$langIso] = $this->initVariantTranslation();
                }

                $data[$langIso]['packUnit'] = $unitI18n->getName();
            }
        }

        return $data;
    }

    /**
     * @return array
     */
    protected function initVariantTranslation()
    {
        return [
            'additionalText' => '',
            'packUnit' => '',
            'shippingTime' => '',
        ];
    }

    /**
     * @return array
     */
    protected function initArticleTranslation()
    {
        return [
            'name' => '',
            'description' => '',
            'descriptionLong' => '',
            'shippingTime' => '',
            'additionalText' => '',
            'keywords' => '',
            'packUnit' => '',
        ];
    }

    protected function saveVariationTranslationData(JtlProduct $product, ArticleSW &$productSW)
    {
        /** @var ConfiguratorGroup $groupMapper */
        $groupMapper = Mmc::getMapper('ConfiguratorGroup');

        /** @var ConfiguratorOption $optionMapper */
        $optionMapper = Mmc::getMapper('ConfiguratorOption');
        $confiSetSW = $productSW->getConfiguratorSet();
        if ($confiSetSW !== null && count($product->getVariations()) > 0) {

            // Get default translation values
            $variations = array();
            $values = array();
            $defaultIso = LanguageUtil::map(Shopware()->Shop()->getLocale()->getLocale());
            foreach ($product->getVariations() as $variation) {
                foreach ($variation->getI18ns() as $variationI18n) {
                    if ($variationI18n->getLanguageISO() === $defaultIso) {
                        $variations[$variationI18n->getName()] = $variation->getId()->getHost();
                        break;
                    }
                }

                foreach ($variation->getValues() as $value) {
                    foreach ($value->getI18ns() as $valueI18n) {
                        if ($valueI18n->getLanguageISO() === $defaultIso) {
                            $values[$variation->getId()->getHost()][$valueI18n->getName()] = $value->getId()->getHost();
                            break;
                        }
                    }
                }
            }

            // Write non default translation values
            foreach ($product->getVariations() as $variation) {
                foreach ($variation->getI18ns() as $variationI18n) {
                    if ($variationI18n->getLanguageISO() !== $defaultIso) {
                        foreach ($confiSetSW->getGroups() as $groupSW) {
                            if (isset($variations[$groupSW->getName()]) && $variations[$groupSW->getName()] == $variation->getId()->getHost()) {
                                try {
                                    $groupMapper->saveTranslatation($groupSW->getId(), $variationI18n->getLanguageISO(), $variationI18n->getName());
                                } catch (\Exception $e) {
                                    Logger::write(ExceptionFormatter::format($e), Logger::ERROR, 'database');
                                }
                            }
                        }
                    }
                }

                foreach ($variation->getValues() as $value) {
                    foreach ($value->getI18ns() as $valueI18n) {
                        if ($valueI18n->getLanguageISO() !== $defaultIso) {
                            foreach ($confiSetSW->getOptions() as $optionSW) {
                                if (isset($values[$variation->getId()->getHost()][$optionSW->getName()])
                                    && $values[$variation->getId()->getHost()][$optionSW->getName()] == $value->getId()->getHost()) {

                                    try {
                                        $optionMapper->saveTranslatation($optionSW->getId(), $valueI18n->getLanguageISO(), $valueI18n->getName());
                                    } catch (\Exception $e) {
                                        Logger::write(ExceptionFormatter::format($e), Logger::ERROR, 'database');
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    protected function prepareSetVariationRelations(JtlProduct $product, ArticleSW &$productSW)
    {
        if (!$this->hasVariationChanges($product)) {
            return;
        }

        $confiSet = $productSW->getConfiguratorSet();

        $sql = "DELETE FROM s_article_configurator_set_group_relations WHERE set_id = ?";
        Shopware()->Db()->query($sql, array($confiSet->getId()));

        $sql = "DELETE FROM s_article_configurator_set_option_relations WHERE set_id = ?";
        Shopware()->Db()->query($sql, array($confiSet->getId()));

        // Groups
        foreach ($confiSet->getGroups() as $groupSW) {
            $sql = "INSERT INTO s_article_configurator_set_group_relations (set_id, group_id) VALUES (?,?)";
            Shopware()->Db()->query($sql, array($confiSet->getId(), $groupSW->getId()));
        }

        // Options            
        foreach ($confiSet->getOptions() as $optionSW) {
            $sql = "INSERT INTO s_article_configurator_set_option_relations (set_id, option_id) VALUES (?,?)";
            Shopware()->Db()->query($sql, array($confiSet->getId(), $optionSW->getId()));
        }
    }

    protected function prepareUnitAssociatedData(JtlProduct $product, DetailSW &$detailSW = null)
    {
        if ($product->getUnitId()->getHost() > 0) {
            $unitMapper = Mmc::getMapper('Unit');
            $unitSW = $unitMapper->findOneBy(array('hostId' => $product->getUnitId()->getHost()));
            if ($unitSW !== null) {
                foreach ($unitSW->getI18ns() as $unitI18n) {
                    if ($unitI18n->getLanguageIso() === LanguageUtil::map(Shopware()->Shop()->getLocale()->getLocale())) {
                        $detailSW->setPackUnit($unitI18n->getName());
                    }
                }
            }
        }
    }

    protected function prepareMeasurementUnitAssociatedData(JtlProduct $product, DetailSW &$detailSW = null)
    {
        if (strlen($product->getMeasurementUnitCode()) > 0) {
            $measurementUnitMapper = Mmc::getMapper('MeasurementUnit');
            $measurementUnitSW = $measurementUnitMapper->findOneBy(array('unit' => $product->getMeasurementUnitCode()));
            if ($measurementUnitSW !== null) {
                $detailSW->setUnit($measurementUnitSW);
            }
        }
    }

    protected function prepareMediaFileAssociatedData(JtlProduct $product, ArticleSW &$productSW)
    {
        $linkCollection = array();
        $downloadCollection = array();

        foreach ($product->getMediaFiles() as $mediaFile) {
            $name = '';
            foreach ($mediaFile->getI18ns() as $i18n) {
                if ($i18n->getLanguageIso() === LanguageUtil::map(Shopware()->Shop()->getLocale()->getLocale())) {
                    $name = $i18n->getName();
                }
            }

            if (preg_match('/^http|ftp{1}/i', $mediaFile->getUrl())) {
                $linkSW = new LinkSW();
                $linkSW->setLink($mediaFile->getUrl())
                    ->setName($name);

                $this->Manager()->persist($linkSW);
                $linkCollection[] = $linkSW;
            } else {
                $downloadSW = new DownloadSW();
                $downloadSW->setFile($mediaFile->getUrl())
                    ->setSize(0)
                    ->setName($name);

                $this->Manager()->persist($downloadSW);
                $downloadCollection[] = $downloadSW;
            }
        }

        $productSW->setLinks($linkCollection);
        $productSW->setDownloads($downloadCollection);
    }

    protected function deleteTranslationData(ArticleSW $productSW)
    {
        $translationUtil = new TranslationUtil();
        $translationUtil->delete('article', $productSW->getId());
    }

    protected function deleteProductData(JtlProduct $product)
    {
        $productId = (strlen($product->getId()->getEndpoint()) > 0) ? $product->getId()->getEndpoint() : null;

        /*
        Logger::write(sprintf('>>> Product with id (%s, %s), masterProductId (%s, %s), manufacturerId (%s, %s)',
            $product->getId()->getEndpoint(),
            $product->getId()->getHost(),
            $product->getMasterProductId()->getEndpoint(),
            $product->getMasterProductId()->getHost(),
            $product->getManufacturerId()->getEndpoint(),
            $product->getManufacturerId()->getHost()
        ), Logger::DEBUG, 'database');
        */

        if ($productId !== null) {
            list($detailId, $id) = IdConcatenator::unlink($productId);
            $detailSW = $this->findDetail((int)$detailId);
            if ($detailSW === null) {
                //throw new DatabaseException(sprintf('Detail (%s) not found', $detailId));
                Logger::write(sprintf('Detail with id (%s, %s) not found',
                    $product->getId()->getEndpoint(),
                    $product->getId()->getHost()
                ), Logger::ERROR, 'database');
                return;
            }

            $productSW = $this->find((int)$id);
            if ($productSW === null) {
                Logger::write(sprintf('Product with id (%s, %s) not found',
                    $product->getId()->getEndpoint(),
                    $product->getId()->getHost()
                ), Logger::ERROR, 'database');
                return;
            }

            $mainDetailId = Shopware()->Db()->fetchOne(
                'SELECT main_detail_id FROM s_articles WHERE id = ?',
                array($productSW->getId())
            );

            $sql = 'DELETE FROM s_article_configurator_option_relations WHERE article_id = ?';
            Shopware()->Db()->query($sql, array($detailSW->getId()));

            if ($this->isChildSW($productSW, $detailSW)) {
                //Shopware()->Db()->delete('s_articles_attributes', array('articledetailsID = ?' => $detailSW->getId()));

                try {
                    Shopware()->Db()->delete('s_articles_attributes', array('articledetailsID = ?' => $detailSW->getId()));
                    Shopware()->Db()->delete('s_articles_prices', array('articledetailsID = ?' => $detailSW->getId()));
                    Shopware()->Db()->delete('s_articles_details', array('id = ?' => $detailSW->getId()));

                    if ($mainDetailId == $detailSW->getId()) {
                        $count = Shopware()->Db()->fetchOne(
                            'SELECT count(*) FROM s_articles_details WHERE articleID = ?',
                            array($productSW->getId())
                        );

                        $kindSql = ($count > 1) ? ' AND kind != ' . self::KIND_VALUE_PARENT . ' ' : '';

                        Shopware()->Db()->query(
                            'UPDATE s_articles SET main_detail_id = (SELECT id FROM s_articles_details WHERE articleID = ? ' . $kindSql . ' LIMIT 1) WHERE id = ?',
                            array($productSW->getId(), $productSW->getId())
                        );

                        /*
                        $sql = '
                            INSERT INTO s_articles_attributes (id, articleID, articledetailsID)
                              SELECT null, ?, main_detail_id
                              FROM s_articles
                              WHERE id = ?
                        ';

                        Shopware()->Db()->query($sql, array($productSW->getId(), $productSW->getId()));
                        */
                    }

                    /*
                    $this->Manager()->remove($detailSW->getAttribute());
                    $this->Manager()->remove($detailSW);
                    $this->Manager()->flush();
                    */

                    /*
                    Logger::write(sprintf('>>>> DELETING DETAIL with id (%s, %s)',
                        $product->getId()->getEndpoint(),
                        $product->getId()->getHost()
                    ), Logger::DEBUG, 'database');
                    */

                    /*
                    if ($productSW !== null && $mainDetailId == $detailSW->getId()) {
                        $mainDetailSW = $this->findDetailBy(array('articleId' => $productSW->getId()));

                        if ($mainDetailSW !== null && $mainDetailSW->getKind() != 0) {
                            $attributeSW = $mainDetailSW->getAttribute();
                            if ($attributeSW === null) {
                                $attributeSW = new \Shopware\Models\Attribute\Article();
                                $attributeSW->setArticle($productSW);
                                $attributeSW->setArticleDetail($mainDetailSW);

                                $this->Manager()->persist($attributeSW);
                            }

                            $productSW->setAttribute($attributeSW);
                            $mainDetailSW->setAttribute($attributeSW);
                            $productSW->setMainDetail($mainDetailSW);

                            $this->Manager()->persist($productSW);
                            $this->Manager()->flush();
                        }
                    }
                    */
                } catch (\Exception $e) {
                    Logger::write('DETAIL ' . ExceptionFormatter::format($e), Logger::ERROR, 'database');
                }
            } elseif ($productSW !== null) {
                try {
                    $this->deleteTranslationData($productSW);

                    $set = $productSW->getConfiguratorSet();
                    if ($set !== null) {
                        $this->Manager()->remove($set);
                    }

                    Shopware()->Db()->delete('s_articles_attributes', array('articledetailsID = ?' => $detailSW->getId()));
                    Shopware()->Db()->delete('s_articles_prices', array('articledetailsID = ?' => $detailSW->getId()));
                    Shopware()->Db()->delete('s_articles_details', array('id = ?' => $detailSW->getId()));
                    Shopware()->Db()->query(
                        'DELETE f, r
                            FROM s_filter f
                            LEFT JOIN s_filter_relations r ON r.groupID = f.id
                            WHERE f.name = ?',
                        array($detailSW->getNumber())
                    );
                    Shopware()->Db()->delete('s_filter_articles', array('articleID = ?' => $productSW->getId()));

                    $this->Manager()->remove($productSW);
                    $this->Manager()->flush($productSW);

                    /*
                    Logger::write(sprintf('>>>> DELETING PARENT with id (%s, %s)',
                        $product->getId()->getEndpoint(),
                        $product->getId()->getHost()
                    ), Logger::DEBUG, 'database');
                    */
                } catch (\Exception $e) {
                    Logger::write('PARENT ' . ExceptionFormatter::format($e), Logger::ERROR, 'database');
                }
            }
        }
    }

    public function isChild(JtlProduct $product)
    {
        //return (strlen($product->getId()->getEndpoint()) > 0 && strpos($product->getId()->getEndpoint(), '_') !== false);
        //return (!$product->getIsMasterProduct() && count($product->getVariations()) > 0 && $product->getMasterProductId()->getHost() > 0);
        return (!$product->getIsMasterProduct() && $product->getMasterProductId()->getHost() > 0);
    }

    public function isParent(JtlProduct $product)
    {
        //return ($product->getIsMasterProduct() && count($product->getVariations()) > 0 && $product->getMasterProductId()->getHost() == 0);
        return ($product->getIsMasterProduct() && $product->getMasterProductId()->getHost() == 0);
    }

    public function isChildSW(ArticleSW $productSW = null, DetailSW $detailSW)
    {
        // If the parent is already deleted or a configurator set is present
        if ($productSW === null || ($productSW->getConfiguratorSet() !== null && $productSW->getConfiguratorSet()->getId() > 0)) {
            return ((int)$detailSW->getKind() !== self::KIND_VALUE_PARENT);
        }

        return false;
    }


    /**
     * @param mixed[] $data
     * @return boolean
     */
    public function isDetailData(array $data)
    {
        return (
            isset($data['article']) &&
            is_array($data['article']) &&
            isset($data['article']['configuratorSetId']) &&
            (int) $data['article']['configuratorSetId'] > 0 &&
            isset($data['kind']) &&
            $data['kind'] != self::KIND_VALUE_PARENT
        );
    }

    /**
     * @param mixed[] $data
     * @return boolean
     */
    public function isParentData(array $data)
    {
        return (
            isset($data['configuratorSetId']) &&
            (int)$data['configuratorSetId'] > 0 &&
            isset($data['kind']) &&
            (int) $data['kind'] == self::KIND_VALUE_PARENT
        );
    }
}