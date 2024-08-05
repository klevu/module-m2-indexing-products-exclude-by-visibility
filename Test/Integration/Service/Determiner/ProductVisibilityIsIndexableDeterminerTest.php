<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\IndexingProductsExcludeByVisibility\Test\Integration\Service\Determiner;

use Klevu\IndexingApi\Service\Determiner\IsIndexableDeterminerInterface;
use Klevu\IndexingProducts\Service\Provider\ProductEntityProvider;
use Klevu\IndexingProductsExcludeByVisibility\Service\Determiner\ProductVisibilityIsIndexableDeterminer;
use Klevu\TestFixtures\Catalog\ProductTrait;
use Klevu\TestFixtures\Store\StoreFixturesPool;
use Klevu\TestFixtures\Store\StoreTrait;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Klevu\TestFixtures\Traits\TestImplementsInterfaceTrait;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TddWizard\Fixtures\Catalog\ProductFixturePool;
use TddWizard\Fixtures\Core\ConfigFixture;

/**
 * @covers \Klevu\IndexingProductsExcludeByVisibility\Service\Determiner\ProductVisibilityIsIndexableDeterminer::class
 * @method ProductVisibilityIsIndexableDeterminer instantiateTestObject(?array $arguments = null)
 * @method ProductVisibilityIsIndexableDeterminer instantiateTestObjectFromInterface(?array $arguments = null)
 */
class ProductVisibilityIsIndexableDeterminerTest extends TestCase
{
    use ObjectInstantiationTrait;
    use ProductTrait;
    use StoreTrait;
    use TestImplementsInterfaceTrait;

