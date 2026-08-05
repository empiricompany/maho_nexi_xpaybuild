<?php

declare(strict_types=1);

/**
 * Customer account controller for saved card management.
 *
 * SPDX-FileCopyrightText: Tony <https://github.com/empiricompany>
 * SPDX-License-Identifier: OSL-3.0
 * @package Nexi_XPayBuild
 */

use Maho\Config\Route;

class Nexi_XPayBuild_AccountController extends Mage_Core_Controller_Front_Action
{
    private function _checkAuthentication(): bool
    {
        if (!Mage::getSingleton('customer/session')->isLoggedIn()) {
            $this->_redirect('customer/account/login');
            return false;
        }
        return true;
    }

    #[Route('/nexixpaybuild/account/index', methods: ['GET'])]
    public function indexAction(): void
    {
        if (!$this->_checkAuthentication()) {
            return;
        }

        $this->loadLayout();

        $root = $this->getLayout()->getBlock('root');
        if ($root) {
            $root->setTitle($this->__('My Payment Cards'));
        }

        $this->renderLayout();
    }

    #[Route('/nexixpaybuild/account/delete', methods: ['POST'])]
    public function deleteAction(): void
    {
        if (!$this->_checkAuthentication()) {
            return;
        }

        if (!$this->getRequest()->isPost() || !$this->_validateFormKey()) {
            Mage::getSingleton('core/session')->addError($this->__('Invalid request.'));
            $this->_redirect('nexixpaybuild/account/index');
            return;
        }

        $cardId = (int) $this->getRequest()->getPost('id');

        if ($cardId <= 0) {
            Mage::getSingleton('core/session')->addError($this->__('Invalid request.'));
            $this->_redirect('nexixpaybuild/account/index');
            return;
        }

        $customerId = (int) Mage::getSingleton('customer/session')->getCustomerId();

        try {
            Mage::helper('nexi_xpaybuild/savedCard')->deleteCard($cardId, $customerId);
            Mage::getSingleton('core/session')->addSuccess(
                $this->__('Card removed successfully.'),
            );
        } catch (\Throwable $e) {
            Mage::logException($e);
            Mage::getSingleton('core/session')->addError(
                $this->__('Unable to remove card. Please try again.'),
            );
        }

        $this->_redirect('nexixpaybuild/account/index');
    }
}
