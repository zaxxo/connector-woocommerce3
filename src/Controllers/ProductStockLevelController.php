<?php

declare(strict_types=1);

namespace JtlWooCommerceConnector\Controllers;

use Exception;
use Jtl\Connector\Core\Controller\PushInterface;
use Jtl\Connector\Core\Model\AbstractModel;
use Jtl\Connector\Core\Model\Product;
use JtlWooCommerceConnector\Integrations\Plugins\Wpml\WpmlProduct;

class ProductStockLevelController extends AbstractBaseController implements PushInterface
{
    /**
     * @param AbstractModel $model
     * @return AbstractModel
     * @throws Exception
     */
    public function push(AbstractModel $model): AbstractModel
    {
        /** @var Product $model */
        $productId = $model->getId()->getEndpoint();
        $wcProduct = \wc_get_product($productId);

        if ($wcProduct === false || $wcProduct === null) {
            return $model;
        }

        if ('yes' === \get_option('woocommerce_manage_stock')) {
            $wcProducts[] = $wcProduct;

            if ($this->wpml->canBeUsed()) {
                /** @var WpmlProduct $wpmlProduct */
                $wpmlProduct = $this->wpml->getComponent(WpmlProduct::class);

                $wcProductTranslations = $wpmlProduct
                    ->getWooCommerceProductTranslations($wcProduct);
                $wcProducts            = \array_merge($wcProducts, $wcProductTranslations);
            }

            foreach ($wcProducts as $wcProduct) {
                \update_post_meta($wcProduct->get_id(), '_manage_stock', 'yes');

                $stockLevel  = $model->getStockLevel();
                $stockStatus = $this->util->getStockStatus($stockLevel, $wcProduct->backorders_allowed());

                // Stock status is always determined by children so sync later.
                if (!$wcProduct->is_type('variable')) {
                    $wcProduct->set_stock_status($stockStatus);
                }

                \wc_update_product_stock((int)$productId, (int)\wc_stock_amount($stockLevel));

                if ($wcProduct->is_type('variation')) {
                    \WC_Product_Variable::sync_stock_status($wcProduct->get_id());
                }

                \wc_delete_product_transients($wcProduct->get_id());
            }
        }

        return $model;
    }
}
