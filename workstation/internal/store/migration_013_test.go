package store

import (
	"path/filepath"
	"testing"
)

// TestMigration013_BackfillFromSettings simulates a pre-013 database that still
// carries the legacy *_printer_ip setting keys, then runs the 013 backfill SQL
// (the portion after the ALTER/UPDATE schema steps, which Open already applied)
// and asserts the legacy IPs become printer devices with the right roles and
// the legacy keys are purged.
//
// kitchen + hold share an IP (should collapse to ONE device with both roles);
// bar has its own IP (separate device); a bare IP gets :9100 appended.
func TestMigration013_BackfillFromSettings(t *testing.T) {
	db, err := Open(filepath.Join(t.TempDir(), "test.db"))
	if err != nil {
		t.Fatalf("open db: %v", err)
	}
	defer db.Close()

	// Seed legacy settings. kitchen+hold same host, bar separate, kitchen has
	// no port (must get :9100), bar already has a port.
	seed := map[string]string{
		"kitchen_printer_ip": "192.168.1.100",
		"hold_printer_ip":    "192.168.1.100",
		"bar_printer_ip":     "192.168.1.200:9100",
	}
	for k, v := range seed {
		if _, err := db.Exec(
			"INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value",
			k, v,
		); err != nil {
			t.Fatalf("seed %s: %v", k, err)
		}
	}

	// Re-run the data-backfill portion of migration 013 (Open already ran the
	// ALTER + initial UPDATE; the legacy keys didn't exist then so nothing was
	// migrated). This mirrors the migration body verbatim.
	backfill := `
CREATE TEMP TABLE _legacy_printer_ips AS
SELECT 'kitchen_printer' AS role, trim(value) AS ip
FROM settings WHERE key = 'kitchen_printer_ip' AND trim(value) <> ''
UNION ALL
SELECT 'hold_printer', trim(value)
FROM settings WHERE key = 'hold_printer_ip' AND trim(value) <> ''
UNION ALL
SELECT 'bar_printer', trim(value)
FROM settings WHERE key = 'bar_printer_ip' AND trim(value) <> '';

UPDATE _legacy_printer_ips SET ip = ip || ':9100' WHERE instr(ip, ':') = 0;

INSERT INTO printers (id, type, name, connection_type, address, config, roles, is_active, created_at, updated_at)
SELECT
    lower(hex(randomblob(16))),
    (SELECT role FROM _legacy_printer_ips r2 WHERE r2.ip = r.ip ORDER BY role LIMIT 1),
    'Migrated printer (' || r.ip || ')',
    'network',
    r.ip,
    json_object('paper_width', 80, 'cut_type', 'full'),
    (SELECT json_group_array(role) FROM (
        SELECT DISTINCT role FROM _legacy_printer_ips r3 WHERE r3.ip = r.ip ORDER BY role
    )),
    1, datetime('now'), datetime('now')
FROM (SELECT DISTINCT ip FROM _legacy_printer_ips) r
WHERE NOT EXISTS (SELECT 1 FROM printers p WHERE p.address = r.ip AND p.is_active = 1);

DROP TABLE _legacy_printer_ips;

DELETE FROM settings WHERE key IN ('kitchen_printer_ip', 'hold_printer_ip', 'bar_printer_ip');
`
	if _, err := db.conn.Exec(backfill); err != nil {
		t.Fatalf("run backfill: %v", err)
	}

	// Two devices: one for .100 (kitchen+hold), one for .200 (bar).
	var count int
	if err := db.QueryRow("SELECT COUNT(*) FROM printers").Scan(&count); err != nil {
		t.Fatalf("count printers: %v", err)
	}
	if count != 2 {
		t.Fatalf("expected 2 migrated printers, got %d", count)
	}

	// .100 should carry both kitchen + hold roles, with port appended.
	var roles100 string
	if err := db.QueryRow(
		"SELECT roles FROM printers WHERE address = '192.168.1.100:9100'",
	).Scan(&roles100); err != nil {
		t.Fatalf("lookup .100 device: %v", err)
	}
	for _, want := range []string{"kitchen_printer", "hold_printer"} {
		if !contains(roles100, want) {
			t.Errorf(".100 roles = %s, missing %s", roles100, want)
		}
	}

	// .200 carries bar only.
	var roles200 string
	if err := db.QueryRow(
		"SELECT roles FROM printers WHERE address = '192.168.1.200:9100'",
	).Scan(&roles200); err != nil {
		t.Fatalf("lookup .200 device: %v", err)
	}
	if !contains(roles200, "bar_printer") {
		t.Errorf(".200 roles = %s, missing bar_printer", roles200)
	}

	// Legacy keys purged.
	var leftover int
	if err := db.QueryRow(
		"SELECT COUNT(*) FROM settings WHERE key IN ('kitchen_printer_ip','hold_printer_ip','bar_printer_ip')",
	).Scan(&leftover); err != nil {
		t.Fatalf("count leftover keys: %v", err)
	}
	if leftover != 0 {
		t.Errorf("expected legacy keys purged, %d remain", leftover)
	}
}

func contains(haystack, needle string) bool {
	return len(haystack) >= len(needle) && indexOf(haystack, needle) >= 0
}

func indexOf(s, sub string) int {
	for i := 0; i+len(sub) <= len(s); i++ {
		if s[i:i+len(sub)] == sub {
			return i
		}
	}
	return -1
}
