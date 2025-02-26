<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\IndexingProductsExcludeByVisibility\Test\IntegrationService;

use Klevu\Indexing\Model\IndexingEntity;
use Klevu\Indexing\Test\Integration\Traits\IndexingEntitiesTrait;
use Klevu\IndexingApi\Model\Source\Actions;
use Klevu\IndexingApi\Service\EntityDiscoveryOrchestratorServiceInterface;
use Klevu\IndexingProductsExcludeByVisibility\Service\Determiner\ProductVisibilityIsIndexableCondition;
use Klevu\IndexingProductsExcludeByVisibility\Service\EntityDiscoveryOrchestratorService;
use Klevu\TestFixtures\Catalog\Attribute\AttributeFixturePool;
use Klevu\TestFixtures\Catalog\AttributeTrait;
use Klevu\TestFixtures\Catalog\ProductTrait;
use Klevu\TestFixtures\Store\StoreFixturesPool;
use Klevu\TestFixtures\Store\StoreTrait;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Downloadable\Model\Product\Type as DownloadableType;
use Magento\Framework\ObjectManagerInterface;
use Magento\GroupedProduct\Model\Product\Type\Grouped;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use TddWizard\Fixtures\Catalog\ProductFixturePool;
use TddWizard\Fixtures\Core\ConfigFixture;

/**
 * @covers EntityDiscoveryOrchestratorService::class
 * @method EntityDiscoveryOrchestratorServiceInterface instantiateTestObject(?array $arguments = null)
 * @method EntityDiscoveryOrchestratorServiceInterface instantiateTestObjectFromInterface(?array $arguments = null)
 */
class EntityDiscoveryOrchestratorServiceTest extends TestCase
{
    use AttributeTrait;
    use IndexingEntitiesTrait;
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
     * @magentoConfigFixture klevu_test_store_1_store klevu_configuration/auth_keys/js_api_key klevu-js-api-key
     * @magentoConfigFixture klevu_test_store_1_store klevu_configuration/auth_keys/rest_auth_key klevu-rest-auth-key
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
    public function testExecute_AddsNewProducts_AsIndexable_WhenVisibilityAllowed(
        int $productVisibility,
        string $configVisibilities,
    ): void
    {
        $apiKey = 'klevu-js-api-key';

        $this->createStore();
        $storeFixture = $this->storeFixturesPool->get('test_store');
        $store = $storeFixture->get();

        ConfigFixture::setGlobal(
            path: ProductVisibilityIsIndexableCondition::XML_PATH_SYNC_VISIBILITIES,
            value: $configVisibilities,
        );

        $this->createProduct(
            productData: [
                'type_id' => $this->getRandomProductType(),
                'visibility' => $productVisibility,
            ],
            storeId: (int)$store->getId(),
        );
        $productFixture = $this->productFixturePool->get('test_product');

        $this->cleanIndexingEntities($apiKey);

        $service = $this->instantiateDiscoveryOrchestrator();
        $resultGenerators = $service->execute(entityTypes: ['KLEVU_PRODUCT'], apiKeys: [$apiKey]);
        $resultsArray = [];
        foreach ($resultGenerators as $resultGenerator) {
            $resultsArray[] = iterator_to_array($resultGenerator);
        }
        $results = array_filter(
            array_merge(...$resultsArray),
        );
        $result = array_shift($results);

        $this->assertTrue($result->isSuccess());

        $indexingEntity = $this->getIndexingEntityForEntity(
            apiKey: $apiKey,
            entity: $productFixture->getProduct(),
            type: 'KLEVU_PRODUCT',
        );
        $this->assertTrue(
            condition: $indexingEntity->getIsIndexable(),
        );
        $this->assertSame(
            expected: Actions::ADD,
            actual: $indexingEntity->getNextAction(),
        );

        $this->cleanIndexingEntities($apiKey);
    }

