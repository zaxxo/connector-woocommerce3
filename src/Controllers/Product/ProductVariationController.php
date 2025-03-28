<?php

declare(strict_types=1);

namespace JtlWooCommerceConnector\Controllers\Product;

use Jtl\Connector\Core\Model\AbstractIdentity;
use Jtl\Connector\Core\Model\Identity;
use Jtl\Connector\Core\Model\Product as ProductModel;
use Jtl\Connector\Core\Model\ProductVariation;
use Jtl\Connector\Core\Model\ProductVariation as ProductVariationModel;
use Jtl\Connector\Core\Model\ProductVariationI18n as ProductVariationI18nModel;
use Jtl\Connector\Core\Model\ProductVariationValue as ProductVariationValueModel;
use Jtl\Connector\Core\Model\ProductVariationValueI18n as ProductVariationValueI18nModel;
use JtlWooCommerceConnector\Controllers\AbstractBaseController;
use JtlWooCommerceConnector\Logger\ErrorFormatter;
use JtlWooCommerceConnector\Utilities\Id;
use JtlWooCommerceConnector\Utilities\SqlHelper;
use Psr\Log\InvalidArgumentException;
use WC_Product;
use WC_Product_Attribute;
use WP_Error;

class ProductVariationController extends AbstractBaseController
{
    // <editor-fold defaultstate="collapsed" desc="Pull">
    /**
     * @param ProductModel         $model
     * @param WC_Product_Attribute $attribute
     * @param string               $languageIso
     * @return ProductVariationModel|null
     */
    public function pullDataParent(
        ProductModel $model,
        WC_Product_Attribute $attribute,
        string $languageIso = ''
    ): ?ProductVariationModel {
        $id = new Identity(Id::link([$model->getId()->getEndpoint(), $attribute->get_id()]));

        $productVariation = (new ProductVariationModel())
            ->setId($id)
            ->setType(ProductVariationModel::TYPE_SELECT)
            ->addI18n((new ProductVariationI18nModel())
                ->setName(\wc_attribute_label($attribute->get_name()))
                ->setLanguageISO($languageIso));

        if ($attribute->is_taxonomy()) {
            $terms = $attribute->get_terms();

            if (!\is_array($terms)) {
                return null;
            }

            /** @var \WP_Term $term */
            foreach ($terms as $sort => $term) {
                $valueId = new Identity(Id::link([$id->getEndpoint(), $term->term_id]));

                $productVariation->addValue((new ProductVariationValueModel())
                    ->setId($valueId)
                    ->setSort($sort)
                    ->addI18n((new ProductVariationValueI18nModel())
                        ->setName($term->name)
                        ->setLanguageISO($languageIso)));
            }
        } else {
            $options = $attribute->get_options();

            foreach ($options as $sort => $option) {
                $valueId = new Identity(Id::link([$id->getEndpoint(), \sanitize_key($option)]));

                $productVariation->addValue((new ProductVariationValueModel())
                    ->setId($valueId)
                    ->setSort($sort)
                    ->addI18n((new ProductVariationValueI18nModel())
                        ->setName($option)
                        ->setLanguageISO($languageIso)));
            }
        }

        return $productVariation;
    }

    /**
     * @param WC_Product   $product
     * @param ProductModel $model
     * @param string       $languageIso
     * @return array<int, AbstractIdentity|ProductVariation>
     * @throws \InvalidArgumentException
     */
    public function pullDataChild(WC_Product $product, ProductModel $model, string $languageIso = ''): array
    {
        $parentProduct = \wc_get_product($product->get_parent_id());

        if (!$parentProduct instanceof \WC_Product) {
            throw new \InvalidArgumentException(
                "Parent product with ID {$product->get_parent_id()} not found."
            );
        }

        $productVariations = [];
        /**
         * @var string $slug
         * @var WC_Product_Attribute $attribute
         */
        foreach ($parentProduct->get_attributes() as $slug => $attribute) {
            $id = new Identity(Id::link([$parentProduct->get_id(), $attribute->get_id()]));

            $productVariation = (new ProductVariationModel())
                ->setId($id)
                ->setType(ProductVariationModel::TYPE_SELECT)
                ->addI18n((new ProductVariationI18nModel())
                    ->setName(\wc_attribute_label($attribute->get_name()))
                    ->setLanguageISO($languageIso));

            if ($attribute->is_taxonomy()) {
                $terms = $attribute->get_terms();

                if (!\is_array($terms)) {
                    continue;
                }

                $value = $product->get_attribute($slug);

                /** @var \WP_Term $term */
                foreach ($terms as $sort => $term) {
                    if ($term->name !== $value) {
                        continue;
                    }

                    $valueId = new Identity(Id::link([$id->getEndpoint(), $term->term_id]));

                    $productVariation->addValue((new ProductVariationValueModel())
                        ->setId($valueId)
                        ->setSort($sort)
                        ->addI18n((new ProductVariationValueI18nModel())
                            ->setName($term->name)
                            ->setLanguageISO($languageIso)));
                }
            } else {
                $value = $product->get_attribute($slug);

                foreach ($attribute->get_options() as $sort => $option) {
                    if ($option !== $value) {
                        continue;
                    }

                    $valueId = new Identity(Id::link([$id->getEndpoint(), \sanitize_key($option)]));

                    $productVariation->addValue((new ProductVariationValueModel())
                        ->setId($valueId)
                        ->setSort($sort)
                        ->addI18n((new ProductVariationValueI18nModel())
                            ->setName($option)
                            ->setLanguageISO($languageIso)));
                }
            }

            $productVariations[] = $productVariation;
        }

        return $productVariations;
    }

