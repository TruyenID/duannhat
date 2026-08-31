# Gate 2 — Stripe test-mode E2E cục bộ (#1344)

Chạy ngày 2026-07-30 trên docker dev (`tempo` @ mysql:3307), Stripe CLI 1.44.0,
tài khoản sandbox riêng, key `sk_test_…` hết hạn 2026-10-25. Mọi chỗ dưới đây
viết `acct_<sandbox của bạn>` — lấy giá trị thật bằng `stripe config --list`
(khoá `account_id`). Đừng chép cứng một acct id: nó già đi thành giá trị sai mà
vẫn trông như thật.

**Không có secret nào trong file này, và không có secret nào trong repo.** Mọi
giá trị nhạy cảm đọc thẳng từ Stripe CLI vào biến môi trường của tiến trình.

## Kết quả tóm tắt

| Bước | Trạng thái |
|---|---|
| 1. Secret store cục bộ (keyring) | ✅ dựng xong, provider nạp được |
| 2. Connection Stripe `environment=test` | ✅ tạo được, secret nạp qua `rotate`, giải mã round-trip khớp |
| 3. Approve channel `customer_web` + publish policy revision | ⛔ chưa làm |
| 4. Verify webhook theo connection-scoped secret | ❌ **fail — 400, chưa giải thích được** |
| 5. Chứng minh hết `customer_web_stripe_bootstrap_fallback` | ⛔ chưa tới (phụ thuộc 3 + 4) |
| 6. Runbook | ✅ file này |

Kèm theo: **hai lỗi thật phát hiện khi chạy**, mục cuối.

---

## 1. Keyring — dựng ngoài repo

`FileGatewayMasterKeyProvider` từ chối keyring nằm dưới `base_path()` hoặc
`public_path()`, đòi mode `0600` và `fileowner() == posix_geteuid()`. PHP trong
container chạy **uid 0 (root)**, nên bind mount phải hiện ra là root-owned.

```sh
mkdir -p ~/.tempo
KEY=$(openssl rand -base64 32)
KEYID="local-$(date +%Y%m%d)"
printf '{"active_key_id":"%s","keys":{"%s":"base64:%s"}}' "$KEYID" "$KEYID" "$KEY" \
  > ~/.tempo/gateway-keyring.json
chmod 600 ~/.tempo/gateway-keyring.json
```

`docker-compose.override.yml` (gitignored — `.gitignore:19`, **đừng commit**):

```yaml
services:
  app:
    volumes:
      - ~/.tempo/gateway-keyring.json:/run/secrets/gateway-keyring.json:ro
    environment:
      PAYMENT_GATEWAY_KEYRING_PATH: /run/secrets/gateway-keyring.json
  queue:
    volumes:
      - ~/.tempo/gateway-keyring.json:/run/secrets/gateway-keyring.json:ro
    environment:
      PAYMENT_GATEWAY_KEYRING_PATH: /run/secrets/gateway-keyring.json
```

Đường dẫn keyring **không phải secret** (`config/payments.php` nói rõ, và nó
config-cacheable). Chỉ vật liệu khoá là secret, và nó nằm ngoài repo.

```sh
docker compose up -d app queue
docker compose exec -T app stat -c '%n owner=%u mode=%a' /run/secrets/gateway-keyring.json
#   /run/secrets/gateway-keyring.json owner=0 mode=600      ← đúng cái provider đòi
```

Kiểm provider nạp được:

```sh
docker compose exec -T app php artisan tinker --execute '
  $k = app(App\Services\Payment\Secret\Contracts\GatewayMasterKeyProvider::class)->active();
  echo get_class($k), PHP_EOL;'
#   App\Services\Payment\Secret\ValueObjects\GatewayMasterKey
```

### Bước dễ trượt: trigger append-only

`DatabaseGatewaySecretStore::rotate()` gọi `GatewaySecretAuditProtection::assertInstalled()`,
đòi **2 trigger** trên `payment_gateway_secret_audits`. Không migration nào tạo
chúng — có lệnh artisan riêng, và **DB dev không có sẵn**:

```sh
docker compose exec -T app php artisan payments:install-gateway-secret-audit-protection
#   INFO  Payment gateway secret audit protection is installed.
```

Thiếu bước này thì mọi `rotate` chết bằng `InvalidGatewaySecretConfiguration`
("configuration is invalid") — thông báo **không** nói là thiếu trigger, nên rất
dễ đi lạc sang nghi ngờ keyring.

---

## 2. Connection Stripe + nạp secret

### Endpoint mà issue nêu KHÔNG dùng được cho seed dev

Issue ghi `POST /api/v1/shops/{shop}/payment-gateways`. Đường đó gọi
`PaymentPolicyEvaluationService::onboardFranchiseConnection()` và trả:

```
PaymentGatewayMutationForbiddenException: HQ-managed shops cannot mutate gateway connections.
```

Đó là **luật sản phẩm đúng** — đường shop dành cho quán nhượng quyền. Seed dev là
shop HQ-managed, nên phải đi đường HQ
(`PaymentGatewayConfigurationService::createConnection`, cùng service mà
`POST /api/v1/hq/{brandSlug}/payment-gateways` gọi).

