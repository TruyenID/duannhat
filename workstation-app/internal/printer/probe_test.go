package printer

import (
	"net"
	"testing"
	"time"
)

// Every printer is constructed StatusOffline and nothing dials until the first
// real print, so a device the operator just added showed "offline" whether or
// not it worked — indistinguishable from "it was never created".
func TestProbe_ReachablePrinterGoesOnline(t *testing.T) {
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("listen: %v", err)
	}
	defer ln.Close()
	go func() {
		for {
			conn, err := ln.Accept()
			if err != nil {
				return
			}
			conn.Close()
		}
	}()

	p := NewPrinter("id", "Kitchen", []DeviceType{TypeKitchenPrinter},
		ConnNetwork, ln.Addr().String(), PrinterConfig{})
	if p.Status() != StatusOffline {
		t.Fatalf("precondition: status = %s, want offline", p.Status())
	}

	p.Probe()

	if p.Status() != StatusOnline {
		t.Errorf("status = %s, want online", p.Status())
	}
	// Probe must not retain the connection — that is Connect's job. Holding it
	// would leave a socket open per device for a mere status check.
	if p.IsConnected() {
		t.Error("Probe retained the connection; it should dial and close")
	}
}

// A LAN address nobody answers is offline, not error: the printer is plugged
// into a different subnet or powered down, which the operator can fix.
func TestProbe_UnreachablePrinterIsOffline(t *testing.T) {
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("listen: %v", err)
	}
	addr := ln.Addr().String()
	ln.Close() // nothing is listening there now

	p := NewPrinter("id", "Ghost", []DeviceType{TypeKitchenPrinter},
		ConnNetwork, addr, PrinterConfig{})
	p.Probe()

	if p.Status() != StatusOffline {
		t.Errorf("status = %s, want offline", p.Status())
	}
}

// An address that can never work is a configuration fault, distinct from a
// printer that simply is not answering.
func TestProbe_InvalidAddressIsError(t *testing.T) {
	p := NewPrinter("id", "Bad", []DeviceType{TypeKitchenPrinter},
		ConnNetwork, "8.8.8.8:9100", PrinterConfig{})
	p.Probe()

	if p.Status() != StatusError {
		t.Errorf("status = %s, want error", p.Status())
	}
}

// ProbeAll must settle every device, not just the first — and must not
// deadlock against the manager lock it reads the device list under.
func TestProbeAll_UpdatesEveryDevice(t *testing.T) {
	m := newTestManager(t)

	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("listen: %v", err)
	}
	defer ln.Close()
	go func() {
		for {
			conn, err := ln.Accept()
			if err != nil {
				return
			}
			conn.Close()
		}
	}()

	live, err := m.AddPrinter("Live", []DeviceType{TypeKitchenPrinter},
		ConnNetwork, ln.Addr().String(), PrinterConfig{})
	if err != nil {
		t.Fatalf("add live: %v", err)
	}
	dead, err := m.AddPrinter("Dead", []DeviceType{TypeBarPrinter},
		ConnNetwork, "127.0.0.1:1", PrinterConfig{})
	if err != nil {
		t.Fatalf("add dead: %v", err)
	}

	m.ProbeAll()

	if live.Status() != StatusOnline {
		t.Errorf("live printer status = %s, want online", live.Status())
	}
	if dead.Status() != StatusOffline {
		t.Errorf("dead printer status = %s, want offline", dead.Status())
	}
}

// ProbeIfStale must skip a device probed moments ago — the device list polls
// every few seconds and re-dialing every printer on every poll is pointless
// traffic.
func TestProbeIfStale_SkipsFreshResult(t *testing.T) {
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("listen: %v", err)
	}
	addr := ln.Addr().String()
	go func() {
		for {
			conn, err := ln.Accept()
			if err != nil {
				return
			}
			conn.Close()
		}
	}()

	p := NewPrinter("id", "P", []DeviceType{TypeKitchenPrinter},
		ConnNetwork, addr, PrinterConfig{})
	p.Probe()
	if p.Status() != StatusOnline {
		t.Fatalf("precondition: status = %s, want online", p.Status())
	}

	// Kill the listener, then ask for a refresh with a long freshness window:
	// the cached "online" must survive because no re-dial should happen.
	ln.Close()
	p.ProbeIfStale(time.Hour)
	if p.Status() != StatusOnline {
		t.Errorf("status = %s — a fresh result was re-probed", p.Status())
	}

	// With a zero window it must re-dial and notice the printer is gone.
	p.ProbeIfStale(0)
	if p.Status() != StatusOffline {
		t.Errorf("status = %s, want offline after a forced re-probe", p.Status())
	}
}

// The dial must happen with p.mu released, otherwise probing a dead printer
// stalls a real print for the whole probeTimeout.
//
// 10.255.255.1 is RFC1918 (so ValidateAddress accepts it) but routed nowhere,
// so the dial blocks for the full probeTimeout rather than being refused —
// which is exactly the window a competing Print must not be stuck behind.
//
// Print is expected to fail here ("not connected"); the assertion is on how
// fast it returns, i.e. how fast it acquires the lock, not on its error.
func TestProbe_DoesNotBlockPrint(t *testing.T) {
	p := NewPrinter("id", "BlackHole", []DeviceType{TypeKitchenPrinter},
		ConnNetwork, "10.255.255.1:9100", PrinterConfig{})

	probeStart := time.Now()
	probeDone := make(chan struct{})
	go func() {
		defer close(probeDone)
		p.Probe()
	}()

	// Let the probe get past its lock acquisition and into the dial.
	time.Sleep(100 * time.Millisecond)

	printDone := make(chan struct{})
	go func() {
		defer close(printDone)
		_ = p.Print([]byte("hello"))
	}()

	select {
	case <-printDone:
	case <-time.After(probeTimeout / 2):
		t.Fatal("Print blocked behind an in-flight Probe — the dial is holding p.mu")
	}

	// Guard against a vacuous pass: if the dial had returned immediately there
	// would have been no contention to observe in the first place.
	<-probeDone
	if elapsed := time.Since(probeStart); elapsed < probeTimeout/2 {
		t.Fatalf("probe finished in %v — it never blocked on the dial, so this "+
			"test proved nothing about lock contention", elapsed)
	}
}
