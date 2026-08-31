package printer

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"log/slog"
	"sort"
	"strings"
	"sync"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
	"github.com/google/uuid"
)

type DeviceType string

const (
	TypeHallPrinter    DeviceType = "hall_printer"
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
	TypeHallPrinter,
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
		SELECT id, type, name, connection_type, address, config, roles, is_active, created_at, origin, model_profile
		FROM printers WHERE is_active = 1
	`)
	if err != nil {
		return nil, fmt.Errorf("load devices: %w", err)
	}
	defer rows.Close()

	devices := make(map[string]Device)
	for rows.Next() {
		var info DeviceInfo
		var addressNull, configNull, rolesNull, createdAtNull, originNull, profileNull sql.NullString
		var isActive int
		if err := rows.Scan(&info.ID, &info.Type, &info.Name, &info.ConnectionType, &addressNull, &configNull, &rolesNull, &isActive, &createdAtNull, &originNull, &profileNull); err != nil {
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
		// plan-052 §3b — an absent/blank/corrupt profile resolves to
		// escpos_generic rather than failing the row: a machine nobody has
		// described still has to print (P-29).
		//
		// #3059 — nhưng "chưa ai mô tả máy" KHÔNG có nghĩa là chưa ai nói gì:
		// `printers.cut_type` là lời khai của người lắp máy, đồng bộ từ Cloud
		// và hiện trên màn cấu hình. Trước bản này nó không đổi được gì, nên
		// `none` vẫn nhả lệnh cắt và `partial` vẫn cắt rời.
		//
		// Profile THẬT vẫn thắng — nó giàu hơn và biết máy nói tiếng nào.
		printer.profile = ProfileForRow(profileNull.String, printerConfig.CutType)
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

// probeEach runs fn against every printer concurrently, EXCEPT it collapses
// printers that share a (connectionType, address) down to one dial.
//
// Some ESC/POS printers (confirmed: Star mC-Print3) accept only one TCP
// connection at a time on the RAW port. A shop that registers the same
// physical printer twice — once mirrored from Cloud, once added by hand
// locally, both at the same address — used to have both copies probed
// concurrently every cycle: whichever dial won left the other seeing
// "connection refused" from a printer that was actually fine, so the two
// badges flapped online/offline at random depending on goroutine scheduling.
//
// Only the address's designated printer (cloud origin preferred, matching
// GetPrinterByRole's routing preference) actually dials. When that leader is
// a cloud printer, every other printer at the address is stamped OFFLINE
// outright rather than sharing the leader's result — GetPrinterByRole routes
// every print job to the cloud printer as long as it's reachable, so a local
// duplicate at the same address is never actually the one in rotation and
// must not badge as "connected" (that reads as two independent working
// printers when there is only one, and physically only one dial can hold the
// port at a time anyway). When the leader is local (no cloud printer covers
// this address), duplicates share its real result as before — there's no
// cloud/local ambiguity to hide in that case.
func (m *Manager) probeEach(fn func(*Printer)) {
	m.mu.RLock()
	devices := make([]*Printer, 0, len(m.devices))
	for _, dev := range m.devices {
		if p, ok := dev.(*Printer); ok {
			devices = append(devices, p)
		}
	}
	m.mu.RUnlock()

	type addrKey struct {
		connType ConnectionType
		address  string
	}
	groups := make(map[addrKey][]*Printer, len(devices))
	for _, p := range devices {
		key := addrKey{p.connectionType, p.address}
		groups[key] = append(groups[key], p)
	}

	var wg sync.WaitGroup
	for _, group := range groups {
		leader := group[0]
		for _, p := range group[1:] {
			if p.origin == "cloud" && leader.origin != "cloud" {
				leader = p
			}
		}
		wg.Add(1)
		go func(leader *Printer, group []*Printer) {
			defer wg.Done()
			fn(leader)
			leaderIsCloud := leader.origin == "cloud"
			result, probedAt := leader.snapshotStatus()
			for _, p := range group {
				if p == leader {
					continue
				}
				if leaderIsCloud {
					p.applyProbedStatus(StatusOffline, probedAt)
				} else {
					p.applyProbedStatus(result, probedAt)
				}
			}
		}(leader, group)
	}
	wg.Wait()
}

// legacyHoldPrinter is the previous spelling of TypeHallPrinter. The role prints
// the ホール伝票 — the front-of-house ticket floor staff run to the table — and
// "hold" was a mis-transliteration of ホール (hall), which read as "on hold".
// Migration 051 rewrites the stored rows, but a DB restored from an older backup
// still carries the old value, so normalise on read too.
const legacyHoldPrinter DeviceType = "hold_printer"

// normaliseRole maps a stored role onto its canonical value.
func normaliseRole(role DeviceType) DeviceType {
	if role == legacyHoldPrinter {
		return TypeHallPrinter
	}
	return role
}

// parseRoles decodes the JSON roles column, falling back to a single-element
// list built from the legacy `type` column when roles is empty/invalid.
func parseRoles(rolesJSON string, fallbackType DeviceType) []DeviceType {
	if rolesJSON != "" {
		var roles []DeviceType
		if err := json.Unmarshal([]byte(rolesJSON), &roles); err == nil && len(roles) > 0 {
			for i, r := range roles {
				roles[i] = normaliseRole(r)
			}
			return roles
		}
	}
	if fallbackType != "" {
		return []DeviceType{normaliseRole(fallbackType)}
	}
	return nil
}

// anyPrinterRole reports whether the role list contains at least one role we
// drive as an ESC/POS printer.
func anyPrinterRole(roles []DeviceType) bool {
	for _, r := range roles {
		switch r {
		case TypeKitchenPrinter, TypeHallPrinter, TypeBarPrinter, TypeReceiptPrinter, TypeLabelPrinter:
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

// UpdatePrinter edits the identity of an existing printer — name, how it is
// connected, where it lives and its paper width — without minting a new device
// id. Until this existed, roles were the ONLY editable property: correcting a
// typo in the address, or moving a printer to a new IP after a DHCP change,
// forced delete + re-add, which mints a new id and silently orphans everything
// keyed on the old one.
//
// The address is validated exactly like AddPrinter, so an edit can never store
// an address the add path would have rejected (#85).
//
// Persist first, then swap memory: a failed write leaves the in-memory device on
// its old, still-working connection rather than a value the DB doesn't have. The
// live connection is dropped so the next print redials the new address instead
// of writing to the previous socket.
func (m *Manager) UpdatePrinter(id, name string, connType ConnectionType, address string, config PrinterConfig) error {
	if strings.TrimSpace(name) == "" {
		return fmt.Errorf("name is required")
	}
	if err := ValidateAddress(connType, address); err != nil {
		return err
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

	configJSON, _ := json.Marshal(config)
	if _, err := m.db.Exec(
		`UPDATE printers SET name = ?, connection_type = ?, address = ?, config = ?, updated_at = ?
		 WHERE id = ? AND is_active = 1`,
		name, connType, address, string(configJSON),
		time.Now().UTC().Format(time.RFC3339), id,
	); err != nil {
		return fmt.Errorf("save printer: %w", err)
	}

	addressChanged := p.address != address || p.connectionType != connType
	p.name = name
	p.connectionType = connType
	p.address = address
	p.config = config
	if addressChanged {
		p.Disconnect()
		p.status = StatusOffline
	}

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
// fallback for when the cloud device is CONFIRMED unreachable (a completed
// probe came back offline — e.g. two devices racing for the same physical
// printer's single TCP slot, or the cloud printer genuinely powered off). A
// cloud printer that hasn't been probed yet (fresh add / just loaded from DB)
// is not yet "confirmed" anything, so it still wins — otherwise every restart
// would print on local until the first probe cycle completes. Map iteration
// order is random, so we can't just return the first match; scan for a
// non-confirmed-offline cloud printer first, keeping any cloud match and any
// local match as fallbacks.
func (m *Manager) GetPrinterByRole(role DeviceType) *Printer {
	m.mu.RLock()
	defer m.mu.RUnlock()
	var cloudAny, localAny *Printer
	for _, dev := range m.devices {
		printer, ok := dev.(*Printer)
		if !ok || !printer.HasRole(role) {
			continue
		}
		if printer.origin == "cloud" {
			if !(printer.Probed() && printer.Status() == StatusOffline) {
				return printer // not confirmed offline — cloud wins outright
			}
			if cloudAny == nil {
				cloudAny = printer
			}
			continue
		}
		if localAny == nil {
			localAny = printer
		}
	}
	// Cloud printer confirmed offline: fall back to local if one covers the
	// role, otherwise return the offline cloud printer so callers still see a
	// device — and its own error — instead of silently reporting no printer.
	if localAny != nil {
		return localAny
	}
	return cloudAny
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
			// m.devices only ever holds rows loaded with is_active=1 (readDevices'
			// WHERE clause) or freshly inserted via AddPrinter (always 1) — every
			// entry here is active by construction.
			IsActive: true,
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
