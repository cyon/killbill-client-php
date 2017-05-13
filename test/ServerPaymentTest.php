<?php

/*
 * Copyright 2011-2017 Ning, Inc.
 * Copyright 2014 Groupon, Inc.
 * Copyright 2014 The Billing Project, LLC
 *
 * The Billing Project licenses this file to you under the Apache License, version 2.0
 * (the "License"); you may not use this file except in compliance with the
 * License.  You may obtain a copy of the License at:
 *
 *    http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS, WITHOUT
 * WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.  See the
 * License for the specific language governing permissions and limitations
 * under the License.
 */

namespace Killbill\Client;

use Killbill\Client\Type\PaymentTransactionAttributes;

/**
* Tests for ServerPayment
*/
class ServerPaymentTest extends KillbillTest
{
    /** @var Account|null */
    protected $account = null;
    /** @var string|null */
    private $externalBundleId = null;

    /**
    * Set up test
    */
    public function setUp()
    {
        parent::setUp();

        $this->externalBundleId = uniqid();
        if (getenv('ENV') === 'local' || getenv('RECORD_REQUESTS') == '1') {
            $this->externalBundleId = md5('serverPaymentTest'.static::class.':'.$this->getName());
        }
        $this->account = $this->accountData->create(self::USER, self::REASON, self::COMMENT, $this->tenant->getTenantHeaders());

        $paymentMethod = new PaymentMethod();
        $paymentMethod->setAccountId($this->account->getAccountId());
        $paymentMethod->setIsDefault(true);
        $paymentMethod->setPluginName('__EXTERNAL_PAYMENT__');
        $paymentMethod->create(self::USER, self::REASON, self::COMMENT, $this->tenant->getTenantHeaders());

        $this->account = $this->account->get($this->tenant->getTenantHeaders());
        $this->assertNotEmpty($this->account->getPaymentMethodId());
    }

    /**
    * Tear down test
    */
    public function tearDown()
    {
        parent::tearDown();
        unset($this->externalBundleId);
        unset($this->account);
    }

    /**
    * Test basic functionality
    */
    public function testBasic()
    {
        // Add AUTO_PAY_OFF to account to end up with unpaid invoices
        $this->account->addTags(array('00000000-0000-0000-0000-000000000001'), self::USER, self::REASON, self::COMMENT, $this->tenant->getTenantHeaders());

        $subscriptionData = new Subscription();
        $subscriptionData->setAccountId($this->account->getAccountId());
        $subscriptionData->setProductName('Sports');
        $subscriptionData->setProductCategory('BASE');
        $subscriptionData->setBillingPeriod('MONTHLY');
        $subscriptionData->setPriceList('DEFAULT');
        $subscriptionData->setExternalKey($this->externalBundleId);

        $subscription = $subscriptionData->create(self::USER, self::REASON, self::COMMENT, $this->tenant->getTenantHeaders());
        $this->assertEquals($subscription->getAccountId(), $subscriptionData->getAccountId());
        $this->assertEquals($subscription->getProductName(), $subscriptionData->getProductName());
        $this->assertEquals($subscription->getProductCategory(), $subscriptionData->getProductCategory());
        $this->assertEquals($subscription->getBillingPeriod(), $subscriptionData->getBillingPeriod());
        $this->assertEquals($subscription->getExternalKey(), $subscriptionData->getExternalKey());

        // Move after trial
        $this->clock->addDays(31, $this->tenant->getTenantHeaders());

        $unpaidInvoices = $this->account->getInvoices(true, true, $this->tenant->getTenantHeaders());
        $this->assertEquals(count($unpaidInvoices), 1);

        // Remove the tag
        $this->account->deleteTags(array('00000000-0000-0000-0000-000000000001'), self::USER, self::REASON, self::COMMENT, $this->tenant->getTenantHeaders());

        // processing unpaid invoices is asynchronous (bus event), so let's wait a bit before we check
        usleep(3000000);
        $unpaidInvoices = $this->account->getInvoices(true, true, $this->tenant->getTenantHeaders());
        $this->assertEmpty($unpaidInvoices);

        $allInvoices = $this->account->getInvoices(true, null, $this->tenant->getTenantHeaders());
        $this->assertEquals(count($allInvoices), 2);
    }

