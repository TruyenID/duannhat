package handler

import (
	"os"
	"strings"
	"sync"
)

// Reverse-proxy / tunnel support for the LAN gateway.
//
// The LAN server is normally reached at a private IP or `localhost`, and three
// guards enforce that: the WS Host check (anti-DNS-rebinding), the browser CORS
// origin allow-list, and the credentialed-origin check. A staging deployment
// that fronts the workstation with a Cloudflare tunnel breaks all three — the
// browser sends `Host: <name>.trycloudflare.com` and
// `Origin: https://<name>.trycloudflare.com`, so the WS handshake is refused and
// pos-web silently loses realtime (it falls back to no polling at all, because
// polling is disabled precisely when the socket is expected to be up).
//
// Rather than widening the guards for everyone, the extra hosts are opt-in via
// WS_APP_TUNNEL_HOSTS. Unset (the default, and what production ships) leaves
// every guard exactly as strict as before.
//
//	WS_APP_TUNNEL_HOSTS=".trycloudflare.com,pos.staging.example"
//
// An entry starting with "." is a subdomain-suffix match anchored on the dot,
// so ".godx.jp" cannot be satisfied by "evilgodx.jp". Any other entry must
// match the hostname exactly. Matching is case-insensitive; ports are stripped
// by the callers before they get here.
//
// Threat note: this only relaxes *which hostname* may address the workstation.
// It does not bypass the outer lanOnly ring, the bearer-token auth on the first
// WS message, or per-route authorization. A tunnel host also cannot reach this
// process unless a tunnel was already pointed at it from the machine itself.
const tunnelHostsEnv = "WS_APP_TUNNEL_HOSTS"

var (
	tunnelHostsOnce sync.Once
	tunnelHostsList []string
)

func tunnelHosts() []string {
	tunnelHostsOnce.Do(func() {
		for _, raw := range strings.Split(os.Getenv(tunnelHostsEnv), ",") {
			if e := strings.ToLower(strings.TrimSpace(raw)); e != "" {
				tunnelHostsList = append(tunnelHostsList, e)
			}
		}
	})
	return tunnelHostsList
}

// isTunnelHost reports whether `host` (a bare hostname, no port) is on the
// configured tunnel allow-list. Always false when WS_APP_TUNNEL_HOSTS is unset.
func isTunnelHost(host string) bool {
	host = strings.ToLower(strings.TrimSpace(host))
	if host == "" {
		return false
	}
	for _, entry := range tunnelHosts() {
		if strings.HasPrefix(entry, ".") {
			if strings.HasSuffix(host, entry) {
				return true
			}
			continue
		}
		if host == entry {
			return true
		}
	}
	return false
}