```sh
docker compose exec -T app php artisan tinker --execute '
$svc = app(App\Services\Payment\Configuration\PaymentGatewayConfigurationService::class);
$r = $svc->createConnection(
  "019fa603-a2e5-739c-bedf-96651489c090",   # organization_id
  "019fa603-a300-713c-bc4c-a2eaaf918b32",   # brand_id
  [
    "provider_code"           => "stripe",
    "environment"             => "test",
    "merchant_account_id"     => "acct_<sandbox của bạn>",
    "merchant_display_name"   => "Famgia Inc sandbox (#1344 local test)",
    "charge_model"            => "direct",
    "identity_brand_id"       => "00000001-bbbb-4bbb-bbbb-000000000003",
    "brand_owner_org_unit_id" => "5b1a66b6-80b6-402c-9bbd-59252c078153",
    "operator_org_unit_id"    => "d0c6cde9-c48a-4326-8534-af058096513e",
    "ownership_revision"      => "1",
  ],
  (string) Illuminate\Support\Str::uuid()
);
echo "created=", var_export($r["created"],true), " id=", $r["connection"]->id, PHP_EOL;'
#   created=true id=019fb32a-c799-73fa-8997-b301a3007c27
```

**Lưu ý:** `Branch::first()` có `organization_id` và `brand_id` đều `NULL` trên
seed dev — phải lấy id từ connection `internal` đang có, nếu không sẽ `TypeError`.

### Nạp API secret (không in ra màn hình)

```sh
SK=$(stripe config --list | awk -F"'" '/test_mode_api_key/{print $2}')
SK="$SK" docker compose exec -T -e SK app php artisan tinker --execute '
  app(App\Services\Payment\Configuration\PaymentGatewayConfigurationService::class)
    ->rotateConnectionSecret($ORG, $BRAND, $CONN, ["api_secret"=>getenv("SK")], $ACTOR, (string) Str::uuid());'
```

### Nạp webhook secret

```sh
WH=$(stripe listen --print-secret | tr -d '\r\n')
WH="$WH" docker compose exec -T -e WH app php artisan tinker --execute '
  $conn = App\Models\PaymentGatewayConnection::find($CONN);
  $ctx  = app(App\Services\Payment\Configuration\Internal\EloquentPaymentGatewayConfigurationPersistence::class)
            ->secretContext($conn, $ACTOR, (string) Str::uuid());
  app(App\Services\Payment\Secret\GatewayConnectionSecretResolver::class)
    ->rotateWebhook($ctx, new App\Services\Payment\Gateway\ValueObjects\EphemeralSecret(getenv("WH")), 0);'
```

### Chứng minh mã hoá/giải mã đi đúng qua keyring

```
webhook secret giải mã KHỚP: true
api secret giải mã ra sk_test_: true
payment_gateway_secret_versions: 0 → 2
```

(so với hiện trạng issue đo được: keyring trống, 0 secret version, 0 connection Stripe)

---

## 4. Webhook — CHƯA ĐẠT

### `?connection=` là tuỳ chọn production có tài liệu, không phải lối tắt test

Thứ tự phân giải connection (`WebhookConnectionResolver`, mô tả ở
[`docs/guide/paypay-customer-web-qr.md`](../../../docs/guide/paypay-customer-web-qr.md)
mục *"Which connection a webhook lands on"*):

1. hint `?connection={uuid}` nếu URL có;
2. khớp `merchant_id` trong payload với `payment_gateway_connections.merchant_account_id`;
3. nếu **đúng một** connection active của provider đó → dùng nó;
4. còn lại → `null` → 400.

Bootstrap lưu `merchant_account_id` **tổng hợp** nên bước 2 không bao giờ khớp.
Deployment một tổ chức thì bước 3 gánh; **từ tổ chức thứ hai trở đi bước 3 hỏng
và tài liệu nói thẳng: "register the URL with an explicit `?connection=<uuid>`"**.

Nên dùng hint ở đây không phải để lách khi test — nó là cấu hình khuyến nghị cho
multi-tenant, và cũng là thứ làm **lỗi A không xảy ra** (có hint thì không đi
vào đường bootstrap).

```sh
stripe listen --forward-to "http://localhost:5400/api/v1/webhooks/payment/stripe?connection=<uuid connection của bạn>"
stripe trigger payment_intent.succeeded
```

```
<--  [400] POST .../webhooks/payment/stripe?connection=019fb32a-… [evt_…]
laravel.log: Provider webhook signature verification failed
             {"provider":"stripe","error":"The provider webhook could not be verified."}
```

Đã thu hẹp được:

- **Không phải lỗi cấu hình secret.** Không có `?connection=` thì lỗi là
  `STRIPE_WEBHOOK_SECRET is not configured` (đường fallback legacy). Có
  `?connection=` thì lỗi đổi sang *verification failed* ⇒ **đường
  connection-scoped đã được chạm và đã tìm ra secret**.
- **Không phải secret sai.** `stripe listen --print-secret` trả đúng giá trị đã
  nạp (so khớp bằng `hash_equals` trong tiến trình, không in ra), và
  `webhookCandidates()` giải mã ra đúng nó.
