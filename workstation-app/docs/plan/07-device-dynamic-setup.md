# Plan 07 — Device setup động (tự đặt name + IP, thêm máy mới)

> **Discovered:** 2026-06-11, khi review phần Settings của workstation.
> **Status:** SHIPPED — implemented 2026-06-11 (multi-role devices, fallback removed, migration 013).
> **Scope:** Chỉ workstation-app (frontend Settings/Devices + Go printer manager). Không động kiosk, không động Cloud.

### Quyết định đã chốt
- **Mô hình: máy in ↔ NHIỀU tác vụ (multi-role).** Một máy in có thể đảm nhiệm
  nhiều vai trò in cùng lúc. Hỗ trợ 3 tình huống:
  1. Shop có **1 máy in** → gán **tất cả** tác vụ (bếp + hold + bar + hóa đơn) cho máy đó.
  2. Shop có **nhiều máy** → mỗi máy 1 tác vụ riêng.
  3. Shop có nhiều máy nhưng **1 máy gánh vài tác vụ** (vd 1 máy in cả bếp lẫn hóa đơn).
  → Tức device có **danh sách roles** (nhiều), không phải 1 `type` cố định.
- **Fallback `*_printer_ip`: bỏ hẳn + migrate 1 lần** (đọc key cũ → tạo device, xóa key).
- **Tách khỏi plan in hóa đơn** — plan 08 lo template in, plan 07 lo device setup.

---

## Vấn đề (gốc)

Hiện workstation có **2 cơ chế cấu hình máy in song song, trùng vai trò**:

