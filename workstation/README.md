# workstation — máy trạm tại quán (Go + Wails v3)

Mỗi quán chạy **một** workstation. Nó giữ bản sao dữ liệu của quán trong SQLite,
phục vụ LAN cho pos-web/kiosk/KDS, điều khiển máy in ESC/POS, và đồng bộ hai
chiều với Cloud — **bán được cả khi mất mạng**.

Nằm trong monorepo TempoFast tại `workstation/`. Luật khi sửa code: `CLAUDE.md`
cùng thư mục. Kiến trúc toàn hệ: `CLAUDE.md` ở gốc repo.

## Chạy

```sh
cd workstation
make dev        # wails3 dev, frontend hot-reload ở :5173
make build      # frontend + posweb + go build → build/bin/ws-app
make test       # go test -timeout=40m ./...
```

`make dev` cần `wails3` ở `~/go/bin/`. `make build` chạy `frontend` và `posweb`
trước: bundle pos-web được `go:embed` vào binary để phục vụ tại `/pos`.

Timeout 40 phút của `make test` **không phải phòng xa**: `internal/service` mất
560–710s, còn mặc định của Go là 600s — bỏ cờ đó là panic ngẫu nhiên trên máy
đang tải (#1186).

## Đường vào

| | |
|---|---|
| HTTP/WebSocket cho LAN | `:8080` (đổi trong `config.json`) |
| Swagger UI | `http://localhost:8080/docs` |
| pos-web nhúng sẵn | `http://<ip-máy-trạm>:8080/pos` |
| Quảng bá mDNS | `_ws-app._tcp.local.` |
| Thư mục dữ liệu | `~/.ws-app/` |

## Bố cục

```
cmd/workstation/     điểm vào
internal/
  service/           nghiệp vụ đơn hàng, in ấn, thuế, đồng bộ
  handler/           HTTP + WebSocket
  printer/           ESC/POS (USB + TCP, Shift_JIS)
  config/            đọc/ghi config.json, Version đóng dấu lúc build
frontend/            React 19 — UI của Wails, có lockfile RIÊNG
docs/                tài liệu sâu (DEVICES, RELEASE, CLOUD_API, INTEGRATION_GAPS…)
```

`frontend/` **không** nằm trong pnpm workspace của repo: `pnpm-workspace.yaml`
chỉ liệt `packages/*`, nên phải `pnpm install` bên trong nó (`make frontend` làm
sẵn, kèm `--ignore-workspace`).

## Phiên bản

`VERSION` suy từ `git describe --tags --match 'v*'`, **không ghi cứng**. Bỏ
`--match` là hỏng: repo có tag `recover/pr-1410-…` nên `git describe` trần trả về
tên một nhánh khôi phục và mọi build cục bộ tự xưng bằng tên đó (#1832).

## Đối chiếu với Cloud

Nửa Go của mọi cặp parity tiền/thuế/in ấn sống ở đây; nửa PHP ở `backend/`. Hai
bên gate trên **cùng một file golden** (`internal/service/testdata/*.json` ↔
`backend/tests/Fixtures/*.json`, giống nhau từng byte). Sửa một bên mà quên bên
kia là cổng đỏ — xem `docs/guide/offline-order-evidence.md` ở gốc repo.
