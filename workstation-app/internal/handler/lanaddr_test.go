package handler

import (
	"encoding/json"
	"net"
	"os"
	"path/filepath"
	"sort"
	"testing"
)

func TestLooksVirtual(t *testing.T) {
	virtual := []string{
		"vEthernet (Default Switch)", // Hyper-V, Windows
		"VirtualBox Host-Only Network",
		"vboxnet0",
		"VMware Network Adapter VMnet1",
		"docker0",
		"br-1a2b3c",
		"veth9f8e",
		"utun4",      // macOS VPN
		"tailscale0", // mesh VPN
		"bridge100",  // macOS internet sharing
	}
	for _, name := range virtual {
		if !looksVirtual(name) {
			t.Errorf("looksVirtual(%q) = false, want true", name)
		}
	}

	physical := []string{"eth0", "en0", "Ethernet", "Wi-Fi", "enp3s0", "wlan0"}
	for _, name := range physical {
		if looksVirtual(name) {
			t.Errorf("looksVirtual(%q) = true, want false", name)
		}
	}
}

// The bug this replaced: VirtualBox's 192.168.56.1 matched the old "192"
// prefix test and was returned ahead of the real LAN address.
func TestRangeRank_PrefersConsumerLANRanges(t *testing.T) {
	if rangeRank("192.168.1.50") >= rangeRank("10.0.0.5") {
		t.Error("192.168/16 must rank ahead of 10/8")
	}
	if rangeRank("10.0.0.5") >= rangeRank("172.20.0.5") {
		t.Error("10/8 must rank ahead of 172.16/12 (Docker/WSL/Hyper-V squat there)")
	}
}

func TestGetLANAddresses_SortsPreferredAndPhysicalFirst(t *testing.T) {
	in := []LANCandidate{
		{IP: "172.20.0.1", Interface: "vEthernet", Virtual: true},
		{IP: "192.168.56.1", Interface: "vboxnet0", Virtual: true},
		{IP: "10.0.0.5", Interface: "eth1"},
		{IP: "192.168.1.50", Interface: "eth0"},
		{IP: "172.20.5.5", Interface: "eth2", Preferred: true},
	}
	sort.SliceStable(in, func(i, j int) bool {
		if in[i].Preferred != in[j].Preferred {
			return in[i].Preferred
		}
		if in[i].Virtual != in[j].Virtual {
			return !in[i].Virtual
		}
		return rangeRank(in[i].IP) < rangeRank(in[j].IP)
	})

	if in[0].IP != "172.20.5.5" {
		t.Errorf("first = %s, want the route-preferred address to win outright", in[0].IP)
	}
	if in[1].IP != "192.168.1.50" || in[2].IP != "10.0.0.5" {
		t.Errorf("physical order = %s,%s; want 192.168.1.50,10.0.0.5", in[1].IP, in[2].IP)
	}
	if !in[3].Virtual || !in[4].Virtual {
		t.Error("virtual adapters must sort last")
	}
}

func TestGetLANAddress_EnvOverride(t *testing.T) {
	t.Setenv(lanIPOverrideEnv, "10.9.8.7")
	if got := GetLANAddress(); got != "10.9.8.7" {
		t.Errorf("GetLANAddress() = %q, want the override", got)
	}
}

// A typo in the shop's .env must not stop the app — detection takes over.
func TestGetLANAddress_MalformedOverrideFallsBack(t *testing.T) {
	t.Setenv(lanIPOverrideEnv, "not-an-ip")
	got := GetLANAddress()
	if got == "not-an-ip" {
		t.Fatal("malformed override was returned verbatim")
	}
	if net.ParseIP(got) == nil {
		t.Errorf("fallback %q is not a valid IP", got)
	}
}

// Whatever detection returns must always be a dialable IPv4 literal — several
// call sites paste it straight into a URL.
func TestGetLANAddress_AlwaysValidIPv4(t *testing.T) {
	os.Unsetenv(lanIPOverrideEnv)
	got := GetLANAddress()
	ip := net.ParseIP(got)
	if ip == nil || ip.To4() == nil {
		t.Fatalf("GetLANAddress() = %q, want a valid IPv4", got)
	}
	if !ip.IsPrivate() && !ip.IsLoopback() {
		t.Errorf("GetLANAddress() = %q, want private or loopback", got)
	}
}

func TestWriteEndpointFile(t *testing.T) {
	dir := t.TempDir()
	t.Setenv(lanIPOverrideEnv, "192.168.1.50")

	ep, err := WriteEndpointFile(dir, 8080, "1.2.3")
	if err != nil {
		t.Fatalf("WriteEndpointFile: %v", err)
	}
	if ep.URL != "http://192.168.1.50:8080" {
		t.Errorf("URL = %q, want http://192.168.1.50:8080", ep.URL)
	}

	raw, err := os.ReadFile(filepath.Join(dir, EndpointFileName))
	if err != nil {
		t.Fatalf("read back: %v", err)
	}
	var decoded ExportedEndpoint
	if err := json.Unmarshal(raw, &decoded); err != nil {
		t.Fatalf("file is not valid JSON: %v", err)
	}
	if decoded.URL != ep.URL || decoded.Port != 8080 || decoded.Version != "1.2.3" {
		t.Errorf("decoded = %+v, does not match returned %+v", decoded, ep)
	}

	// The temp file must not survive — a stray endpoint.json.tmp next to the
	// real one invites a support script reading the wrong path.
	if _, err := os.Stat(filepath.Join(dir, EndpointFileName+".tmp")); !os.IsNotExist(err) {
		t.Error("temp file left behind after atomic rename")
	}
}

func TestWriteEndpointFile_OverwritesCleanly(t *testing.T) {
	dir := t.TempDir()

	t.Setenv(lanIPOverrideEnv, "192.168.1.50")
	if _, err := WriteEndpointFile(dir, 8080, "1.0.0"); err != nil {
		t.Fatal(err)
	}
	// Simulates a DHCP lease change while the app is running.
	t.Setenv(lanIPOverrideEnv, "192.168.1.77")
	if _, err := WriteEndpointFile(dir, 8080, "1.0.0"); err != nil {
		t.Fatal(err)
	}

	raw, err := os.ReadFile(filepath.Join(dir, EndpointFileName))
	if err != nil {
		t.Fatal(err)
	}
	var decoded ExportedEndpoint
	if err := json.Unmarshal(raw, &decoded); err != nil {
		t.Fatalf("not valid JSON after rewrite: %v", err)
	}
	if decoded.IP != "192.168.1.77" {
		t.Errorf("IP = %q, want the new address after rewrite", decoded.IP)
	}
}
