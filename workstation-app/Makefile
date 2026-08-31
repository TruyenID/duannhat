NAME := ws-app
VERSION := 0.2.0
BUILD_DIR := build/bin

LDFLAGS := -s -w -X 'github.com/dxs-platform/workstation-app/internal/config.Version=$(VERSION)'

.PHONY: dev build build-all clean test frontend ci-local

# Run in dev mode with hot reload
# Port 5173 is Vite's default + the team-standard frontend port.
# Wails3 defaults internally to 9245 — override via -port flag so it
# matches what Vite will actually listen on (saves a config sync).
# WS_APP_SERVER_PORT=8080 pins the Go HTTP server to the port the frontend
# hardcodes in lib/api.ts, preventing config.json drift from E2E runs.
# WS_APP_CLOUD_URL points dev at the umbrella docker backend — the compiled-in
# default is PRODUCTION (see internal/config/config.go), so without this a
# `make dev` pairing would burn a real pairing code on the live cloud.
# Herd users: WS_APP_CLOUD_URL=https://dxs-product.test make dev
WS_APP_CLOUD_URL ?= http://localhost:5400

dev:
	WS_APP_SERVER_PORT=8080 WS_APP_CLOUD_URL=$(WS_APP_CLOUD_URL) ~/go/bin/wails3 dev -port 5173

# Build for current platform
build: frontend
	go build -ldflags "$(LDFLAGS)" -o $(BUILD_DIR)/$(NAME) ./cmd/workstation/
	@echo "Built $(BUILD_DIR)/$(NAME)"

# Build frontend
frontend:
	cd frontend && pnpm install --ignore-workspace && pnpm run build

# Build for all platforms
build-all: frontend
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
	go test ./...

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
	@go test -race -timeout=5m ./...
	@echo "==> build current platform"
	@$(MAKE) build
