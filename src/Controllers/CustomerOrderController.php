<?php

declare(strict_types=1);

namespace JtlWooCommerceConnector\Controllers;

use Defuse\Crypto\Exception\EnvironmentIsBrokenException;
use Jtl\Connector\Core\Controller\PullInterface;
use Jtl\Connector\Core\Controller\StatisticInterface;
use Jtl\Connector\Core\Model\AbstractModel;
use Jtl\Connector\Core\Model\CustomerOrder as CustomerOrderModel;
use Jtl\Connector\Core\Model\CustomerOrderPaymentInfo;
use Jtl\Connector\Core\Model\Identity;
use Jtl\Connector\Core\Definition\PaymentType;
use Jtl\Connector\Core\Model\KeyValueAttribute;
use Jtl\Connector\Core\Model\QueryFilter;
use JtlWooCommerceConnector\Controllers\Order\CustomerOrderBillingAddressController;
use JtlWooCommerceConnector\Controllers\Order\CustomerOrderItemController;
use JtlWooCommerceConnector\Controllers\Order\CustomerOrderShippingAddressController;
use JtlWooCommerceConnector\Utilities\Config;
use JtlWooCommerceConnector\Utilities\Id;
use JtlWooCommerceConnector\Utilities\SqlHelper;
use JtlWooCommerceConnector\Utilities\SupportedPlugins;
use TheIconic\NameParser\Parser;

class CustomerOrderController extends AbstractBaseController implements PullInterface, StatisticInterface
{
    /** Order received (unpaid) */
    public const
        STATUS_PENDING = 'pending',
        /** Payment received – the order is awaiting fulfillment */
        STATUS_PROCESSING = 'processing',
        /** Order fulfilled and complete */
        STATUS_COMPLETED = 'completed',
        /** Awaiting payment – stock is reduced, but you need to confirm payment */
        STATUS_ON_HOLD = 'on-hold',
        /** Payment failed or was declined (unpaid) */
        STATUS_FAILED = 'failed',
        /** Cancelled by an admin or the customer */
        STATUS_CANCELLED = 'cancelled',
        /** Already paid */
        STATUS_REFUNDED = 'refunded';

    public const BILLING_ID_PREFIX  = 'b_';
    public const SHIPPING_ID_PREFIX = 's_';

