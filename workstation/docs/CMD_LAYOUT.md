# Cấu trúc `cmd/` — 2 binaries, 1 codebase

> Workstation-app có **2 entry points** dùng chung toàn bộ `internal/`. Doc này giải thích cách 2 binary share code, đồng thời tài liệu hóa bug Taskfile thiếu source path → cách fix.

---

## 1. Dependency graph

```
                                      ┌─────────────────────────────┐
                                      │  cmd/workstation/main.go    │
                                      │  package main (entry #1)    │
                                      │                             │
                                      │  - Wails desktop window     │
                                      │  - mDNS advertise           │
                                      │  - LAN HTTP server          │
                                      │  → binary: ws-app           │
                                      └──────────────┬──────────────┘
                                                     │
                                                     │ import
                                                     ▼
                                      ┌──────────────────────────────┐
                                      │  cmd/ws-server/main.go       │
                                      │  package main (entry #2)     │
                                      │                              │
                                      │  - HTTP-only headless        │
                                      │  - không Wails, không webview│
                                      │  - dùng cho CI / soak test   │
                                      │  → binary: ws-server         │
                                      └──────────────┬───────────────┘
                                                     │
                                                     │ import (cả 2 cùng đụng vào)
                                                     ▼
        ┌────────────────────────────────────────────────────────────────────┐
        │  internal/    (project-private, Go compiler enforce)               │
        ├────────────────────────────────────────────────────────────────────┤
        │  ┌─────────────────┐   ┌─────────────────┐   ┌──────────────────┐  │
        │  │ handler/        │   │ service/        │   │ store/           │  │
        │  │ - routes.go     │──▶│ - sync_service  │──▶│ - db.go (SQLite) │  │
        │  │ - server.go     │   │ - sync_pull     │   │ - migrate.go     │  │
        │  │ - auth_middleware│   │ - cloud_verifier│   │ - migrations/    │  │
        │  │ - lan_local.go  │   │ - auth_cache    │   └──────────────────┘  │
        │  │ - ws.go         │   │ - device_seen   │                         │
        │  └─────────────────┘   │ - maintenance   │                         │
        │           │             │ - order_service │                         │
        │           │             │ - print_service │                         │
        │           ▼             └─────────────────┘                         │
        │  ┌─────────────────┐   ┌─────────────────┐   ┌──────────────────┐  │
        │  │ printer/        │   │ audit/          │   │ monitor/         │  │
        │  │ - manager       │   │ - logger        │   │ - load monitor   │  │
        │  │ - escpos/       │   └─────────────────┘   └──────────────────┘  │
        │  └─────────────────┘                                                │
        │  ┌─────────────────┐   ┌─────────────────┐   ┌──────────────────┐  │
        │  │ discovery/      │   │ config/         │   │ domain/          │  │
        │  │ - mDNS          │   │ - config.json   │   │ - types          │  │
        │  └─────────────────┘   └─────────────────┘   │ - generated/     │  │
        │                                              └──────────────────┘  │
        └────────────────────────────────────────────────────────────────────┘
                                                     ▲
                                                     │
                                  ┌──────────────────┴──────────────────┐
                                  │  frontend.go     migrations.go      │
                                  │  (root-level go:embed declarations) │
                                  │  - frontend.go: embed frontend/dist │
                                  │  - migrations.go: embed migrations/ │
                                  └─────────────────────────────────────┘
                                                     │
                                                     ▼
                                  ┌─────────────────────────────────────┐
                                  │  frontend/dist/    (React build)    │
                                  │  migrations/, migrations/omnify/    │
                                  └─────────────────────────────────────┘
```

### Key observations

- Cả `ws-app` và `ws-server` cùng import `internal/handler.New(deps...)` để dựng HTTP server. Logic LAN routes giống hệt nhau.
- Khác biệt **DUY NHẤT** ở 2 file `main.go`:
  - `cmd/workstation/main.go` thêm 1 đoạn `application.New(...).Run()` để mở Wails webview window
  - `cmd/ws-server/main.go` bỏ phần đó, chỉ chạy HTTP server + chờ signal SIGINT/SIGTERM
