package handler

// lanaddr.go — figuring out which address LAN clients should actually dial.
//
// This is the address printed on the dashboard and handed to tablets, so
// picking wrong means "the app runs fine but nothing can connect" — the worst
// class of bug to debug remotely.
//
// The previous implementation matched on string prefixes:
//
//	if ip[:3] == "192" || ip[:2] == "10" { return ip }
//
// which had three real failure modes on a shop PC:
//
//   - A VirtualBox host-only adapter defaults to 192.168.56.1. It matches "192"
//     and wins, but no tablet can route to it.
//   - Hyper-V, WSL2 and Docker all hand out 172.16–31.x addresses. Those are
//     genuinely private but were NOT preferred, so a shop whose real LAN is
//     172.20.x fell through to "first non-loopback IPv4" — frequently a virtual
//     adapter again.
//   - "10" also prefixes 100.64.x (CGNAT) and 109.x (public).
//
// The replacement asks the OS which source address it would use to reach the
// internet, which is the interface with the default route — on a shop PC that
// is the real LAN adapter, virtual ones never carry the default route. Ranked
// enumeration is the fallback for machines with no gateway at all.

import (
	"encoding/json"
	"log/slog"
	"net"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"time"
)

// lanIPOverrideEnv lets an operator pin the advertised address when the machine
// has several plausible NICs and auto-detection still picks the wrong one.
const lanIPOverrideEnv = "WS_APP_LAN_IP"

// LANCandidate is one address LAN clients could dial.
type LANCandidate struct {
	IP        string `json:"ip"`
	Interface string `json:"interface"`
	// Virtual marks an adapter that looks like a hypervisor/VPN/container
	// bridge. Never auto-selected unless it is the only thing available.
	Virtual bool `json:"virtual"`
	// Preferred is the one address the OS routes outbound traffic through.
	Preferred bool `json:"preferred"`
}

// virtualIfacePatterns are lowercase substrings of interface names that belong
// to hypervisors, containers and VPNs rather than the physical shop network.
var virtualIfacePatterns = []string{
	"vethernet", // Hyper-V (Windows)
	"virtualbox", "vboxnet",
	"vmware", "vmnet",
	"docker", "br-", "veth",
	"wsl",
	"tailscale", "zerotier", "wg", "utun", "tun", "tap", "ppp",
	"bridge",      // macOS bridge100 etc.
	"awdl", "llw", // Apple Wireless Direct Link
}

func looksVirtual(name string) bool {
	n := strings.ToLower(name)
	for _, p := range virtualIfacePatterns {
		if strings.Contains(n, p) {
			return true
		}
	}
	return false
}

// GetLANAddress returns the single best address for LAN clients to dial.
// Falls back to 127.0.0.1 when the machine has no usable private address —
// the app still works locally, it just cannot serve tablets.
func GetLANAddress() string {
	if v := strings.TrimSpace(os.Getenv(lanIPOverrideEnv)); v != "" {
		if net.ParseIP(v) != nil {
			return v
		}
		// A malformed override is a config error worth surfacing, but not worth
		// refusing to start over — fall through to detection.
	}

	candidates := GetLANAddresses()
	if len(candidates) == 0 {
		return "127.0.0.1"
	}
	return candidates[0].IP
}

// GetLANAddresses returns every private IPv4 the machine has, best first.
// Exposed so the UI can show the operator what else is available when the
// auto-picked address turns out to be unreachable from their tablets.
func GetLANAddresses() []LANCandidate {
	ifaces, err := net.Interfaces()
	if err != nil {
		return nil
	}

	routed := routePreferredIP()

	var out []LANCandidate
	for _, iface := range ifaces {
		// Down interfaces hold stale addresses; loopback is never dialable
		// from another machine.
		if iface.Flags&net.FlagUp == 0 || iface.Flags&net.FlagLoopback != 0 {
			continue
		}
		addrs, err := iface.Addrs()
		if err != nil {
			continue
		}
		for _, addr := range addrs {
			ipnet, ok := addr.(*net.IPNet)
			if !ok {
				continue
			}
			ip4 := ipnet.IP.To4()
			// IPv4 only: every LAN client in this fleet dials an IPv4 literal,
			// and a bracketed IPv6 URL would break the Host checks downstream.
			if ip4 == nil || !ip4.IsPrivate() {
				continue
			}
			out = append(out, LANCandidate{
				IP:        ip4.String(),
				Interface: iface.Name,
				Virtual:   looksVirtual(iface.Name),
				Preferred: routed != "" && ip4.String() == routed,
			})
		}
	}

	sort.SliceStable(out, func(i, j int) bool {
		// The address the OS actually routes through always wins.
		if out[i].Preferred != out[j].Preferred {
			return out[i].Preferred
		}
		// Then anything that doesn't look like a hypervisor/VPN adapter.
		if out[i].Virtual != out[j].Virtual {
			return !out[i].Virtual
		}
		return rangeRank(out[i].IP) < rangeRank(out[j].IP)
	})
	return out
}

