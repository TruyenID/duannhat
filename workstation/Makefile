NAME := ws-app
# Suy từ git, KHÔNG ghi cứng (#1832). Con số cứng đã lệch: repo ghi 0.2.0 trong
# khi bản phát hành là v0.3.0, và `Taskfile.yml` giữ bản sao thứ hai kèm dòng
# "KEEP IN SYNC" — một lời nhắc thay cho rào, và nó lệch đúng như mọi lời nhắc.
#
# `--match 'v*'` là BẮT BUỘC, không phải cho đẹp: repo có tag `recover/pr-1410-…`,
# nên `git describe --tags` trần trả về `recover/pr-1410-b92584c-84-g027073c` và
# mọi build cục bộ sẽ tự xưng bằng tên một nhánh khôi phục.
#
# Đứng đúng trên tag → `v0.3.0`. Ở giữa hai bản → `v0.3.0-12-gabc123`, TRUY ĐƯỢC
# về commit, thay vì một con số phẳng nói dối là bản đã phát hành. `--dirty` cho
# biết cây có bẩn. CI vẫn đóng dấu tên tag khi build từ tag (#1827) — chỗ này chỉ
# quyết định build cục bộ.
# #2660 — số phiên bản đến từ FILE `VERSION` ở gốc cây, không phải từ git tag.
#
# Bản trước dò semver tag mới nhất. Nó đúng về ý nhưng phụ thuộc việc có người
# nhớ đẩy tag — và đường phát hành đã đứng im 3 ngày đúng vì điều đó: tag thật
# toàn dạng ngày (`v2026.8.10e`) nên không khớp pattern nào, còn lần có người
# đẩy `v0.4.0` cho đúng thì build lại đỏ ở `darwin-arm64`.
#
# Đọc từ file thì mọi lượt build — CI, máy cá nhân, runner — cho ra cùng một số
# mà không cần ai nhớ gì. Hậu tố `-dirty` giữ nguyên: một binary dựng từ cây bẩn
# phải TỰ KHAI điều đó, nếu không "quán chạy bản nào" lại thành câu hỏi không
# trả lời được.
VERSION_FILE := $(shell git rev-parse --show-toplevel 2>/dev/null)/VERSION
BASE_VERSION := $(shell cat $(VERSION_FILE) 2>/dev/null || echo 0.0.0)
VERSION := $(shell if [ -n "$$(git status --porcelain 2>/dev/null)" ]; then echo "v$(BASE_VERSION)-dirty"; else echo "v$(BASE_VERSION)"; fi)

BUILD_DIR := build/bin

LDFLAGS := -s -w -X 'github.com/dxs-platform/workstation-app/internal/config.Version=$(VERSION)'

.PHONY: dev build build-all clean test frontend posweb ci-local

# Source of the pos-web bundle embedded at /pos (#1169). In the umbrella the
# sibling checkout ../web/pos exists; override with POSWEB_SRC=/path for a
# standalone build.
POSWEB_SRC ?= ../web/pos

# Run in dev mode with hot reload
# Port 5173 is Vite's default + the team-standard frontend port.
# Wails3 defaults internally to 9245 — override via -port flag so it
# matches what Vite will actually listen on (saves a config sync).
# Deliberately does NOT set WS_APP_SERVER_PORT here — leaving it unset lets
# config.go read whatever server_port is already saved in config.json (the
# Settings screen's "Cổng server cục bộ" writes there via PATCH /api/config),
# falling back to the compiled-in default (6969) only on a machine with no
# config.json yet. Pinning it here would always win over that saved value
# (see config.go's NewManager: WS_APP_SERVER_PORT beats config.json on
# purpose), which is exactly what made the Settings port field untestable in
# dev — every `make dev` silently reset it back to whatever was hardcoded.
# Trade-off: a stray port an E2E run left in config.json (e.g. 18080) now
# carries over to the next `make dev` instead of being pinned away — accepted
# so the Settings screen's own port change is actually observable in dev.
# WS_APP_CLOUD_URL points dev at the umbrella docker backend — the compiled-in
# default is PRODUCTION (see internal/config/config.go), so without this a
# `make dev` pairing would burn a real pairing code on the live cloud.
# Herd users: WS_APP_CLOUD_URL=https://dxs-product.test make dev
WS_APP_CLOUD_URL ?= http://localhost:5400

