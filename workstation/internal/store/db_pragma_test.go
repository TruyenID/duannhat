package store

import (
	"path/filepath"
	"strings"
	"sync"
	"testing"
)

// Every connection borrowed from the pool MUST carry the busy_timeout +
// journal_mode pragmas. Pre-fix the pragmas were set via `conn.Exec` on
// the first connection only — pool-spawned connections used SQLite
// defaults (busy_timeout=0, rollback journal), causing the slow puller
// to trip SQLITE_BUSY immediately and wedge a writer that drained the
// pool. /api/lan/health then blocked on workstationBranchID()'s SELECT
// and pos-web's "disconnected" banner surfaced.
//
// We exercise the pool by spinning N concurrent readers each doing
// `PRAGMA busy_timeout` from its own connection.  Every read must
// return 2000 — below the POS client's 3-second LAN request budget.
func TestOpen_PragmasAppliedToEveryPoolConnection(t *testing.T) {
	db := openTestDB(t)

	const concurrency = 16
	var wg sync.WaitGroup
	errs := make(chan error, concurrency)

	for i := 0; i < concurrency; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			var bt int
			if err := db.conn.QueryRow("PRAGMA busy_timeout").Scan(&bt); err != nil {
				errs <- err
				return
			}
			if bt != 2000 {
				errs <- &pragmaErr{name: "busy_timeout", want: 2000, got: bt}
			}
			var jm string
			if err := db.conn.QueryRow("PRAGMA journal_mode").Scan(&jm); err != nil {
				errs <- err
				return
			}
			if jm != "wal" {
				errs <- &pragmaErr{name: "journal_mode", want: 9999, got: 0, str: "want=wal got=" + jm}
			}
			var fk int
			if err := db.conn.QueryRow("PRAGMA foreign_keys").Scan(&fk); err != nil {
				errs <- err
				return
			}
			if fk != 1 {
				errs <- &pragmaErr{name: "foreign_keys", want: 1, got: fk}
			}
		}()
	}
	wg.Wait()
	close(errs)
	for err := range errs {
		t.Errorf("%v", err)
	}
}

type pragmaErr struct {
	name      string
	want, got int
	str       string
}

func (e *pragmaErr) Error() string {
	if e.str != "" {
		return "pragma " + e.name + ": " + e.str
	}
	return "pragma " + e.name + ": want=" + fmtInt(e.want) + " got=" + fmtInt(e.got)
}

func fmtInt(i int) string {
	// strconv would be fine but the test stays dep-free.
	if i == 0 {
		return "0"
	}
	neg := false
	if i < 0 {
		neg = true
		i = -i
	}
	out := []byte{}
	for i > 0 {
		out = append([]byte{byte('0' + i%10)}, out...)
		i /= 10
	}
	if neg {
		return "-" + string(out)
	}
	return string(out)
}

// buildDSN must thread the pragmas as `_pragma=k(v)` query params.
// modernc.org/sqlite reads these on EVERY pool dial.
func TestBuildDSN_AppliesPragmas(t *testing.T) {
	dsn := buildDSN(filepath.Join("/tmp", "x.db"), map[string]string{
		"journal_mode": "WAL",
		"busy_timeout": "2000",
	})
	wantFragments := []string{
		"file:/tmp/x.db",
		"_pragma=",
		"journal_mode%28WAL%29",
		"busy_timeout%282000%29",
	}
	for _, f := range wantFragments {
		if !strings.Contains(dsn, f) {
			t.Errorf("DSN missing %q: %s", f, dsn)
		}
	}
}