    /**
     * @param QueryFilter $query
     * @return AbstractModel[]
     * @throws \InvalidArgumentException
     * @throws \WC_Data_Exception
     * @throws \Exception
     */
    public function pull(QueryFilter $query): array
    {
        $orders = [];

        $orderIds = $this->db->queryList(SqlHelper::customerOrderPull($query->getLimit()));

        foreach ($orderIds as $orderId) {
            $order = \wc_get_order($orderId);

            if (!$order instanceof \WC_Order) {
                continue;
            }

            $total    = $order->get_total();
            $totalTax = $order->get_total_tax();
            $totalSum = $total - $totalTax;

            $customerOrder = (new CustomerOrderModel())
                ->setId(new Identity((string)$order->get_id()))
                ->setCreationDate($order->get_date_created())
                ->setCurrencyIso($order->get_currency())
                ->setNote($order->get_customer_note())
                ->setCustomerId($order->get_customer_id() === 0
                    ? new Identity(Id::link([Id::GUEST_PREFIX, $order->get_id()]))
                    : new Identity((string)$order->get_customer_id()))
                ->setOrderNumber($order->get_order_number())
                ->setShippingMethodName($order->get_shipping_method())
                ->setPaymentModuleCode($this->util->mapPaymentModuleCode($order))
                ->setPaymentStatus(CustomerOrderModel::PAYMENT_STATUS_UNPAID)
                ->setStatus($this->status($order))
                ->setTotalSum((float)$totalSum);

            $customerOrder
                ->setItems(...(new CustomerOrderItemController($this->db, $this->util))->pull($order))
                ->setBillingAddress((new CustomerOrderBillingAddressController($this->db, $this->util))->pull($order))
                ->setShippingAddress(
                    (new CustomerOrderShippingAddressController($this->db, $this->util))->pull($order)
                );

            /** @var string $wpmlLanguage */
            $wpmlLanguage = $order->get_meta('wpml_language');

            if ($this->wpml->canBeUsed() && !empty($wpmlLanguage)) {
                $customerOrder->setLanguageISO($this->wpml->convertLanguageToWawi($wpmlLanguage));
            }

            if ($order->is_paid()) {
                $customerOrder->setPaymentDate($order->get_date_paid());
            }

            if ($customerOrder->getPaymentModuleCode() === PaymentType::AMAPAY) {
                /** @var bool|int|string $amazonChargePermissionId */
                $amazonChargePermissionId = $order->get_meta('amazon_charge_permission_id');
                if (!empty($amazonChargePermissionId)) {
                    $customerOrder->addAttribute(
                        (new KeyValueAttribute())
                            ->setKey('AmazonPay-Referenz')
                            ->setValue((string)$amazonChargePermissionId)
                    );
                }
            }

            if (SupportedPlugins::isActive(SupportedPlugins::PLUGIN_CHECKOUT_FIELD_EDITOR_FOR_WOOCOMMERCE)) {
                foreach ($order->get_meta_data() as $metaData) {
                    /** @var bool|int|string|null $customCheckoutFields */
                    $customCheckoutFields = Config::get(Config::OPTIONS_CUSTOM_CHECKOUT_FIELDS);

                    if (
                        \in_array(
                            $metaData->get_data()['key'],
                            \explode(',', (string)$customCheckoutFields)
                        )
                    ) {
                        $customerOrder->addAttribute(
                            (new KeyValueAttribute())
                                ->setKey($metaData->get_data()['key'])
                                ->setValue($metaData->get_data()['value'])
                        );
                    }
                }
            }

            if ($customerOrder->getPaymentModuleCode() === PaymentType::PAYPAL_PLUS) {
                $this->setPayPalPlusPaymentInfo($order, $customerOrder);
            }

            if (
                SupportedPlugins::isActive(SupportedPlugins::PLUGIN_WOOCOMMERCE_GERMANIZED)
                || SupportedPlugins::isActive(SupportedPlugins::PLUGIN_WOOCOMMERCE_GERMANIZED2)
                || SupportedPlugins::isActive(SupportedPlugins::PLUGIN_WOOCOMMERCE_GERMANIZEDPRO)
            ) {
                $this->setGermanizedPaymentInfo($customerOrder);
            }

            if (SupportedPlugins::isActive(SupportedPlugins::PLUGIN_GERMAN_MARKET)) {
                $this->setGermanMarketPaymentInfo($customerOrder);
            }

            if (SupportedPlugins::isActive(SupportedPlugins::PLUGIN_DHL_FOR_WOOCOMMERCE)) {
                $dhlPreferredDeliveryOptions = \get_post_meta((int)$orderId, '_pr_shipment_dhl_label_items', true);

                if (\is_array($dhlPreferredDeliveryOptions)) {
                    $this->setPreferredDeliveryOptions($customerOrder, $dhlPreferredDeliveryOptions);
                }
            }

            $orders[] = $customerOrder;
        }

        return $orders;
    }