- `StripePaymentGateway::verifyWebhook` dùng `Webhook::constructEvent($payload,
  $signature, $secret)` chuẩn của SDK, duyệt qua từng candidate.

Nghi ngờ còn lại **chưa kiểm chứng** (đừng tin, hãy đo): thân request tới
`rawBody()` khác byte-for-byte với thân Stripe đã ký, hoặc lệch đồng hồ vượt
tolerance 300s giữa host và container. Bước tiếp theo nên là log
`strlen($payload)` + `sha256($payload)` ở verifier rồi so với `Content-Length`
Stripe gửi.

---

## Hai lỗi thật phát hiện khi chạy

### A. Bootstrap connection "legacy" đua tranh — webhook đồng thời trượt 500

Webhook Stripe **không kèm `?connection=`** rơi vào đường bootstrap, tạo một org
+ connection tổng hợp:

```
organizations:                00000000-0000-4000-8000-000000000002  legacy-stripe-intake
payment_gateway_connections:  00000000-0000-4000-8000-000000000001  stripe/test  merchant=legacy:global-platform
```

`LegacyGlobalStripeConnection::ensureOrganization()` là **find rồi mới create**
— không phải insert mù. Nhưng hai bước đó không nguyên tử, và `stripe trigger`
bắn nhiều sự kiện cùng lúc, nên các request song song đều thấy `find()` trả
`null` rồi cùng `create()`:

```
UniqueConstraintViolationException: SQLSTATE[23000] 1062 Duplicate entry
'00000000-0000-4000-8000-000000000002' for key 'organizations.PRIMARY'
```

⇒ HTTP 500 cho những request thua cuộc. Timeline thật, 4 sự kiện của một lần
`stripe trigger`:

```
13:19:09  DUPLICATE-ENTRY          ← thua đua
13:19:09  DUPLICATE-ENTRY          ← thua đua
13:19:09  WEBHOOK_SECRET-missing   ← qua được ensureOrganization
13:19:11  WEBHOOK_SECRET-missing   ← qua được
```

Sau khi dòng đã tồn tại, request sau đó **không** còn 500 — nên đây là lỗi
**đua tranh lúc bootstrap**, không phải "hỏng vĩnh viễn từ sự kiện thứ hai".
Kịch bản sai ở production: một cụm nhận nhiều webhook đồng thời trong lúc chưa
bootstrap sẽ trả 500 cho phần lớn số đó; Stripe retry nên tự lành, nhưng log đầy
500 và một số sự kiện tới muộn hơn TTL retry thì mất.

`firstOrCreate` **không đủ để sửa** — nó cũng là find-rồi-create. Cần khoá ở
tầng DB (`insertOrIgnore` / `upsert` rồi tìm lại, hoặc bắt `UniqueConstraintViolationException`
rồi tìm lại).

**Bẫy tiềm ẩn thứ hai, cùng chỗ** (do review #1346 phát hiện): `Organization`
dùng `SoftDeletes` (`app/Models/Organization.php:12`), nên `find()` có global
scope `whereNull(deleted_at)` trong khi PRIMARY key thì không — một dòng
soft-deleted sẽ làm `find()` trả `null` rồi `create()` đâm 1062 **vĩnh viễn**,
không phải chỉ lúc đua. Đây **không** phải nguyên nhân của lần chạy này (đã
kiểm: `deleted_at` của dòng đó là `NULL`), nhưng cách sửa phải đóng cả hai —
tức phải `withTrashed()` chứ không chỉ đổi sang `firstOrCreate`.

### B. `InvalidGatewaySecretConfiguration` không nói được lý do

Thiếu trigger append-only và keyring hỏng cho **cùng một** thông báo
"Payment gateway secret-store configuration is invalid." Lớp exception có mang
mã lý do (`audit_protection_missing`, `keyring_permissions`, …) nhưng nó không
tới được người vận hành. Mất thời gian thật vì chuyện này.

---

## Cảnh báo giữ nguyên

- Key CLI là `sk_test_`, **hết hạn 2026-10-25** — đừng dựng hạ tầng dài hạn quanh nó.
- `STRIPE_LIVE_REFUNDS_ENABLED` không đụng tới. Không gọi API mode live.
- `docker-compose.override.yml` và `~/.tempo/gateway-keyring.json` **không bao giờ commit**.

## Dọn dẹp sau khi thử

```sh
pkill -f "stripe listen"
docker compose exec -T app php artisan tinker --execute '
  App\Models\PaymentGatewayConnection::find("019fb32a-c799-73fa-8997-b301a3007c27")?->delete();'
rm ~/.tempo/gateway-keyring.json docker-compose.override.yml   # nếu không dùng tiếp
```

Ghi chú: các dòng đã tạo ở DB dev **vẫn còn** sau lần chạy này (connection Stripe
`019fb32a-…`, 2 secret version, org + connection legacy do lỗi A sinh ra). Để lại
có chủ ý làm bằng chứng; xoá bằng đoạn trên nếu muốn DB sạch.
