<?php

declare(strict_types=1);

/**
 * Saved card management helper.
 *
 * SPDX-FileCopyrightText: Tony <https://github.com/empiricompany>
 * SPDX-License-Identifier: OSL-3.0
 * @package Nexi_XPayBuild
 */

class Nexi_XPayBuild_Helper_SavedCard extends Mage_Core_Helper_Abstract
{
    public function generateXpayContractNumber(int $customerId): string
    {
        $ms4 = str_pad((string) ((int) (microtime(true) * 1000) % 10000), 4, '0', STR_PAD_LEFT);
        return substr('C' . $customerId . '-' . $ms4, 0, 30);
    }

    public function saveCard(
        int $customerId,
        string $gatewayType,
        string $gatewayToken,
        ?string $maskedPan,
        ?string $brand,
        ?int $expiryMonth,
        ?int $expiryYear,
    ): Nexi_XPayBuild_Model_SavedCard {
        $resource = Mage::getSingleton('core/resource');
        $write = $resource->getConnection('core_write');
        if ($write === false) {
            Mage::throwException(Mage::helper('nexi_xpaybuild')->__('Database connection is unavailable.'));
        }
        $table = $resource->getTableName('nexi_xpaybuild/saved_card');

        $data = [
            'customer_id' => $customerId,
            'gateway_type' => $gatewayType,
            'gateway_token' => $gatewayToken,
            'masked_pan' => $maskedPan,
            'brand' => $brand,
            'expiry_month' => $expiryMonth,
            'expiry_year' => $expiryYear,
            'is_active' => 1,
        ];

        $write->insertOnDuplicate(
            $table,
            $data,
            ['masked_pan', 'brand', 'expiry_month', 'expiry_year', 'gateway_type', 'is_active'],
        );

        $collection = Mage::getModel('nexi_xpaybuild/savedCard')->getCollection();
        if ($collection === false) {
            Mage::throwException(Mage::helper('nexi_xpaybuild')->__('Unable to load saved cards.'));
        }

        /** @var Nexi_XPayBuild_Model_SavedCard $card */
        $card = $collection
            ->addFieldToFilter('customer_id', $customerId)
            ->addFieldToFilter('gateway_token', $gatewayToken)
            ->setPageSize(1)
            ->getFirstItem();

        return $card;
    }

    /**
     * @return Nexi_XPayBuild_Model_SavedCard[]
     */
    public function getActiveCards(int $customerId, ?string $gatewayType = null): array
    {
        $collection = Mage::getModel('nexi_xpaybuild/savedCard')->getCollection();
        if (!$collection instanceof Nexi_XPayBuild_Model_Resource_SavedCard_Collection) {
            return [];
        }

        $collection->addCustomerFilter($customerId)->setOrderByCreatedAtDesc();

        if ($gatewayType !== null) {
            $collection->addGatewayTypeFilter($gatewayType);
        }

        /** @var Nexi_XPayBuild_Model_SavedCard[] $items */
        $items = $collection->getItems();

        return $items;
    }

    public function getCardBrandIcon(string $brand): string
    {
        $brandMap = [
            'VISA' => 'visa.png',
            'MASTERCARD' => 'mastercard.png',
            'AMEX' => 'amex.png',
            'MAESTRO' => 'maestro.png',
            'DINERS' => 'diners.png',
        ];

        $icon = $brandMap[strtoupper($brand)] ?? 'credit_card.png';
        return 'nexi/xpaybuild/images/' . $icon;
    }

    public function loadCard(int $cardId, int $customerId): ?Nexi_XPayBuild_Model_SavedCard
    {
        $card = Mage::getModel('nexi_xpaybuild/savedCard')->load($cardId);

        if (!$card->getId()) {
            return null;
        }

        if ($card->getCustomerId() !== $customerId) {
            Mage::helper('nexi_xpaybuild')->log(
                'SavedCard: unauthorised load attempt: card ' . $cardId . ' belongs to customer ' . $card->getCustomerId(),
                Mage::LOG_WARNING,
            );
            return null;
        }

        if (!$card->getIsActive()) {
            return null;
        }

        return $card;
    }

    public function deleteCard(int $cardId, int $customerId): bool
    {
        $card = $this->loadCard($cardId, $customerId);

        if ($card === null) {
            Mage::helper('nexi_xpaybuild')->log(
                'SavedCard::deleteCard: card ' . $cardId . ' not found or not owned by customer ' . $customerId,
            );
            return false;
        }

        $card->deactivate();
        return true;
    }

    /**
     * Persist a card from a successful XPay authorization response.
     */
    public function saveCardFromResponse(
        array $response,
        ?string $numeroContratto,
        Mage_Sales_Model_Order $order,
    ): void {
        $customerId = (int) $order->getCustomerId();
        if (!$customerId) {
            return;
        }

        $gatewayToken = $numeroContratto
            ?? ($response['codiceAutorizzazione'] ?? $response['codAut'] ?? '');
        if (!$gatewayToken) {
            return;
        }

        $expiryRaw = $response['scadenzaPan'] ?? $response['scadenza'] ?? '';
        $expiryMonth = null;
        $expiryYear = null;

        if ($expiryRaw && preg_match('/^(\d{4})(\d{2})$/', $expiryRaw, $m)) {
            $expiryYear = (int) $m[1];
            $expiryMonth = (int) $m[2];
        } elseif ($expiryRaw && str_contains($expiryRaw, '/')) {
            $parts = explode('/', $expiryRaw);
            $expiryMonth = (int) ($parts[0] ?? 0);
            $expiryYear = strlen($parts[1] ?? '') === 4
                ? (int) $parts[1]
                : 2000 + (int) ($parts[1] ?? 0);
        }

        try {
            $this->saveCard(
                $customerId,
                'XPAY',
                $gatewayToken,
                $response['pan'] ?? null,
                $response['brand'] ?? null,
                $expiryMonth,
                $expiryYear,
            );
        } catch (\Throwable $e) {
            Mage::log('Failed to save card: ' . $e->getMessage(), Mage::LOG_WARNING, 'nexi_xpaybuild.log');
        }
    }

    /**
     * Enrich the payment info and rawDetails from a previously saved card.
     *
     * @param array<string, mixed> $rawDetails
     */
    public function enrichFromSavedCard(
        Mage_Sales_Model_Order_Payment $payment,
        array &$rawDetails,
        int $savedCardId,
        int $customerId,
    ): void {
        $savedCard = $this->loadCard($savedCardId, $customerId);
        if (!$savedCard) {
            return;
        }

        if (empty($rawDetails['brand']) && $savedCard->getBrand()) {
            $payment->setAdditionalInformation('nexi_brand', $savedCard->getBrand());
            $rawDetails['brand'] = $savedCard->getBrand();
        }
        if (empty($rawDetails['pan']) && $savedCard->getMaskedPan()) {
            $payment->setAdditionalInformation('nexi_pan', $savedCard->getMaskedPan());
            $rawDetails['pan'] = $savedCard->getMaskedPan();
        }
        if (empty($rawDetails['scadenza']) && $savedCard->getExpiryYear()) {
            $scadenza = sprintf('%04d%02d', $savedCard->getExpiryYear(), (int) $savedCard->getExpiryMonth());
            $payment->setAdditionalInformation('nexi_scadenza', $scadenza);
            $rawDetails['scadenza'] = $scadenza;
        }

        $payment->setTransactionAdditionalInfo(
            Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS,
            // @phpstan-ignore argument.type (RAW_DETAILS is an array by design; core phpdoc declares string)
            $rawDetails,
        );
    }
}
