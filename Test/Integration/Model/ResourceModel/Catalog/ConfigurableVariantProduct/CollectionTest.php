<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

// phpcs:disable Generic.Files.LineLength.TooLong

namespace Klevu\IndexingProductsExcludeByVisibility\Test\Integration\Model\ResourceModel\Catalog\ConfigurableVariantProduct;

use Klevu\IndexingProducts\Model\ResourceModel\Catalog\ConfigurableVariantProduct\Collection as ConfigurableVariantProductCollection;
use Klevu\TestFixtures\Catalog\Attribute\AttributeFixturePool;
use Klevu\TestFixtures\Catalog\AttributeTrait;
use Klevu\TestFixtures\Catalog\ProductTrait;
use Klevu\TestFixtures\Store\StoreFixturesPool;
use Klevu\TestFixtures\Store\StoreTrait;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\App\ResourceConnection\SourceProviderInterface;
use Magento\Framework\DataObject;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use TddWizard\Fixtures\Catalog\ProductFixturePool;

// phpcs:enable Generic.Files.LineLength.TooLong
/**
 * @covers Collection::class
 * @method SourceProviderInterface instantiateTestObject(?array $arguments = null)
 * @method SourceProviderInterface instantiateTestObjectFromInterface(?array $arguments = null)
 */
class CollectionTest extends TestCase
{
    use AttributeTrait;
    use ProductTrait;
    use StoreTrait;

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

        $this->objectManager = Bootstrap::getObjectManager();
        $this->storeFixturesPool = $this->objectManager->get(StoreFixturesPool::class);
        $this->attributeFixturePool = $this->objectManager->get(AttributeFixturePool::class);
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
        $this->attributeFixturePool->rollback();
        $this->storeFixturesPool->rollback();
    }

    /**
     * @magentoDbIsolation disabled
     */
    public function testGetConfigurableCollection_ReturnsVariantCollectionContainingParentVisibility(): void
    {
        $this->createStore();
        $storeFixture = $this->storeFixturesPool->get('test_store');

        $this->createAttribute([
            'key' => 'klevu_test_attribute',
            'attribute_type' => 'configurable',
            'options' => [
                '1' => 'Option 1',
                '2' => 'Option 2',
                '3' => 'Option 3',
            ],
        ]);
        $configurableAttribute = $this->attributeFixturePool->get('klevu_test_attribute');

        $this->createProduct([
            'key' => 'test_simple_product_1',
            'sku' => 'test_simple_product_1',
            'status' => Status::STATUS_ENABLED,
            'visibility' => Visibility::VISIBILITY_NOT_VISIBLE,
            'website_ids' => [
                $storeFixture->getWebsiteId(),
            ],
            'data' => [
                $configurableAttribute->getAttributeCode() => '1',
            ],
            'stores' => [
                $storeFixture->getId() => [
                    'name' => 'Simple Product 1 Store 1',
                    'visibility' => Visibility::VISIBILITY_BOTH,
                ],
            ],
        ]);
        $productSimple1 = $this->productFixturePool->get('test_simple_product_1');
        $this->createProduct([
            'key' => 'test_simple_product_2',
            'sku' => 'test_simple_product_2',
            'status' => Status::STATUS_ENABLED,
            'visibility' => Visibility::VISIBILITY_IN_CATALOG,
            'website_ids' => [
                $storeFixture->getWebsiteId(),
            ],
            'data' => [
                $configurableAttribute->getAttributeCode() => '2',
            ],
            'stores' => [
                $storeFixture->getId() => [
                    'name' => 'Simple Product 2 Store 1',
                    'visibility' => Visibility::VISIBILITY_IN_SEARCH,
                ],
            ],
        ]);
        $productSimple2 = $this->productFixturePool->get('test_simple_product_2');
        $this->createProduct([
            'key' => 'test_simple_product_3',
            'sku' => 'test_simple_product_3',
            'status' => Status::STATUS_ENABLED,
            'visibility' => Visibility::VISIBILITY_BOTH,
            'website_ids' => [
                $storeFixture->getWebsiteId(),
            ],
            'data' => [
                $configurableAttribute->getAttributeCode() => '3',
            ],
            'stores' => [
                $storeFixture->getId() => [
                    'name' => 'Simple Product 3 Store 1',
                    'visibility' => Visibility::VISIBILITY_NOT_VISIBLE,
                ],
            ],
        ]);
        $productSimple3 = $this->productFixturePool->get('test_simple_product_3');

        $this->createProduct([
            'key' => 'test_configurable_product',
            'sku' => 'test_configurable_product',
            'name' => 'Configurable Product 1',
            'status' => Status::STATUS_ENABLED,
            'visibility' => Visibility::VISIBILITY_IN_SEARCH,
            'website_ids' => [
                $storeFixture->getWebsiteId(),
            ],
            'type_id' => Configurable::TYPE_CODE,
            'configurable_attributes' => [
                $configurableAttribute->getAttribute(),
            ],
            'variants' => [
                $productSimple1->getProduct(),
                $productSimple2->getProduct(),
                $productSimple3->getProduct(),
            ],
            'stores' => [
                $storeFixture->getId() => [
                    'name' => 'Configurable Product 1 Store 1',
                    'visibility' => Visibility::VISIBILITY_IN_CATALOG,
                ],
            ],
        ]);
        $productConfigurable = $this->productFixturePool->get('test_configurable_product');

        $collection = $this->objectManager->get(ConfigurableVariantProductCollection::class);
        $result = $collection->getConfigurableCollection(store: $storeFixture->get());

        $items = $result->getItems();
        $product1Items = array_filter(
            array: $items,
            callback: static fn (DataObject&ProductInterface $product): bool => (
                    (int)$product->getId() === $productSimple1->getId()
                    && (int)$product->getData('parent_id') === $productConfigurable->getId()
            ),
        );
        /** @var ProductInterface $product1Item */
        $product1Item = array_shift($product1Items);
        $this->assertSame(
            expected: Visibility::VISIBILITY_IN_CATALOG,
            actual: (int)$product1Item->getVisibility(),
        );

        $product2Items = array_filter(
            array: $items,
            callback: static fn (DataObject&ProductInterface $product): bool => (
                (int)$product->getId() === $productSimple2->getId()
                && (int)$product->getData('parent_id') === $productConfigurable->getId()
            ),
        );
        /** @var ProductInterface $product2Item */
        $product2Item = array_shift($product2Items);
        $this->assertSame(
            expected: Visibility::VISIBILITY_IN_CATALOG,
            actual: (int)$product2Item->getVisibility(),
        );

        $product3Items = array_filter(
            array: $items,
            callback: static fn (DataObject&ProductInterface $product): bool => (
                    (int)$product->getId() === $productSimple3->getId()
                    && (int)$product->getData('parent_id') === $productConfigurable->getId()
            ),
        );
        /** @var ProductInterface $product3Item */
        $product3Item = array_shift($product3Items);
        $this->assertSame(
            expected: Visibility::VISIBILITY_IN_CATALOG,
            actual: (int)$product3Item->getVisibility(),
        );
    }
}
