package config

import (
	"os"
	"path/filepath"
	"testing"
)

func TestNewManagerResetsInvalidServerPort(t *testing.T) {
	dir := t.TempDir()
	t.Setenv("WS_APP_CONFIG_DIR", dir)

	// Seed config.json với port lạ (giả lập E2E test ghi vào rồi quên reset).
	bogus := `{"server_port": 18080, "cloud_api_url": "http://localhost:5400"}`
	if err := os.WriteFile(filepath.Join(dir, "config.json"), []byte(bogus), 0o600); err != nil {
		t.Fatalf("seed config: %v", err)
	}

	// 18080 IS in valid range [1024-65535], so should be KEPT (not invalid).
	m, err := NewManager()
	if err != nil {
		t.Fatalf("NewManager: %v", err)
	}
	if got := m.Get().ServerPort; got != 18080 {
		t.Fatalf("expected 18080 (valid range), got %d", got)
	}
}

func TestNewManagerResetsOutOfRangePortInConfig(t *testing.T) {
	dir := t.TempDir()
	t.Setenv("WS_APP_CONFIG_DIR", dir)

	// Port < 1024 (privileged) → out of valid range → reset.
	bogus := `{"server_port": 80, "cloud_api_url": "http://localhost:5400"}`
	if err := os.WriteFile(filepath.Join(dir, "config.json"), []byte(bogus), 0o600); err != nil {
		t.Fatalf("seed config: %v", err)
	}

	m, err := NewManager()
	if err != nil {
		t.Fatalf("NewManager: %v", err)
	}
	if got := m.Get().ServerPort; got != defaultPort {
		t.Fatalf("expected reset to %d, got %d", defaultPort, got)
	}
}

func TestNewManagerEnvOverridesConfig(t *testing.T) {
	dir := t.TempDir()
	t.Setenv("WS_APP_CONFIG_DIR", dir)
	t.Setenv("WS_APP_SERVER_PORT", "9090")

	// Config có port khác — env override phải win.
	bogus := `{"server_port": 18080, "cloud_api_url": "http://localhost:5400"}`
	_ = os.WriteFile(filepath.Join(dir, "config.json"), []byte(bogus), 0o600)

	m, err := NewManager()
	if err != nil {
		t.Fatalf("NewManager: %v", err)
	}
	if got := m.Get().ServerPort; got != 9090 {
		t.Fatalf("expected env override 9090, got %d", got)
	}
}

func TestNewManagerRejectsInvalidEnvFallback(t *testing.T) {
	dir := t.TempDir()
	t.Setenv("WS_APP_CONFIG_DIR", dir)
	t.Setenv("WS_APP_SERVER_PORT", "99999") // > maxValidPort

	m, err := NewManager()
	if err != nil {
		t.Fatalf("NewManager: %v", err)
	}
	if got := m.Get().ServerPort; got != defaultPort {
		t.Fatalf("expected fallback to %d, got %d", defaultPort, got)
	}
}

func TestNewManagerFreshInstallUsesDefault(t *testing.T) {
	dir := t.TempDir()
	t.Setenv("WS_APP_CONFIG_DIR", dir)

	m, err := NewManager()
	if err != nil {
		t.Fatalf("NewManager: %v", err)
	}
	cfg := m.Get()
	if cfg.ServerPort != defaultPort {
		t.Fatalf("ServerPort: expected %d, got %d", defaultPort, cfg.ServerPort)
	}
	if cfg.CloudAPIURL != defaultCloudAPIURL {
		t.Fatalf("CloudAPIURL: expected %q, got %q", defaultCloudAPIURL, cfg.CloudAPIURL)
	}
}