    /**
     * @param \WC_Order          $order
     * @param CustomerOrderModel $customerOrder
     * @return void
     * @throws \InvalidArgumentException
     */
    protected function setPayPalPlusPaymentInfo(\WC_Order $order, CustomerOrderModel $customerOrder): void
    {
        $instructionType = $order->get_meta('instruction_type');

        if ($instructionType === PaymentController::PAY_UPON_INVOICE) {
            $payPalPlusSettings = \get_option('woocommerce_paypal_plus_settings', []);

            if (!\is_array($payPalPlusSettings)) {
                throw new \InvalidArgumentException(
                    "payPalSettings expected to be an array but got " . \gettype($payPalPlusSettings)
                );
            }

            $pui = $payPalPlusSettings['pay_upon_invoice_instructions'] ?? '';
            if (empty($pui)) {
                $orderMetaData = $order->get_meta('_payment_instruction_result');

                if (!\is_array($orderMetaData)) {
                    throw new \InvalidArgumentException(
                        "orderMetaData expected to be an array but got " . \gettype($orderMetaData)
                    );
                }

                if (
                    !empty($orderMetaData)
                    && $orderMetaData['instruction_type'] === PaymentController::PAY_UPON_INVOICE
                ) {
                    /** @var array<string, string>|string $bankData */
                    $bankData = $orderMetaData['recipient_banking_instruction'] ?? '';
                    /** @var string $paymentDueDate */
                    $paymentDueDate = $order->get_meta('payment_due_date') ?? '';

                    if (!empty($bankData)) {
                        $pui = (\sprintf(
                            'Bitte überweisen Sie %s %s bis %s an folgendes Konto: %s Verwendungszweck: %s',
                            \number_format((float)$order->get_total(), 2),
                            $customerOrder->getCurrencyIso(),
                            $paymentDueDate,
                            \sprintf(
                                'Empfänger: %s, Bank: %s, IBAN: %s, BIC: %s',
                                $bankData['account_holder_name'] ?? '',
                                $bankData['bank_name'] ?? '',
                                $bankData['international_bank_account_number'] ?? '',
                                $bankData['bank_identifier_code'] ?? ''
                            ),
                            $orderMetaData['reference_number']
                        ));
                    }
                }
            }

            $customerOrder->setPui($pui);
        }
    }

    /**
     * @param \WC_Order $order
     * @return string
     */
    protected function status(\WC_Order $order): string
    {
        if ($order->has_status(self::STATUS_COMPLETED)) {
            return CustomerOrderModel::STATUS_SHIPPED;
        } elseif ($order->has_status([self::STATUS_CANCELLED, self::STATUS_REFUNDED])) {
            return CustomerOrderModel::STATUS_CANCELLED;
        }

        return CustomerOrderModel::STATUS_NEW;
    }

    /**
     * @param CustomerOrderModel $customerOrder
     * @return void
     * @throws EnvironmentIsBrokenException
     * @throws \TypeError
     */
    protected function setGermanizedPaymentInfo(CustomerOrderModel &$customerOrder): void
    {
        $directDebitGateway = new \WC_GZD_Gateway_Direct_Debit();

        if ($customerOrder->getPaymentModuleCode() === PaymentType::DIRECT_DEBIT) {
            $orderId = $customerOrder->getId()->getEndpoint();

            $bic  = $directDebitGateway->maybe_decrypt(\get_post_meta((int)$orderId, '_direct_debit_bic', true));
            $iban = $directDebitGateway->maybe_decrypt(\get_post_meta((int)$orderId, '_direct_debit_iban', true));

            /** @var string $directDebitHolder */
            $directDebitHolder = \get_post_meta((int)$orderId, '_direct_debit_holder', true);

            $paymentInfo = (new CustomerOrderPaymentInfo())
                ->setBic($bic)
                ->setIban($iban)
                ->setAccountHolder($directDebitHolder);

            $customerOrder->setPaymentInfo($paymentInfo);
        } elseif ($customerOrder->getPaymentModuleCode() === PaymentType::INVOICE) {
            /** @var array<string, string> $settings */
            $settings = \get_option('woocommerce_invoice_settings');

            if (!empty($settings) && isset($settings['instructions'])) {
                $customerOrder->setPui($settings['instructions']);
            }
        }
    }

