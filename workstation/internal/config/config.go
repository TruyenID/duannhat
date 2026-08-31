package config

import (
	"encoding/json"
	"log/slog"
	"os"
	"path/filepath"
	"strconv"
	"sync"
)

var Version = "dev"

const (
	appDirName  = ".ws-app"
	configFile  = "config.json"
	credFile    = "credentials.json"
	defaultPort = 6969
	// defaultCloudAPIURL points at the PRODUCTION cloud. This is the value a
	// freshly-installed workstation pairs against (handleDevicePair reads
	// config.CloudAPIURL, then persists it into settings.cloud_api_url), so it
	// must be the URL real restaurants use — no local default may ship in a
	// release binary.
	//
	// Local development overrides it via WS_APP_CLOUD_URL, which `make dev` /
	// `wails3 task dev` set to the umbrella docker backend (:5400) for you.
	// Herd users: WS_APP_CLOUD_URL=https://dxs-product.test.
	//
	// NOTE: this is only a *default*. Once ~/.ws-app/config.json exists, its
	// `cloud_api_url` wins — changing this constant does not re-point a machine
	// that has already run the app. Delete the key (or the file) to re-seed.
	defaultCloudAPIURL = "https://tempo.godx.jp"
	// Acceptable ServerPort range. Outside this → reset to default on load.
	// Prevents "config drift after E2E test wrote 18080" class of bugs.
	minValidPort = 1024
	maxValidPort = 65535
)

type Config struct {
	StoreName    string `json:"store_name"`
	StoreAddress string `json:"store_address"`
	// plan-043 (T3.7) — the legacy `tax_rate` config feed was REMOVED. The
	// consumption-tax engine now reads the SYNCED shop_settings.tax_rate (per
	// branch, from Cloud) plus the per-line tax_types snapshots; there is no
	// machine-local tax rate anymore. Old config.json files that still carry a
	// `tax_rate` key are ignored (json.Unmarshal drops unknown fields).
	ServerPort        int    `json:"server_port"`
	CloudAPIURL       string `json:"cloud_api_url"`
	BranchID          string `json:"branch_id"`
	OrderNumberPrefix string `json:"order_number_prefix"`
	DatabasePath      string `json:"database_path"`
}

type Manager struct {
	mu     sync.RWMutex
	config Config
	dir    string
}

func NewManager() (*Manager, error) {
	dir, err := configDir()
	if err != nil {
		return nil, err
	}

	if err := os.MkdirAll(dir, 0700); err != nil {
		return nil, err
	}

	m := &Manager{
		dir: dir,
		config: Config{
			ServerPort:  defaultServerPort(),
			CloudAPIURL: defaultCloudURL(),
		},
	}

	if err := m.load(); err != nil && !os.IsNotExist(err) {
		return nil, err
	}

	if m.config.DatabasePath == "" {
		m.config.DatabasePath = filepath.Join(dir, "ws-app.db")
	}

	// Safety: if config.json has an invalid ServerPort (eg. left behind by an
	// E2E test that wrote 18080), reset unless the operator explicitly
	// requested a non-default port via WS_APP_SERVER_PORT for this process.
	if envPort := os.Getenv("WS_APP_SERVER_PORT"); envPort != "" {
		if p, err := strconv.Atoi(envPort); err == nil && p >= minValidPort && p <= maxValidPort {
			m.config.ServerPort = p
		} else {
			slog.Warn("invalid WS_APP_SERVER_PORT; falling back to default",
				"value", envPort, "default", defaultPort)
			m.config.ServerPort = defaultPort
		}
	} else if m.config.ServerPort < minValidPort || m.config.ServerPort > maxValidPort {
		slog.Warn("ServerPort out of valid range; resetting to default",
			"found", m.config.ServerPort, "default", defaultPort)
		m.config.ServerPort = defaultPort
	}

	// Persist the resolved defaults on first run so the on-disk config.json
	// is self-describing (otherwise the file would silently omit cloud_api_url
	// and team members would have to know it falls back from env / constant).
	if m.config.CloudAPIURL == "" {
		m.config.CloudAPIURL = defaultCloudURL()
	}
	if err := m.save(); err != nil {
		return nil, err
	}

	return m, nil
}

// defaultCloudURL resolves the cloud API URL in this order:
//  1. WS_APP_CLOUD_URL env var (per-machine override for local dev / staging)
//  2. defaultCloudAPIURL constant (production cloud)
func defaultCloudURL() string {
	if v := os.Getenv("WS_APP_CLOUD_URL"); v != "" {
		return v
	}
	return defaultCloudAPIURL
}

// defaultServerPort resolves the HTTP server port in this order:
//  1. WS_APP_SERVER_PORT env var (for E2E test / multi-instance dev)
//  2. defaultPort constant (6969 — what the embedded frontend hardcodes)
//
// Out-of-range env values fall back to defaultPort with a Warn log.
func defaultServerPort() int {
	if v := os.Getenv("WS_APP_SERVER_PORT"); v != "" {
		if p, err := strconv.Atoi(v); err == nil && p >= minValidPort && p <= maxValidPort {
			return p
		}
	}
	return defaultPort
}

func (m *Manager) Get() Config {
	m.mu.RLock()
	defer m.mu.RUnlock()
	return m.config
}

func (m *Manager) Update(fn func(*Config)) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	fn(&m.config)
	return m.save()
}

// IsValidPort reports whether p is in the range a workstation may bind to.
// Exported so the HTTP settings handler can reject an out-of-range operator
// input with the same bound this package enforces on load (minValidPort /
// maxValidPort), instead of silently persisting a value load() will later
// reset anyway.
func IsValidPort(p int) bool {
	return p >= minValidPort && p <= maxValidPort
}

func (m *Manager) Dir() string {
	return m.dir
}

func (m *Manager) load() error {
	data, err := os.ReadFile(filepath.Join(m.dir, configFile))
	if err != nil {
		return err
	}
	return json.Unmarshal(data, &m.config)
}

func (m *Manager) save() error {
	data, err := json.MarshalIndent(m.config, "", "  ")
	if err != nil {
		return err
	}
	return os.WriteFile(filepath.Join(m.dir, configFile), data, 0600)
}

func configDir() (string, error) {
	if dir := os.Getenv("WS_APP_CONFIG_DIR"); dir != "" {
		return dir, nil
	}
	home, err := os.UserHomeDir()
	if err != nil {
		return "", err
	}
	return filepath.Join(home, appDirName), nil
}