    /**
     * @magentoDbIsolation disabled
     * @magentoConfigFixture klevu_test_store_1_store klevu_configuration/auth_keys/js_api_key klevu-js-api-key
     * @magentoConfigFixture klevu_test_store_1_store klevu_configuration/auth_keys/rest_auth_key klevu-rest-auth-key
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
    public function testExecute_AddsNewProducts_AsNotIndexable_WhenVisibilityDisallowed(
        int $productVisibility,
        string $configVisibilities,
    ): void
    {
        $apiKey = 'klevu-js-api-key';

        $this->createStore();
        $storeFixture = $this->storeFixturesPool->get('test_store');
        $store = $storeFixture->get();

        ConfigFixture::setGlobal(
            path: ProductVisibilityIsIndexableCondition::XML_PATH_SYNC_VISIBILITIES,
            value: $configVisibilities,
        );

        $this->createProduct(
            productData: [
                'type_id' => $this->getRandomProductType(),
                'visibility' => $productVisibility,
            ],
            storeId: (int)$store->getId(),
        );
        $productFixture = $this->productFixturePool->get('test_product');

        $this->cleanIndexingEntities($apiKey);

        $service = $this->instantiateDiscoveryOrchestrator();
        $resultGenerators = $service->execute(entityTypes: ['KLEVU_PRODUCT'], apiKeys: [$apiKey]);
        $resultsArray = [];
        foreach ($resultGenerators as $resultGenerator) {
            $resultsArray[] = iterator_to_array($resultGenerator);
        }
        $results = array_filter(
            array_merge(...$resultsArray),
        );
        $result = array_shift($results);

        $this->assertTrue($result->isSuccess());

        $indexingEntity = $this->getIndexingEntityForEntity(
            apiKey: $apiKey,
            entity: $productFixture->getProduct(),
            type: 'KLEVU_PRODUCT',
        );
        $this->assertFalse(
            condition: $indexingEntity->getIsIndexable(),
        );
        $this->assertSame(
            expected: Actions::NO_ACTION,
            actual: $indexingEntity->getNextAction(),
        );

        $this->cleanIndexingEntities($apiKey);
    }

    /**
     * @magentoDbIsolation disabled
     * @magentoConfigFixture klevu_test_store_1_store klevu_configuration/auth_keys/js_api_key klevu-js-api-key
     * @magentoConfigFixture klevu_test_store_1_store klevu_configuration/auth_keys/rest_auth_key klevu-rest-auth-key
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
    public function testExecute_SetsPreviouslyIndexedProductsAsNotIndexable_WhenVisibilityDisallowed(
        int $productVisibility,
        string $configVisibilities,
    ): void
    {
        $apiKey = 'klevu-js-api-key';

        $this->createStore();
        $storeFixture = $this->storeFixturesPool->get('test_store');
        $store = $storeFixture->get();

        ConfigFixture::setGlobal(
            path: ProductVisibilityIsIndexableCondition::XML_PATH_SYNC_VISIBILITIES,
            value: $configVisibilities,
        );

        $this->createProduct(
            productData: [
                'type_id' => $this->getRandomProductType(),
                'visibility' => $productVisibility,
            ],
            storeId: (int)$store->getId(),
        );
        $productFixture = $this->productFixturePool->get('test_product');

        $this->cleanIndexingEntities($apiKey);

        $this->createIndexingEntity([
            IndexingEntity::API_KEY => $apiKey,
            IndexingEntity::TARGET_ID => $productFixture->getId(),
            IndexingEntity::TARGET_ENTITY_TYPE => 'KLEVU_PRODUCT',
            IndexingEntity::TARGET_ENTITY_SUBTYPE => 'simple',
            IndexingEntity::LAST_ACTION => Actions::UPDATE,
            IndexingEntity::LAST_ACTION_TIMESTAMP => date('Y-m-d H:i:s'),
            IndexingEntity::IS_INDEXABLE => true,
        ]);

        $service = $this->instantiateDiscoveryOrchestrator();
        $resultGenerators = $service->execute(entityTypes: ['KLEVU_PRODUCT'], apiKeys: [$apiKey]);
        $resultsArray = [];
        foreach ($resultGenerators as $resultGenerator) {
            $resultsArray[] = iterator_to_array($resultGenerator);
        }
        $results = array_filter(
            array_merge(...$resultsArray),
        );
        $result = array_shift($results);

        $this->assertTrue($result->isSuccess());

        $indexingEntity = $this->getIndexingEntityForEntity(
            apiKey: $apiKey,
            entity: $productFixture->getProduct(),
            type: 'KLEVU_PRODUCT',
        );
        $this->assertTrue(
            condition: $indexingEntity->getIsIndexable(),
        );
        $this->assertSame(
            expected: Actions::DELETE,
            actual: $indexingEntity->getNextAction(),
        );

        $this->cleanIndexingEntities($apiKey);
    }

    /**
     * @magentoDbIsolation disabled
     * @magentoConfigFixture klevu_test_store_1_store klevu_configuration/auth_keys/js_api_key klevu-js-api-key
     * @magentoConfigFixture klevu_test_store_1_store klevu_configuration/auth_keys/rest_auth_key klevu-rest-auth-key
     */
    public function testExecute_AddsNewProducts_AsIndexable_WhenVisibilityAllowed_forConfigurableVariants(): void
    {
        $apiKey = 'klevu-js-api-key';

        $this->createStore();

        ConfigFixture::setGlobal(
            path: ProductVisibilityIsIndexableCondition::XML_PATH_SYNC_VISIBILITIES,
            value: Visibility::VISIBILITY_IN_SEARCH . ',' . Visibility::VISIBILITY_BOTH,
        );

        $this->createAttribute([
            'attribute_type' => 'configurable',
        ]);
        $attributeFixture = $this->attributeFixturePool->get('test_attribute');

        $this->createProduct([
            'key' => 'test_product_variant_1',
            'name' => 'Klevu Simple Product Test 1',
            'sku' => 'KLEVU-SIMPLE-SKU-001',
            'visibility' => Visibility::VISIBILITY_NOT_VISIBLE,
            'price' => 49.99,
            'data' => [
                $attributeFixture->getAttributeCode() => '1',
            ],
        ]);
        $variantProductFixture1 = $this->productFixturePool->get('test_product_variant_1');
        $this->createProduct([
            'key' => 'test_product_variant_2',
            'name' => 'Klevu Simple Product Test 2',
            'sku' => 'KLEVU-SIMPLE-SKU-002',
            'visibility' => Visibility::VISIBILITY_IN_CATALOG,
            'price' => 39.99,
            'data' => [
                $attributeFixture->getAttributeCode() => '2',
            ],
        ]);
        $variantProductFixture2 = $this->productFixturePool->get('test_product_variant_2');
        $this->createProduct([
            'key' => 'test_product_variant_3',
            'name' => 'Klevu Simple Product Test 3',
            'sku' => 'KLEVU-SIMPLE-SKU-003',
            'visibility' => Visibility::VISIBILITY_IN_SEARCH,
            'price' => 39.99,
            'data' => [
                $attributeFixture->getAttributeCode() => '3',
            ],
        ]);
        $variantProductFixture3 = $this->productFixturePool->get('test_product_variant_3');

        $this->createProduct([
            'type_id' => Configurable::TYPE_CODE,
            'name' => 'Klevu Configurable Product Test',
            'sku' => 'KLEVU-CONFIGURABLE-SKU-001',
            'price' => 99.99,
            'in_stock' => true,
            'visibility' => Visibility::VISIBILITY_BOTH,
            'configurable_attributes' => [
                $attributeFixture->getAttribute(),
            ],
            'variants' => [
                $variantProductFixture1->getProduct(),
                $variantProductFixture2->getProduct(),
                $variantProductFixture3->getProduct(),
            ],
        ]);
        $productFixture = $this->productFixturePool->get('test_product');

        $this->cleanIndexingEntities($apiKey);

        $service = $this->instantiateDiscoveryOrchestrator();
        $resultGenerators = $service->execute(entityTypes: ['KLEVU_PRODUCT'], apiKeys: [$apiKey]);
        $resultsArray = [];
        foreach ($resultGenerators as $resultGenerator) {
            $resultsArray[] = iterator_to_array($resultGenerator);
        }
        $results = array_filter(
            array_merge(...$resultsArray),
        );
        $result = array_shift($results);

        $this->assertTrue($result->isSuccess());

        $indexingEntity = $this->getIndexingEntityForEntity(
            apiKey: $apiKey,
            entity: $productFixture->getProduct(),
            type: 'KLEVU_PRODUCT',
        );
        $this->assertTrue(
            condition: $indexingEntity->getIsIndexable(),
        );
        $this->assertSame(
            expected: Actions::ADD,
            actual: $indexingEntity->getNextAction(),
        );

        $indexingEntitySimpleProduct1 = $this->getIndexingEntityForEntity(
            apiKey: $apiKey,
            entity: $variantProductFixture1->getProduct(),
            type: 'KLEVU_PRODUCT',
        );
        $this->assertFalse(
            condition: $indexingEntitySimpleProduct1->getIsIndexable(),
        );
        $this->assertSame(
            expected: Actions::NO_ACTION,
            actual: $indexingEntitySimpleProduct1->getNextAction(),
        );

        $indexingEntitySimpleProduct2 = $this->getIndexingEntityForEntity(
            apiKey: $apiKey,
            entity: $variantProductFixture2->getProduct(),
            type: 'KLEVU_PRODUCT',
        );
        $this->assertFalse(
            condition: $indexingEntitySimpleProduct2->getIsIndexable(),
        );
        $this->assertSame(
            expected: Actions::NO_ACTION,
            actual: $indexingEntitySimpleProduct2->getNextAction(),
        );

        $indexingEntitySimpleProduct3 = $this->getIndexingEntityForEntity(
            apiKey: $apiKey,
            entity: $variantProductFixture3->getProduct(),
            type: 'KLEVU_PRODUCT',
        );
        $this->assertTrue(
            condition: $indexingEntitySimpleProduct3->getIsIndexable(),
        );
        $this->assertSame(
            expected: Actions::ADD,
            actual: $indexingEntitySimpleProduct3->getNextAction(),
        );

        $indexingEntityVariant1 = $this->getIndexingEntityForVariant(
            apiKey: $apiKey,
            entity: $variantProductFixture1->getProduct(),
            parentEntity: $productFixture->getProduct(),
            type: 'KLEVU_PRODUCT',
        );
        $this->assertTrue(
            condition: $indexingEntityVariant1->getIsIndexable(),
        );
        $this->assertSame(
            expected: Actions::ADD,
            actual: $indexingEntityVariant1->getNextAction(),
        );

        $indexingEntityVariant2 = $this->getIndexingEntityForVariant(
            apiKey: $apiKey,
            entity: $variantProductFixture2->getProduct(),
            parentEntity: $productFixture->getProduct(),
            type: 'KLEVU_PRODUCT',
        );
        $this->assertTrue(
            condition: $indexingEntityVariant2->getIsIndexable(),
        );
        $this->assertSame(
            expected: Actions::ADD,
            actual: $indexingEntityVariant2->getNextAction(),
        );

        $indexingEntityVariant3 = $this->getIndexingEntityForVariant(
            apiKey: $apiKey,
            entity: $variantProductFixture3->getProduct(),
            parentEntity: $productFixture->getProduct(),
            type: 'KLEVU_PRODUCT',
        );
        $this->assertTrue(
            condition: $indexingEntityVariant3->getIsIndexable(),
        );
        $this->assertSame(
            expected: Actions::ADD,
            actual: $indexingEntityVariant3->getNextAction(),
        );

        $this->cleanIndexingEntities($apiKey);
    }

