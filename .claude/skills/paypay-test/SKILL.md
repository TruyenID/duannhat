---
name: paypay-test
description: Test PayPay payments in TempoFast — sandbox smoke, pest suites, signed webhook simulation, fake gateway for feature tests. Use whenever the user mentions PayPay testing, PayPay sandbox, PayPay webhook, or paypay.preauth.wallet.v1. PayPay is a NATIVE gateway here (PayPay OPA SDK) — it does NOT go through Stripe.
---

# PayPay payment testing (TempoFast)

## Architecture — read this first

PayPay is a **standalone provider**, NOT a Stripe payment method. It rides the
plan-047/048 payment-orchestration layer:

- Adapter: `backend/app/Services/Payment/Gateway/PayPay/PayPayPaymentGateway.php`
  (OPA **PreAuth & Capture 1.0**, JPY only, capability `paypay.preauth.wallet.v1`).
- SDK: `godx-jp/paypayopa-php-sdk ^2.1` (fork of `paypayopa/php-sdk` — the
  upstream is NOT installed; the fork fixes php-jwt ^7). Namespace stays
  `PayPay\OpenPaymentAPI\*`.
- Sandbox vs live is decided by the **connection's `environment`** column
  (`PayPaySdkClientFactory::productionMode()` — only `Live` hits production).
- Credentials come from `config('services.paypay.*')` (`PAYPAY_API_KEY` = Key Id,
  `PAYPAY_API_SECRET` = Key Secret, `PAYPAY_MERCHANT_ID`, `PAYPAY_WEBHOOK_SECRET`);
  `merchant_account_reference` on the connection overrides the merchant id
  (assume-merchant). Mismatch with `CreatePaymentCommand` metadata →
  `PAYMENT_GATEWAY_AUTHENTICATION_FAILED`.
- Catalog rows (provider + capability) are seeded by migration
  `2026_07_26_100000_seed_payment_gateway_catalog` and
  `PaymentGatewayCatalogSeeder` (`PAYPAY_OPTION_CODE = 'paypay.preauth.wallet.v1'`).
  Deploy never runs `db:seed` — the migration is what guarantees the rows.
- Reference doc: `docs/guide/payment-gateway-paypay-certification.md` (Gate 8
  evidence, residual gaps).

## 1. Automated tests (fastest, no credentials needed)

Run natively from `backend/` (NOT in docker):

```sh
cd backend
vendor/bin/pest --compact tests/Unit/Services/Payment/PayPayPaymentGatewayAdapterTest.php
vendor/bin/pest --compact tests/Unit/Services/Payment/PayPayPaymentGatewayContractTest.php
vendor/bin/pest --compact tests/Unit/Services/Payment/PayPayPaymentGatewayCertificationTest.php
```

- Contract suite extends `tests/Contracts/Payment/PaymentGatewayContractTestCase.php`
  — every gateway must pass the same lifecycle contract.
- Feature tests never hit PayPay: bind `Tests\Fakes\Payment\PayPayFakePaymentGateway`
  and use `Tests\Support\Payment\PaymentGatewayFixtures::payPayPreauthCapability()`.

## 2. Sandbox connectivity smoke (real PayPay sandbox)

API credentials live in `backend/.env` (`PAYPAY_*` keys — sandbox creds from
the PayPay for Developers dashboard):

```sh
# Sandbox (PayPay for Developers) — no real money
PAYPAY_API_KEY=a_Jf0KxxXIi6_LM8r          # Key Id (Client ID: a_Jf0KxxXIi6)
PAYPAY_API_SECRET=H7O2KWWesebVKfADVymSODnQVKBJXaxA58Ruxsnknm4=
PAYPAY_MERCHANT_ID=991602796635988897
PAYPAY_WEBHOOK_SECRET=                    # chưa cấp — sandbox accept unsigned
```

Sandbox **test users** — log into the PayPay app (sandbox/OPA mode) with these
to scan test QR codes and complete payments (sandbox-only accounts, no real
money):

| # | Phone | Password |
|---|-------------|------------|
| 1 | 09009354551 | FrZmoL0PUF |
| 2 | 07068417027 | 0zMgVghHyl |
| 3 | 07070013608 | 8dDpFF0mdd | If running under docker,
restart the app container after editing `.env` so config picks them up:
`docker compose restart app` (and `php artisan config:clear` if config is cached).

Quick credential/connectivity check via tinker (sandbox mode = second ctor arg `false`):

