<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\IndexingProductsExcludeByVisibility\Service\Determiner;

use Klevu\Configuration\Service\Provider\ScopeProviderInterface;
use Klevu\IndexingApi\Service\Determiner\IsIndexableDeterminerInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Cms\Api\Data\PageInterface;
use Magento\Framework\Api\ExtensibleDataInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
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
     * @var ScopeProviderInterface
     */
    private readonly ScopeProviderInterface $scopeProvider;

    /**
     * @param LoggerInterface $logger
     * @param ScopeConfigInterface $scopeConfig
     * @param ScopeProviderInterface $scopeProvider
     */
    public function __construct(
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig,
        ScopeProviderInterface $scopeProvider,
    ) {
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        $this->scopeProvider = $scopeProvider;
    }

    /**
     * @param ExtensibleDataInterface|PageInterface $entity
     * @param StoreInterface $store
     * @param string $entitySubtype
     *
     * @return bool
     */
    public function execute(
        ExtensibleDataInterface | PageInterface $entity,
        StoreInterface $store,
        string $entitySubtype = '', // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
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

        return $this->isIndexable($entity, $store);
    }

    /**
     * @param ProductInterface $product
     * @param StoreInterface $store
     *
     * @return bool
     */
    private function isIndexable(
        ProductInterface $product,
        StoreInterface $store,
    ): bool {
        $visibility = (int)$product->getVisibility();
        $isVisibilityAllowed = in_array(
            needle: $visibility,
            haystack: $this->getAllowedSyncVisibilities((int)$store->getId()),
            strict: true,
        );

        if (!$isVisibilityAllowed) {
            $currentScope = $this->scopeProvider->getCurrentScope();
            $this->scopeProvider->setCurrentScope(scope: $store);
            $this->logger->debug(
            // phpcs:ignore Generic.Files.LineLength.TooLong
                message: 'Store ID: {storeId} Product ID: {productId} not indexable due to Visibility: {visibility} in {method}',
                context: [
                    'storeId' => $store->getId(),
                    'productId' => $product->getId(),
                    'visibility' => $visibility,
                    'method' => __METHOD__,
                ],
            );
            if ($currentScope->getScopeObject()) {
                $this->scopeProvider->setCurrentScope(scope: $currentScope->getScopeObject());
            } else {
                $this->scopeProvider->unsetCurrentScope();
            }
        }

        return $isVisibilityAllowed;
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
}