    // </editor-fold>

    // <editor-fold defaultstate="collapsed" desc="Push">
    /**
     * @param string                                             $productId
     * @param array<string, array<string, int|string>>           $variationSpecificData
     * @param array<string, array<string, bool|int|string|null>> $attributesFilteredVariationSpecifics
     * @return array<string, array<string, bool|int|string|null>>|null
     * @throws InvalidArgumentException
     */
    public function pushMasterData(
        string $productId,
        array $variationSpecificData,
        array $attributesFilteredVariationSpecifics
    ): ?array {
        $result                  = null;
        $productVaSpeAttrHandler = new ProductVaSpeAttrHandlerController($this->db, $this->util);

        foreach ($variationSpecificData as $key => $variationSpecific) {
            $taxonomy = $this->createVariantSlug($key);
            /** @var array<int, array<string, int|string|null>> $specificID */
            $specificID     = $this->db->query(SqlHelper::getSpecificId(\substr($taxonomy, 3)));
            $specificExists = isset($specificID[0]['attribute_id']);
            $options        = [];

            if (\array_key_exists($taxonomy, $attributesFilteredVariationSpecifics)) {
                $attributesFilteredVariationSpecifics[$taxonomy]['is_variation'] = true;
            }

            if ($specificExists) {
                //Get existing values
                $pushedValues = \explode(' ' . \WC_DELIMITER . ' ', (string)$variationSpecific['value']);
                foreach ($pushedValues as $pushedValue) {
                    //check if value did not exists
                    $termId = (int)$productVaSpeAttrHandler
                        ->getSpecificValueId($taxonomy, \trim($pushedValue))
                        ->getEndpoint();

                    if (!$termId > 0) {
                        $newTerm = \wp_insert_term($pushedValue, $taxonomy);

                        if ($newTerm instanceof WP_Error) {
                            $this->logger->error(ErrorFormatter::formatError($newTerm));
                            continue;
                        }

                        $termId = $newTerm['term_id'];
                    }

                    if (\array_key_exists($taxonomy, $attributesFilteredVariationSpecifics)) {
                        $attributesFilteredVariationSpecifics[$taxonomy]['is_variation'] = true;

                        $options = \explode(
                            ' ' . \WC_DELIMITER . ' ',
                            (string)$attributesFilteredVariationSpecifics[$taxonomy]['value']
                        );

                        if ((!\in_array($termId, $options))) {
                            $options[] = $termId;
                        }

                        $attributesFilteredVariationSpecifics[$taxonomy]['value'] = \implode(
                            ' ' . \WC_DELIMITER . ' ',
                            $options
                        );
                    } else {
                        $options[]                                       = $termId;
                        $attributesFilteredVariationSpecifics[$taxonomy] = [
                            'name' => $taxonomy,
                            'value' => \implode(' ' . \WC_DELIMITER . ' ', $options),
                            'position' => $variationSpecific['position'] ?? 0,
                            'is_visible' => $this->util->showVariationSpecificsOnProductPageEnabled(),
                            'is_variation' => true,
                            'is_taxonomy' => $taxonomy,
                        ];
                    }

                    foreach ($options as $key => $value) {
                        $options[$key] = (int)$value;
                    }

                    \wp_set_object_terms(
                        (int)$productId,
                        $options,
                        (string)$attributesFilteredVariationSpecifics[$taxonomy]['name'],
                        true
                    );
                }
            } else {
                //Create specific and add values
                $endpoint = [
                    'id' => '',
                    'name' => $variationSpecific['name'],
                    'slug' => $taxonomy,
                    'type' => 'select',
                    'order_by' => 'menu_order',
                    'has_archives' => false
                ];

                $options = \explode(
                    ' ' . \WC_DELIMITER . ' ',
                    (string)$variationSpecific['value']
                );

                $attributeId = \wc_create_attribute($endpoint);

                if ($attributeId instanceof WP_Error) {
                    //var_dump($attributeId);
                    //die();
                    //return $termId->get_error_message();
                    $this->logger->error(ErrorFormatter::formatError($attributeId));

                    return null;
                }

                //Register taxonomy for current request
                \register_taxonomy($taxonomy, []);

                $assignedValueIds = [];

                foreach ($options as $optionKey => $optionValue) {
                    $slug = \wc_sanitize_taxonomy_name($optionValue);

                    $endpointValue = [
                        'name' => $optionValue,
                        'slug' => $slug,
                    ];

                    /** @var array<int, array<string, int|string|null>> $exValId */
                    $exValId = $this->db->query(
                        SqlHelper::getSpecificValueId(
                            $taxonomy,
                            $endpointValue['name']
                        )
                    ) ?? [];

                    if (\count($exValId) >= 1) {
                        if (isset($exValId[0]['term_id'])) {
                            $exValId = $exValId[0]['term_id'];
                        } else {
                            $exValId = null;
                        }
                    } else {
                        $exValId = null;
                    }

                    if (\is_null($exValId)) {
                        $newTerm = \wp_insert_term(
                            $endpointValue['name'],
                            $taxonomy
                        );

                        if ($newTerm instanceof WP_Error) {
                            // var_dump($newTerm);
                            // die();
                            $this->logger->error(ErrorFormatter::formatError($newTerm));
                            continue;
                        }

                        $termId = $newTerm['term_id'];

                        if ($termId instanceof WP_Error) {
                            // var_dump($termId);
                            // die();
                            $this->logger->error(ErrorFormatter::formatError($termId));
                            continue;
                        }

                        $assignedValueIds[] = $termId;
                    }
                }

                $attributesFilteredVariationSpecifics[$taxonomy] = [
                    'name' => $taxonomy,
                    'value' => \implode(' ' . \WC_DELIMITER . ' ', $options),
                    'position' => null,
                    'is_visible' => $this->util->showVariationSpecificsOnProductPageEnabled(),
                    'is_variation' => true,
                    'is_taxonomy' => $taxonomy,
                ];

                \wp_set_object_terms(
                    (int)$productId,
                    $assignedValueIds,
                    $attributesFilteredVariationSpecifics[$taxonomy]['name'],
                    true
                );
            }
            $result = $attributesFilteredVariationSpecifics;
        }

        return $result;
    }