```php
// php artisan tinker  (docker: docker compose exec app php artisan tinker)
use PayPay\OpenPaymentAPI\Client;
use PayPay\OpenPaymentAPI\Models\CreateQrCodePayload;

$client = new Client([
    'API_KEY'     => config('services.paypay.api_key'),
    'API_SECRET'  => config('services.paypay.api_secret'),
    'MERCHANT_ID' => config('services.paypay.merchant_id'),
], false); // false = sandbox (stg-api.paypay.ne.jp)

$p = new CreateQrCodePayload();
$p->setMerchantPaymentId('smoke-'.now()->getTimestamp());
$p->setAmount(['amount' => 100, 'currency' => 'JPY']);
$p->setCodeType('ORDER_QR');
$res = $client->code->createQRCode($p);
// Expect $res['resultInfo']['code'] === 'SUCCESS' and a $res['data']['url'] QR link.
// Scan with the PayPay sandbox test user app to complete; then:
$client->code->getPaymentDetails('smoke-<ts>');   // status COMPLETED
```

`resultInfo.code` other than `SUCCESS` = credential/merchant problem, not code.
Clean up with `$client->code->deleteQRCode($res['data']['codeId'])` if unused.

Note: the production flow uses **preauth** (`createPaymentAuth` → `capturePaymentAuth`
→ `revertAuth`), which needs a user-authorization token — the QR smoke above only
proves credentials + connectivity. Full preauth lifecycle is exercised by the
adapter/certification pest suites and, end-to-end, by the orchestrator with a real
connection row.

## 3. Webhook — URL & configuration

Intake endpoint (`PaymentProviderWebhookController`, plan-048 Gate 3):

```
POST https://<backend-host>/api/v1/webhooks/payment/paypay[?connection={connection_uuid}]
```

- `?connection=` is OPTIONAL. Without it, `WebhookConnectionResolver` matches the
  payload's `merchant_id` / `merchantId` / `assumeMerchant` against an ACTIVE
  PayPay connection's `merchant_account_id`. With it, the UUID must be an active
  PayPay connection. Either way an **active connection row must exist** or the
  request 400s — create one first (see §4 step 2).
- Responses: 200 = verified & queued (NOT yet processed — async inbox),
  400 = bad signature / no connection / malformed, 409 = payload conflict,
  5xx = internal fault after verification (provider will retry).

**Local dev — public address (configured 2026-07-27)**: the permanent
cloudflared tunnel `famsys` routes **`tempo.famsys.net` → `localhost:5400`**
(docker backend). Webhook URL to register:

```
https://tempo.famsys.net/api/v1/webhooks/payment/paypay
```

Verified reachable end-to-end (POST `{}` → 400 `Invalid signature.` from
Laravel, i.e. route + tunnel OK). Plumbing, if it ever needs fixing: ingress
entry in `~/.cloudflared/config.yml`, DNS CNAME via
`cloudflared tunnel route dns famsys tempo.famsys.net`, service = launchd
`com.cloudflare.cloudflared` (restart:
`launchctl kickstart -k gui/$UID/com.cloudflare.cloudflared`). Docker stack
must be up (`docker compose up -d` in the umbrella root).

**PayPay for Developers dashboard** (https://developer.paypay.ne.jp/, sandbox
mode, merchant `991602796635988897`): open the app → **Webhook設定 / Webhook
settings** → register the URL above for **Transaction** events (add Refund
events too if offered). PayPay sandbox thường KHÔNG ký payload — để
`PAYPAY_WEBHOOK_SECRET` trống, code chấp nhận unsigned CHỈ với connection
sandbox (log `paypay_webhook_unverified_accept`); nếu dashboard cấp secret thì
điền vào `.env` để đi đường ký thật. Caveat: kể cả sandbox, request PHẢI có
header `PayPay-Signature` hoặc `Authorization` khác rỗng (fail-closed) — nếu
PayPay sandbox gửi không kèm header nào, intake sẽ 400; check
`Provider webhook signature verification failed` trong log để xác nhận.

### Simulating a signed webhook locally

Verification rules in `PayPayPaymentGateway::verifyWebhook()`:

- Header `PayPay-Signature` (or `Authorization`) is REQUIRED — missing/empty → 400.
- If `PAYPAY_WEBHOOK_SECRET` is set: signature must be (or contain) hex
  `hash_hmac('sha256', rawBody, secret)`.