- `internal/` là project-private — Go compiler từ chối import từ project khác. Đảm bảo business logic không leak ra ngoài.
- `frontend.go` + `migrations.go` ở **root** vì `go:embed` chỉ thấy file trong cùng package. Để embed `frontend/dist/` và `migrations/`, file embed phải nằm ở root (cùng cấp với folder bị embed).

### Build path tương ứng

```
go build -o build/bin/ws-app    ./cmd/workstation/   → build entry #1
go build -o build/bin/ws-server ./cmd/ws-server/     → build entry #2
```

`./cmd/workstation/` và `./cmd/ws-server/` là source path **bắt buộc** vì root package (`workstation/`) **không phải main package** — nó là `package workstation` chỉ chứa embed declarations.

---

## 2. Code shared (% reuse)

| Component | Dòng code | ws-app dùng? | ws-server dùng? |
|---|---|---|---|
| `internal/handler/` | ~3,500 | ✅ | ✅ |
| `internal/service/` | ~4,200 | ✅ | ✅ |
| `internal/store/` | ~500 | ✅ | ✅ |
| `internal/printer/` | ~1,200 | ✅ | ✅ (no-op nếu không cắm printer) |
| `internal/audit/`, `monitor/`, `config/`, `discovery/`, `domain/` | ~2,000 | ✅ | ✅ |
| `frontend.go` (embed) | 10 | ✅ (Wails serve UI) | ✅ (serve `/`) |
| `migrations.go` (embed) | 20 | ✅ | ✅ |
| **Wails-specific code** (webview window) | ~30 dòng | ✅ | ❌ |
| **`main.go` wiring** | 130 / 80 | mỗi binary có 1 file riêng | |

**Reuse ratio: > 99%.** Chỉ khác ~30 dòng Wails wrapper.

---

## 3. Vì sao tách 2 entry points?

| Tình huống | Binary phù hợp | Lý do |
|---|---|---|
| Cửa hàng có nhân viên thu ngân dùng GUI | `ws-app` | UI native, in bill, bấm nút tạo order |
| CI pipeline chạy unit + integration test | `ws-server` | Headless, không cần display, build nhanh |
| Soak test 1h+ trên cloud VM | `ws-server` | VM không có GUI, ws-app sẽ crash khi mở window |
| Docker container (future) | `ws-server` | Container không có X server/GUI |
| Embedded ARM device không màn | `ws-server` | Tiết kiệm dependencies |
| Developer local hot reload | `ws-app` (via `make dev`) | Wails hot reload UI |

Tách 2 binary cho phép **deploy linh hoạt mà không phải maintain 2 codebase**.

---

## 4. Bug Wails3 Taskfile — thiếu source path

> **Status:** ✅ FIXED — đã sửa cả 4 file Taskfile, `wails3 task build` + `task build:server` đều produce binary hợp lệ. Xem section "Cách fix" bên dưới.

### Triệu chứng

```sh
$ make dev
...
task: [darwin:build:native] go build -buildvcs=false -gcflags=all="-l" -o bin/ws-app

task: [darwin:run] bin/ws-app.dev.app/Contents/MacOS/ws-app
  ERROR   task: Failed to run task "run": fork/exec ...ws-app: exec format error
```

### Root cause

3 file Taskfile có bug **cùng class**, thiếu source path `./cmd/workstation/`:

