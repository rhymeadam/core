<?php

/*
 * Isotope eCommerce for Contao Open Source CMS
 *
 * Copyright (C) 2009 - 2019 terminal42 gmbh & Isotope eCommerce Workgroup
 *
 * @link       https://isotopeecommerce.org
 * @license    https://opensource.org/licenses/lgpl-3.0.html
 */

namespace Isotope\Model\Payment;

use Isotope\Interfaces\IsotopeOrderableCollection;
use Isotope\Interfaces\IsotopeProductCollection;
use Isotope\Interfaces\IsotopePurchasableCollection;
use Isotope\Isotope;
use Isotope\Model\ProductCollection\Order;
use Isotope\Module\Checkout;

/**
 * SWISSBILLING payment method
 *
 * @property string $swissbilling_id
 * @property string $swissbilling_pwd
 * @property bool   $swissbilling_b2b
 */
class Swissbilling extends Postsale
{
    /**
     * @inheritDoc
     */
    public function isAvailable()
    {
        $cart = Isotope::getCart();

        if (null === $cart
            || (!$this->swissbilling_b2b
                && $cart->hasShipping()
                && $cart->getBillingAddress()->id !== $cart->getShippingAddress()->id
            )
        ) {
            return false;
        }

        if ('ch' !== $cart->getBillingAddress()->country) {
            return false;
        }

        if ('CHF' !== $cart->getCurrency()) {
            return false;
        }

        if (!$cart->requiresShipping()) {
            return false;
        }

        return parent::isAvailable();
    }

    /**
     * Perform server to server data check
     *
     * @inheritdoc
     */
    public function processPostsale(IsotopeProductCollection $objOrder)
    {
        if (!$objOrder instanceof IsotopePurchasableCollection) {
            \System::log('Product collection ID "' . $objOrder->getId() . '" is not purchasable', __METHOD__, TL_ERROR);
            return;
        }

        if ($objOrder->isCheckoutComplete()) {
            return;
        }

        $mpay24 = $this->getApiClient();
        $status = $mpay24->paymentStatus(\Input::get('MPAYTID'));

        $this->debugLog($status);

        if ($status->getParam('STATUS') !== 'BILLED') {
            \System::log('Payment for order ID "' . $objOrder->getId() . '" failed.', __METHOD__, TL_ERROR);

            return;
        }

        if (!$objOrder->checkout()) {
            \System::log('Postsale checkout for Order ID "' . \Input::post('refno') . '" failed', __METHOD__, TL_ERROR);

            return;
        }

        $objOrder->setDatePaid(time());
        $objOrder->updateOrderStatus($this->new_order_status);

        $objOrder->save();

        die('OK');
    }

    /**
     * @inheritdoc
     */
    public function getPostsaleOrder()
    {
        return Order::findByPk(\Input::get('TID'));
    }

    /**
     * @inheritdoc
     */
    public function checkoutForm(IsotopeProductCollection $objOrder, \Module $objModule)
    {
        if (!$objOrder instanceof IsotopePurchasableCollection) {
            \System::log('Product collection ID "' . $objOrder->getId() . '" is not purchasable', __METHOD__, TL_ERROR);
            return false;
        }

        $mpay24 = $this->getApiClient();

        $mdxi = new Mpay24Order();
        $mdxi->Order->Tid = $objOrder->getId();
        $mdxi->Order->Price = $objOrder->getTotal();
        $mdxi->Order->URL->Success = \Environment::get('base') . Checkout::generateUrlForStep('complete', $objOrder);
        $mdxi->Order->URL->Error = \Environment::get('base') . Checkout::generateUrlForStep('failed');
        $mdxi->Order->URL->Confirmation = \Environment::get('base') . 'system/modules/isotope/postsale.php?mod=pay&id=' . $this->id;

        $template = new \FrontendTemplate('iso_payment_mpay24');
        $template->setData($this->row());
        $template->location = $mpay24->paymentPage($mdxi)->getLocation();

        return $template->parse();
    }


    private function getMerchantConfig()
    {
        return [
            'id' => $this->swissbilling_id,
            'pwd' => $this->swissbilling_pwd,
            'success_url' => '',
            'cancel_url' => '',
            'error_url' => '',
        ];
    }

    private function getTransaction(IsotopeOrderableCollection $collection)
    {
        return [
            'type' => 'Real',
            'is_B2B' => (int) $this->swissbilling_b2b,
            'eshop_ID' => $collection->getStoreId(),
            'eshop_ref' => $collection->getId(),
            'order_timestamp' => strtotime('Y-m-dTh-i-s'),
            'currency' => 'CHF',
            'amount' => $collection->getTotal(),
            'VAT_amount' => $collection->getTaxAmount(),
            'admin_fee_amount' => '0',
            'delivery_fee_amount' => $collection->getShippingMethod()->getPrice($collection),
            'vol_discount' => '0',
            'coupon_discount_amount' => '0',
            'phys_delivery' => '1',
            'debtor_IP' => \Environment::get('ip'),
        ];
    }

    private function getDebtor(IsotopeOrderableCollection $collection)
    {
        $billingAddress = $collection->getBillingAddress();
        $shippingAddress = $this->swissbilling_b2b ? $collection->getShippingAddress() : $billingAddress;

        if (!$billingAddress || !$shippingAddress) {
            throw new \RuntimeException('Must have billing and shipping address');
        }

        return [
            'company_name' => $billingAddress->company,
            'firstname' => $billingAddress->firstname,
            'lastname' => $billingAddress->lastname,
            'birthdate' => 1970-01-01,
            'adr1' => $billingAddress->street_1,
            'adr2' => $billingAddress->street_2,
            'city' => $billingAddress->city,
            'zip' => $billingAddress->postal,
            'country' => 'CH',
            'email' => $billingAddress->email,
            'language' => strtoupper($GLOBALS['TL_LANGUAGE']),
            'user_ID' => 73916,
            'deliv_company_name' => $shippingAddress->company,
            'deliv_firstname' => $shippingAddress->firstname,
            'deliv_lastname' => $shippingAddress->lastname,
            'deliv_adr1' => $shippingAddress->street_1,
            'deliv_adr2' => $shippingAddress->street_2,
            'deliv_city' => $shippingAddress->city,
            'deliv_zip' => $shippingAddress->postal,
            'deliv_country' => 'CH',
        ];
    }

    private function getItems(IsotopeOrderableCollection $collection)
    {
        $data = [];

        foreach ($collection->getItems() as $item) {
            $taxClass = $item->getProduct()->getPrice($collection)->getRelated('tax_class');
            $arrAddresses = array(
                'billing'  => $collection->getBillingAddress(),
                'shipping' => $collection->getShippingAddress(),
            );

            /** @var \Isotope\Model\TaxRate $taxRate */
            if (($taxRate = $taxClass->getRelated('includes')) !== null && $taxRate->isApplicable($item->price, $arrAddresses)) {
                $vatRate = $taxRate->getAmount();
            } else if (($taxRates = $this->getRelated('rates')) !== null) {
                foreach ($taxRates as $taxRate) {
                    if ($taxRate->isApplicable($item->price, $arrAddresses)) {
                        $vatRate = $taxRate->getAmount();
                        break;
                    }
                }
            }

            $data[] = [
                'desc' => $item->getName(),
                'short_desc' => $item->getName(),
                'quantity' => $item->quantity,
                'unit_price' => $item->getPrice(),
                'VAT_rate' => $vatRate,
                'VAT_amount' => $item->getPrice() - $item->getTaxFreePrice(),
             ];
        }

        return $data;
    }
}
