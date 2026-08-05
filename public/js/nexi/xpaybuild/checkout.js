// SPDX-FileCopyrightText: Tony <https://github.com/empiricompany>
// SPDX-License-Identifier: OSL-3.0

/**
 * Nexi XPay Build checkout integration.
 *
 *  - One JS instance per rendered form element, created on the core
 *    `payment-method:switched` event. When the checkout re-renders the
 *    payment section (one-step innerHTML), a NEW element gets a NEW
 *    instance: no stale-instance bookkeeping (_destroyed flags) is needed.
 *  - The Place Order button (.btn-checkout) is intercepted in the capture
 *    phase; the nonce is generated and the order placed via the module
 *    endpoint.
 *  - Multi-step only: the Payment step "Continue" button is intercepted the
 *    same way so the card is validated (and the 3DS challenge shown) BEFORE
 *    advancing to the Order Review step.
 *  - Errors are shown inside the form (#nexi-error). After a declined
 *    payment the card form is remounted, because the nonce is consumed.
 */
class NexiXPayBuildCheckout {
    static _sdkPromise = null;

    constructor(formDiv) {
        this.formDiv = formDiv;
        this.methodCode = formDiv.dataset.methodCode || 'nexi_xpaybuild';
        this.environment = formDiv.dataset.environment || 'test';
        this.cardStyle = formDiv.dataset.cardFormStyle === 'SPLIT_CARD' ? 'SPLIT_CARD' : 'CARD';
        this.getPaymentDataUrl = formDiv.dataset.getPaymentDataUrl;
        this.placeOrderUrl = formDiv.dataset.placeOrderUrl;

        this.buildData = null;       // payload from getPaymentData (alias, importo, mac, savedCards...)
        this.cardForm = null;        // mounted XPay card form instance
        // Currently selected saved card (0 = new card). Initialized from the
        // pre-checked radio rendered by the template (first saved card).
        const checkedCard = formDiv.querySelector('input[name="nexi_saved_card"]:checked');
        this.selectedCardId = checkedCard ? (parseInt(checkedCard.value, 10) || 0) : 0;
        this.nonce = null;           // nonce generated on the Payment step (multi-step)

        this._mounted = false;
        this._submitting = false;
        this._readyCount = 0;
        this._expectedReady = 1;
        this._readyTimer = null;
    }

    // ------------------------------------------------------------------
    // Mounting
    // ------------------------------------------------------------------

    async loadSdkAndMount() {
        if (this._mounted) return;
        if (!document.body.contains(this.formDiv)) return;
        this.formDiv.style.display = '';
        this.hideError();

        try {
            this.buildData = await this._fetchPaymentData();
            await NexiXPayBuildCheckout._loadSdk(this.buildData.sdkUrl);
            this._configureAndMount();
            this._attachListeners();
        } catch (err) {
            this.handleError(err);
        }
    }

