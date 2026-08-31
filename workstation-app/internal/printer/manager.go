package printer

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"log/slog"
	"sort"
	"sync"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
	"github.com/google/uuid"
)

type DeviceType string

const (
	TypeHoldPrinter    DeviceType = "hold_printer"
	TypeKitchenPrinter DeviceType = "kitchen_printer"
	TypeBarPrinter     DeviceType = "bar_printer"
	TypeReceiptPrinter DeviceType = "receipt_printer"
	TypeLabelPrinter   DeviceType = "label_printer" // #253: prep/ingredient labels
	TypeStaffCaller    DeviceType = "staff_caller"
	TypePOS            DeviceType = "pos"
)

// PrinterRoles is the canonical set of roles a physical printer can be
// assigned. A single device may carry several of these at once (multi-role).
var PrinterRoles = []DeviceType{
	TypeKitchenPrinter,
	TypeHoldPrinter,
	TypeBarPrinter,
	TypeReceiptPrinter,
}

type ConnectionType string

const (
	ConnUSB     ConnectionType = "usb"
	ConnNetwork ConnectionType = "network"
)

type DeviceStatus string

const (
	StatusOnline   DeviceStatus = "online"
	StatusOffline  DeviceStatus = "offline"
	StatusError    DeviceStatus = "error"
	StatusPrinting DeviceStatus = "printing"
)

type Device interface {
	ID() string
	Type() DeviceType
	Roles() []DeviceType
	HasRole(role DeviceType) bool
	Name() string
	Connect() error
	Disconnect() error
	Status() DeviceStatus
	IsConnected() bool
}

type DeviceEvent struct {
	DeviceID string       `json:"device_id"`
	Type     string       `json:"type"` // connected, disconnected, error, printing
	Status   DeviceStatus `json:"status"`
}

type DeviceInfo struct {
	ID             string         `json:"id"`
	Type           DeviceType     `json:"type"`
	Roles          []DeviceType   `json:"roles"`
	Name           string         `json:"name"`
	ConnectionType ConnectionType `json:"connection_type"`
	Address        string         `json:"address"`
	Config         string         `json:"config"`
	IsActive       bool           `json:"is_active"`
	Status         DeviceStatus   `json:"status"`
	CreatedAt      string         `json:"created_at"`
	// Origin is 'cloud' (synced from admin-web) or 'local' (added in the WS App).
	// Surfaced so the device list can badge which printers are centrally managed
	// vs the offline-fallback locals.
	Origin string `json:"origin"`
}

type Manager struct {
	mu      sync.RWMutex
	devices map[string]Device
	db      *store.DB
	Events  chan DeviceEvent
}

func NewManager(database *store.DB) *Manager {
	return &Manager{
		devices: make(map[string]Device),
		db:      database,
		Events:  make(chan DeviceEvent, 100),
	}
}

// LoadFromDB populates the manager from the printers table at boot. The map is
// empty and nothing else touches it yet, so this merges into m.devices directly.
// After boot, use Reload — a sync-down that changed the cloud printer set runs
// on the puller goroutine and must swap the map atomically.
func (m *Manager) LoadFromDB() error {
	devices, err := m.readDevices()
	if err != nil {
		return err
	}
	m.mu.Lock()
	for id, p := range devices {
		m.devices[id] = p
	}
	m.mu.Unlock()
	return nil
}

// Reload rebuilds the device map from the printers table and swaps it in under
// the write lock. Called after PullPrinters commits a changed cloud printer set
// so new routing takes effect without a restart. Building the fresh map before
// taking the lock keeps the DB read off the hot path; the swap itself is O(1).
func (m *Manager) Reload() error {
	devices, err := m.readDevices()
	if err != nil {
		return err
	}
	m.mu.Lock()
	m.devices = devices
	m.mu.Unlock()
	return nil
}

// readDevices reads every active printer row into a fresh map. Pure w.r.t.
// manager state (takes no lock, mutates nothing) so both LoadFromDB and Reload
// can share it.
func (m *Manager) readDevices() (map[string]Device, error) {
	rows, err := m.db.Query(`
		SELECT id, type, name, connection_type, address, config, roles, is_active, created_at, origin
		FROM printers WHERE is_active = 1
	`)
	if err != nil {
		return nil, fmt.Errorf("load devices: %w", err)
	}
	defer rows.Close()

	devices := make(map[string]Device)
	for rows.Next() {
		var info DeviceInfo
		var addressNull, configNull, rolesNull, createdAtNull, originNull sql.NullString
		var isActive int
		if err := rows.Scan(&info.ID, &info.Type, &info.Name, &info.ConnectionType, &addressNull, &configNull, &rolesNull, &isActive, &createdAtNull, &originNull); err != nil {
			slog.Error("scan device", "error", err)
			continue
		}
		info.Address = addressNull.String
		configJSON := configNull.String

		var printerConfig PrinterConfig
		if configJSON != "" {
			json.Unmarshal([]byte(configJSON), &printerConfig)
		}

		roles := parseRoles(rolesNull.String, info.Type)
		if !anyPrinterRole(roles) {
			// Non-printer device row (e.g. staff_caller / pos) — skip.
			continue
		}
		printer := NewPrinter(info.ID, info.Name, roles, info.ConnectionType, info.Address, printerConfig)
		printer.createdAt = createdAtNull.String
		printer.origin = originNull.String
		devices[info.ID] = printer
	}

	return devices, nil
}

