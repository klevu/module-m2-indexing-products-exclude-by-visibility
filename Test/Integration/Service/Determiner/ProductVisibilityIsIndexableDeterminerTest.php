<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\IndexingProductsExcludeByVisibility\Test\Integration\Service\Determiner;

use Klevu\IndexingApi\Service\Determiner\IsIndexableConditionInterface;
use Klevu\IndexingProductsExcludeByVisibility\Service\Determiner\ProductVisibilityIsIndexableCondition;
use Klevu\TestFixtures\Catalog\ProductTrait;
use Klevu\TestFixtures\Store\StoreFixturesPool;
use Klevu\TestFixtures\Store\StoreTrait;
use Klevu\TestFixtures\Traits\ObjectInstantiationTrait;
use Klevu\TestFixtures\Traits\TestImplementsInterfaceTrait;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TddWizard\Fixtures\Catalog\ProductFixturePool;
use TddWizard\Fixtures\Core\ConfigFixture;

/**
 * @covers \Klevu\IndexingProductsExcludeByVisibility\Service\Determiner\ProductVisibilityIsIndexableCondition::class
 * @method IsIndexableConditionInterface instantiateTestObject(?array $arguments = null)
 * @method IsIndexableConditionInterface instantiateTestObjectFromInterface(?array $arguments = null)
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

        $this->implementationFqcn = ProductVisibilityIsIndexableCondition::class;
        $this->interfaceFqcn = IsIndexableConditionInterface::class;
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
     * @magentoDbIsolation disabled
     * @testWith [4, "1,2,3,4"]
     *           [4, "2,3,4"]
     *           [4, "1,3,4"]
     *           [4, "1,2,4"]
     *           [4, "3,4"]
     *           [4, "2,4"]
     *           [4, "1,4"]
     *           [4, "4"]
     *           [3, "1,2,3,4"]
     *           [3, "2,3,4"]
     *           [3, "1,3,4"]
     *           [3, "1,2,3"]
     *           [3, "3,4"]
     *           [3, "2,3"]
     *           [3, "1,3"]
     *           [3, "3"]
     *           [2, "1,2,3,4"]
     *           [2, "2,3,4"]
     *           [2, "1,2,4"]
     *           [2, "1,2,3"]
     *           [2, "2,4"]
     *           [2, "2,3"]
     *           [2, "1,2"]
     *           [2, "2"]
     *           [1, "1,2,3,4"]
     *           [1, "1,3,4"]
     *           [1, "1,2,4"]
     *           [1, "1,2,3"]
     *           [1, "1,4"]
     *           [1, "1,3"]
     *           [1, "1,2"]
     *           [1, "1"]
     */
    public function testExecute_ReturnsTrue_ForConfiguredVisibility(
        int $productVisibility,
        string $configVisibilities,
    ): void {
        $this->createStore();
        $storeFixture = $this->storeFixturesPool->get('test_store');
        $this->createProduct([
            ProductInterface::VISIBILITY => $productVisibility,
        ]);
        $productFixture = $this->productFixturePool->get('test_product');
        $product = $productFixture->getProduct();

        ConfigFixture::setGlobal(
            path: ProductVisibilityIsIndexableCondition::XML_PATH_SYNC_VISIBILITIES,
            value: $configVisibilities,
        );

        $determiner = $this->instantiateTestObject([
            'logger' => $this->getMockLogger_ExpectedNotToWrite(),
        ]);
        $this->assertTrue(
            condition: $determiner->execute(
                entity: $product,
                store: $storeFixture->get(),
            ),
        );
    }

    /**
     * @magentoDbIsolation disabled
     * @testWith [4, "1,2,3"]
     *           [4, "2,3"]
     *           [4, "1,3"]
     *           [4, "1,2"]
     *           [4, "3"]
     *           [4, "2"]
     *           [4, "1"]
     *           [3, "1,2,4"]
     *           [3, "2,4"]
     *           [3, "1,4"]
     *           [3, "1,2"]
     *           [3, "4"]
     *           [3, "2"]
     *           [3, "1"]
     *           [2, "1,3,4"]
     *           [2, "3,4"]
     *           [2, "1,4"]
     *           [2, "1,3"]
     *           [2, "4"]
     *           [2, "3"]
     *           [2, "1"]
     *           [1, "2,3,4"]
     *           [1, "3,4"]
     *           [1, "2,4"]
     *           [1, "2,3"]
     *           [1, "4"]
     *           [1, "3"]
     *           [1, "2"]
     */
    public function testExecute_ReturnsFalse_ForNotConfiguredVisibility(
        int $productVisibility,
        string $configVisibilities,
    ): void {
        $this->createStore();
        $storeFixture = $this->storeFixturesPool->get('test_store');

        $this->createProduct([
            ProductInterface::VISIBILITY => $productVisibility,
        ]);
        $productFixture = $this->productFixturePool->get('test_product');

        ConfigFixture::setGlobal(
            path: ProductVisibilityIsIndexableCondition::XML_PATH_SYNC_VISIBILITIES,
            value: $configVisibilities,
        );

        $mockLogger = $this->getMockLogger_ExpectedNotToWrite([
            'emergency',
            'critical',
            'alert',
            'error',
            'warning',
            'notice',
            'info',
        ]);
        $mockLogger->expects($this->once())
            ->method('debug')
            ->with(
                // phpcs:ignore Generic.Files.LineLength.TooLong
            'Store ID: {storeId} Product ID: {productId} not indexable due to Visibility: {visibility} in {method}',
                [
                    'storeId' => (string)$storeFixture->getId(),
                    'productId' => (string)$productFixture->getId(),
                    'visibility' => $productVisibility,
                    // phpcs:ignore Generic.Files.LineLength.TooLong
                    'method' => 'Klevu\IndexingProductsExcludeByVisibility\Service\Determiner\ProductVisibilityIsIndexableCondition::isIndexable',
                ],
            );

        $determiner = $this->instantiateTestObject([
            'logger' => $mockLogger,
        ]);
        $this->assertFalse(
            condition: $determiner->execute(
                entity: $productFixture->getProduct(),
                store: $storeFixture->get(),
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
