package discovery

import (
	"fmt"
	"log/slog"
	"os"

	"github.com/grandcat/zeroconf"
)

const (
	serviceType = "_ws-app._tcp"
	domain      = "local."
)

type MDNSAdvertiser struct {
	server *zeroconf.Server
	port   int
}

// NewMDNSAdvertiser starts advertising the workstation on the LAN.
//
// TXT records emitted (per Phase 1 plan):
//
//	version    — server version, e.g. 0.1.0
//	hostname   — OS hostname, for distinguishing multiple WS on same branch
//	branch_id  — UUID of branch this WS serves (kiosk uses to filter)
//	name       — human-readable display name (e.g. "Branch Tokyo - WS1")
//	store      — alias of `name` for backward compat with older clients
//	proxy_url  — full base URL kiosk can call directly (skips DNS resolve)
func NewMDNSAdvertiser(port int, storeName, branchID, version, lanIP string) (*MDNSAdvertiser, error) {
	hostname, _ := os.Hostname()

	txt := []string{
		fmt.Sprintf("version=%s", version),
		fmt.Sprintf("hostname=%s", hostname),
	}
	if branchID != "" {
		txt = append(txt, fmt.Sprintf("branch_id=%s", branchID))
	}
	if storeName != "" {
		// `name` is the canonical key per Phase 1 plan; `store` is kept for backcompat.
		txt = append(txt,
			fmt.Sprintf("name=%s", storeName),
			fmt.Sprintf("store=%s", storeName),
		)
	}
	if lanIP != "" && port > 0 {
		txt = append(txt, fmt.Sprintf("proxy_url=http://%s:%d", lanIP, port))
	}

	instanceName := "ws-app"
	if storeName != "" {
		instanceName = storeName
	}

	server, err := zeroconf.Register(instanceName, serviceType, domain, port, txt, nil)
	if err != nil {
		return nil, fmt.Errorf("mdns register: %w", err)
	}

	slog.Info("mDNS advertising started",
		"service", serviceType, "port", port, "instance", instanceName, "txt", txt)

	return &MDNSAdvertiser{
		server: server,
		port:   port,
	}, nil
}

func (a *MDNSAdvertiser) Stop() {
	if a.server != nil {
		a.server.Shutdown()
		slog.Info("mDNS advertising stopped")
	}
}