// ProbeAll refreshes every device's reachability concurrently. Call it after
// LoadFromDB so the device list doesn't open showing everything "offline"
// regardless of whether the printers actually answer. Each Probe is bounded by
// probeTimeout, so this settles in ~2s no matter how many rows exist.
func (m *Manager) ProbeAll() {
	m.probeEach(func(p *Printer) { p.Probe() })
}

// RefreshStale re-probes only the devices whose last result is older than
// maxAge. The device list polls every few seconds, and probing on startup
// alone is not enough: move the workstation to another network and every badge
// keeps reporting the old answer forever.
//
// It returns once every probe has settled, so callers that must not block on a
// dial should run it in a goroutine.
func (m *Manager) RefreshStale(maxAge time.Duration) {
	m.probeEach(func(p *Printer) { p.ProbeIfStale(maxAge) })
}

// probeEach runs fn against every printer concurrently. The device list is
// snapshotted under the lock and released before any dialing, so a slow probe
// never blocks AddPrinter or a lookup.
func (m *Manager) probeEach(fn func(*Printer)) {
	m.mu.RLock()
	devices := make([]*Printer, 0, len(m.devices))
	for _, dev := range m.devices {
		if p, ok := dev.(*Printer); ok {
			devices = append(devices, p)
		}
	}
	m.mu.RUnlock()

	var wg sync.WaitGroup
	for _, p := range devices {
		wg.Add(1)
		go func(p *Printer) {
			defer wg.Done()
			fn(p)
		}(p)
	}
	wg.Wait()
}

// parseRoles decodes the JSON roles column, falling back to a single-element
// list built from the legacy `type` column when roles is empty/invalid.
func parseRoles(rolesJSON string, fallbackType DeviceType) []DeviceType {
	if rolesJSON != "" {
		var roles []DeviceType
		if err := json.Unmarshal([]byte(rolesJSON), &roles); err == nil && len(roles) > 0 {
			return roles
		}
	}
	if fallbackType != "" {
		return []DeviceType{fallbackType}
	}
	return nil
}

// anyPrinterRole reports whether the role list contains at least one role we
// drive as an ESC/POS printer.
func anyPrinterRole(roles []DeviceType) bool {
	for _, r := range roles {
		switch r {
		case TypeKitchenPrinter, TypeHoldPrinter, TypeBarPrinter, TypeReceiptPrinter, TypeLabelPrinter:
			return true
		}
	}
	return false
}

func (m *Manager) AddPrinter(name string, roles []DeviceType, connType ConnectionType, address string, config PrinterConfig) (*Printer, error) {
	if len(roles) == 0 {
		return nil, fmt.Errorf("at least one role is required")
	}
	// Reject SSRF/arbitrary-file addresses before persisting the device. (#85)
	if err := ValidateAddress(connType, address); err != nil {
		return nil, err
	}
	id := uuid.New().String()
	now := time.Now().UTC().Format(time.RFC3339)

	configJSON, _ := json.Marshal(config)
	rolesJSON, _ := json.Marshal(roles)

	// `type` retained for back-compat — primary role goes there.
	primaryType := roles[0]

	_, err := m.db.Exec(`
		INSERT INTO printers (id, type, name, connection_type, address, config, roles, is_active, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
	`, id, primaryType, name, connType, address, string(configJSON), string(rolesJSON), now, now)
	if err != nil {
		return nil, fmt.Errorf("save device: %w", err)
	}

	printer := NewPrinter(id, name, roles, connType, address, config)
	printer.createdAt = now
	printer.origin = "local" // added by hand in the WS App; DB column defaults to 'local' too

	m.mu.Lock()
	m.devices[id] = printer
	m.mu.Unlock()

	return printer, nil
}

