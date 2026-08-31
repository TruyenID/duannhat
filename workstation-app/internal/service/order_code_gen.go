package service

import (
	"fmt"
	"sync"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
)

// localCodeDeviceFallback is the device segment used while the workstation is
// still unpaired (no device_id in settings yet). Keeps the provisional code
// well-formed (`WS-LOCAL-...`) instead of emitting an empty segment (`WS--...`).
const localCodeDeviceFallback = "LOCAL"

// LocalCodeGenerator generates offline-safe, provisional order codes in format:
//
//	WS-{deviceShort}-{YYYYMMDD}-{counter}
//
// e.g. "WS-A1B2-20260521-001"
//
// This is a PROVISIONAL code only — Cloud is the authority for the canonical
// ORD-#### code, which the workstation adopts on sync (plan-041). The code is
// local + transient, so the device segment is purely for readability.
//
// Counter resets daily, persisted in SQLite settings table via
// key "order_code_counter_{YYYYMMDD}". Single-writer mutex serialises
// concurrent calls so parallel order creation never produces duplicate codes.
type LocalCodeGenerator struct {
	db          *store.DB
	deviceShort string // cached once resolved to a non-empty value
	mu          sync.Mutex
}

// NewLocalCodeGenerator creates a generator. deviceID is truncated to 4 chars
// for the embedded short prefix. The generator is built at startup, before the
// first device pairing writes `device_id`, so an empty value here is normal —
// it is resolved lazily from settings on first use (see devicePrefix).
func NewLocalCodeGenerator(db *store.DB, deviceID string) *LocalCodeGenerator {
	return &LocalCodeGenerator{db: db, deviceShort: shortenDeviceID(deviceID)}
}

func shortenDeviceID(deviceID string) string {
	if len(deviceID) > 4 {
		return deviceID[:4]
	}
	return deviceID
}

// devicePrefix returns the device segment, resolving it lazily from the
// settings table when it wasn't available at construction (the generator is
// created before pairing). Once a non-empty value is found it's cached so we
// don't hit the DB on every call. Falls back to a fixed token so the code never
// emits an empty segment. Caller must hold g.mu.
func (g *LocalCodeGenerator) devicePrefix() string {
	if g.deviceShort != "" {
		return g.deviceShort
	}
	var deviceID string
	_ = g.db.QueryRow("SELECT value FROM settings WHERE key = 'device_id'").Scan(&deviceID)
	if s := shortenDeviceID(deviceID); s != "" {
		g.deviceShort = s // cache once paired
		return s
	}
	return localCodeDeviceFallback
}

// Next atomically increments today's counter and returns the next code.
// Thread-safe via mutex; SQLite UPSERT ensures durability across restarts.
func (g *LocalCodeGenerator) Next() (string, error) {
	g.mu.Lock()
	defer g.mu.Unlock()

	today := time.Now().Format("20060102")
	counterKey := "order_code_counter_" + today

	var counter int
	// If key doesn't exist yet, Scan leaves counter=0 which we then increment.
	_ = g.db.QueryRow(
		"SELECT CAST(value AS INTEGER) FROM settings WHERE key = ?", counterKey,
	).Scan(&counter)
	counter++

	if _, err := g.db.Exec(
		`INSERT INTO settings (key, value) VALUES (?, ?)
		 ON CONFLICT(key) DO UPDATE SET value = excluded.value`,
		counterKey, fmt.Sprintf("%d", counter),
	); err != nil {
		return "", err
	}

	return fmt.Sprintf("WS-%s-%s-%03d", g.devicePrefix(), today, counter), nil
}
