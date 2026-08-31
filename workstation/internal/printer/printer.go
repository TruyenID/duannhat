package printer

import (
	"fmt"
	"io"
	"net"
	"os"
	"strconv"
	"strings"
	"sync"
	"time"
)

type PrinterConfig struct {
	PaperWidth int    `json:"paper_width"` // 58 or 80mm
	CutType    string `json:"cut_type"`    // full, partial, none
	Encoding   string `json:"encoding"`    // shift_jis
}

type Printer struct {
	id             string
	name           string
	roles          []DeviceType
	connectionType ConnectionType
	address        string
	config         PrinterConfig
	// createdAt (RFC3339, from the `printers` row) exists only to give
	// ListDevices a stable sort key. The manager holds devices in a map, and
	// Go randomises map iteration, so without this the device list came back
	// in a different order on every poll.
	createdAt string
	// origin is 'cloud' (mirrored from the Cloud printer config by PullPrinters)
	// or 'local' (added by hand in the WS App). Routing prefers cloud printers;
	// local ones are the offline fallback. Empty on freshly-constructed printers
	// until readDevices stamps it from the DB row.
	origin string
	// profile is the plan-052 capability profile (DESIGN §3b) — what this
	// MACHINE can do, as data. Freshly-constructed printers carry the
	// escpos_generic default so nothing ever has to nil-check it (P-29).
	profile Profile

	mu        sync.Mutex
	conn      io.WriteCloser
	status    DeviceStatus
	lastProbe time.Time
}

// probeTimeout bounds the reachability dial. Short on purpose: it runs on the
// add path and at startup, where a slow answer is worse than an unknown one.
const probeTimeout = 2 * time.Second

func NewPrinter(id, name string, roles []DeviceType, connType ConnectionType, address string, config PrinterConfig) *Printer {
	if config.PaperWidth == 0 {
		config.PaperWidth = 80
	}
	if config.CutType == "" {
		config.CutType = "full"
	}
	return &Printer{
		id:             id,
		name:           name,
		roles:          roles,
		connectionType: connType,
		address:        address,
		config:         config,
		// #1965 — chưa nạp profile nào. #3059 — nhưng `cut_type` trong chính
		// config này ĐÃ là một lời khai, nên tôn trọng nó ngay từ đây thay vì
		// chỉ ở đường đọc DB: máy thêm bằng tay qua wizard cũng đi lối này.
		// Manager ghi đè bằng `ProfileForRow` khi hàng DB có profile thật.
		profile: UndescribedProfileForCut(config.CutType),
		status:  StatusOffline,
	}
}

func (p *Printer) ID() string   { return p.id }
func (p *Printer) Name() string { return p.name }

// Type returns the primary (first) role, kept for back-compat with callers
// and the legacy `type` column.
func (p *Printer) Type() DeviceType {
	if len(p.roles) == 0 {
		return ""
	}
	return p.roles[0]
}

func (p *Printer) Roles() []DeviceType { return p.roles }

func (p *Printer) HasRole(role DeviceType) bool {
	for _, r := range p.roles {
		if r == role {
			return true
		}
	}
	return false
}

// Status is read from HTTP handlers while Connect/Print/Disconnect mutate it
// on other goroutines, so it must take the same lock they do.
func (p *Printer) Status() DeviceStatus {
	p.mu.Lock()
	defer p.mu.Unlock()
	return p.status
}

func (p *Printer) Address() string   { return p.address }
func (p *Printer) CreatedAt() string { return p.createdAt }
func (p *Printer) Origin() string    { return p.origin }

// Profile returns this machine's capability profile (plan-052 §3b). Renderers
// read it to choose the way OUT — never to change what the slip says.
func (p *Printer) Profile() Profile {
	p.mu.Lock()
	defer p.mu.Unlock()
	return p.profile
}

// SetProfile replaces the capability profile in memory. P-31: the change
// applies to the NEXT print — a job already rendered keeps the bytes it was
// built with, which is what makes a mid-shift profile edit safe.
func (p *Printer) SetProfile(profile Profile) {
	p.mu.Lock()
	defer p.mu.Unlock()
	p.profile = profile
}

// Probed reports whether a reachability dial has ever completed for this
// printer. A freshly-constructed device is StatusOffline (see NewPrinter)
// purely as an unset default, not because a dial failed — callers that need
// to tell "never checked" apart from "confirmed unreachable" (e.g. routing's
// cloud-over-local preference) must consult this alongside Status().
func (p *Printer) Probed() bool {
	p.mu.Lock()
	defer p.mu.Unlock()
	return !p.lastProbe.IsZero()
}