    /**
     * @magentoDbIsolation disabled
     * @magentoConfigFixture klevu_test_store_1_store klevu_configuration/auth_keys/js_api_key klevu-js-api-key
     * @magentoConfigFixture klevu_test_store_1_store klevu_configuration/auth_keys/rest_auth_key klevu-rest-auth-key
     */
    public function testExecute_updatesNextAction_WhenAllowedVisibilityChanged(): void
    {
        $apiKey = 'klevu-js-api-key';

        $this->createStore();

        ConfigFixture::setGlobal(
            path: ProductVisibilityIsIndexableCondition::XML_PATH_SYNC_VISIBILITIES,
            value: Visibility::VISIBILITY_IN_CATALOG . ',' . Visibility::VISIBILITY_IN_SEARCH
                . ',' . Visibility::VISIBILITY_BOTH,
        );

        $this->createAttribute([
            'attribute_type' => 'configurable',
        ]);
        $attributeFixture = $this->attributeFixturePool->get('test_attribute');

        $this->createProduct([
            'key' => 'test_product_variant_1',
            'name' => 'Klevu Simple Product Test 1',
            'sku' => 'KLEVU-SIMPLE-SKU-001',
            'visibility' => Visibility::VISIBILITY_NOT_VISIBLE,
            'price' => 49.99,
            'data' => [
                $attributeFixture->getAttributeCode() => '1',
            ],
        ]);
        $variantProductFixture1 = $this->productFixturePool->get('test_product_variant_1');
        $this->createProduct([
            'key' => 'test_product_variant_2',
            'name' => 'Klevu Simple Product Test 2',
            'sku' => 'KLEVU-SIMPLE-SKU-002',
            'visibility' => Visibility::VISIBILITY_IN_CATALOG,
            'price' => 39.99,
            'data' => [
                $attributeFixture->getAttributeCode() => '2',
            ],
        ]);
        $variantProductFixture2 = $this->productFixturePool->get('test_product_variant_2');
        $this->createProduct([
            'key' => 'test_product_variant_3',
            'name' => 'Klevu Simple Product Test 3',
            'sku' => 'KLEVU-SIMPLE-SKU-003',
            'visibility' => Visibility::VISIBILITY_IN_SEARCH,
            'price' => 39.99,
            'data' => [
                $attributeFixture->getAttributeCode() => '3',
            ],
        ]);
        $variantProductFixture3 = $this->productFixturePool->get('test_product_variant_3');

        $this->createProduct([
            'type_id' => Configurable::TYPE_CODE,
            'name' => 'Klevu Configurable Product Test',
            'sku' => 'KLEVU-CONFIGURABLE-SKU-001',
            'price' => 99.99,
            'in_stock' => true,
            'visibility' => Visibility::VISIBILITY_BOTH,
            'configurable_attributes' => [
                $attributeFixture->getAttribute(),
            ],
            'variants' => [
                $variantProductFixture1->getProduct(),
                $variantProductFixture2->getProduct(),
                $variantProductFixture3->getProduct(),
            ],
        ]);
        $productFixture = $this->productFixturePool->get('test_product');

        $this->cleanIndexingEntities($apiKey);

        $this->createIndexingEntity([
            IndexingEntity::API_KEY => $apiKey,
            IndexingEntity::TARGET_ID => $productFixture->getId(),
            IndexingEntity::TARGET_ENTITY_TYPE => 'KLEVU_PRODUCT',
            IndexingEntity::TARGET_ENTITY_SUBTYPE => 'configurable',
            IndexingEntity::LAST_ACTION => Actions::ADD,
            IndexingEntity::LAST_ACTION_TIMESTAMP => date('Y-m-d H:i:s'),
            IndexingEntity::IS_INDEXABLE => true,
        ]);

        $this->createIndexingEntity([
            IndexingEntity::API_KEY => $apiKey,
            IndexingEntity::TARGET_ID => $variantProductFixture1->getId(),
            IndexingEntity::TARGET_ENTITY_TYPE => 'KLEVU_PRODUCT',
            IndexingEntity::TARGET_ENTITY_SUBTYPE => 'simple',
            IndexingEntity::LAST_ACTION => Actions::NO_ACTION,
            IndexingEntity::LAST_ACTION_TIMESTAMP => null,
            IndexingEntity::IS_INDEXABLE => false,
        ]);
        $this->createIndexingEntity([
            IndexingEntity::API_KEY => $apiKey,
            IndexingEntity::TARGET_ID => $variantProductFixture1->getId(),
            IndexingEntity::TARGET_PARENT_ID => $productFixture->getId(),
            IndexingEntity::TARGET_ENTITY_TYPE => 'KLEVU_PRODUCT',
            IndexingEntity::TARGET_ENTITY_SUBTYPE => 'configurable_variants',
            IndexingEntity::LAST_ACTION => Actions::ADD,
            IndexingEntity::LAST_ACTION_TIMESTAMP => date('Y-m-d H:i:s'),
            IndexingEntity::IS_INDEXABLE => true,
        ]);

        $this->createIndexingEntity([
            IndexingEntity::API_KEY => $apiKey,
            IndexingEntity::TARGET_ID => $variantProductFixture2->getId(),
            IndexingEntity::TARGET_ENTITY_TYPE => 'KLEVU_PRODUCT',
            IndexingEntity::TARGET_ENTITY_SUBTYPE => 'simple',
            IndexingEntity::LAST_ACTION => Actions::ADD,
            IndexingEntity::LAST_ACTION_TIMESTAMP => date('Y-m-d H:i:s'),
            IndexingEntity::IS_INDEXABLE => true,
        ]);
        $this->createIndexingEntity([
            IndexingEntity::API_KEY => $apiKey,
            IndexingEntity::TARGET_ID => $variantProductFixture2->getId(),
            IndexingEntity::TARGET_PARENT_ID => $productFixture->getId(),
            IndexingEntity::TARGET_ENTITY_TYPE => 'KLEVU_PRODUCT',
            IndexingEntity::TARGET_ENTITY_SUBTYPE => 'configurable_variants',
            IndexingEntity::LAST_ACTION => Actions::ADD,
            IndexingEntity::LAST_ACTION_TIMESTAMP => date('Y-m-d H:i:s'),
            IndexingEntity::IS_INDEXABLE => true,
        ]);

        $this->createIndexingEntity([
            IndexingEntity::API_KEY => $apiKey,
            IndexingEntity::TARGET_ID => $variantProductFixture3->getId(),
            IndexingEntity::TARGET_ENTITY_TYPE => 'KLEVU_PRODUCT',
            IndexingEntity::TARGET_ENTITY_SUBTYPE => 'simple',
            IndexingEntity::LAST_ACTION => Actions::ADD,
            IndexingEntity::LAST_ACTION_TIMESTAMP => date('Y-m-d H:i:s'),
            IndexingEntity::IS_INDEXABLE => true,
        ]);
        $this->createIndexingEntity([
            IndexingEntity::API_KEY => $apiKey,
            IndexingEntity::TARGET_ID => $variantProductFixture3->getId(),
            IndexingEntity::TARGET_PARENT_ID => $productFixture->getId(),
            IndexingEntity::TARGET_ENTITY_TYPE => 'KLEVU_PRODUCT',
            IndexingEntity::TARGET_ENTITY_SUBTYPE => 'configurable_variants',
            IndexingEntity::LAST_ACTION => Actions::ADD,
            IndexingEntity::LAST_ACTION_TIMESTAMP => date('Y-m-d H:i:s'),
            IndexingEntity::IS_INDEXABLE => true,
        ]);

        ConfigFixture::setGlobal(
            path: ProductVisibilityIsIndexableCondition::XML_PATH_SYNC_VISIBILITIES,
            value: Visibility::VISIBILITY_BOTH,
        );

        $service = $this->instantiateDiscoveryOrchestrator();
        $resultGenerators = $service->execute(entityTypes: ['KLEVU_PRODUCT'], apiKeys: [$apiKey]);
        $resultsArray = [];
        foreach ($resultGenerators as $resultGenerator) {
            $resultsArray[] = iterator_to_array($resultGenerator);
        }
        $results = array_filter(
            array_merge(...$resultsArray),
        );
        $result = array_shift($results);

        $this->assertTrue($result->isSuccess());

        $indexingEntity = $this->getIndexingEntityForEntity(
            apiKey: $apiKey,
            entity: $productFixture->getProduct(),
            type: 'KLEVU_PRODUCT',
        );
        $this->assertTrue(
            condition: $indexingEntity->getIsIndexable(),
        );
        $this->assertSame(
            expected: Actions::NO_ACTION,
            actual: $indexingEntity->getNextAction(),
        );

        $indexingEntitySimpleProduct1 = $this->getIndexingEntityForEntity(
            apiKey: $apiKey,
            entity: $variantProductFixture1->getProduct(),
            type: 'KLEVU_PRODUCT',
        );
        $this->assertFalse(
            condition: $indexingEntitySimpleProduct1->getIsIndexable(),
        );
        $this->assertSame(
            expected: Actions::NO_ACTION,
            actual: $indexingEntitySimpleProduct1->getNextAction(),
        );

        $indexingEntitySimpleProduct2 = $this->getIndexingEntityForEntity(
            apiKey: $apiKey,
            entity: $variantProductFixture2->getProduct(),
            type: 'KLEVU_PRODUCT',
        );
        $this->assertTrue(
            condition: $indexingEntitySimpleProduct2->getIsIndexable(),
        );
        $this->assertSame(
            expected: Actions::DELETE,
            actual: $indexingEntitySimpleProduct2->getNextAction(),
        );

        $indexingEntitySimpleProduct3 = $this->getIndexingEntityForEntity(
            apiKey: $apiKey,
            entity: $variantProductFixture3->getProduct(),
            type: 'KLEVU_PRODUCT',
        );
        $this->assertTrue(
            condition: $indexingEntitySimpleProduct3->getIsIndexable(),
        );
        $this->assertSame(
            expected: Actions::DELETE,
            actual: $indexingEntitySimpleProduct3->getNextAction(),
        );

        $indexingEntityVariant1 = $this->getIndexingEntityForVariant(
            apiKey: $apiKey,
            entity: $variantProductFixture1->getProduct(),
            parentEntity: $productFixture->getProduct(),
            type: 'KLEVU_PRODUCT',
        );
        $this->assertTrue(
            condition: $indexingEntityVariant1->getIsIndexable(),
        );
        $this->assertSame(
            expected: Actions::NO_ACTION,
            actual: $indexingEntityVariant1->getNextAction(),
        );

        $indexingEntityVariant2 = $this->getIndexingEntityForVariant(
            apiKey: $apiKey,
            entity: $variantProductFixture2->getProduct(),
            parentEntity: $productFixture->getProduct(),
            type: 'KLEVU_PRODUCT',
        );
        $this->assertTrue(
            condition: $indexingEntityVariant2->getIsIndexable(),
        );
        $this->assertSame(
            expected: Actions::NO_ACTION,
            actual: $indexingEntityVariant2->getNextAction(),
        );

        $indexingEntityVariant3 = $this->getIndexingEntityForVariant(
            apiKey: $apiKey,
            entity: $variantProductFixture3->getProduct(),
            parentEntity: $productFixture->getProduct(),
            type: 'KLEVU_PRODUCT',
        );
        $this->assertTrue(
            condition: $indexingEntityVariant3->getIsIndexable(),
        );
        $this->assertSame(
            expected: Actions::NO_ACTION,
            actual: $indexingEntityVariant3->getNextAction(),
        );

        $this->cleanIndexingEntities($apiKey);
    }

    /**
     * @param mixed[] $arguments
     *
     * @return EntityDiscoveryOrchestratorServiceInterface
     */
    private function instantiateDiscoveryOrchestrator(
        array $arguments = [],
    ): EntityDiscoveryOrchestratorServiceInterface {
        return $this->objectManager->create(
            type: EntityDiscoveryOrchestratorServiceInterface::class,
            arguments: $arguments,
        );
    }

    /**
     * @return string
     * @throws \Exception
     */
    private function getRandomProductType(): string
    {
        $productTypes = [
            Type::TYPE_SIMPLE,
            Type::TYPE_VIRTUAL,
            Type::TYPE_BUNDLE,
            DownloadableType::TYPE_DOWNLOADABLE,
            Grouped::TYPE_CODE,
        ];

        return $productTypes[random_int(min: 0, max: count($productTypes) - 1)];
    }
}