    async _fetchPaymentData() {
        const response = await mahoFetch(this.getPaymentDataUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ saved_card_id: this.selectedCardId }),
            loaderArea: this.formDiv,
        });

        if (!response.success || !response.data) {
            throw new Error(response.message || 'Failed to load payment data.');
        }
        return response.data;
    }

    static _loadSdk(sdkUrl) {
        if (typeof XPay !== 'undefined') {
            return Promise.resolve();
        }
        if (!NexiXPayBuildCheckout._sdkPromise) {
            NexiXPayBuildCheckout._sdkPromise = new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = sdkUrl;
                script.onload = () => resolve();
                script.onerror = () => reject(new Error('Failed to load XPay SDK.'));
                document.head.appendChild(script);
            });
        }
        return NexiXPayBuildCheckout._sdkPromise;
    }

    _configureAndMount() {
        if (this._mounted) return;
        if (typeof XPay === 'undefined' || !this.buildData) return;
        if (!document.body.contains(this.formDiv)) return;

        const data = this.buildData;

        // XPay.Environments is populated by XPay.init(): call it first.
        XPay.init();
        const env = this.environment === 'test' ? XPay.Environments.INTEG : XPay.Environments.PROD;

        // Saved card (OneClick): hidden form + contract params
        const savedCard = this.selectedCardId > 0 && data.savedCards
            ? data.savedCards.find(c => c.cardId === this.selectedCardId)
            : null;

        if (savedCard) {
            this._toggleCardForm(false);
            XPay.setConfig({
                baseConfig: { apiKey: data.alias, enviroment: env },
                paymentParams: {
                    amount: data.importo,
                    transactionId: savedCard.codTrans,
                    currency: data.divisa,
                    timeStamp: savedCard.timeStamp,
                    mac: savedCard.mac,
                },
                language: data.language || XPay.LANGUAGE.ITA,
                customParams: { num_contratto: savedCard.gatewayToken },
                serviceType: 'paga_oc3d',
                requestType: 'PR',
            });
            this.cardForm = XPay.create(XPay.OPERATION_TYPES.CARD, data.style || {});
            this._mountFields(['xpay-oneclick-hidden']);
            return;
        }

        this._toggleCardForm(true);
        XPay.setConfig({
            baseConfig: { apiKey: data.alias, enviroment: env },
            paymentParams: {
                amount: data.importo,
                transactionId: data.codTrans,
                currency: data.divisa,
                timeStamp: data.timeStamp,
                mac: data.mac,
            },
            language: data.language || XPay.LANGUAGE.ITA,
        });

        if (this.cardStyle === 'CARD') {
            this.cardForm = XPay.create(XPay.OPERATION_TYPES.CARD, data.style || {});
            this._mountFields(['xpay-pan-expiry-cvv-card']);
        } else {
            this.cardForm = XPay.create(XPay.OPERATION_TYPES.SPLIT_CARD, data.style || {});
            this._mountFields(['xpay-pan', 'xpay-expiry', 'xpay-cvv']);
        }
    }

    _mountFields(fieldIds) {
        const existing = fieldIds.filter(id => document.getElementById(id));
        if (existing.length === 0) return;

        existing.forEach(id => document.getElementById(id).classList.add('loading'));
        this.cardForm.mount(...existing);
        this._mounted = true;
        this._armReady(existing.length);
    }

    _armReady(expected) {
        this._expectedReady = expected;
        this._readyCount = 0;
        clearTimeout(this._readyTimer);
        // Fallback: never leave the form unusable if XPay_Ready is missed.
        this._readyTimer = setTimeout(() => { this._readyCount = this._expectedReady; }, 3000);
    }

    get isReady() {
        return this._mounted && this._readyCount >= this._expectedReady;
    }

    /**
     * Remount the card form: required after a declined payment (the nonce is
     * consumed) or when switching between saved card / new card. Fresh payment
     * data (codTrans, timestamp, MAC) is fetched from the server.
     */
    async remount() {
        this.nonce = null;
        this._unmountXPay();
        this._mounted = false;
        this.buildData = null;

        try {
            this.buildData = await this._fetchPaymentData();
            this._configureAndMount();
        } catch (err) {
            this.handleError(err);
        }
    }

    _unmountXPay() {
        if (this.cardForm && typeof this.cardForm.unmount === 'function') {
            try { this.cardForm.unmount(); } catch (err) { /* ignore */ }
        }
        this.cardForm = null;

        this.formDiv.querySelectorAll('.nexi-build-field').forEach(el => {
            el.querySelectorAll('iframe').forEach(iframe => iframe.remove());
            el.classList.remove('loading');
        });
    }

    _toggleCardForm(showNewCardForm) {
        // The OneClick container is a silent SDK mount point (requestType PR):
        // it must always stay hidden, no CVV is asked for saved cards.
        const oneclick = document.getElementById('xpay-oneclick-hidden');
        if (oneclick) oneclick.style.display = 'none';
        const saveCard = document.getElementById('nexi-save-card-container');
        if (saveCard) saveCard.style.display = showNewCardForm ? '' : 'none';
        const brands = this.formDiv.querySelector('.nexi-accepted-brands');
        if (brands) brands.style.display = showNewCardForm ? '' : 'none';
        const cardForm = this.formDiv.querySelector('.nexi-card-form');
        if (cardForm) cardForm.style.display = showNewCardForm ? '' : 'none';
    }

    _attachListeners() {
        this.formDiv.addEventListener('change', (e) => {
            if (e.target.name === 'nexi_saved_card') {
                this.selectedCardId = parseInt(e.target.value, 10) || 0;
                this._setHidden('nexi-saved-card-id', this.selectedCardId);
                this.remount();
            }

            if (e.target.id === 'nexi-save-card' && this.cardForm && typeof XPay !== 'undefined' && XPay.updateConfig) {
                XPay.updateConfig(this.cardForm, {
                    serviceType: 'paga_oc3d',
                    requestType: e.target.checked ? 'PP' : 'PA',
                });
            }
        });

        window.addEventListener('XPay_Ready', () => {
            this._readyCount++;
            const first = this.formDiv.querySelector('.nexi-build-field.loading');
            if (first) first.classList.remove('loading');
        });
    }

    // ------------------------------------------------------------------
    // Payment submission
    // ------------------------------------------------------------------

    /**
     * Entry point for the Place Order button (.btn-checkout).
     * Generates the nonce when not already generated on the Payment step
     * (multi-step), then places the order.
     */
    async submitPayment() {
        if (this._submitting) return;
        this._submitting = true;
        this.hideError();

        try {
            if (!this.nonce) {
                this.nonce = await this._generateNonce();
            }
            await this._placeOrder(this.nonce);
        } catch (err) {
            if (!err.cancelled) {
                this.handleError(err);
            }
            this._submitting = false;
        }
    }

    /**
     * Entry point for the multi-step Payment "Continue" button: validate the
     * card (nonce generation, incl. the 3DS challenge) BEFORE advancing to
     * the Order Review step. Resolves true when the checkout may proceed.
     */
    async validateBeforeContinue() {
        if (this.nonce) return true;
        this.hideError();

        try {
            this.nonce = await this._generateNonce();
            return true;
        } catch (err) {
            if (!err.cancelled) {
                this.handleError(err);
            }
            return false;
        }
    }

    _generateNonce() {
        return new Promise((resolve, reject) => {
            if (!this.isReady || !this.cardForm || typeof XPay === 'undefined') {
                reject(new Error('Payment form is not ready yet. Please wait a moment and try again.'));
                return;
            }

            const cleanup = () => {
                window.removeEventListener('XPay_Nonce', onNonce);
                window.removeEventListener('XPay_Card_Error', onCardError);
            };

            const onNonce = (e) => {
                cleanup();
                const detail = e.detail || {};
                if (detail.esito === 'OK' && detail.xpayNonce) {
                    resolve(detail.xpayNonce);
                    return;
                }
                const codice = detail.errore ? String(detail.errore.codice) : '';
                const err = new Error(detail.errore?.messaggio || 'Invalid payment data. Please check your card details.');
                err.cancelled = codice === '600'; // 3DS cancelled by the customer
                // The nonce is consumed by any failed/cancelled attempt, so the
                // form is stale: remount it so the next attempt works.
                if (codice === '5' || codice === '9' || codice === '600') {
                    this.remount();
                }
                reject(err);
            };

            const onCardError = (e) => {
                const detail = e.detail || {};
                const payload = detail.payload || detail;
                const msg = detail.errorMessage || payload?.errorMessage || payload?.errore?.messaggio;

                // The SDK also fires XPay_Card_Error for purely informational
                // validation events (payload with "ok" flags and no error
                // message): those must be ignored, not treated as failures.
                if (!msg) return;

                cleanup();
                reject(new Error(msg));
            };

            window.addEventListener('XPay_Nonce', onNonce);
            window.addEventListener('XPay_Card_Error', onCardError);

            const formId = this.cardStyle === 'SPLIT_CARD'
                ? 'payment_form_nexi_xpaybuild'
                : 'xpay-pan-expiry-cvv-card';
            try {
                XPay.createNonce(formId, this.cardForm);
            } catch (e) {
                cleanup();
                reject(new Error(e?.message || 'Payment form error. Please check your card details.'));
            }
        });
    }

    async _placeOrder(nonce) {
        const response = await mahoFetch(this.placeOrderUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                xpay_nonce: nonce,
                xpay_cod_trans: this._getCodTrans(),
                saved_card_id: this.selectedCardId,
                save_card: document.getElementById('nexi-save-card')?.checked || false,
            }),
            loaderArea: this.formDiv,
        });

        if (response.success && response.redirect_url) {
            window.location.href = response.redirect_url;
            return;
        }

        // The nonce was consumed by the failed authorization: remount the
        // form so the customer can retry without reloading the page, and make
        // sure the error is visible (multi-step: go back to the Payment step).
        const err = new Error(response.message || 'Order placement failed.');
        this._backToPaymentStep();
        this.remount();
        throw err;
    }

    _getCodTrans() {
        if (this.selectedCardId > 0 && this.buildData?.savedCards) {
            const savedCard = this.buildData.savedCards.find(c => c.cardId === this.selectedCardId);
            if (savedCard?.codTrans) return savedCard.codTrans;
        }
        return this.buildData?.codTrans || '';
    }

    /**
     * The error element lives inside the payment form, which is hidden while
     * the customer is on the Order Review step (multi-step): navigate back so
     * the message is actually visible.
     */
    _backToPaymentStep() {
        if (typeof checkout !== 'undefined' && typeof checkout.gotoSection === 'function'
            && !document.getElementById('onestep-checkout')) {
            checkout.gotoSection('payment');
        }
    }

    // ------------------------------------------------------------------
    // UI helpers
    // ------------------------------------------------------------------

    handleError(err) {
        console.error('[NexiXPayBuild]', err);
        const el = document.getElementById('nexi-error');
        if (!el) return;
        el.textContent = err?.message || 'An error occurred with your card payment.';
        el.style.display = '';
        el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    hideError() {
        const el = document.getElementById('nexi-error');
        if (el) {
            el.textContent = '';
            el.style.display = 'none';
        }
    }

    _setHidden(id, val) {
        const el = document.getElementById(id);
        if (el) el.value = String(val ?? '');
    }

    _isNexiSelected() {
        if (typeof payment !== 'undefined' && payment.currentMethod) {
            return payment.currentMethod === this.methodCode;
        }
        return document.querySelector('input[type=radio][name="payment[method]"]:checked')?.value === this.methodCode;
    }
}