// Probe refreshes Status by checking reachability, without retaining the
// connection the way Connect does.
//
// Every printer is constructed StatusOffline and nothing dials until the first
// real print, so a freshly added device — or every device after a restart —
// showed "offline" whether or not it worked. That reads as "the device was
// never created".
//
// Unreachable maps to Offline rather than Error (the printer is simply not
// answering — powered off, unplugged, on another subnet); Error is reserved
// for an address that can never work.
//
// The dial happens with p.mu released. Holding it across a 2s dial to a dead
// printer would stall a real print queued behind it, which is a far worse
// outcome than a status badge being a few seconds stale.
func (p *Printer) Probe() {
	p.mu.Lock()
	if p.conn != nil {
		// An open connection is proof enough, and re-dialing would say
		// nothing new.
		p.status = StatusOnline
		p.lastProbe = time.Now()
		p.mu.Unlock()
		return
	}
	connType, address := p.connectionType, p.address
	p.mu.Unlock()

	status := probeReachable(connType, address)

	p.mu.Lock()
	// Connect/Print may have run while the dial was in flight; their status is
	// authoritative because it reflects a real write, not a bare TCP handshake.
	if p.conn == nil {
		p.status = status
	}
	p.lastProbe = time.Now()
	p.mu.Unlock()
}

// ProbeIfStale re-probes only when the last result is older than maxAge, so a
// polling caller (the device list) can keep the badge honest without dialing
// every printer on every request.
func (p *Printer) ProbeIfStale(maxAge time.Duration) {
	p.mu.Lock()
	fresh := !p.lastProbe.IsZero() && time.Since(p.lastProbe) < maxAge
	p.mu.Unlock()
	if fresh {
		return
	}
	p.Probe()
}

// snapshotStatus reads back the result of a just-completed Probe, for a
// caller that dialed on behalf of a group of printers sharing one physical
// address (see Manager.probeEach) and needs to copy the outcome onto the
// others without making them dial too.
func (p *Printer) snapshotStatus() (DeviceStatus, time.Time) {
	p.mu.Lock()
	defer p.mu.Unlock()
	return p.status, p.lastProbe
}

// applyProbedStatus stamps a status obtained by probing a DIFFERENT printer
// at the same physical address, without dialing this one itself. Used only
// when this printer lost the leader election in Manager.probeEach — it never
// overrides a live Connect/Print (p.conn != nil is authoritative, same rule
// as Probe).
func (p *Printer) applyProbedStatus(status DeviceStatus, probedAt time.Time) {
	p.mu.Lock()
	defer p.mu.Unlock()
	if p.conn == nil {
		p.status = status
	}
	p.lastProbe = probedAt
}

// probeReachable performs the dial itself. Kept free of Printer state so it
// can run without the lock held.
func probeReachable(connType ConnectionType, address string) DeviceStatus {
	if err := ValidateAddress(connType, address); err != nil {
		return StatusError
	}
	switch connType {
	case ConnNetwork:
		conn, err := net.DialTimeout("tcp", address, probeTimeout)
		if err != nil {
			return StatusOffline
		}
		conn.Close()
	case ConnUSB:
		f, err := os.OpenFile(address, os.O_WRONLY, 0)
		if err != nil {
			return StatusOffline
		}
		f.Close()
	default:
		return StatusError
	}
	return StatusOnline
}

func (p *Printer) IsConnected() bool {
	p.mu.Lock()
	defer p.mu.Unlock()
	return p.conn != nil
}

func (p *Printer) Connect() error {
	p.mu.Lock()
	defer p.mu.Unlock()

	if p.conn != nil {
		return nil
	}

	// Defense-in-depth: re-validate at the choke point where the actual
	// Dial/OpenFile syscalls happen. AddPrinter validates on insert, but a
	// legacy DB row (pre-#85) or a direct code path could still carry a
	// host-controlled address; refuse to Dial an arbitrary internet host or
	// OpenFile an arbitrary path. (#85)
	if err := ValidateAddress(p.connectionType, p.address); err != nil {
		p.status = StatusError
		return fmt.Errorf("printer %s: %w", p.name, err)
	}

	var conn io.WriteCloser
	var err error

	switch p.connectionType {
	case ConnNetwork:
		conn, err = net.DialTimeout("tcp", p.address, 5*time.Second)
	case ConnUSB:
		conn, err = os.OpenFile(p.address, os.O_WRONLY, 0)
	default:
		return fmt.Errorf("unsupported connection type: %s", p.connectionType)
	}

	if err != nil {
		p.status = StatusError
		return fmt.Errorf("connect to printer %s: %w", p.name, err)
	}

	p.conn = conn
	p.status = StatusOnline
	return nil
}