- Secret empty + connection **sandbox** → accepted with a loud
  `paypay_webhook_unverified_accept` warning in the `payment_orchestration` log
  channel (PayPay sandbox doesn't always sign). Secret empty + **live** → fail
  closed (#1107).
- Body containing the deprecated host `api.paypay.ne.jp` → always rejected.

Signed simulation:

```sh
BODY='{"notification_type":"Transaction","merchant_order_id":"smoke-123","state":"COMPLETED","order_amount":100}'
SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$PAYPAY_WEBHOOK_SECRET" -hex | awk '{print $2}')
curl -i -X POST "http://localhost:5400/api/v1/webhooks/payment/paypay?connection=<uuid>" \
  -H 'Content-Type: application/json' -H "PayPay-Signature: $SIG" -d "$BODY"
```

Processing is async via the provider-event inbox — a 2xx only means "verified &
queued". Check `payment_provider_events` rows / `ProviderEventApplicator` effects
for the actual state change. Event-shape mapping lives in `PayPayLifecycleMapper`.

## 4. End-to-end via orchestrator

1. Seed catalog (docker): `docker compose exec app php artisan migrate --force`
   (migration seeds provider+capability) or full
   `migrate:fresh --seed --force` for a clean stack.
2. Create a **connection** for the brand via the HQ payment-gateway connection
   API (`PaymentGatewayConnectionStoreRequest`) with `environment = sandbox`
   and the capability `paypay.preauth.wallet.v1`. Quick local-dev recipe
   (docker DB; verified 2026-07-27, created connection
   `019fa213-0262-7097-8e70-4478bdc0550d` — recreate after `migrate:fresh`):

   ```php
   // docker exec tempo-app php artisan tinker --execute='...'
   use App\Models\{PaymentGatewayConnection, PaymentGatewayProvider, Organization, Brand};
   use Illuminate\Support\Str;
   $c = new PaymentGatewayConnection();
   $c->forceFill([
     "provider_id" => PaymentGatewayProvider::where("code", "paypay")->first()->id,
     "organization_id" => Organization::first()->id,
     "brand_id" => Brand::first()->id,
     "owner_branch_id" => null, "owner_scope" => "hq",
     "identity_brand_id" => (string) Str::uuid(),
     "brand_owner_org_unit_id" => (string) Str::uuid(),
     "operator_org_unit_id" => (string) Str::uuid(),
     "ownership_revision" => "1",
     "environment" => "sandbox",
     "merchant_account_id" => "991602796635988897",   // must match webhook payload merchant_id
     "merchant_display_name" => "PayPay sandbox (Famgia dev)",
     "charge_model" => "provider_native",
     "secret_ref" => (string) Str::uuid(), "webhook_secret_ref" => (string) Str::uuid(),
     "secret_version" => "1", "key_fingerprint" => hash("sha256", "paypay-sandbox-dev"),
     "health" => "ready", "health_reason_code" => "DEVSETUP",
     "last_validated_at" => now(), "is_active" => true,
   ])->save();
   ```

   E2E proof (2026-07-27): POST qua `https://tempo.famsys.net/...` với payload
   `{"merchant_id":"991602796635988897","order_id":...,"state":"COMPLETED"}` +
   header `PayPay-Signature: sandbox-unsigned` → 200 `{received:true}`, row
   `payment_provider_events` state `queued`, đúng mapping
   (`paypay.payment.notification`, `raw_status COMPLETED`), log
   `paypay_webhook_unverified_accept` bắn như thiết kế. Row nằm ở `queued`
   cho tới khi queue worker chạy (processing là async).
3. Drive a payment through the normal order/payment flow; the registry
   (`config/payments.php` → `'paypay' => PayPayPaymentGateway::class`) routes it.
4. Idempotency is enforced by `PayPayMutationIdempotency` (merchant payment id
   derived from the operation id) — retries must not double-charge.

## Gotchas

- JPY only; amounts are integer yen (`ZeroDecimalCurrency`).
- Circuit breaker (#1105) exists but is flag-gated OFF
  (`payments.circuit_breaker.enabled`); PayPay outage classification is in
  `PayPayOutageClassifier` next to the adapter.
- Partial refund is conditional on `connection_partial_refund_enabled`
  (merchant contract) — don't assume it in tests.
- Don't import the SDK outside `Gateway/PayPay/` — the gateway-boundary
  architecture test bans SDK types in the neutral layer.
- Webhook 2xx ≠ processed (async inbox); assert on persisted provider events.
