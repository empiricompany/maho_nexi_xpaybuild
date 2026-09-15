# Maho Nexi XPay Build

Modulo di pagamento con form embedded (senza redirect) per carte di credito tramite il gateway **XPay Build** di Nexi, per [Maho](https://github.com/mahocommerce/maho).

[![Latest Version on Packagist](https://img.shields.io/packagist/v/empiricompany/maho-nexi-xpaybuild.svg?style=flat-square)](https://packagist.org/packages/empiricompany/maho-nexi-xpaybuild)
[![Total Downloads](https://img.shields.io/packagist/dt/empiricompany/maho-nexi-xpaybuild.svg?style=flat-square)](https://packagist.org/packages/empiricompany/maho-nexi-xpaybuild)
[![Software License](https://img.shields.io/badge/license-OSL--3.0-brightgreen.svg?style=flat-square)](LICENSE)
![Maho Commerce](https://img.shields.io/badge/Maho_Commerce-module-orange)
![PHP](https://img.shields.io/badge/php-%3E%3D8.3-8892BF)
![PHPStan Level](https://img.shields.io/badge/PHPStan-level%208-brightgreen)

---

## Compatibilità

| Piattaforma | Versione minima | PHP |
|---|---|---|
| Maho | 26.7.0 | 8.3 — 8.5 |

---

## Descrizione

Modulo di pagamento con form embedded (senza redirect) per carte di credito tramite il gateway **XPay Build** di Nexi.

I dati della carta (PAN, scadenza, CVV) vengono inseriti direttamente nel checkout tramite iframe gestiti dall'SDK XPay di Nexi: **nessun dato sensibile tocca mai il server** (compliance PCI-DSS).

### Funzionalità supportate

- Form di pagamento con **inserimento carta al checkout** (hosted fields)
- Due stili di form: **CARD** (unificato) o **SPLIT_CARD** (3 campi separati: PAN, scadenza, CVV)
- **OneClick / Carte Salvate** — il cliente registrato può salvare la carta e pagare con un clic agli acquisti successivi
- Contabilizzazione **Immediata** (cattura automatica) o **Differita** (solo autorizzazione)
- **Backend**: cattura pagamento e contabilizzazione su Nexi in modalità Differita
- **Backend**: nota di credito online e storno su Nexi (ricordarsi di creare la nota di credito sulla fattura)
- **Backend**: informazioni complete nella transazione (brand, last4, codAut, transaction ID)
- **Dev**: log con dettaglio API in `var/log/nexi_xpaybuild.log`
- **Sicurezza**: MAC (SHA1) su tutte le richieste/risposte API, verifica timing-safe con `hash_equals`
- **Sicurezza**: chiave MAC crittografata nel database
- **Sicurezza**: rate limiting sugli endpoint AJAX (30 tentativi / 5 minuti per sessione)
- **Sicurezza**: ownership check sulle carte salvate (un cliente non può usare le carte di un altro)

---

## Requisiti

- Maho 26.7.0 o superiore
- PHP 8.3 o superiore
- Credenziali Nexi XPay attive (Alias + MAC Key)

---

## Installazione

```bash
composer require empiricompany/maho-nexi-xpaybuild
php maho migrate
php maho cache:flush
```

---

## Configurazione

Vai in **System → Configuration → Payment Methods → Nexi XPay Build**.

| Campo | Descrizione | Default |
|---|---|---|
| Enable | Abilita o disabilita il modulo | Disabilitato |
| Title | Etichetta visibile al cliente nel checkout | Credit Card (Nexi) |
| Environment | Test / Produzione | Test |
| XPay Alias | Alias fornito da Nexi | — |
| MAC Key | Chiave per il calcolo MAC (crittografata nel DB) | — |
| XPay Card Form Style | `SPLIT_CARD`: 3 campi separati (PAN, Expiry, CVV) · `CARD`: form unificato | CARD |
| Accounting Type | `Immediate` (cattura subito) · `Deferred` (solo autorizzazione) | Immediate |
| New Order Status | Stato per i nuovi ordini creati | Processing |
| Enable OneClick (Saved Cards) | Abilita il salvataggio carte per i clienti registrati | Abilitato |
| Payment from Applicable Countries | Restrizione metodo di pagamento per paese | Tutti i paesi |
| Payment from Specific Countries | Specifica paesi consentiti | — |
| Sort Order | Ordine di visualizzazione nel checkout | 10 |

> **Test mode**: quando l'environment è impostato su **Test**, il metodo di pagamento è visibile solo agli IP autorizzati in **System → Configuration → Developer → Developer Client Restrictions**.

---

## Funzionalità OneClick / Carte Salvate

Quando abilitata, la funzione OneClick permette ai clienti registrati di:

- Salvare la carta al momento del pagamento (consenso esplicito tramite checkbox)
- Visualizzare e gestire le carte salvate dalla propria area account (**Account → My Payment Cards**)
- Rimuovere una carta salvata in qualsiasi momento
- Pagare con una carta già salvata senza reinserire i dati

I token delle carte sono memorizzati nella tabella `nexi_saved_cards` e contengono solo il token gateway, il PAN mascherato, il brand e la scadenza — **nessun dato sensibile** del titolare della carta viene salvato.

---

## Flusso di pagamento

1. Il cliente seleziona "Credit Card (Nexi)" nel checkout
2. Il frontend richiede i dati di pagamento al server (`getPaymentData`: alias, importo, MAC, carte salvate)
3. L'SDK XPay viene caricato e i campi carta (iframe) vengono montati nel form
4. Il cliente inserisce i dati e clicca "Place Order"
5. L'SDK genera un **nonce** (con eventuale challenge 3DS)
6. Il nonce viene inviato al server (`placeOrder`) che autorizza verso Nexi
7. L'ordine viene creato, la transazione registrata e (se contabilizzazione immediata) la fattura emessa
8. Redirect alla pagina di successo

## Screen Demo
<img width="497" height="664" alt="demo_checkout" src="https://github.com/user-attachments/assets/df33795a-d561-492b-9a94-d5404f469fb2" />

---

## Sviluppo

Il modulo adotta i gate CI standard di Maho: gli stessi controlli vengono eseguiti su ogni pull request.

- **PHPUnit** (unit test) — `composer test`
- **PHPStan** (level 8) — `composer phpstan`
- **Rector** (dry-run) — `composer rector`
- **PHP CS Fixer** (dry-run) — `composer cs` (oppure `composer cs-fix` per applicare le correzioni)
- **Controlli di sintassi PHP / XML** — `composer lint`
- **Matrix CI** — `.github/workflows/ci.yml` esegue gli stessi gate su PHP 8.3, 8.4 e 8.5

### Contribuire

1. Fai un fork del repository e crea un branch dedicato (`fix/...`, `feature/...`).
2. Installa le dipendenze una volta con `composer install`.
3. Implementa la modifica con un focus singolo e mirato.
4. Prima di aprire la pull request esegui tutti i gate in locale con `composer check` (ordine: test → lint → cs → phpstan → rector).
5. Apri la pull request verso `main` descrivendo il problema e la soluzione proposta: la CI deve risultare verde su tutte le versioni PHP supportate.

---

## Licenza

Open Software License (OSL) v. 3.0