// rangeRank orders private ranges by how likely they are to be the shop's real
// network. 192.168/16 is the overwhelming default on consumer/SMB routers;
// 172.16/12 is last because Docker, WSL2 and Hyper-V all squat there.
func rangeRank(ip string) int {
	switch {
	case strings.HasPrefix(ip, "192.168."):
		return 0
	case strings.HasPrefix(ip, "10."):
		return 1
	default:
		return 2
	}
}

// routePreferredIP asks the kernel which local address it would use to reach a
// public host. UDP "dial" performs no I/O — it only installs a route and binds
// a local address — so this neither sends packets nor requires the internet to
// be up. It does require a default gateway; with none configured it returns "".
func routePreferredIP() string {
	conn, err := net.DialTimeout("udp4", "8.8.8.8:80", 500*time.Millisecond)
	if err != nil {
		return ""
	}
	defer conn.Close()

	addr, ok := conn.LocalAddr().(*net.UDPAddr)
	if !ok || addr.IP == nil {
		return ""
	}
	ip4 := addr.IP.To4()
	if ip4 == nil || !ip4.IsPrivate() {
		return ""
	}
	return ip4.String()
}

// ─── Endpoint export ──────────────────────────────────────────────────────

// EndpointFileName is written into the config dir so installers, service
// wrappers and support scripts can read the connect URL without scraping logs
// or querying the API. `type endpoint.json` on the shop PC answers "what do I
// type into the tablet?".
const EndpointFileName = "endpoint.json"

// ExportedEndpoint is the on-disk shape of EndpointFileName.
type ExportedEndpoint struct {
	URL        string         `json:"url"`
	IP         string         `json:"ip"`
	Port       int            `json:"port"`
	Hostname   string         `json:"hostname"`
	Version    string         `json:"version"`
	UpdatedAt  string         `json:"updated_at"`
	Candidates []LANCandidate `json:"candidates"`
}

// WriteEndpointFile atomically writes the current connect details into dir.
// Atomic because a support script may read it at any moment; a torn read would
// hand someone a truncated URL.
func WriteEndpointFile(dir string, port int, version string) (ExportedEndpoint, error) {
	hostname, _ := os.Hostname()
	ip := GetLANAddress()

	ep := ExportedEndpoint{
		URL:        "http://" + net.JoinHostPort(ip, strconv.Itoa(port)),
		IP:         ip,
		Port:       port,
		Hostname:   hostname,
		Version:    version,
		UpdatedAt:  time.Now().Format(time.RFC3339),
		Candidates: GetLANAddresses(),
	}

	data, err := json.MarshalIndent(ep, "", "  ")
	if err != nil {
		return ep, err
	}
	data = append(data, '\n')

	final := filepath.Join(dir, EndpointFileName)
	tmp := final + ".tmp"
	if err := os.WriteFile(tmp, data, 0600); err != nil {
		return ep, err
	}
	if err := os.Rename(tmp, final); err != nil {
		os.Remove(tmp)
		return ep, err
	}
	return ep, nil
}

// endpointExportInterval re-checks the LAN address periodically. A DHCP lease
// change moves the address out from under whatever the operator wrote down, and
// nothing else in the process would notice — the HTTP server binds 0.0.0.0 and
// keeps serving happily on the new address under a stale advertised URL.
const endpointExportInterval = 60 * time.Second

// StartEndpointExporter writes endpoint.json immediately and then keeps it
// current in the background. Non-fatal by design: an unwritable config dir must
// never stop the shop from trading, so failures are logged and retried on the
// next tick.
//
// Runs for the lifetime of the process — both entry points call it once during
// startup and neither supports restarting the LAN server in-place.
func StartEndpointExporter(dir string, port int, version string) {
	write := func() string {
		ep, err := WriteEndpointFile(dir, port, version)
		if err != nil {
			slog.Warn("endpoint export failed", "dir", dir, "err", err)
			return ""
		}
		return ep.URL
	}

	last := write()
	slog.Info("endpoint exported", "url", last, "file", filepath.Join(dir, EndpointFileName))

	go func() {
		ticker := time.NewTicker(endpointExportInterval)
		defer ticker.Stop()
		for range ticker.C {
			if url := write(); url != "" && url != last {
				slog.Warn("LAN address changed — clients pointed at the old one will fail",
					"was", last, "now", url)
				last = url
			}
		}
	}()
}