func (p *Printer) Disconnect() error {
	p.mu.Lock()
	defer p.mu.Unlock()

	if p.conn == nil {
		return nil
	}

	// For TCP connections: half-close the write side so the printer can drain
	// its receive buffer (including the cut command) before we tear down the
	// socket.  Without this, Close() sends a FIN immediately and the cut bytes
	// still in the kernel send-buffer may be dropped on some Star LAN models.
	if tc, ok := p.conn.(*net.TCPConn); ok {
		_ = tc.CloseWrite()
		// Drain any response bytes (status/ACK) the printer may send back.
		buf := make([]byte, 64)
		tc.SetReadDeadline(time.Now().Add(500 * time.Millisecond))
		for {
			if _, err := tc.Read(buf); err != nil {
				break
			}
		}
	}

	err := p.conn.Close()
	p.conn = nil
	p.status = StatusOffline
	return err
}

func (p *Printer) Print(data []byte) error {
	p.mu.Lock()
	defer p.mu.Unlock()

	if p.conn == nil {
		return fmt.Errorf("printer %s is not connected", p.name)
	}

	p.status = StatusPrinting
	_, err := p.conn.Write(data)
	if err != nil {
		p.status = StatusError
		// Try to reconnect on next print
		p.conn.Close()
		p.conn = nil
		return fmt.Errorf("print to %s: %w", p.name, err)
	}

	p.status = StatusOnline
	return nil
}

// CharWidth is the printable column count. The capability profile answers it
// (P-29 default 32/48), so a machine whose real width differs from the usual
// ESC/POS assumption is corrected by DATA rather than by a code branch.
func (p *Printer) CharWidth() int {
	p.mu.Lock()
	profile, width := p.profile, p.config.PaperWidth
	p.mu.Unlock()
	return profile.ColumnsFor(width)
}

// ValidateAddress rejects printer addresses that a LAN caller could abuse to
// turn the workstation into an SSRF/port-scan proxy (network) or to corrupt an
// arbitrary file on the host (usb). Before #85, handleAddDevice/handleTestDevice
// fed an attacker-controlled connection_type+address straight into
// net.DialTimeout / os.OpenFile.
//
//   - network: must be host:port; the host must be a private/LAN IP literal
//     (RFC1918 / ULA / loopback / link-local) or an mDNS ".local" name. A
//     public IP or arbitrary hostname is refused so the workstation can't be
//     pointed at internet hosts or internal services on other subnets.
//   - usb: must be a real printer device node under /dev (Linux lp/usblp,
//     macOS cu./tty.) or a Windows COM/LPT port — never an arbitrary path.
func ValidateAddress(connType ConnectionType, address string) error {
	switch connType {
	case ConnNetwork:
		host, port, err := net.SplitHostPort(address)
		if err != nil {
			return fmt.Errorf("network address must be host:port: %w", err)
		}
		if host == "" {
			return fmt.Errorf("network address host is empty")
		}
		if n, err := strconv.Atoi(port); err != nil || n < 1 || n > 65535 {
			return fmt.Errorf("network address has invalid port %q", port)
		}
		if ip := net.ParseIP(host); ip != nil {
			if !isPrivatePrinterIP(ip) {
				return fmt.Errorf("network printer must be on a private/LAN address, got %q", host)
			}
			return nil
		}
		// Hostname form — only mDNS .local names are LAN-scoped; anything
		// else could resolve to an arbitrary internet host (SSRF).
		if strings.HasSuffix(strings.ToLower(host), ".local") {
			return nil
		}
		return fmt.Errorf("network printer host must be a private IP or .local mDNS name, got %q", host)
	case ConnUSB:
		if !isAllowedUSBPath(address) {
			return fmt.Errorf("usb printer address must be a printer device node (e.g. /dev/usb/lp0), got %q", address)
		}
		return nil
	default:
		return fmt.Errorf("unsupported connection type: %s", connType)
	}
}

func isPrivatePrinterIP(ip net.IP) bool {
	return ip.IsLoopback() || ip.IsPrivate() || ip.IsLinkLocalUnicast()
}

// isAllowedUSBPath permits only well-known printer device-node prefixes and
// forbids path traversal, so `/dev/../etc/passwd` and arbitrary files are
// rejected.
func isAllowedUSBPath(p string) bool {
	if p == "" || strings.Contains(p, "..") {
		return false
	}
	// Unix device nodes: Linux line printers + macOS USB/serial.
	unixPrefixes := []string{"/dev/usb/lp", "/dev/usblp", "/dev/lp", "/dev/cu.", "/dev/tty."}
	for _, prefix := range unixPrefixes {
		if strings.HasPrefix(p, prefix) {
			return true
		}
	}
	// Windows printer ports (COM1.., LPT1..) and device namespace.
	up := strings.ToUpper(p)
	if strings.HasPrefix(up, "COM") || strings.HasPrefix(up, "LPT") || strings.HasPrefix(p, `\\.\`) {
		return true
	}
	return false
}