    /**
     * @param CustomerOrderModel    $customerOrder
     * @param array<string, string> $dhlPreferredDeliveryOptions
     * @return void
     */
    protected function setPreferredDeliveryOptions(
        CustomerOrderModel &$customerOrder,
        array $dhlPreferredDeliveryOptions = []
    ): void {
        $customerOrder->addAttribute(
            (new KeyValueAttribute())
                ->setKey('dhl_wunschpaket_feeder_system')
                ->setValue('wooc')
        );

        //foreach each item mach
        foreach ($dhlPreferredDeliveryOptions as $optionName => $optionValue) {
            switch ($optionName) {
                case 'pr_dhl_preferred_day':
                    $customerOrder->addAttribute(
                        (new KeyValueAttribute())
                            ->setKey('dhl_wunschpaket_day')
                            ->setValue($optionValue)
                    );
                    break;
                case 'pr_dhl_preferred_location':
                    $customerOrder->addAttribute(
                        (new KeyValueAttribute())
                            ->setKey('dhl_wunschpaket_location')
                            ->setValue($optionValue)
                    );
                    break;
                case 'pr_dhl_preferred_time':
                    $customerOrder->addAttribute(
                        (new KeyValueAttribute())
                            ->setKey('dhl_wunschpaket_time')
                            ->setValue($optionValue)
                    );
                    break;
                case 'pr_dhl_preferred_neighbour_address':
                    $parts       = \array_map('trim', \explode(',', $optionValue, 2));
                    $streetParts = [];
                    $pattern     = '/^(?P<street>\d*\D+[^A-Z]) (?P<number>[^a-z]?\D*\d+.*)$/';
                    \preg_match($pattern, $parts[0], $streetParts);

                    if (isset($streetParts['street'])) {
                        $customerOrder->addAttribute(
                            (new KeyValueAttribute())
                                ->setKey('dhl_wunschpaket_neighbour_street')
                                ->setValue($streetParts['street'])
                        );
                    }
                    if (isset($streetParts['number'])) {
                        $customerOrder->addAttribute(
                            (new KeyValueAttribute())
                                ->setKey('dhl_wunschpaket_neighbour_house_number')
                                ->setValue($streetParts['number'])
                        );
                    }

                    $shippingAdress = $customerOrder->getShippingAddress();

                    $addressAddition = \sprintf(
                        '%s %s',
                        $shippingAdress ? $shippingAdress->getZipCode() : '',
                        $shippingAdress ? $shippingAdress->getCity() : ''
                    );

                    if (isset($parts[1])) {
                        $addressAddition = $parts[1];
                    }

                    $customerOrder->addAttribute(
                        (new KeyValueAttribute())
                            ->setKey('dhl_wunschpaket_neighbour_address_addition')
                            ->setValue($addressAddition)
                    );

                    break;
                case 'pr_dhl_preferred_neighbour_name':
                    $name = (new Parser())->parse($optionValue);

                    $salutation = $name->getSalutation();
                    $firstName  = $name->getFirstname();

                    if (\preg_match("/(herr|frau)/i", $firstName)) {
                        $salutation = \ucfirst(\mb_strtolower($firstName));
                        $firstName  = $name->getMiddlename();
                    }
                    $salutation = \trim($salutation);
                    if (empty($salutation)) {
                        $salutation = 'Herr';
                    }

                    $customerOrder->addAttribute(
                        (new KeyValueAttribute())
                            ->setKey('dhl_wunschpaket_neighbour_salutation')
                            ->setValue($salutation)
                    );
                    $customerOrder->addAttribute(
                        (new KeyValueAttribute())
                            ->setKey('dhl_wunschpaket_neighbour_first_name')
                            ->setValue($firstName)
                    );
                    $customerOrder->addAttribute(
                        (new KeyValueAttribute())
                            ->setKey('dhl_wunschpaket_neighbour_last_name')
                            ->setValue($name->getLastname())
                    );
                    break;
            }
        }
    }