| Cơ chế | Ở đâu | Cách hoạt động | Hạn chế |
|---|---|---|---|
| **A. Slot IP cố định** | [Settings.tsx:188-247](../../frontend/src/pages/Settings.tsx#L188) | 3 ô IP hard-code: Kitchen / Hold / Bar → lưu setting key `kitchen_printer_ip` / `hold_printer_ip` / `bar_printer_ip` | **Cố định name theo type, không tạo thêm máy mới được.** Muốn thêm máy phải sửa code |
| **B. Device động** | [Devices.tsx](../../frontend/src/pages/Devices.tsx) | Form tạo mới: tự nhập **name + type + connection + address(IP)** → `POST /api/devices` → `AddPrinter(...)` | Đã đúng hướng nhưng type vẫn bó trong 3 giá trị (`kitchen/hold/bar`) |

Backend hợp nhất 2 cơ chế qua `GetPrinterByTypeOrSettings()`
([manager.go:197](../../internal/printer/manager.go#L197)): ưu tiên device đã đăng ký
(cơ chế B), nếu không có thì fallback đọc setting key cố định (cơ chế A) và
dựng printer ad-hoc `adhoc-<type>`.

➡️ **Yêu cầu của user:** bỏ kiểu "cố định name để set up", chuyển hẳn sang
**dạng tạo mới — tự đặt name + IP**, và **thêm được máy mới khi có thêm thiết bị**.

---

## Mục tiêu

1. Settings KHÔNG còn 3 ô IP cố định theo type. Mọi máy in được quản lý ở **một
   chỗ duy nhất** dạng list + "tạo mới" (name + IP tự do).
2. Thêm máy mới = thêm 1 record device, không sửa code.
3. Không phá luồng in hiện có (kitchen/hold/runner ticket vẫn resolve đúng máy).

---

## Các việc sẽ làm

### Task 1 — Hợp nhất UI về một cơ chế (frontend)
- [x] Bỏ card "Printers (LAN)" với 3 ô IP cố định trong [Settings.tsx](../../frontend/src/pages/Settings.tsx)
      (xóa state `kitchenPrinterIp/holdPrinterIp/barPrinterIp`, `savePrinterIps`,
      load `getSetting("*_printer_ip")`).
- [x] Mọi quản lý máy in dồn về [Devices.tsx](../../frontend/src/pages/Devices.tsx)
      (form tạo mới đã có: name + type + connection + address). Cân nhắc đổi tên
      mục/nav cho rõ là "Máy in / Thiết bị".
- [x] (Tùy chọn) Thêm field **paper width** vào form (hiện hard-code 80 ở
      [Devices.tsx:29](../../frontend/src/pages/Devices.tsx#L29)) nếu cần khổ giấy khác nhau.

### Task 2 — Device multi-role: 1 máy đảm nhiệm nhiều tác vụ (frontend + backend)
**Mô hình đã chốt: device có DANH SÁCH roles (nhiều), không phải 1 type cố định.**
- [x] **Schema/domain**: chuyển từ `Type DeviceType` (1 giá trị) sang **`Roles []DeviceType`**
      (vd `["kitchen_printer","receipt_printer"]`). Lưu vào DB (cột JSON hoặc bảng
      `device_roles` 1-nhiều). Ảnh hưởng `Printer` struct + `AddPrinter`.
- [x] **Form tạo/sửa device** ([Devices.tsx](../../frontend/src/pages/Devices.tsx)):
      đổi `<select type>` (1 giá trị) → **multi-select / checkbox roles**. Cho phép
      tick nhiều vai trò cho 1 máy (bếp + hold + bar + hóa đơn).
- [x] **Resolve printer khi in**: `GetPrinterByType(role)` → trả mọi máy **có chứa
      role** đó trong `Roles`. Nếu shop chỉ 1 máy gán tất cả role → mọi tác vụ về máy đó.
- [x] Bổ sung role **`receipt_printer`** (máy in hóa đơn — dùng cho plan 08) vào
      danh sách roles ([Devices.tsx:10](../../frontend/src/pages/Devices.tsx#L10))
      + enum Go ([manager.go:18](../../internal/printer/manager.go#L18)).
- [x] Cảnh báo UI khi **một role chưa máy nào đảm nhiệm** (vd chưa có máy in hóa đơn).

### Task 3 — Bỏ hẳn fallback setting key cố định (backend)
**Đã chốt: bỏ hẳn + migrate 1 lần.**
- [x] Xóa `settingsKeyForType` + nhánh fallback đọc `*_printer_ip` trong
      `GetPrinterByTypeOrSettings` ([manager.go:186-219](../../internal/printer/manager.go#L186)).
      Sau migrate, mọi máy in đều là device record → resolve chỉ qua `Roles`.
- [x] Đổi tên / đơn giản hóa `GetPrinterByTypeOrSettings` → resolve theo role từ
      device đã đăng ký (không còn nhánh settings).
- [x] Kiểm tra các call site
      ([print_helpers.go](../../internal/handler/print_helpers.go),
      [routes.go handlePrintOrder](../../internal/handler/routes.go#L484)) vẫn resolve đúng
      sau khi đổi sang model roles.

### Task 4 — Migrate dữ liệu cũ (backend, chạy 1 lần)
- [x] Migrate: đọc `kitchen_printer_ip` / `hold_printer_ip` / `bar_printer_ip` trong
      bảng `settings` → tạo device record tương ứng (mỗi key → 1 device với role
      tương ứng; nếu trùng IP → gộp thành 1 device nhiều role), rồi **xóa các key cũ**.
- [x] Idempotent: chạy lại không tạo trùng (check theo IP/address).

### Task 5 — Test + tài liệu
- [x] Test: tạo / sửa / xóa / test-print device qua API; resolve printer khi in
      kitchen/hold ticket vẫn đúng sau khi bỏ slot cố định.
- [x] Cập nhật [docs/DEVICES.md](../DEVICES.md) + [docs/device-management.md](../../../docs/device-management.md)
      phản ánh mô hình mới.

---

## Quyết định (đã chốt — không còn câu hỏi treo)

| Vấn đề | Quyết định |
|---|---|
| Mô hình type/role | **Multi-role**: 1 device có nhiều roles. 1 máy gánh tất cả / nhiều máy chia / 1 máy vài role — đều hỗ trợ |
| Fallback `*_printer_ip` | **Bỏ hẳn + migrate 1 lần** |
| Migrate dữ liệu cũ | Có migrate (idempotent), gộp theo IP nếu trùng |
| Quan hệ với plan in hóa đơn | **Tách** — plan 08 lo template, plan 07 lo device setup. `receipt_printer` là role thêm ở đây |

---

## File liên quan

- [frontend/src/pages/Settings.tsx](../../frontend/src/pages/Settings.tsx) — card 3 ô IP cố định (sẽ bỏ)
- [frontend/src/pages/Devices.tsx](../../frontend/src/pages/Devices.tsx) — form tạo device động (giữ + mở rộng)
- [internal/printer/manager.go](../../internal/printer/manager.go) — enum type, `GetPrinterByTypeOrSettings`, `settingsKeyForType`
- [internal/handler/routes.go](../../internal/handler/routes.go) — `POST /api/devices` → `handleAddDevice` → `AddPrinter`
- [internal/handler/print_helpers.go](../../internal/handler/print_helpers.go) — resolve printer khi in
