// Package main provides a headless HTTP-server-only entry point for
// integration testing and soak runs. It wires all the same services as the
// Wails desktop binary but skips the webview, so it can run in CI and on
// headless machines.
//
// Usage:
//
//	./ws-server          # serves on :6969, config from ~/.ws-app/
//	WS_APP_CONFIG_DIR=/tmp/soak ./ws-server
package main

import (
	"io/fs"
	"log"
	"log/slog"
	"os"
	"os/signal"
	"syscall"

	workstation "github.com/dxs-platform/workstation-app"
	"github.com/dxs-platform/workstation-app/internal/audit"
	"github.com/dxs-platform/workstation-app/internal/cloudhttp"
	"github.com/dxs-platform/workstation-app/internal/config"
	"github.com/dxs-platform/workstation-app/internal/discovery"
	"github.com/dxs-platform/workstation-app/internal/handler"
	"github.com/dxs-platform/workstation-app/internal/monitor"
	"github.com/dxs-platform/workstation-app/internal/printer"
	"github.com/dxs-platform/workstation-app/internal/service"
	"github.com/dxs-platform/workstation-app/internal/store"
)

func main() {
	// Load .env BEFORE NewManager: the config manager reads WS_APP_* during
	// construction and persists the resolved values into config.json on first
	// run. Loading afterwards would silently have no effect on a fresh install.
	envFile := config.LoadDotEnv()

	// Initialize config
	cfg, err := config.NewManager()
	if err != nil {
		log.Fatal("config:", err)
	}
	slog.Info("ws-server starting", "version", config.Version, "config_dir", cfg.Dir(), "env_file", envFile)

	// Wire omnify-generated migrations
	store.OmnifyMigrations = &workstation.OmnifyMigrations

	// Open database (runs all migrations)
	appConfig := cfg.Get()
	database, err := store.Open(appConfig.DatabasePath)
	if err != nil {
		log.Fatal("database:", err)
	}
	defer database.Close()
	slog.Info("database opened", "path", appConfig.DatabasePath)

	// #2901 — từ đây trở đi `slog` có chỗ GHI LẠI, không chỉ có stderr.
	//
	// Trước bản này máy trạm không có logger dùng chung: `slog` mặc định ra
	// stderr của một tiến trình Windows không ai nhìn, và điều tra một sự cố ở
	// quán nghĩa là ngồi trước chính máy đó. Handler bọc chuyển tiếp NGUYÊN VẸN
	// xuống stderr rồi ghi thêm những dòng `info+` ĐÃ QUA allowlist vào SQLite.
	//
	// Đăng ký `defer` NGAY SAU `database.Close()` là cố ý: defer chạy ngược thứ
	// tự, nên lượt xả cuối cùng xảy ra TRƯỚC khi database đóng.
	stopLogRecorder := service.InstallLogRecorder(database)
	defer stopLogRecorder()

	// #2123 tầng D — request đi Cloud MANG TOKEN CỦA CHÍNH MÁY TRẠM thì gắn
	// X-App-Version, để Cloud trả lời được "còn bao nhiêu máy trạm chạy bản chưa
	// biết đọc sổ?" (nửa Cloud ở #2142).
	//
	// Phải chạy SAU khi mở DB (getter đọc bảng settings) nhưng TRƯỚC client đầu
	// tiên. Cổng so token: máy trạm cũng chuyển tiếp token của kiosk/kds/tms qua
	// CloudVerifier, và gắn header lên những request đó sẽ ghi đè app_version
	// của CHÍNH thiết bị kia (#2145 vòng 1).
	cloudhttp.InstallVersionHeader(func() string {
		var token string
		_ = database.QueryRow("SELECT COALESCE(value, '') FROM settings WHERE key = 'device_token'").Scan(&token)

		return token
	})

	// Initialize services. plan-043 (T3.7) — no machine-local tax rate feed;
	// the engine reads synced shop_settings.tax_rate + per-line tax_types
	// snapshots. 0 = no legacy fallback (never a hardcoded 10%).
	orderEngine := service.NewOrderEngine(database)
	deviceManager := printer.NewManager(database)

	if err := deviceManager.LoadFromDB(); err != nil {
		slog.Warn("load devices failed", "error", err)
	}
	// Devices load as "offline" and nothing dials until the first print, so
	// without this the device list opens claiming every printer is down.
	go deviceManager.ProbeAll()

	syncEngine := service.NewSyncEngine(database, appConfig.CloudAPIURL, func(status service.ConnStatus) {
		slog.Info("connectivity changed", "status", status)
	})
	syncEngine.Start()
	defer syncEngine.Stop()

	auditLogger := audit.NewLogger(database)
	loadMonitor := monitor.New(nil)

	// Extract embedded frontend assets
	frontendFS, err := fs.Sub(workstation.FrontendAssets, "frontend/dist")
	if err != nil {
		log.Fatal("frontend assets:", err)
	}
	// Embedded pos-web bundle served at /pos (#1169). Placeholder is always
	// present, so this only fails on a structurally broken embed.
	posFS, err := fs.Sub(workstation.PosWebAssets, "pos-web/dist")
	if err != nil {
		log.Fatal("pos-web assets:", err)
	}

	// Start local HTTP server
	lanServer := handler.New(handler.Dependencies{
		Config:    cfg,
		DB:        database,
		Orders:    orderEngine,
		Devices:   deviceManager,
		Sync:      syncEngine,
		Audit:     auditLogger,
		Monitor:   loadMonitor,
		Port:      appConfig.ServerPort,
		Assets:    frontendFS,
		PosAssets: posFS,
	})
	go func() {
		if err := lanServer.Start(); err != nil {
			slog.Error("local server failed", "error", err)
			os.Exit(1)
		}
	}()
	defer lanServer.Stop()

	lanIP := handler.GetLANAddress()
	slog.Info("local server started", "address", lanIP, "port", appConfig.ServerPort)

	// Publish the connect URL to <config dir>/endpoint.json so an installer,
	// service wrapper or support script can read "what do I type into the
	// tablet?" without scraping logs. Refreshed on a ticker because a DHCP
	// lease change moves the address out from under whoever wrote it down.
	handler.StartEndpointExporter(cfg.Dir(), appConfig.ServerPort, config.Version)

	// Start mDNS advertisement
	mdns, err := discovery.NewMDNSAdvertiser(
		appConfig.ServerPort,
		appConfig.StoreName,
		appConfig.BranchID,
		config.Version,
		lanIP,
	)
	if err != nil {
		slog.Warn("mDNS failed to start", "error", err)
	} else {
		defer mdns.Stop()
	}

	// Block until SIGTERM / SIGINT
	quit := make(chan os.Signal, 1)
	signal.Notify(quit, syscall.SIGINT, syscall.SIGTERM)
	sig := <-quit
	slog.Info("shutting down", "signal", sig)
}