| File | Dòng | Lệnh hiện tại (BUG) | Output |
|---|---|---|---|
| [build/darwin/Taskfile.yml](../build/darwin/Taskfile.yml#L42) | 42 | `go build {{.BUILD_FLAGS}} -o {{.OUTPUT}}` | macOS native — `make dev` crash |
| [build/linux/Taskfile.yml](../build/linux/Taskfile.yml#L53) | 53 | `go build {{.BUILD_FLAGS}} -o {{.OUTPUT}}` | Linux build |
| [build/windows/Taskfile.yml](../build/windows/Taskfile.yml#L45) | 45 | `go build {{.BUILD_FLAGS}} -o "{{.BIN_DIR}}/{{.APP_NAME}}.exe"` | Windows build |
| [build/Taskfile.yml](../build/Taskfile.yml#L138) | 138 | `go build -tags server {{.BUILD_FLAGS}} -o {{.BIN_DIR}}/{{.APP_NAME}}-server{{exeExt}}` | server build |

Khi thiếu source path, `go build` mặc định lấy package ở thư mục hiện tại = root `workstation/`. Root là `package workstation` (NOT `package main`) → `go build` chạy thành công nhưng KHÔNG tạo executable hợp lệ — chỉ tạo file rỗng/library archive. macOS loader cố exec file đó → `exec format error`.

`make build` (Makefile) hoạt động vì có ghi rõ source path:

```makefile
# Makefile:17
go build -ldflags "$(LDFLAGS)" -o $(BUILD_DIR)/$(NAME) ./cmd/workstation/
                                                       ^^^^^^^^^^^^^^^^^
```

### Cách fix

Thêm source path vào cả 4 file Taskfile:

**[build/darwin/Taskfile.yml:42](../build/darwin/Taskfile.yml#L42)** (ws-app native macOS):
```diff
- - go build {{.BUILD_FLAGS}} -o {{.OUTPUT}}
+ - go build {{.BUILD_FLAGS}} -o {{.OUTPUT}} ./cmd/workstation/
```

**[build/linux/Taskfile.yml:53](../build/linux/Taskfile.yml#L53)** (ws-app Linux):
```diff
- - go build {{.BUILD_FLAGS}} -o {{.OUTPUT}}
+ - go build {{.BUILD_FLAGS}} -o {{.OUTPUT}} ./cmd/workstation/
```

**[build/windows/Taskfile.yml:45](../build/windows/Taskfile.yml#L45)** (ws-app Windows):
```diff
- - go build {{.BUILD_FLAGS}} -o "{{.BIN_DIR}}/{{.APP_NAME}}.exe"
+ - go build {{.BUILD_FLAGS}} -o "{{.BIN_DIR}}/{{.APP_NAME}}.exe" ./cmd/workstation/
```

**[build/Taskfile.yml:138](../build/Taskfile.yml#L138)** (ws-server với build tag):
```diff
- - go build -tags server {{.BUILD_FLAGS}} -o {{.BIN_DIR}}/{{.APP_NAME}}-server{{exeExt}}
+ - go build -tags server {{.BUILD_FLAGS}} -o {{.BIN_DIR}}/{{.APP_NAME}}-server{{exeExt}} ./cmd/ws-server/
```

### Tại sao bug ẩn lâu

| Lý do | Detail |
|---|---|
| `make build` được dev dùng nhiều hơn `make dev` | Vì `make dev` cần frontend hot reload, mất 30s khởi động — chậm. `make build` build raw binary nhanh hơn cho test |
| Wails3 alpha-74 mới ra, template chưa hoàn thiện | Wails đang trong alpha cycle, có thể template default thiếu sót |
| Không có CI test cho `make dev` flow | CI chỉ test `go test` + `go build`, không test full Wails dev flow |
| Build cache có thể che giấu | Nếu trước đó đã build thành công vào `bin/ws-app`, lần sau wails3 task chạy thành công nhưng cp binary cũ → exec OK; chỉ fail khi clean state |

### Workaround tạm (không sửa Taskfile)

Khi cần demo gấp (như tình huống hiện tại):

```sh
# Bỏ qua wails3 dev, dùng binary đã build bằng Makefile
make build
./build/bin/ws-app
```

Hoặc dùng `go build` trực tiếp (bypass wails3 toàn bộ):

```sh
cd frontend && pnpm install && pnpm build && cd ..
go build -o build/bin/ws-app ./cmd/workstation/
./build/bin/ws-app
```

### Sửa permanent

PR fix thì cần đụng 4 file Taskfile như trên. Test plan:

1. Clean state: `rm -rf bin/ build/bin/`
2. `make dev` — kỳ vọng Wails window mở thành công
3. `make build` — vẫn phải work
4. `task build:server` — `bin/ws-app-server` chạy được headless

Effort: ~15 phút (4 dòng đổi + test).

---

## 5. Liên quan

- Convention chuẩn Go: https://github.com/golang-standards/project-layout
- Wails v3 alpha docs (alpha-74): nếu template chính thức của Wails có cùng bug, đáng gửi PR upstream
- Sprint 2 backlog: bao gồm fix này (15 phút) + thêm CI test cho `make dev` flow để tránh regression