// UpdateDeviceRoles replaces the role list of an existing device, persisting it
// and swapping it in memory so routing picks it up without a restart.
//
// This exists so a shop can give one physical device an extra role — e.g. the
// kitchen printer also answering `bar_printer` in a shop with no separate bar
// station. Before this, roles were fixed at creation and the only way to change
// them was delete + re-add, which mints a new device id.
//
// Deliberately does NOT reroute anything by itself: it makes the operator's
// intent explicit in the device config, which is exactly what the dispatcher
// then honours (see Dispatcher.RouteKitchenItem — it never substitutes a
// printer for a role that has no device).
func (m *Manager) UpdateDeviceRoles(id string, roles []DeviceType) error {
	if len(roles) == 0 {
		return fmt.Errorf("at least one role is required")
	}
	for _, r := range roles {
		if !anyPrinterRole([]DeviceType{r}) {
			return fmt.Errorf("unknown role %q", r)
		}
	}

	m.mu.Lock()
	defer m.mu.Unlock()

	dev, ok := m.devices[id]
	if !ok {
		return fmt.Errorf("device not found")
	}
	p, ok := dev.(*Printer)
	if !ok {
		return fmt.Errorf("device is not a printer")
	}

	rolesJSON, _ := json.Marshal(roles)
	// `type` mirrors the primary role for back-compat, same as AddPrinter.
	if _, err := m.db.Exec(
		`UPDATE printers SET roles = ?, type = ?, updated_at = ? WHERE id = ? AND is_active = 1`,
		string(rolesJSON), roles[0], time.Now().UTC().Format(time.RFC3339), id,
	); err != nil {
		return fmt.Errorf("save roles: %w", err)
	}

	// Persist first, then mutate memory — a failed write leaves routing on the
	// old, still-accurate list rather than a value the DB doesn't have.
	p.roles = roles
	return nil
}

func (m *Manager) RemoveDevice(id string) error {
	m.mu.Lock()
	if dev, ok := m.devices[id]; ok {
		dev.Disconnect()
		delete(m.devices, id)
	}
	m.mu.Unlock()

	_, err := m.db.Exec("UPDATE printers SET is_active = 0 WHERE id = ?", id)
	return err
}

func (m *Manager) GetDevice(id string) (Device, bool) {
	m.mu.RLock()
	defer m.mu.RUnlock()
	dev, ok := m.devices[id]
	return dev, ok
}

func (m *Manager) GetPrinter(id string) (*Printer, bool) {
	m.mu.RLock()
	defer m.mu.RUnlock()
	dev, ok := m.devices[id]
	if !ok {
		return nil, false
	}
	printer, ok := dev.(*Printer)
	return printer, ok
}

// GetPrinterByRole returns a registered printer that carries `role` in its role
// list. With the multi-role model a single physical printer can answer for
// several roles, so a shop with one printer assigned every role resolves every
// print job to that one device.
//
// When both a cloud- and a local-origin printer cover the same role, the cloud
// one wins — admin-web is the source of truth, and the local printer is only a
// fallback. Map iteration order is random, so we can't just return the first
// match; scan for a cloud printer, keeping any local match as the fallback.
func (m *Manager) GetPrinterByRole(role DeviceType) *Printer {
	m.mu.RLock()
	defer m.mu.RUnlock()
	var fallback *Printer
	for _, dev := range m.devices {
		printer, ok := dev.(*Printer)
		if !ok || !printer.HasRole(role) {
			continue
		}
		if printer.origin == "cloud" {
			return printer // cloud wins outright
		}
		if fallback == nil {
			fallback = printer
		}
	}
	return fallback
}

// RolesWithoutPrinter returns the printer roles that no registered device
// currently covers — drives the "role chưa máy nào đảm nhiệm" UI warning.
func (m *Manager) RolesWithoutPrinter() []DeviceType {
	m.mu.RLock()
	defer m.mu.RUnlock()

	covered := make(map[DeviceType]bool)
	for _, dev := range m.devices {
		for _, r := range dev.Roles() {
			covered[r] = true
		}
	}

	var missing []DeviceType
	for _, role := range PrinterRoles {
		if !covered[role] {
			missing = append(missing, role)
		}
	}
	return missing
}

func (m *Manager) ListDevices() []DeviceInfo {
	m.mu.RLock()
	defer m.mu.RUnlock()

	var list []DeviceInfo
	for _, dev := range m.devices {
		info := DeviceInfo{
			ID:     dev.ID(),
			Type:   dev.Type(),
			Roles:  dev.Roles(),
			Name:   dev.Name(),
			Status: dev.Status(),
		}
		if printer, ok := dev.(*Printer); ok {
			info.ConnectionType = printer.connectionType
			info.Address = printer.address
			info.CreatedAt = printer.createdAt
			info.Origin = printer.origin
		}
		list = append(list, info)
	}
	// m.devices is a map and Go randomises map iteration, so an unsorted list
	// came back in a different order on every poll — the UI reshuffled every
	// 5s and a device you just added landed in a random row, which reads as
	// "nothing was created". Newest first so a new device shows up on top;
	// ID breaks ties (same-second adds) to keep the order total.
	sort.Slice(list, func(i, j int) bool {
		if list[i].CreatedAt != list[j].CreatedAt {
			return list[i].CreatedAt > list[j].CreatedAt
		}
		return list[i].ID < list[j].ID
	})
	return list
}

func (m *Manager) ConnectAll() {
	m.mu.RLock()
	defer m.mu.RUnlock()

	for _, dev := range m.devices {
		if err := dev.Connect(); err != nil {
			slog.Warn("connect device failed", "device", dev.Name(), "error", err)
		} else {
			slog.Info("device connected", "device", dev.Name())
		}
	}
}

func (m *Manager) DisconnectAll() {
	m.mu.RLock()
	defer m.mu.RUnlock()

	for _, dev := range m.devices {
		dev.Disconnect()
	}
}