    /**
     * @param int                $productId
     * @param ProductVariation[] $pushedVariations
     * @return array<int, string>
     * @throws InvalidArgumentException
     */
    public function pushChildData(
        int $productId,
        array $pushedVariations
    ): array {
        $updatedAttributeKeys = [];

        foreach ($pushedVariations as $variation) {
            foreach ($variation->getValues() as $variationValue) {
                foreach ($variation->getI18ns() as $variationI18n) {
                    if ($this->skipNotDefaultLanguage($variationI18n->getLanguageISO())) {
                        continue;
                    }

                    foreach ($variationValue->getI18ns() as $i18n) {
                        if ($this->skipNotDefaultLanguage($i18n->getLanguageISO())) {
                            continue;
                        }

                        $metaKey                =
                            'attribute_pa_' . \wc_sanitize_taxonomy_name(
                                \substr(
                                    \trim(
                                        $variationI18n->getName()
                                    ),
                                    0,
                                    27
                                )
                            );
                        $updatedAttributeKeys[] = $metaKey;

                        $term = \get_term_by(
                            'name',
                            $i18n->getName(),
                            \str_replace('attribute_', '', $metaKey)
                        );

                        $slug = ($term instanceof \WP_Term)
                            ? $term->slug
                            : \wc_sanitize_taxonomy_name($i18n->getName());

                        \update_post_meta(
                            $productId,
                            $metaKey,
                            $slug
                        );
                    }
                    break;
                }
            }
        }

        return $updatedAttributeKeys;
    }

    /**
     * @param string $wawiLanguageIso
     * @return bool
     * @throws InvalidArgumentException
     */
    protected function skipNotDefaultLanguage(string $wawiLanguageIso): bool
    {
        if ($this->wpml->canBeUsed()) {
            if ($this->wpml->convertLanguageToWawi($this->wpml->getDefaultLanguage()) !== $wawiLanguageIso) {
                return true;
            }
        } else {
            if (!$this->util->isWooCommerceLanguage($wawiLanguageIso)) {
                return true;
            }
        }
        return false;
    }


    // </editor-fold>

    // <editor-fold defaultstate="collapsed" desc="Methods">
    /**
     * @param string $slug
     * @return string
     */
    public function createVariantSlug(string $slug): string
    {
        $slug = 'pa_' . \wc_sanitize_taxonomy_name(\substr(\trim($slug), 0, 27));

        if (\wc_check_if_attribute_name_is_reserved(\substr($slug, 3))) {
            $slug = \substr($slug, 0, 28) . '_1';
        }

        return $slug;
    }
    // </editor-fold>
}