    /**
     * @param CustomerOrderModel $customerOrder
     * @return void
     */
    protected function setGermanMarketPaymentInfo(CustomerOrderModel $customerOrder): void
    {
        $orderId = $customerOrder->getId()->getEndpoint();

        if ($customerOrder->getPaymentModuleCode() === PaymentType::DIRECT_DEBIT) {
            $instance = new \WGM_Gateway_Sepa_Direct_Debit();

            /** @var array<string, string> $gmSettings */
            $gmSettings = $instance->settings;

            /** @var string $bic */
            $bic = \get_post_meta((int)$orderId, '_german_market_sepa_bic', true);

            /** @var string $iban */
            $iban = \get_post_meta((int)$orderId, '_german_market_sepa_iban', true);

            /** @var string $accountHolder */
            $accountHolder = \get_post_meta((int)$orderId, '_german_market_sepa_holder', true);

            $settingsKeys = [
                '[creditor_information]',
                '[creditor_identifier]',
                '[creditor_account_holder]',
                '[creditor_iban]',
                '[creditor_bic]',
                '[mandate_id]',
                '[street]',
                '[city]',
                '[postcode]',
                '[country]',
                '[date]',
                '[account_holder]',
                '[account_iban]',
                '[account_bic]',
                '[amount]',
            ];

            $pui = \array_key_exists('direct_debit_mandate', $gmSettings)
                ? $gmSettings['direct_debit_mandate']
                : '';

            foreach ($settingsKeys as $key => $formValue) {
                $billingAdsress = $customerOrder->getBillingAddress();
                $paymentDate    = $customerOrder->getPaymentDate();

                switch ($formValue) {
                    case '[creditor_information]':
                        $value = \array_key_exists(
                            'creditor_information',
                            $gmSettings
                        ) ? $gmSettings['creditor_information'] : '';
                        break;
                    case '[creditor_identifier]':
                        $value = \array_key_exists(
                            'creditor_identifier',
                            $gmSettings
                        ) ? $gmSettings['creditor_identifier'] : '';
                        break;
                    case '[creditor_account_holder]':
                        $value = \array_key_exists(
                            'creditor_account_holder',
                            $gmSettings
                        ) ? $gmSettings['creditor_account_holder'] : '';
                        break;
                    case '[creditor_iban]':
                        $value = \array_key_exists('iban', $gmSettings) ? $gmSettings['iban'] : '';
                        break;
                    case '[creditor_bic]':
                        $value = \array_key_exists('bic', $gmSettings) ? $gmSettings['bic'] : '';
                        break;
                    case '[mandate_id]':
                        /** @var string $value */
                        $value = \get_post_meta((int)$orderId, '_german_market_sepa_mandate_reference', true);
                        break;
                    case '[street]':
                        $value = $billingAdsress ? $billingAdsress->getStreet() : '';
                        break;
                    case '[city]':
                        $value = $billingAdsress ? $billingAdsress->getCity() : '';
                        break;
                    case '[postcode]':
                        $value = $billingAdsress ? $billingAdsress->getZipCode() : '';
                        break;
                    case '[country]':
                        $value = $billingAdsress ? $billingAdsress->getCountryIso() : '';
                        break;
                    case '[date]':
                        $value = $paymentDate ? $paymentDate->getTimestamp() : '';
                        break;
                    case '[account_holder]':
                        $value = $accountHolder;
                        break;
                    case '[account_iban]':
                        $value = $iban;
                        break;
                    case '[account_bic]':
                        $value = $bic;
                        break;
                    case '[amount]':
                        $value = $customerOrder->getTotalSum();
                        break;
                    default:
                        $value = '';
                        break;
                }

                $pui = \str_replace(
                    $formValue,
                    (string)$value,
                    $pui
                );
            }

            $paymentInfo = (new CustomerOrderPaymentInfo())
                ->setBic($bic)
                ->setIban($iban)
                ->setAccountHolder($accountHolder);

            $customerOrder->setPui($pui);
            $customerOrder->setPaymentInfo($paymentInfo);
        } elseif ($customerOrder->getPaymentModuleCode() === PaymentType::INVOICE) {
            $instance   = new \WGM_Gateway_Purchase_On_Account();
            $gmSettings = $instance->settings;

            if (
                \array_key_exists('direct_debit_mandate', $gmSettings)
                && $gmSettings['direct_debit_mandate'] !== ''
            ) {
                $customerOrder->setPui($gmSettings['direct_debit_mandate']);
            }
        }
    }

    /**
     * @param QueryFilter $query
     * @return int
     * @throws \Psr\Log\InvalidArgumentException
     * @throws \InvalidArgumentException
     */
    public function statistic(QueryFilter $query): int
    {
        $customerOrderPull = $this->db->queryOne(SqlHelper::customerOrderPull(null)) ?? 0;
        return (int)$customerOrderPull;
    }
}
