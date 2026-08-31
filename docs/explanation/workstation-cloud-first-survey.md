---
title: "Cloud-first — kiểm kê vai của workstation trước khi bàn hạ nó xuống dự phòng"
category: explanation
tags: [workstation, cloud-first, offline, printing, lan, issue-2210]
summary: "Bản ghi khảo sát #2210: workstation hôm nay giữ những vai gì (mỗi vai kèm lệnh đo), vai nào đã ở Cloud rồi, vai nào KHÔNG chuyển được và ràng buộc vật lý nào chặn, và cái gì phải có trước khi mở lại cuộc bàn."
related:
  - workstation-role-and-failover
  - pos-web-cloud-auth
---

# Cloud-first — kiểm kê vai của workstation

> **Đây không phải đề xuất làm cloud-first.** Hướng đó `abandoned` ở #1879 và hồ
> sơ gốc (`plans/plan-cloud-first-workstation/`) xoá khỏi cây ở #2188. Trang này
> là thứ #2210 yêu cầu: một **bản kiểm kê đo được** để ai mở lại không phải khảo
> sát từ đầu — và, quan trọng hơn, không quyết trên số liệu 2026-07-23.
>
> Ba câu hỏi treo của #2210 (auth · push hay poll · ngưỡng failover) **đã được
> chốt** ở #2689 — xem [Vai workstation, đường đẩy print job, và ngưỡng
> failover](workstation-role-and-failover.md). Trang này không lặp lại chúng.
>
> **Đối chiếu code: 2026-08-17.**

## Cách đọc trang này

Mỗi khẳng định dưới đây đi kèm **một lệnh chạy lại được** hoặc một `file:line`.
Con số ghi trong lệnh là ảnh chụp có ngày, không phải hằng số — chạy lại lệnh
trước khi trích dẫn nó.

Lý do phải nói rõ: [`pos-web-cloud-auth.md`](pos-web-cloud-auth.md) ghim
*"`routes/api/pos.php` … mang 81 route"* (đối chiếu 2026-08-05); hôm nay lệnh
đếm trả **79**. Bản khảo sát gốc của #2210 ghim *"24 op trong `sync_service.go`"*
và cũng đã lệch. Một bảng đọc lên thì sai còn tệ hơn không có bảng.

Mọi lệnh dưới đây chạy từ gốc repo.

---

## 1. Workstation hôm nay giữ những vai gì

