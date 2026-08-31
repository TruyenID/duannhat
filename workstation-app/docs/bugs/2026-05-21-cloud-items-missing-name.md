# BUG-2026-05-21-05 — Cloud `/workstation/orders` không include `menu_item_name` trong items[]

| Field | Value |
|---|---|
| **Status** | ✅ FIXED — 2026-05-21 (cloud-side accessor + eager-load) |
| **Severity** | 🟠 High — workstation pull orders về có items rỗng tên, operator không thấy món gì trong order |
| **Discovered** | 2026-05-21 (anh report "không kéo được tên items về"; em curl Cloud API thấy items[] không có field name) |
| **Class** | "Cloud schema cleanup leftover" — `customer_order_items` không có name snapshot column, controller không JOIN tới product để derive |
| **Files** | [backend/app/Models/CustomerOrderItem.php](../../../backend/app/Models/CustomerOrderItem.php), [backend/app/Http/Controllers/Api/V1/Workstation/OrderController.php](../../../backend/app/Http/Controllers/Api/V1/Workstation/OrderController.php) |

> **Note**: Doc này nằm trong workstation-app/docs/bugs để workstation devs thấy được. Fix thực tế ở **backend repo** (cloud Laravel).

---

## Tóm tắt

Cloud `GET /api/v1/workstation/orders` trả về `items[]` với `product_sku_id` (UUID) nhưng **không có tên hiển thị**. Bảng `customer_order_items` của cloud không có column `menu_item_name`/`product_name`/`sku_name` — chỉ ref tới `product_skus`. Workstation pulled items về và lưu `menu_item_name=''` (empty default). UI hiện "x1", "x2", "x3" không có tên món.

---

## Triệu chứng

### Cloud API (trước fix)

```sh
curl -H "Authorization: Bearer <kiosk-token>" \
  "http://localhost:5400/api/v1/workstation/orders?limit=1&since=2026-04-01T00:00:00Z" \
  | jq '.data[0].items[0]'
```

```json
{
  "id": "019e495e-c2a8-7158-8b81-d3430c5933e1",
  "quantity": "1.0000",
  "unit_price": "420.00",
  "subtotal": "420.00",
  "status": "pending",
  "product_sku_id": "019e495e-c233-72d4-a554-443331a9282c"
  // ← Không có menu_item_name, product_name, sku_name
}
```

### Workstation local sau Recover()

```sh
sqlite3 ~/.ws-app/ws-app.db \
  "SELECT id, customer_order_id, menu_item_name, quantity, unit_price FROM order_items LIMIT 5;"
```

```
019e495e-c2a8-...|019e495e-c2a7-...||1|420
019e495e-c2a8-...|019e495e-c2a7-...||2|580
019e495e-c2a9-...|019e495e-c2a7-...||3|720
```

`menu_item_name=''` (rỗng) trên 5/5 rows — Cloud không gửi, workstation lưu default.

---

## Root cause

`customer_order_items` schema **không có name snapshot column**. Field name chỉ tồn tại derived qua relationship:
`order_item → product_sku → product → name`.

[`OrderController@index`](../../../backend/app/Http/Controllers/Api/V1/Workstation/OrderController.php) (trước fix) chỉ `->with(['items'])` — không eager-load `productSku.product`, không có accessor để expose name. Cloud trả về raw model JSON → không có tên.

Workstation [`SyncPuller.Recover`](../../internal/service/sync_pull.go) UPSERT vào local `order_items` với `menu_item_name = it.MenuItemName` — vì JSON không có key đó, Go nhận `""`.

---

## Fix (đã apply ở backend)

### 1. `backend/app/Models/CustomerOrderItem.php`

Thêm accessor + `$appends`:

```php
protected $appends = ['menu_item_name'];

public function getMenuItemNameAttribute(): string
{
    $sku = $this->productSku;
    if (! $sku) {
        return '';
    }

    return $sku->name ?: ($sku->product->name ?? '');
}
```

**Why accessor over migration**: Không cần ALTER TABLE prod, không cần backfill 2395 rows, không cần đồng bộ với HQ/POS path. Chi phí: 1 JOIN per items request — chấp nhận được vì workstation/orders limit 500/req.

### 2. `backend/app/Http/Controllers/Api/V1/Workstation/OrderController.php`

```php
->with(['items.productSku.product'])  // was: ->with(['items'])
```

Eager-load để accessor không gây N+1.

### Verify

```sh
curl ... /api/v1/workstation/orders?limit=1 | jq '.data[0].items[0].menu_item_name'
# "Chocolate Croissant"  ← was missing
```

```sh
cd backend && php -d memory_limit=-1 vendor/bin/pest --compact tests/Feature/Workstation/
# 28 passed (74 assertions)
```

---

## Caveat / Hệ quả còn lại

1. **Workstation cũ vẫn có rows `menu_item_name=''`** trong local SQLite. Cách clear:
   - Unpair → re-pair (Recover sẽ pull lại từ cloud với name đầy đủ); HOẶC
   - Chờ next periodic order sync nếu có (chưa implement — Sprint 5).

2. **Variants không phân biệt**: Nếu Product có nhiều ProductSku (eg "Café Latte" with sizes S/M/L), accessor trả về `$sku->name` first. Nếu SKU không có `name` riêng → fallback `product->name` → mọi size hiện cùng tên. Để hiển thị variant đúng, seed `product_skus.name` per SKU (eg "Café Latte (M)"). Không phải bug accessor, là gap data.

3. **Response payload to hơn 1 chút** do eager-load nested `product_sku` object trong items. Có thể hide qua `$hidden = ['product_sku']` nếu bandwidth quan trọng — chưa làm vì pilot store < 500 orders/req, network không phải bottleneck.

---

## Bài học

1. **Cloud schema thiếu name snapshot là design debt**: bình thường order line phải có snapshot (price + name) tại thời điểm đặt, để khi product đổi tên sau này hóa đơn vẫn đúng. Hiện tại cloud chưa có → khi POS-web/admin đổi tên product, orders cũ sẽ thay đổi tên hiển thị retroactively. **TODO Sprint 5**: migration `ADD COLUMN menu_item_name VARCHAR(255)` + backfill từ `productSku.name`, sau đó update `CustomerOrderService::create` để snapshot tại commit time.

2. **Cloud API contract chưa có integration test workstation-side**: backend tests check Eloquent behavior, nhưng không có "fetch workstation/orders, expect items[].menu_item_name to be non-empty string". Sprint 5 backlog: add Pest browser/feature test cho contract endpoints.

3. **Curl-first debugging effective**: Anh báo bug, em curl trực tiếp Cloud → thấy ngay items[] thiếu key. Tránh chạy luôn workstation E2E mới phát hiện. Pattern: khi local SQLite trống một field, hỏi "Cloud có gửi field đó không?" trước khi nghi sync code.
