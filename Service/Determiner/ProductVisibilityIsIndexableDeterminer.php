<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\IndexingProductsExcludeByVisibility\Service\Determiner;

use Klevu\IndexingApi\Service\Determiner\IsIndexableDeterminerInterface;
use Klevu\IndexingProducts\Service\Provider\ProductEntityProvider;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Cms\Api\Data\PageInterface;
use Magento\Framework\Api\ExtensibleDataInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class ProductVisibilityIsIndexableDeterminer implements IsIndexableDeterminerInterface
{
    public const XML_PATH_SYNC_VISIBILITIES = 'klevu/indexing_products_exclude_by_visibility/sync_visibilities';

    /**
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;
    /**
     * @var ScopeConfigInterface
     */
    private readonly ScopeConfigInterface $scopeConfig;
    /**
     * @var ProductRepositoryInterface
     */
    private readonly ProductRepositoryInterface $productRepository;

    /**
     * @param LoggerInterface $logger
     * @param ScopeConfigInterface $scopeConfig
     * @param ProductRepositoryInterface $productRepository
     */
    public function __construct(
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig,
        ProductRepositoryInterface $productRepository,
    ) {
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        $this->productRepository = $productRepository;
    }

    /**
     * @param ExtensibleDataInterface|PageInterface $entity
     * @param StoreInterface $store
     * @param string $entitySubtype
     *
     * @return bool
     */
    public function execute(
        ExtensibleDataInterface|PageInterface $entity,
        StoreInterface $store,
        string $entitySubtype = '',
    ): bool {
        if (!($entity instanceof ProductInterface)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid argument provided for "$entity". '
                        . 'Expected %s implementing "getData", "getDataUsingMethod", received %s.',
                    ProductInterface::class,
                    $entity::class,
                ),
            );
        }

        $storeId = (int)$store->getId();
        switch ($entitySubtype) {
            case ProductEntityProvider::ENTITY_SUBTYPE_CONFIGURABLE_VARIANTS:
                $parentProduct = $this->getParentProduct(
                    product: $entity,
                    storeId: $storeId,
                );

                $return = $parentProduct
                    ? $this->isIndexable($parentProduct, $storeId)
                    : true;
                break;

            default:
                $return = $this->isIndexable($entity, $storeId);
                break;
        }

        return $return;
    }

    /**
     * @param ProductInterface $product
     * @param int $storeId
     *
     * @return bool
     */
    private function isIndexable(
        ProductInterface $product,
        int $storeId,
    ): bool {
        if (!method_exists($product, 'getDataUsingMethod')) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid argument provided for "$entity". '
                    . 'Expected %s implementing "getData", "getDataUsingMethod", received %s.',
                    ProductInterface::class,
                    $product::class,
                ),
            );
        }

        return in_array(
            needle: (int)$product->getDataUsingMethod(ProductInterface::VISIBILITY),
            haystack: $this->getAllowedSyncVisibilities($storeId),
            strict: true,
        );
    }

    /**
     * @param int $storeId
     *
     * @return int[]
     */
    private function getAllowedSyncVisibilities(int $storeId): array
    {
        $rawConfigValue = $this->scopeConfig->getValue(
            static::XML_PATH_SYNC_VISIBILITIES,
            ScopeInterface::SCOPE_STORES,
            $storeId,
        );

        $configuredVisibilities = array_map(
            callback: 'intval',
            array: explode(
                separator: ',',
                string: trim((string)$rawConfigValue),
            ),
        );

        return array_intersect(
            [
                Visibility::VISIBILITY_NOT_VISIBLE,
                Visibility::VISIBILITY_IN_CATALOG,
                Visibility::VISIBILITY_IN_SEARCH,
                Visibility::VISIBILITY_BOTH,
            ],
            $configuredVisibilities,
        );
    }

    /**
     * @param ProductInterface $product
     * @param int $storeId
     *
     * @return ProductInterface|null
     */
    private function getParentProduct(
        ProductInterface $product,
        int $storeId,
    ): ?ProductInterface {
        if (
            !method_exists($product, 'getData')
            || !method_exists($product, 'getDataUsingMethod')
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid argument provided for "$entity". '
                    . 'Expected %s implementing "getData", "getDataUsingMethod", received %s.',
                    ProductInterface::class,
                    $product::class,
                ),
            );
        }

        $parentId = $product->getDataUsingMethod('parent_id');
        if (!$parentId) {
            $this->logger->warning(
                message: 'Received configurable variant product without parent id information in {method}',
                context: [
                    'method' => __METHOD__,
                    'productData' => $product->getData(),
                ],
            );

            return null;
        }

        try {
            $parentProduct = $this->productRepository->getById(
                productId: (int)$parentId,
                editMode: false,
                storeId: $storeId,
            );
        } catch (NoSuchEntityException $exception) {
            $this->logger->error(
                message: 'Received configurable variant product with invalid parent id in {method}',
                context: [
                    'method' => __METHOD__,
                    'productData' => $product->getData(),
                ],
            );

            return null;
        }

        return $parentProduct;
    }
}