| Vai | Đo bằng |
|---|---|
| **Hub HTTP trên LAN** cho pos-web · kiosk · KDS · handy · TMS · customer | `grep -oE 'mux\.Handle\("[A-Z]+ /api/v1/[a-z-]+' workstation/internal/handler/routes.go \| sed -E 's#.*/api/v1/##' \| sort \| uniq -c` → `pos 76 · handy 15 · kiosk 10 · kds 9 · tms 2 · devices 1 · customer 1` (2026-08-17) |
| **Bề mặt LAN-only** (in, két, ảnh, bundle, health) | `grep -cE 'mux\.Handle\("[A-Z]+ /api/lan' workstation/internal/handler/routes.go` → 16 |
| **In ESC/POS** qua TCP và USB | `workstation/internal/printer/printer.go:269` (`net.DialTimeout("tcp", …)`) và `:270` (`ConnUSB`); allowlist đường thiết bị ở `:407` |
| **Mã hoá Shift_JIS + fold dấu tiếng Việt** cho máy in nhiệt | `workstation/internal/printer/escpos/encoder.go:299` (`encodeShiftJIS`), `:246` (`StripAccents`), `:308` (₫ → `d`) |
| **Đá két tiền** qua xung ESC/POS của chính máy in | `workstation/internal/handler/drawer.go` (#1951) |
| **Cầu tới máy 釣銭機 Glory** trên LAN | `workstation/internal/device/glory/client.go:15` — `baseURL` kiểu `http://192.168.0.10` |
| **Quảng bá mDNS `_ws-app._tcp`** để thiết bị tự tìm | `workstation/internal/discovery/mdns.go:12`; phía tiêu thụ: `app/kiosk/src/services/workstation/discovery.ts`, `app/pos/src/services/workstation/discovery.ts` (`react-native-zeroconf`) |
| **Phục vụ bundle pos-web tại `/pos`** (same-origin http) | `workstation/posweb.go:21` (`go:embed all:pos-web/dist`); lý do + bẫy: [`guide/workstation-serves-pos-web.md`](../guide/workstation-serves-pos-web.md) |
| **Ghi trước vào SQLite rồi sync UP** | `ls workstation/internal/store/migrations/*.sql \| wc -l` → 94; `grep -rhoE '\.Enqueue\(\s*"[a-z_]+",' workstation/internal --include='*.go' \| grep -v _test \| sort -u` → 10 loại thực thể, `grep -rn '\.Enqueue(' workstation/internal --include='*.go' \| grep -v _test \| wc -l` → 32 điểm gọi |
| **Kéo DOWN mọi feed danh mục/replica** | `grep -c 'tracedPull(' workstation/internal/service/sync_pull.go` → 10; ma trận đầy đủ: [`reference/workstation-cloud-api.md`](../reference/workstation-cloud-api.md) |
| **Ký đơn bán offline (Ed25519)** | `workstation/internal/service/offline_signing.go:158` `SignOfflineOrder`; kho khoá `offline_signing_keystore.go:69` |
| **Sổ quan sát tại chỗ**: alert, log, giao dịch máy đếm tiền | `ls workstation/internal/service/ \| grep -E '^(alert\|log\|cash_device)_'` |
| **Tự cập nhật nhị phân lúc 2h sáng** (khi HQ bật `auto_apply`) | `workstation/internal/handler/auto_update.go:80` `runAutoUpdateLoop`, mắc dây ở `internal/handler/server.go:548` |

---

## 2. Vai ĐÃ ở Cloud, hoặc chuyển được rẻ

**Ranh giới POS đã được viết thành code, không phải lời hứa.** `pos-web` sinh
`web/pos/pos-api-manifest.json`; workstation vendor một bản dưới `testdata/` và
`workstation/internal/handler/pos_api_manifest_parity_test.go` bắt buộc mọi route
trong manifest phải **hoặc** có handler LAN, **hoặc** nằm trong danh sách
Cloud-only tường minh:

```sh
grep -cE '\{http\.Method' workstation/internal/handler/pos_cloud_proxy.go   # 10, đo 2026-08-17
python3 -c "import json;print(json.load(open('web/pos/pos-api-manifest.json'))['route_count'])"  # 89
```

`cloudOnlyPOSRoutes` (`pos_cloud_proxy.go:34`) là bằng chứng rằng "chuyển một
việc lên Cloud" đã có đường đi sẵn: công nợ, mở lại đơn, cấu hình đơn, tổng kết
chain, danh sách máy POS, và ba lệnh khôi phục ca chỉ dành cho quản lý. Route
không khai báo thì **404 tại chỗ**, không âm thầm rơi xuống Cloud —
`posRouteUndeclaredError`.

Ba việc khác đã đứng ở Cloud:

- **Auth.** Giai đoạn 0 của bản khảo sát gốc ("Cloud từ chối device token") đã
  hết đúng: `backend/routes/api.php` gắn `auth.sso_or_device` cho nhóm POS. Bản
  ghi thiết kế: [`pos-web-cloud-auth.md`](pos-web-cloud-auth.md).
- **Sổ phiếu in + registry mẫu in** (plan-052/053) — Cloud giữ `print_jobs` như
  **sổ ghi nhận**, hàng đợi in vẫn của workstation
  (`backend/routes/api/workstation.php`, route `print-jobs` POST).
- **Resolver của KDS đã có nhánh cloud.** `app/kds/src/services/base-url-resolver.ts`
  chạy chế độ `auto` = thử workstation trước rồi ngã về Cloud; đảo thứ tự ưu
  tiên là đổi một hằng số, không phải đổi kiến trúc.

**Một tiền lệ tồn tại cho cả in trực tiếp từ Cloud**: Star CloudPRNT.
`backend/routes/api/cloudprnt.php` phục vụ đúng bộ ba `poll`/`fetch`/`confirm`
cho **máy in tự gọi lên Cloud**, không qua workstation. Đó là bằng chứng tồn tại,
không phải đường tổng quát — nó chỉ chạy với máy in nói được giao thức đó.

---

## 3. Vai KHÔNG chuyển được — và ràng buộc chặn

### 3.1 Cloud không mở được socket vào LAN quán

Máy in ở `net.DialTimeout("tcp", address, …)` và máy 釣銭機 ở
`http://192.168.0.10` đều nằm sau NAT của quán. Đây là **ràng buộc vật lý**, đúng
lý do `log-requests` phải cài ngược chiều ("Cloud hỏi trước, máy trạm tự nhận ở
nhịp sau") thay vì Cloud gọi vào máy trạm — xem
[`reference/workstation-cloud-api.md`](../reference/workstation-cloud-api.md).

Hệ quả đã chốt ở #2689: cloud-first cho máy in chỉ có thể là *Cloud quyết định +
render, LAN giữ một agent đẩy byte*. **Agent đó chính là workstation** — nó
không biến mất, nó chỉ đổi phần việc.

### 3.2 Bán offline chỉ workstation làm được — và điều này viết trong code, có rào

pos-web **không** bán offline được, và đó là thiết kế cố ý chứ không phải thiếu
sót:

- `web/pos/src/lib/offline-cache-policy.ts` — cache đọc offline là **danh sách
  CHO PHÉP**, mặc định `false`. Mọi root key dính tiền (`orders`,
  `order-payments`, `payment-methods`, `effective-payment-options`, `till`,
  `revenue`, `customer-outstanding`) bị loại tường minh.
- `web/pos/src/lib/offline-action-queue.ts` — `LightActionType` là **union
  ĐÓNG** với đúng một giá trị: `"table.status"`. Có test ghim; thêm một kiểu
  dính tiền vào đó thì đỏ.

Chính comment trong hai file đó nêu lý do: bán offline đáng tin cần chữ ký
Ed25519 + `catalog_revision` + Cloud re-price (#1092), và đó là vai của
workstation — **cố ý không nhân bản sang một tab trình duyệt dùng chung**. Toàn
bộ cơ chế: [`guide/offline-order-evidence.md`](../guide/offline-order-evidence.md).

Nói ngắn: hạ workstation xuống dự phòng mà không dựng lại tầng này ở đâu đó
nghĩa là **quán mất mạng thì không bán được**.

### 3.3 Same-origin http là lý do bundle pos-web nằm trong workstation

Bundle ở `/pos` tồn tại để máy tính bảng gọi máy in bằng `http` cùng origin.
Đưa việc phục vụ bundle về Cloud là quay lại đúng tình trạng #1169 sinh ra để
chữa: trang `https` gọi `http://<lan-ip>` ⇒ trình duyệt chặn mixed-content, nút
in im lặng không chạy, và **không có cách khắc phục ở production**.

Ràng buộc này không phụ thuộc chút nào vào chất lượng đường truyền — nó là luật
của trình duyệt.

### 3.4 mDNS là thứ duy nhất trả lời "workstation ở đâu"

`_ws-app._tcp` (`internal/discovery/mdns.go:12`) là cách kiosk và vỏ POS native
tìm ra địa chỉ mà không bắt nhân viên gõ IP. Không có bản Cloud thay thế: Cloud
không biết IP nội bộ của quán, và IP đó đổi theo DHCP.

### 3.5 Lệch phiên bản fleet là ràng buộc thiết kế

Fleet production là **hai máy Windows cài tay**
([`guide/khoi-phuc-manifest-workstation.md`](../guide/khoi-phuc-manifest-workstation.md)).
Có đường tự cập nhật (`auto_update.go`, #2635) nhưng nó **có điều kiện**: HQ phải
bật cờ `auto_apply` cho từng bản, chỉ chạy trong khung 02:00–04:00 **theo đồng hồ
của quán**, và bị chặn khi còn ca mở.

Nghĩa là mọi bước "chuyển vai" phải sống được với **hai phiên bản chạy song song
trong nhiều ngày**. Phép đo có sẵn:

```sh
cd backend && php artisan devices:fleet-versions --type=workstation \
    --min-version=<ngưỡng> --require-min
```

`--require-min` chỉ có nghĩa khi đi kèm `--min-version`
(`DeviceFleetVersions.php`: `if ($this->option('require-min') && $min !== null …)`)
— gọi thiếu nó thì lệnh thoát 0 và trông như đã xanh.

---

## 4. Cái gì phải có TRƯỚC khi bàn tiếp

Không phải danh sách việc — là những khoảng trống mà nếu chưa lấp thì cuộc bàn
sẽ quyết trên cảm giác.

1. **Một phép đo "quán mất mạng bao lâu, bao nhiêu lần"**, theo quán, theo ca.
   Toàn bộ giá trị của workstation nằm ở khoảng thời gian này; hôm nay repo
   không có con số nào cho nó (xem §6).
2. **Trần phơi nhiễm cho tiền offline** — #2689 ghi là còn nợ. Chừng nào chưa có
   trần, "tự động ngã về LAN" ở đường thu tiền là rủi ro không đo được.
3. **Poll sàn cho phiếu in** — cũng còn nợ ở #2689. Bất cứ thiết kế nào đẩy phiếu
   từ Cloud xuống đều phải có nó trước, không phải sau.
4. **Danh sách LAN-only đối chiếu từng đường**, chứ không chỉ đếm. Hôm nay chỉ
   **POS** có rào parity (`pos_api_manifest_parity_test.go`); `handy`, `kiosk`,
   `kds` không có manifest tương đương, nên "route nào chỉ chạy được trên LAN"
   với ba app đó là chưa biết.

---

## 5. Câu hỏi còn mở — cần NGƯỜI quyết, không phải code trả lời

1. **Cloud-first áp cho app nào?** POS đã có hai đường sống song song; kiosk và
   handy thì không. Quyết cho từng app hay cho cả nhà?
2. **Khi mất mạng, mức dịch vụ tối thiểu là gì?** "Bán được như thường" (giữ
   nguyên workstation) hay "chỉ in lại phiếu và đóng ca" (workstation teo được)?
   Đây là quyết định kinh doanh, và nó quyết luôn §3.2.
3. **Ai sở hữu hàng đợi in khi Cloud quyết định in?** Sổ `print_jobs` trên Cloud
   hiện chỉ **ghi nhận**. Đổi nó thành nguồn sự thật là đổi chủ sở hữu hàng đợi —
   và một frame rớt lúc đó là mất phiếu bếp.
4. **Quán một máy có được lợi gì không?** Cả bản khảo sát gốc lẫn trang này đo
   trên quán nhiều máy. Với quán một máy, workstation và POS ở cùng hộp, nên
   "hạ xuống dự phòng" gần như không đổi gì — chưa ai kiểm chứng nhận định này.

---

## 6. Chưa đo

Ghi thẳng ra để lần sau không ai đọc khoảng trống thành kết luận:

| Thứ chưa đo | Vì sao |
|---|---|
| Tần suất và độ dài các lần mất mạng ở quán thật | Không có telemetry nào trong repo trả lời được; `internal/service/connectivity.go` biết trạng thái tại chỗ nhưng không có kho lịch sử đối chiếu được |
| `auto_apply` có đang bật ở production hay không | Cờ do HQ đặt cho từng bản build, sống trong dữ liệu chứ không trong cây mã |
| Với `handy`/`kiosk`/`kds`: route nào chỉ có bản LAN | Ba app này không có manifest parity như POS; đếm được số route hai bên nhưng **không** đối chiếu được từng đường |
| Độ trễ thực tế của pos-web khi chạy cloud-only qua 4G ở quán | Cần đo tại hiện trường, không suy ra từ mã được |
| Số quán đang chạy topology nào | Fleet là hai máy Windows theo trang khôi phục manifest; ngoài ra không có kiểm kê nào trong repo |