    /**
     * @var ObjectManagerInterface|null
     */
    private ?ObjectManagerInterface $objectManager = null; // @phpstan-ignore-line

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->implementationFqcn = ProductVisibilityIsIndexableDeterminer::class;
        $this->interfaceFqcn = IsIndexableDeterminerInterface::class;
        $this->objectManager = Bootstrap::getObjectManager();
        $this->storeFixturesPool = $this->objectManager->get(StoreFixturesPool::class);
        $this->productFixturePool = $this->objectManager->get(ProductFixturePool::class);
    }

    /**
     * @return void
     * @throws \Exception
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        $this->productFixturePool->rollback();
        $this->storeFixturesPool->rollback();
    }

    /**
     * @testWith [4, "simple", "simple", "4"]
     *           [4, "simple", "simple", "1,4"]
     *           [3, "simple", "simple", "3"]
     *           [3, "simple", "simple", "1,3"]
     *           [2, "simple", "simple", "2"]
     *           [2, "simple", "simple", "2,4"]
     *           [1, "virtual", "simple", "1"]
     *           [1, "downloadable", "simple", "1,3"]
     *           [4, "virtual", "virtual", "1,4"]
     *           [3, "virtual", "virtual", "1,3"]
     *           [2, "virtual", "virtual", "2,4"]
     *           [1, "virtual", "virtual", "1,3"]
     *           [4, "downloadable", "downloadable", "1,4"]
     *           [3, "downloadable", "downloadable", "1,3"]
     *           [2, "downloadable", "downloadable", "2,4"]
     *           [1, "downloadable", "downloadable", "1,3"]
     *           [4, "bundle", "bundle", "1,4"]
     *           [3, "bundle", "bundle", "1,3"]
     *           [2, "bundle", "bundle", "2,4"]
     *           [1, "bundle", "bundle", "1,3"]
     *           [4, "grouped", "grouped", "1,4"]
     *           [3, "grouped", "grouped", "1,3"]
     *           [2, "grouped", "grouped", "2,4"]
     *           [1, "grouped", "grouped", "1,3"]
     *           [4, "configurable", "configurable", "1,4"]
     *           [3, "configurable", "configurable", "1,3"]
     *           [2, "configurable", "configurable", "2,4"]
     *           [1, "configurable", "configurable", "1,3"]
     *           [1, "simple", "configurable", "1,3"]
     */
    public function testExecute_ReturnsTrue_ForStandardEntitySubtypes_ForConfiguredVisibility(
        int $productVisibility,
        string $productType,
        string $entitySubtype,
        string $configVisibilities,
    ): void {
        $this->createStore();
        $storeFixture = $this->storeFixturesPool->get('test_store');
        $this->createProduct([
            ProductInterface::TYPE_ID => $productType,
            ProductInterface::VISIBILITY => $productVisibility,
        ]);
        $productFixture = $this->productFixturePool->get('test_product');
        $product = $productFixture->getProduct();

        ConfigFixture::setGlobal(
            path: ProductVisibilityIsIndexableDeterminer::XML_PATH_SYNC_VISIBILITIES,
            value: $configVisibilities,
        );

        $determiner = $this->instantiateTestObject([
            'logger' => $this->getMockLogger_ExpectedNotToWrite(),
        ]);
        $this->assertTrue(
            condition: $determiner->execute(
                entity: $product,
                store: $storeFixture->get(),
                entitySubtype: $entitySubtype,
            ),
        );
    }

    /**
     * @testWith [1, 4, "simple", "configurable_variants", "4"]
     *           [2, 4, "simple", "configurable_variants", "1,4"]
     *           [1, 3, "simple", "configurable_variants", "3"]
     *           [2, 3, "simple", "configurable_variants", "1,3"]
     *           [2, 3, "virtual", "configurable_variants", "1,3"]
     *           [2, 3, "downloadable", "configurable_variants", "1,3"]
     */
    public function testExecute_ReturnsTrue_ForVariantSubtypes_ForConfiguredVisibility(
        int $productVisibility,
        int $parentVisibility,
        string $productType,
        string $entitySubtype,
        string $configVisibilities,
    ): void {
        $this->createStore();
        $storeFixture = $this->storeFixturesPool->get('test_store');

        $this->createProduct([
            ProductInterface::TYPE_ID => $productType,
            ProductInterface::VISIBILITY => $productVisibility,
        ]);
        $productFixture = $this->productFixturePool->get('test_product');
        /** @var Product $product */
        $product = $productFixture->getProduct();

        $this->createProduct([
            'key' => 'test_parent_product',
            ProductInterface::TYPE_ID => 'configurable',
            ProductInterface::VISIBILITY => $parentVisibility,
            'variants' => [
                $product,
            ],
        ]);
        $parentFixture = $this->productFixturePool->get('test_parent_product');
        $parentProduct = $parentFixture->getProduct();

        $product->setData('parent_id', $parentProduct->getId());

        ConfigFixture::setGlobal(
            path: ProductVisibilityIsIndexableDeterminer::XML_PATH_SYNC_VISIBILITIES,
            value: $configVisibilities,
        );

        $determiner = $this->instantiateTestObject([
            'logger' => $this->getMockLogger_ExpectedNotToWrite(),
        ]);
        $this->assertTrue(
            condition: $determiner->execute(
                entity: $product,
                store: $storeFixture->get(),
                entitySubtype: $entitySubtype,
            ),
        );
    }

    /**
     * @testWith [4, "simple", "simple", "3"]
     *           [4, "simple", "simple", "1,3"]
     *           [3, "simple", "simple", "4"]
     *           [3, "simple", "simple", "1,4"]
     *           [2, "simple", "simple", "1"]
     *           [2, "simple", "simple", "1,3"]
     *           [1, "virtual", "simple", "2"]
     *           [1, "downloadable", "simple", "2,4"]
     *           [4, "virtual", "virtual", "1,3"]
     *           [3, "virtual", "virtual", "1,4"]
     *           [2, "virtual", "virtual", "1,3"]
     *           [1, "virtual", "virtual", "2,4"]
     *           [4, "downloadable", "downloadable", "1,3"]
     *           [3, "downloadable", "downloadable", "1,4"]
     *           [2, "downloadable", "downloadable", "1,3"]
     *           [1, "downloadable", "downloadable", "2,4"]
     *           [4, "bundle", "bundle", "1,3"]
     *           [3, "bundle", "bundle", "1,4"]
     *           [2, "bundle", "bundle", "1,3"]
     *           [1, "bundle", "bundle", "2,4"]
     *           [4, "grouped", "grouped", "1,3"]
     *           [3, "grouped", "grouped", "1,4"]
     *           [2, "grouped", "grouped", "1,3"]
     *           [1, "grouped", "grouped", "2,4"]
     *           [4, "configurable", "configurable", "1,3"]
     *           [3, "configurable", "configurable", "1,4"]
     *           [2, "configurable", "configurable", "1,3"]
     *           [1, "configurable", "configurable", "2,4"]
     */
    public function testExecute_ReturnsFalse_ForStandardEntitySubtypes_ForNotConfiguredVisibility(
        int $productVisibility,
        string $productType,
        string $entitySubtype,
        string $configVisibilities,
    ): void {
        $this->createStore();
        $storeFixture = $this->storeFixturesPool->get('test_store');

        $this->createProduct([
            ProductInterface::TYPE_ID => $productType,
            ProductInterface::VISIBILITY => $productVisibility,
        ]);
        $productFixture = $this->productFixturePool->get('test_product');
        /** @var Product $product */
        $product = $productFixture->getProduct();

        ConfigFixture::setGlobal(
            path: ProductVisibilityIsIndexableDeterminer::XML_PATH_SYNC_VISIBILITIES,
            value: $configVisibilities,
        );

        $determiner = $this->instantiateTestObject([
            'logger' => $this->getMockLogger_ExpectedNotToWrite(),
        ]);
        $this->assertFalse(
            condition: $determiner->execute(
                entity: $product,
                store: $storeFixture->get(),
                entitySubtype: $entitySubtype,
            ),
        );
    }

    /**
     * @testWith [4, 1, "simple", "configurable_variants", "4"]
     *           [4, 2, "simple", "configurable_variants", "1,4"]
     *           [3, 1, "simple", "configurable_variants", "3"]
     *           [3, 2, "simple", "configurable_variants", "1,3"]
     *           [3, 2, "virtual", "configurable_variants", "1,3"]
     *           [3, 2, "downloadable", "configurable_variants", "1,3"]
     */
    public function testExecute_ReturnsFalse_ForVariantSubtypes_ForNotConfiguredVisibility(
        int $productVisibility,
        int $parentVisibility,
        string $productType,
        string $entitySubtype,
        string $configVisibilities,
    ): void {
        $this->createStore();
        $storeFixture = $this->storeFixturesPool->get('test_store');

        $this->createProduct([
            ProductInterface::TYPE_ID => $productType,
            ProductInterface::VISIBILITY => $productVisibility,
        ]);
        $productFixture = $this->productFixturePool->get('test_product');
        /** @var Product $product */
        $product = $productFixture->getProduct();

        $this->createProduct([
            'key' => 'test_parent_product',
            ProductInterface::TYPE_ID => 'configurable',
            ProductInterface::VISIBILITY => $parentVisibility,
            'variants' => [
                $product,
            ],
        ]);
        $parentFixture = $this->productFixturePool->get('test_parent_product');
        $parentProduct = $parentFixture->getProduct();

        $product->setData('parent_id', $parentProduct->getId());

        ConfigFixture::setGlobal(
            path: ProductVisibilityIsIndexableDeterminer::XML_PATH_SYNC_VISIBILITIES,
            value: $configVisibilities,
        );

        $determiner = $this->instantiateTestObject([
            'logger' => $this->getMockLogger_ExpectedNotToWrite(),
        ]);
        $this->assertFalse(
            condition: $determiner->execute(
                entity: $product,
                store: $storeFixture->get(),
                entitySubtype: $entitySubtype,
            ),
        );
    }

    public function testExecute_ReturnsTrue_ForVariantSubtypes_WithoutParentId(): void
    {
        $this->createStore();
        $storeFixture = $this->storeFixturesPool->get('test_store');

        $this->createProduct([
            ProductInterface::TYPE_ID => 'simple',
            ProductInterface::VISIBILITY => 1,
        ]);
        $productFixture = $this->productFixturePool->get('test_product');
        /** @var Product $product */
        $product = $productFixture->getProduct();

        ConfigFixture::setGlobal(
            path: ProductVisibilityIsIndexableDeterminer::XML_PATH_SYNC_VISIBILITIES,
            value: '4',
        );

        $mockLogger = $this->getMockLogger_ExpectedNotToWrite([
            'emergency',
            'critical',
            'alert',
            'error',
            'notice',
            'info',
            'debug',
        ]);
        $mockLogger->expects($this->once())
            ->method('warning')
            ->with(
                'Received configurable variant product without parent id information in {method}',
            );
        $determiner = $this->instantiateTestObject([
            'logger' => $mockLogger,
        ]);
        $this->assertTrue(
            condition: $determiner->execute(
                entity: $product,
                store: $storeFixture->get(),
                entitySubtype: ProductEntityProvider::ENTITY_SUBTYPE_CONFIGURABLE_VARIANTS,
            ),
        );
    }

    public function testExecute_ReturnsTrue_ForVariantSubtypes_WithNotFoundParent(): void
    {
        $this->createStore();
        $storeFixture = $this->storeFixturesPool->get('test_store');

        $this->createProduct([
            ProductInterface::TYPE_ID => 'simple',
            ProductInterface::VISIBILITY => 1,
        ]);
        $productFixture = $this->productFixturePool->get('test_product');
        /** @var Product $product */
        $product = $productFixture->getProduct();
        $product->setData('parent_id', 999999999999);

        ConfigFixture::setGlobal(
            path: ProductVisibilityIsIndexableDeterminer::XML_PATH_SYNC_VISIBILITIES,
            value: '4',
        );

        $mockLogger = $this->getMockLogger_ExpectedNotToWrite([
            'emergency',
            'critical',
            'alert',
            'warning',
            'notice',
            'info',
            'debug',
        ]);
        $mockLogger->expects($this->once())
            ->method('error')
            ->with(
                'Received configurable variant product with invalid parent id in {method}',
            );
        $determiner = $this->instantiateTestObject([
            'logger' => $mockLogger,
        ]);
        $this->assertTrue(
            condition: $determiner->execute(
                entity: $product,
                store: $storeFixture->get(),
                entitySubtype: ProductEntityProvider::ENTITY_SUBTYPE_CONFIGURABLE_VARIANTS,
            ),
        );
    }

    /**
     * @testWith [4, 3, "simple", "simple", "3"]
     *           [1, 2, "virtual", "simple", "2"]
     *           [1, 4, "downloadable", "simple", "2,4"]
     *           [4, 1, "bundle", "bundle", "1,3"]
     *           [4, 3, "grouped", "grouped", "1,3"]
     *           [4, 1, "configurable", "configurable", "1,3"]
     */
    public function testExecute_ReturnsTrue_ForStandardEntitySubtypes_StoreScopeConfiguration(
        int $productVisibility,
        int $productVisibilityInStore,
        string $productType,
        string $entitySubtype,
        string $configVisibilities,
    ): void {
        $this->createStore();
        $storeFixture = $this->storeFixturesPool->get('test_store');
        $store = $storeFixture->get();

        $this->createProduct([
            ProductInterface::TYPE_ID => $productType,
            ProductInterface::VISIBILITY => $productVisibility,
            'stores' => [
                $storeFixture->getId() => [
                    ProductInterface::VISIBILITY => $productVisibilityInStore,
                ],
            ],
        ]);
        $productFixture = $this->productFixturePool->get('test_product');
        /** @var Product $product */
        $product = $productFixture->getProduct();
        $product->setStoreId((int)$store->getId());
        $product = $product->load((int)$product->getId()); // @phpstan-ignore-line

        ConfigFixture::setGlobal(
            path: ProductVisibilityIsIndexableDeterminer::XML_PATH_SYNC_VISIBILITIES,
            value: $configVisibilities,
        );

        $determiner = $this->instantiateTestObject([
            'logger' => $this->getMockLogger_ExpectedNotToWrite(),
        ]);
        $this->assertTrue(
            condition: $determiner->execute(
                entity: $product,
                store: $store,
                entitySubtype: $entitySubtype,
            ),
        );
    }

    /**
     * @testWith [3, 4, "simple", "simple", "3"]
     *           [2, 1, "virtual", "simple", "2"]
     *           [4, 1, "downloadable", "simple", "2,4"]
     *           [1, 4, "bundle", "bundle", "1,3"]
     *           [3, 4, "grouped", "grouped", "1,3"]
     *           [1, 4, "configurable", "configurable", "1,3"]
     */
    public function testExecute_ReturnsFalse_ForStandardEntitySubtypes_StoreScopeConfiguration(
        int $productVisibility,
        int $productVisibilityInStore,
        string $productType,
        string $entitySubtype,
        string $configVisibilities,
    ): void {
        $this->createStore();
        $storeFixture = $this->storeFixturesPool->get('test_store');
        $store = $storeFixture->get();

        $this->createProduct([
            ProductInterface::TYPE_ID => $productType,
            ProductInterface::VISIBILITY => $productVisibility,
            'stores' => [
                $storeFixture->getId() => [
                    ProductInterface::VISIBILITY => $productVisibilityInStore,
                ],
            ],
        ]);
        $productFixture = $this->productFixturePool->get('test_product');
        /** @var Product $product */
        $product = $productFixture->getProduct();
        $product->setStoreId((int)$store->getId());
        $product = $product->load((int)$product->getId()); // @phpstan-ignore-line

        ConfigFixture::setGlobal(
            path: ProductVisibilityIsIndexableDeterminer::XML_PATH_SYNC_VISIBILITIES,
            value: $configVisibilities,
        );

        $determiner = $this->instantiateTestObject([
            'logger' => $this->getMockLogger_ExpectedNotToWrite(),
        ]);
        $this->assertFalse(
            condition: $determiner->execute(
                entity: $product,
                store: $store,
                entitySubtype: $entitySubtype,
            ),
        );
    }

    /**
     * @testWith [1, 1, 3, 4, "simple", "configurable_variants", "4"]
     */
    public function testExecute_ReturnsTrue_ForVariantSubtypes_StoreScopeConfiguration(
        int $productVisibility,
        int $productVisibilityInStore,
        int $parentVisibility,
        int $parentVisibilityInStore,
        string $productType,
        string $entitySubtype,
        string $configVisibilities,
    ): void {
        $this->createStore();
        $storeFixture = $this->storeFixturesPool->get('test_store');
        $store = $storeFixture->get();

        $this->createProduct([
            ProductInterface::TYPE_ID => $productType,
            ProductInterface::VISIBILITY => $productVisibility,
            'stores' => [
                $store->getId() => [
                    ProductInterface::VISIBILITY => $productVisibilityInStore,
                ],
            ]
        ]);
        $productFixture = $this->productFixturePool->get('test_product');
        /** @var Product $product */
        $product = $productFixture->getProduct();

        $this->createProduct([
            'key' => 'test_parent_product',
            ProductInterface::TYPE_ID => 'configurable',
            ProductInterface::VISIBILITY => $parentVisibility,
            'stores' => [
                $store->getId() => [
                    ProductInterface::VISIBILITY => $parentVisibilityInStore,
                ],
            ],
            'variants' => [
                $product,
            ],
        ]);
        $parentFixture = $this->productFixturePool->get('test_parent_product');
        $parentProduct = $parentFixture->getProduct();

        $product = $productFixture->getProduct();
        $product->setStoreId((int)$store->getId()); // @phpstan-ignore-line
        $product = $product->load((int)$product->getId()); // @phpstan-ignore-line
        $product->setData('parent_id', $parentProduct->getId());

        ConfigFixture::setGlobal(
            path: ProductVisibilityIsIndexableDeterminer::XML_PATH_SYNC_VISIBILITIES,
            value: $configVisibilities,
        );

        $determiner = $this->instantiateTestObject([
            'logger' => $this->getMockLogger_ExpectedNotToWrite(),
        ]);
        $this->assertTrue(
            condition: $determiner->execute(
                entity: $product,
                store: $store,
                entitySubtype: $entitySubtype,
            ),
        );
    }

    /**
     * @testWith [4, 1, 1, 1, "simple", "configurable_variants", "4"]
     *           [1, 4, 1, 1, "simple", "configurable_variants", "4"]
     *           [1, 1, 4, 1, "simple", "configurable_variants", "4"]
     */
    public function testExecute_ReturnsFalse_ForVariantSubtypes_StoreScopeConfiguration(
        int $productVisibility,
        int $productVisibilityInStore,
        int $parentVisibility,
        int $parentVisibilityInStore,
        string $productType,
        string $entitySubtype,
        string $configVisibilities,
    ): void {
        $this->createStore();
        $storeFixture = $this->storeFixturesPool->get('test_store');
        $store = $storeFixture->get();

        $this->createProduct([
            ProductInterface::TYPE_ID => $productType,
            ProductInterface::VISIBILITY => $productVisibility,
            'stores' => [
                $store->getId() => [
                    ProductInterface::VISIBILITY => $productVisibilityInStore,
                ],
            ]
        ]);
        $productFixture = $this->productFixturePool->get('test_product');
        /** @var Product $product */
        $product = $productFixture->getProduct();

        $this->createProduct([
            'key' => 'test_parent_product',
            ProductInterface::TYPE_ID => 'configurable',
            ProductInterface::VISIBILITY => $parentVisibility,
            'stores' => [
                $store->getId() => [
                    ProductInterface::VISIBILITY => $parentVisibilityInStore,
                ],
            ],
            'variants' => [
                $product,
            ],
        ]);
        $parentFixture = $this->productFixturePool->get('test_parent_product');
        $parentProduct = $parentFixture->getProduct();

        $product = $productFixture->getProduct();
        $product->setStoreId((int)$store->getId()); // @phpstan-ignore-line
        $product = $product->load((int)$product->getId()); // @phpstan-ignore-line
        $product->setData('parent_id', $parentProduct->getId());

        ConfigFixture::setGlobal(
            path: ProductVisibilityIsIndexableDeterminer::XML_PATH_SYNC_VISIBILITIES,
            value: $configVisibilities,
        );

        $determiner = $this->instantiateTestObject([
            'logger' => $this->getMockLogger_ExpectedNotToWrite(),
        ]);
        $this->assertFalse(
            condition: $determiner->execute(
                entity: $product,
                store: $store,
                entitySubtype: $entitySubtype,
            ),
        );
    }

    /**
     * @return MockObject|LoggerInterface
     */
    private function getMockLogger(): MockObject|LoggerInterface
    {
        return $this->getMockBuilder(LoggerInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    /**
     * @param string[]|null $notExpectedMethods
     *
     * @return MockObject
     */
    private function getMockLogger_ExpectedNotToWrite(?array $notExpectedMethods = null): MockObject
    {
        $mockLogger = $this->getMockLogger();

        $notExpectedMethods ??= [
            'emergency',
            'critical',
            'alert',
            'error',
            'warning',
            'notice',
            'info',
            'debug',
        ];

        foreach ($notExpectedMethods as $notExpectedMethod) {
            $mockLogger->expects($this->never())->method($notExpectedMethod);
        }

        return $mockLogger;
    }
}