dev:
	WS_APP_CLOUD_URL=$(WS_APP_CLOUD_URL) ~/go/bin/wails3 dev -port 5173

# Build for current platform
build: frontend posweb
	go build -ldflags "$(LDFLAGS)" -o $(BUILD_DIR)/$(NAME) ./cmd/workstation/
	@echo "Built $(BUILD_DIR)/$(NAME)"

# Build frontend
frontend:
	cd frontend && pnpm install --ignore-workspace && pnpm run build

# Build + embed the pos-web bundle at /pos (#1169). Built with base=/pos/ via
# pnpm build:workstation, then copied into pos-web/dist (gitignored — only
# .gitkeep is tracked, which we restore after copying so the tree stays clean).
# Fails LOUD when the source is absent so a release never silently ships a
# bundle-less binary; POSWEB_OPTIONAL=1 skips it (keeps /pos serving 404) for a
# Go-only iteration that does not need the real bundle.
posweb:
	@if [ ! -d "$(POSWEB_SRC)" ]; then \
		if [ "$(POSWEB_OPTIONAL)" = "1" ]; then \
			echo "posweb: $(POSWEB_SRC) not found; POSWEB_OPTIONAL=1 -> keeping bundle-less pos-web/dist"; \
			exit 0; \
		fi; \
		echo "posweb: pos-web source not found at $(POSWEB_SRC)."; \
		echo "  Run from the umbrella (../web/pos exists), set POSWEB_SRC=/path/to/pos-web,"; \
		echo "  or POSWEB_OPTIONAL=1 to build a bundle-less binary."; \
		exit 1; \
	fi
	cd "$(POSWEB_SRC)" && pnpm install && pnpm run build:workstation
	rm -rf pos-web/dist && mkdir -p pos-web/dist
	cp -R "$(POSWEB_SRC)/dist/." pos-web/dist/
	@touch pos-web/dist/.gitkeep
	@echo "Embedded pos-web bundle from $(POSWEB_SRC) -> pos-web/dist"

# Build for all platforms
build-all: frontend posweb
	GOOS=darwin GOARCH=arm64 go build -ldflags "$(LDFLAGS)" -o $(BUILD_DIR)/$(NAME)-darwin-arm64 ./cmd/workstation/
	GOOS=darwin GOARCH=amd64 go build -ldflags "$(LDFLAGS)" -o $(BUILD_DIR)/$(NAME)-darwin-amd64 ./cmd/workstation/
	GOOS=linux GOARCH=arm64 go build -ldflags "$(LDFLAGS)" -o $(BUILD_DIR)/$(NAME)-linux-arm64 ./cmd/workstation/
	GOOS=linux GOARCH=amd64 go build -ldflags "$(LDFLAGS)" -o $(BUILD_DIR)/$(NAME)-linux-amd64 ./cmd/workstation/
	GOOS=windows GOARCH=amd64 go build -ldflags "$(LDFLAGS)" -o $(BUILD_DIR)/$(NAME)-windows-amd64.exe ./cmd/workstation/
	@echo "Built all platforms in $(BUILD_DIR)/"

# Clean build artifacts
clean:
	rm -rf $(BUILD_DIR)

# Run tests
test:
	# #1186 — internal/service measures 560-710s; Go's default timeout is 600s,
	# so a bare `go test` panics at random on a loaded machine.
	go test -timeout=40m ./...

# Mirror CI locally before pushing
ci-local:
	@echo "==> go vet"
	@go vet ./...
	@echo "==> gofmt check"
	@unformatted=$$(find . -name '*.go' \
		-not -path './internal/domain/generated/*' \
		-not -path './internal/generated/*' \
		-not -path './vendor/*' | xargs gofmt -l); \
	if [ -n "$$unformatted" ]; then \
		echo "Files need gofmt:"; echo "$$unformatted"; exit 1; \
	fi
	@echo "==> go test -race"
	@go test -race -timeout=40m ./...
	@echo "==> build current platform"
	@$(MAKE) build
