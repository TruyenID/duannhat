# Workstation Demo Runbook

> Hai kịch bản: **A — Seed only** (offline, 5 phút setup) | **B — Cloud paired** (full architecture, 15 phút setup).
> Demo timeline: 5–7 phút. Chuẩn bị: ≥30 phút trước giờ demo.

---

## 0. Quyết định nhanh

| Sếp focus | Chọn |
|---|---|
| Sales / UX / "máy bán hàng làm được gì" | **A** |
| Tech / architecture / "HQ điều khiển cửa hàng thế nào" | **B** |
| Vừa A vừa B | **B**, fallback **A** nếu cloud lỗi |

---

## 1. Chuẩn bị chung (làm 1 lần, ≥30 phút trước demo)

### 1.1 Build workstation — chọn 1 trong 3 cách

| Lệnh | Output | Use case |
|---|---|---|
| `make dev` | Wails window + hot reload | **Demo cho sếp** — UI native, đổi code reload tức thì |
| `make build` | `build/bin/ws-app` (binary built sẵn) | **Demo ổn định** — chạy file đã build, ít rủi ro nhất |
| `go build -o build/bin/ws-server ./cmd/ws-server/` | `build/bin/ws-server` (headless) | **Fallback** — nếu Wails / pnpm có issue → demo qua browser |

> **Recommended cho demo**: `make build` rồi `./build/bin/ws-app`. Lý do: binary built sẵn ổn định hơn `make dev` (không bị surprise nếu dev mode rebuild giữa chừng). `make dev` chỉ nên dùng khi đang edit code trực tiếp.

```sh
cd /Users/phamduyanh1910/Documents/famgia/godx/godx-tempo/workstation-app
make build
ls -la build/bin/ws-app          # phải có file ~25MB
```

**Build nhanh hơn (chỉ Go, dùng frontend/dist cũ — sau khi đã `make build` ≥1 lần):**

```sh
go build -ldflags "-s -w -X 'github.com/dxs-platform/workstation-app/internal/config.Version=0.1.0'" \
    -o build/bin/ws-app ./cmd/workstation/
# → 10 giây thay vì 5-10 phút (skip pnpm install + vite build)
```

Dùng khi anh đổi Go code nhưng frontend không đổi.

> **Lưu ý**: lệnh `go build` BẮT BUỘC phải có `./cmd/workstation/` ở cuối — không có path sẽ build root package (không phải main) → binary lỗi `exec format error`. Xem [docs/CMD_LAYOUT.md](CMD_LAYOUT.md) section 4.

### 1.2 Cloud API URL — default + override

Workstation tự gen `~/.ws-app/config.json` lần đầu với:

```json
{
  "server_port": 8080,
  "cloud_api_url": "http://localhost:5400",   ← umbrella docker default
  ...
}
```

Nếu anh dùng **Herd** (`https://dxs-product.test`) hoặc staging/prod, override bằng env var TRƯỚC lần chạy đầu:

```sh
export WS_APP_CLOUD_URL="https://dxs-product.test"   # Herd
# hoặc
export WS_APP_CLOUD_URL="https://api.staging.example.com"   # staging
./build/bin/ws-app
```

Hoặc đơn giản là **sửa thẳng `~/.ws-app/config.json`** sau lần chạy đầu (workstation đọc lại mỗi lần restart).

### 1.3 Smoke check

```sh
rm -rf ~/.ws-app                 # clean state
./build/bin/ws-app &
sleep 3
curl -s http://localhost:8080/api/status
# Expected: {"version":"...","status":"running","port":8080,...}
pkill ws-app
```

### 1.3 Tắt mọi process workstation/soak còn sót

```sh
pkill -f "ws-app\|ws-server" 2>/dev/null
lsof -ti :8080 | xargs kill -9 2>/dev/null
```

### 1.4 Nếu muốn dùng `make dev` (hot reload mode)

`make dev` mở Wails window cùng với Vite dev server cho frontend (port **5173** — team-standard). Hot reload khi anh edit file React.