    /**
    * Test auth capture refund
    */
    public function testAuthCaptureRefund()
    {
        $paymentData = new Transaction();
        $paymentData->setAmount(10);
        $paymentData->setCurrency('USD');
        $payment = $paymentData->createAuthorization($this->account->getAccountId(), null, self::USER, self::REASON, self::COMMENT, $this->tenant->getTenantHeaders());
        $this->verifyPaymentAndTransaction($payment, 10, 1, 10, 0, 0, 0, 0);

        // Populate the paymentId, required below
        $paymentData->setPaymentId($payment->getPaymentId());

        // Partial capture 1
        $paymentData->setAmount(2);
        $payment = $paymentData->createCapture(self::USER, self::REASON, self::COMMENT, $this->tenant->getTenantHeaders());
        $this->verifyPaymentAndTransaction($payment, 2, 2, 10, 2, 0, 0, 0);

        // Partial capture 2
        $paymentData->setAmount(3);
        $payment = $paymentData->createCapture(self::USER, self::REASON, self::COMMENT, $this->tenant->getTenantHeaders());
        $this->verifyPaymentAndTransaction($payment, 3, 3, 10, 5, 0, 0, 0);

        // Partial refund
        $paymentData->setAmount(4);
        $payment = $paymentData->createRefund(self::USER, self::REASON, self::COMMENT, $this->tenant->getTenantHeaders());
        $this->verifyPaymentAndTransaction($payment, 4, 4, 10, 5, 0, 4, 0);
    }

    /**
    * Test auth void
    */
    public function testAuthVoid()
    {
        $paymentData = new Transaction();
        $paymentData->setAmount(10);
        $paymentData->setCurrency('USD');
        $payment = $paymentData->createAuthorization($this->account->getAccountId(), null, self::USER, self::REASON, self::COMMENT, $this->tenant->getTenantHeaders());
        $this->verifyPaymentAndTransaction($payment, 10, 1, 10, 0, 0, 0, 0);

        // Populate the paymentId, required below
        $paymentData->setPaymentId($payment->getPaymentId());

        // Void
        $payment = $paymentData->createVoid(self::USER, self::REASON, self::COMMENT, $this->tenant->getTenantHeaders());
        $this->verifyPaymentAndTransaction($payment, 0, 2, 0, 0, 0, 0, 0);
    }

    /**
    * Test purchase credit
    */
    public function testPurchaseCredit()
    {
        $paymentData = new Transaction();
        $paymentData->setAmount(10);
        $paymentData->setCurrency('USD');
        $payment = $paymentData->createPurchase($this->account->getAccountId(), null, self::USER, self::REASON, self::COMMENT, $this->tenant->getTenantHeaders());
        $this->verifyPaymentAndTransaction($payment, 10, 1, 0, 0, 10, 0, 0);

        $paymentData->setAmount(12);
        $payment = $paymentData->createCredit($this->account->getAccountId(), null, self::USER, self::REASON, self::COMMENT, $this->tenant->getTenantHeaders());
        // A credit is a different payment
        $this->verifyPaymentAndTransaction($payment, 12, 1, 0, 0, 0, 0, 12);
    }

    /**
     * @param Payment $payment
     * @param float   $transactionAmount
     * @param int     $nbTransactions
     * @param float   $authAmount
     * @param float   $capturedAmount
     * @param float   $purchasedAmount
     * @param float   $refundedAmount
     * @param float   $creditedAmount
     */
    private function verifyPaymentAndTransaction($payment, $transactionAmount, $nbTransactions, $authAmount, $capturedAmount, $purchasedAmount, $refundedAmount, $creditedAmount)
    {
        // Check the returned payment
        $this->verifyPayment($payment, $transactionAmount, $nbTransactions, $authAmount, $capturedAmount, $purchasedAmount, $refundedAmount, $creditedAmount);

        // Check the server
        $payments = $this->account->getPayments($this->tenant->getTenantHeaders());
        $retrievedPayment = $payments[count($payments) - 1];
        $this->verifyPayment($retrievedPayment, $transactionAmount, $nbTransactions, $authAmount, $capturedAmount, $purchasedAmount, $refundedAmount, $creditedAmount);
    }

    /**
     * @param Payment $payment
     * @param float   $transactionAmount
     * @param int     $nbTransactions
     * @param float   $authAmount
     * @param float   $capturedAmount
     * @param float   $purchasedAmount
     * @param float   $refundedAmount
     * @param float   $creditedAmount
     */
    private function verifyPayment($payment, $transactionAmount, $nbTransactions, $authAmount, $capturedAmount, $purchasedAmount, $refundedAmount, $creditedAmount)
    {
        $this->assertEquals($authAmount, $payment->getAuthAmount());
        $this->assertEquals($capturedAmount, $payment->getCapturedAmount());
        $this->assertEquals($purchasedAmount, $payment->getPurchasedAmount());
        $this->assertEquals($refundedAmount, $payment->getRefundedAmount());
        $this->assertEquals($creditedAmount, $payment->getCreditedAmount());

        $this->assertEquals($nbTransactions, count($payment->getTransactions()));
        /** @var PaymentTransactionAttributes $tx */
        foreach ($payment->getTransactions() as $tx) {
            $this->assertEquals('SUCCESS', $tx->getStatus());
        }

        $transactions = $payment->getTransactions();
        /** @var PaymentTransactionAttributes $transaction */
        $transaction  = $transactions[count($payment->getTransactions()) - 1];
        $this->assertEquals($transactionAmount, $transaction->getAmount());
        $this->assertEquals('SUCCESS', $transaction->getStatus());
    }
}