// ----------------------------------------------------------------------
// Global wiring: one instance per rendered form element
// ----------------------------------------------------------------------

function _nexiActiveCheckout() {
    const formDiv = document.getElementById('payment_form_nexi_xpaybuild');
    return formDiv?._nexiCheckout || null;
}

document.addEventListener('payment-method:switched', function(e) {
    const formDiv = e.target;
    if (!(formDiv instanceof HTMLElement) || formDiv.dataset.methodCode !== 'nexi_xpaybuild') return;

    // Always re-create on new DOM elements (the one-step checkout re-renders
    // the payment section via innerHTML): a new element gets a new instance.
    if (!formDiv._nexiCheckout) {
        formDiv._nexiCheckout = new NexiXPayBuildCheckout(formDiv);
    }
    formDiv._nexiCheckout.loadSdkAndMount();
}, true);

// Place Order button (one-step; Order Review step in multi-step).
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.btn-checkout');
    if (!btn) return;

    const checkout = _nexiActiveCheckout();
    if (!checkout || !checkout._isNexiSelected()) return;

    e.preventDefault();
    e.stopImmediatePropagation();
    checkout.submitPayment();
}, true);

// Multi-step only: Payment step "Continue" button. Validate the card (nonce
// generation + 3DS challenge) BEFORE advancing to the Order Review step, so
// card errors are shown on the step where the form is visible.
document.addEventListener('click', function(e) {
    const btn = e.target.closest('#payment-buttons-container button');
    if (!btn) return;

    const checkout = _nexiActiveCheckout();
    if (!checkout || !checkout._isNexiSelected()) return;

    e.preventDefault();
    e.stopImmediatePropagation();

    checkout.validateBeforeContinue().then((proceed) => {
        if (proceed && typeof payment !== 'undefined') {
            payment.save();
        }
    });
}, true);

// Initial page load: the method may already be selected (e.g. only one
// payment method available) before any switch event fires.
document.addEventListener('DOMContentLoaded', function() {
    const formDiv = document.getElementById('payment_form_nexi_xpaybuild');
    if (!formDiv) return;
    if (document.querySelector('input[type=radio][name="payment[method]"]:checked')?.value !== 'nexi_xpaybuild') return;

    if (!formDiv._nexiCheckout) {
        formDiv._nexiCheckout = new NexiXPayBuildCheckout(formDiv);
    }
    formDiv._nexiCheckout.loadSdkAndMount();
});
