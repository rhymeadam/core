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
use Isotope\Template;
use Terminal42\SwissbillingApi\ApiFactory;
use Terminal42\SwissbillingApi\Client;
use Terminal42\SwissbillingApi\Type\DateTime;
use Terminal42\SwissbillingApi\Type\Debtor;
use Terminal42\SwissbillingApi\Type\InvoiceItem;
use Terminal42\SwissbillingApi\Type\Merchant;
use Terminal42\SwissbillingApi\Type\Transaction;

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

        if (!parent::isAvailable()) {
            return false;
        }

        // TODO add support for prescreening
        return true;

        try {
            return $this->getClient($cart)->preScreening(
                $this->getTransaction($cart),
                $this->getDebtor($cart),
                $this->getItems($cart)
            )->isAnswered();
        } catch (\SoapFault $e) {
            return false;
        }
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

        try {
            $transaction = $this->getClient($objOrder)->request(
                $this->getTransaction($objOrder),
                $this->getDebtor($objOrder),
                $this->getItems($objOrder)
            );

            $this->debugLog($transaction);

            if ($transaction->hasError()) {
                return false;
            }
        } catch (\SoapFault $e) {
            $this->debugLog('EshopTransactionRequest() caused exception');
            $this->debugLog($e);
            return false;
        }

        $objTemplate = new Template('iso_payment_swissbilling');
        $objTemplate->headline = $GLOBALS['TL_LANG']['MSC']['pay_with_redirect'][0];
        $objTemplate->message = $GLOBALS['TL_LANG']['MSC']['pay_with_redirect'][1];
        $objTemplate->link = $GLOBALS['TL_LANG']['MSC']['pay_with_redirect'][2];
        $objTemplate->url = $transaction->url;

        return $objTemplate->parse();
    }

    private function getTransaction(IsotopeOrderableCollection $collection)
    {
        $transaction = new Transaction();

        $transaction->is_B2B = (bool) $this->swissbilling_b2b;
        $transaction->eshop_ID = $collection->getStoreId();
        $transaction->eshop_ref = $collection->getId();
        $transaction->order_timestamp = new DateTime(new \DateTime());
        $transaction->amount = $collection->getTotal();
        $transaction->VAT_amount = 0; //$collection->getTaxAmount();
        $transaction->admin_fee_amount = 0;
        $transaction->delivery_fee_amount = 0; //$collection->getShippingMethod()->getPrice($collection);
        $transaction->vol_discount = 0;
        $transaction->coupon_discount_amount = 0;
        $transaction->phys_delivery = true;
        $transaction->debtor_IP = \Environment::get('ip');

        return $transaction;
    }

    private function getDebtor(IsotopeOrderableCollection $collection)
    {
        $billingAddress = $collection->getBillingAddress();
        $shippingAddress = $this->swissbilling_b2b ? $collection->getShippingAddress() : $billingAddress;

        if (!$billingAddress || !$shippingAddress) {
            throw new \RuntimeException('Must have billing and shipping address');
        }

        $debtor = new Debtor();
        $debtor->title = '';
        $debtor->company_name = $billingAddress->company;
        $debtor->firstname = $billingAddress->firstname;
        $debtor->lastname = $billingAddress->lastname;
        $debtor->birthdate = '1970-01-01';
        $debtor->adr1 = $billingAddress->street_1;
        $debtor->adr2 = $billingAddress->street_2;
        $debtor->city = $billingAddress->city;
        $debtor->zip = $billingAddress->postal;
        $debtor->country = 'CH';
        $debtor->email = $billingAddress->email;
        $debtor->phone = $billingAddress->phone;
        $debtor->language = strtoupper($GLOBALS['TL_LANGUAGE']);
//        $debtor->user_ID = 73916;

//            'deliv_company_name' => $shippingAddress->company,
//            'deliv_firstname' => $shippingAddress->firstname,
//            'deliv_lastname' => $shippingAddress->lastname,
//            'deliv_adr1' => $shippingAddress->street_1,
//            'deliv_adr2' => $shippingAddress->street_2,
//            'deliv_city' => $shippingAddress->city,
//            'deliv_zip' => $shippingAddress->postal,
//            'deliv_country' => 'CH',

        return $debtor;
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

            $invoiceItem = new InvoiceItem();
            $invoiceItem->desc = $item->getName();
            $invoiceItem->short_desc = $item->getName();
            $invoiceItem->quantity = $item->quantity;
            $invoiceItem->unit_price = $item->getPrice();
            $invoiceItem->VAT_rate = $vatRate;
            $invoiceItem->VAT_amount = $item->getPrice() - $item->getTaxFreePrice();

            $data[] = $invoiceItem;
        }

        return $data;
    }

    private function getClient(IsotopeOrderableCollection $collection): Client
    {
        $failedUrl = \Environment::get('base').Checkout::generateUrlForStep('failed');
        $merchant = new Merchant(
            $this->swissbilling_id,
            $this->swissbilling_pwd,
            \Environment::get('base').Checkout::generateUrlForStep('complete', $collection),
            $failedUrl,
            $failedUrl
        );

        $factory = new ApiFactory(!$this->debug);

        return new Client($factory, $merchant);
    }
}