> **Note**: Wails3 alpha-74 default port là 9245, nhưng team thống nhất dùng 5173 (Vite default). [Makefile:10-15](../Makefile#L10-L15) override qua `wails3 dev -port 5173` — không cần đụng `frontend/package.json` hay `vite.config.ts`. Nếu cần port khác cho dev cá nhân: `~/go/bin/wails3 dev -port <khác>`.

```sh
make dev
# → mở cửa sổ Wails app
# → đổi file frontend/src/* → tự reload
# → đổi file Go cần Ctrl+C rồi chạy lại
```

**Khi nào KHÔNG dùng `make dev` cho demo:**
- Lần đầu chạy mất 30s-1 phút (pnpm install + bindings generate + vite build)
- Nếu Vite dev server crash giữa demo, UI sẽ trắng → awkward
- Yêu cầu `wails3` CLI đã cài (`go install github.com/wailsapp/wails/v3/cmd/wails3@latest`)

**Khi nào nên dùng `make dev`:**
- Đang phát triển feature, cần thấy đổi UI ngay
- Demo nội bộ dev team
- Sếp muốn xem flow "đổi 1 dòng code → reload tức thì" (impressive nhưng risky)

---

## 2. Kịch bản A — Seed Only (offline demo)

**Story**: "Đây là máy POS chạy local, mất mạng vẫn bán bình thường."

### 2.1 Khởi động (1 lệnh)

```sh
rm -rf ~/.ws-app
./build/bin/ws-app
# → Wails window mở tự động, dashboard hiện ra
```

### 2.2 Seed menu (1 lần, trước khi demo)

```sh
curl -X POST http://localhost:8080/api/menu/seed
# → tạo 10 món: Phở Bò, Cơm Gà, Bún Chả, Bánh Mì, Cà Phê Sữa Đá, Bia Saigon, ...
```

### 2.3 Kịch bản demo (5 phút)

| Bước | Click trong app | Talking point |
|---|---|---|
| 1 | Dashboard | "Local-first POS — chạy hoàn toàn offline cũng OK" |
| 2 | Menu tab → 10 món | "Menu sync từ HQ xuống, edit local đẩy lên cloud" |
| 3 | New Order: bàn A1 + Phở Bò + Cà Phê | "Order vào SQLite ngay, enqueue lên cloud — mất mạng tự retry" |
| 4 | Order → status: open → preparing → ready → served | "State machine nghiêm ngặt, audit log đầy đủ" |
| 5 | Order → POST payment cash → status: paid | "Thanh toán xong tự động in bill nếu có printer" |
| 6 | Reports → Daily | "Doanh thu hôm nay, top items — cho kế toán cross-check với cloud" |
| 7 | Sync tab | "Offline-first: pending queue retry tự động khi reconnect" |

---

## 3. Kịch bản B — Cloud Paired (full architecture demo)

**Story**: "HQ điều khiển menu → tự động xuống từng cửa hàng → workstation in bill → order về HQ."

### 3.1 Cloud backend (Laravel, terminal 1)

```sh
cd /Users/phamduyanh1910/Documents/famgia/godx/godx-tempo
docker compose up -d                                          # backend lên :5400, mysql :3307
docker compose exec app php artisan migrate:fresh --seed --force
# → cloud DB sẵn sàng với seed brand + branch + admin user
```

Verify: `curl http://localhost:5400/api/health` (hoặc check `http://localhost:5400` trên browser).

### 3.2 Admin Web (Next.js, terminal 2)

```sh
cd /Users/phamduyanh1910/Documents/famgia/godx/godx-tempo/admin-web
pnpm dev
# → http://localhost:5430
```

Login với credential mặc định (kiểm tra `backend/database/seeders/`).

### 3.3 Tạo Device + lấy Pairing Code (admin web)

Trong admin:

1. Login → chọn Brand (vd. "DXS")
2. Brand → **Devices** → **Add Device**
3. Type: `workstation`, Name: "Demo Workstation", Branch: chọn 1 branch
4. Submit → admin hiện ra **pairing code 6 ký tự** (vd. `ABC123`), TTL 15 phút

> Copy code này — sẽ paste vào workstation ở bước tiếp.

### 3.4 Workstation pair (terminal 3)

```sh
rm -rf ~/.ws-app
./build/bin/ws-app
```

Trong app:
1. Settings → set `cloud_api_url = http://localhost:5400`
2. Settings → **Pair Device** → nhập `ABC123` (code từ admin)
3. Đợi vài giây → status hiện ra: "Paired as Demo Workstation, branch=..."

> Background: workstation gọi `POST /api/v1/workstation/pair`, nhận về `device_token` 64-ký-tự, lưu vào SQLite settings.

### 3.5 SyncPuller kéo data thật (đợi 60s đầu tiên)

Sau ~60 giây kể từ lúc pair:
- Menu tab → menu thật của branch (KHÔNG còn là 10 món seed)
- Settings → tax_rate, currency lấy từ cloud
- TMS → zones + tables của branch

> **✅ Sprint 3 đã fix permanent** — `MockDataSeeder` set `timezone='Asia/Tokyo'` cho mọi branch Nhật. Nếu re-seed (`docker compose exec app php artisan migrate:fresh --seed --force`), menu pull work ngay. Historical context: [docs/bugs/2026-05-21-branch-timezone-null.md](bugs/2026-05-21-branch-timezone-null.md).

### 3.6 Kịch bản demo (7 phút)

| Bước | Hành động | Talking point |
|---|---|---|
| 1 | Admin web: Brand → Menu → đổi giá Phở Bò từ 90000 → 95000 → Save | "HQ vừa đổi giá menu" |
| 2 | Đợi ≤60s, mở Workstation → Menu tab → giá đã cập nhật | "Workstation auto-pull menu mỗi phút — production sẽ thêm Reverb push để realtime" |
| 3 | Workstation: tạo order Phở Bò (giá mới) → paid | "Order vào local SQLite — sync queue enqueue" |
| 4 | Đợi vài giây, refresh Admin web: Orders → thấy order mới | "Order tự động đẩy lên cloud, HQ thấy realtime" |
| 5 | Workstation: tắt internet (turn off WiFi) → tạo thêm 2 order | "Mất mạng vẫn bán được" |
| 6 | Bật lại internet → Sync tab thấy pending=2 → vài giây sau pending=0 | "Reconnect auto-flush sync queue" |
| 7 | Admin web: Orders → cả 2 order offline đã lên | "Idempotency key chống dup, đảm bảo exactly-once" |

---

## 4. Kịch bản Unpair / Switch Branch (Sprint 3)

**Story**: "Workstation chuyển sang branch khác, hoặc decommission máy cũ."

### 4.1 Click "Unpair Workstation" trong Settings

Trong app:
- Settings → cuộn xuống "Danger Zone"
- Click **Unpair Workstation** (button đỏ destructive)
- Confirm dialog: "Tất cả data local (orders, menu, devices) sẽ bị xoá"
- Sau 1-2 giây: UI navigate về Pairing screen

### 4.2 Verify local sạch

```sh
sqlite3 ~/.ws-app/ws-app.db <<'SQL'
SELECT 'settings non-empty:'||COUNT(*) FROM settings WHERE value != '' AND key != 'cloud_api_url';
SELECT 'orders:'||COUNT(*) FROM orders;
SELECT 'menu_items:'||COUNT(*) FROM menu_items;
SELECT 'devices:'||COUNT(*) FROM devices;
SELECT 'auth_token_cache:'||COUNT(*) FROM auth_token_cache;
SQL
# → tất cả = 0 (trừ cloud_api_url được giữ)
```

### 4.3 Verify cloud thấy device revoked

Admin web → Devices → workstation device status `revoked`, token cleared.

### 4.4 Pair branch mới (nếu muốn switch)

Settings → Pair Device → nhập pairing code của branch mới. Workstation rebuild fresh state.

**Talking point**: "Mỗi cửa hàng có 1 workstation duy nhất. Re-pair = clean reset toàn bộ — cloud biết device unpaired, không impersonate được nữa, local sạch."

---

## 5. Talking points kỹ thuật (nếu sếp hỏi sâu)

| Câu hỏi | Trả lời ngắn |
|---|---|
| Offline thì sao? | SQLite local owns data, sync_queue retry với exponential backoff, idempotency key chống dup |
| Đa cửa hàng? | Mỗi workstation pair với 1 branch_id, không lẫn data branch khác |
| Bảo mật? | Device token bcrypt server-side, LAN routes phải Bearer auth (Sprint 1) |
| Crash / mất điện? | WAL mode + checkpoint mỗi giờ, snapshot backup mỗi 6h giữ 7 bản |
| Hiệu năng? | SQLite pure-Go no-CGO, soak test 60 phút 18,905 orders WAL <50MB |
| Kiosk / tablet kết nối? | mDNS auto-discovery trong LAN, Bearer token + cache 5 phút |
| Update menu HQ → cửa hàng mất bao lâu? | Hiện tại ≤60s (poll), Sprint 2 thêm Reverb WebSocket cho realtime |

---

## 6. Gotchas — tránh trong demo

| Tránh | Lý do |
|---|---|
| `make build` lần đầu trước mặt sếp | Pnpm fetch có thể chậm 5–10 phút, awkward |
| Mở Swagger UI nếu sếp non-tech | Quá technical, mất focus |
| Vào tab Devices khi chưa cắm máy in | UI hiện "no printer" không impressive |
| Demo trên WiFi yếu (B) | Cloud sync sẽ trễ, dễ tưởng lỗi |
| Quên reset DB | Order cũ tích lũy, dashboard rối |

---

## 7. Cleanup sau demo

```sh
# Kill all
pkill -f "ws-app\|ws-server" 2>/dev/null
docker compose -f /Users/phamduyanh1910/Documents/famgia/godx/godx-tempo/docker-compose.yml down

# Reset workstation state
rm -rf ~/.ws-app

# Backup demo artifacts nếu muốn keep
cp ~/.ws-app/ws-app.db /tmp/demo-$(date +%Y%m%d-%H%M).db 2>/dev/null
```

---

## 8. Fallback plan — nếu cloud crash giữa demo (B)

Nếu admin web hoặc Laravel chết:
1. Đóng admin web → tiếp tục story trên workstation
2. **Talking point chuyển hướng**: "Workstation hoàn toàn tự chủ — cloud xuống không ảnh hưởng. Order vẫn vào local, sẽ sync khi cloud lên lại."
3. Tạo order trên workstation → demo offline path tiếp
4. **Kết luận**: "Đó cũng là lý do thiết kế offline-first"

→ Cloud crash thực ra LÀ điểm bán hàng, không phải lỗi.

---

## 9. Pre-demo checklist (15 phút trước)

- [ ] `make build` xong, binary có trong `build/bin/`
- [ ] Smoke test passed (`curl /api/status` trả 200)
- [ ] Không có process cũ trên port 8080
- [ ] Nếu B: docker + admin web up, login admin OK, pairing code tested 1 lần
- [ ] Backup `~/.ws-app/` sạch (`rm -rf ~/.ws-app`)
- [ ] Battery / power adapter ✓
- [ ] HDMI / màn chiếu test ✓
- [ ] Screen recording (optional, để xem lại)

---

## 10. Tài liệu liên quan

- [docs/ARCHITECTURE.md](ARCHITECTURE.md) — high-level architecture
- [docs/SYNC.md](SYNC.md) — sync 2-chiều detail
- [docs/CLOUD_API.md](CLOUD_API.md) — Cloud REST API spec
- [docs/plan/03-sprint-1-ops-hardening.md](plan/03-sprint-1-ops-hardening.md) — Sprint 1 hardening
