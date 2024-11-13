<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\IndexingProductsExcludeByVisibility\Observer\Admin\System\Config;

use Klevu\IndexingApi\Service\Action\CreateCronScheduleActionInterface;
use Klevu\IndexingProductsExcludeByVisibility\Service\Determiner\ProductVisibilityIsIndexableCondition;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class UpdateProductSyncSettingsObserver implements ObserverInterface
{
    /**
     * @var CreateCronScheduleActionInterface
     */
    private readonly CreateCronScheduleActionInterface $createCronScheduleAction;

    /**
     * @param CreateCronScheduleActionInterface $createCronScheduleAction
     */
    public function __construct(CreateCronScheduleActionInterface $createCronScheduleAction)
    {
        $this->createCronScheduleAction = $createCronScheduleAction;
    }

    /**
     * @param Observer $observer
     *
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $changedPaths = (array)$observer->getData('changed_paths');
        if (
            !in_array(
                needle: ProductVisibilityIsIndexableCondition::XML_PATH_SYNC_VISIBILITIES,
                haystack: $changedPaths,
                strict: true,
            )
        ) {
            return;
        }

        $this->createCronScheduleAction->execute();
    }

}
