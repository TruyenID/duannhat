package service

import (
	"fmt"
	"strings"
	"sync"
	"testing"
)

func TestLocalCodeGenerator_FormatCorrect(t *testing.T) {
	_, db := newOrderEngineForTest(t)
	gen := NewLocalCodeGenerator(db, "ABCDEF")

	code, err := gen.Next()
	if err != nil {
		t.Fatalf("Next: %v", err)
	}

	// Expected: "WS-ABCD-YYYYMMDD-001"
	if !strings.HasPrefix(code, "WS-ABCD-") {
		t.Errorf("expected prefix WS-ABCD-, got %q", code)
	}
	// Ends with "-001" for first code
	if !strings.HasSuffix(code, "-001") {
		t.Errorf("expected suffix -001 for first code, got %q", code)
	}
	// Length: "WS-ABCD-20260521-001" = 20 chars
	parts := strings.Split(code, "-")
	if len(parts) != 4 {
		t.Errorf("expected 4 dash-separated parts, got %d in %q", len(parts), code)
	}
	if len(parts[2]) != 8 {
		t.Errorf("expected 8-char date segment, got %q", parts[2])
	}
}

func TestLocalCodeGenerator_IncrementsCounter(t *testing.T) {
	_, db := newOrderEngineForTest(t)
	gen := NewLocalCodeGenerator(db, "TEST")

	var codes []string
	for i := 0; i < 5; i++ {
		code, err := gen.Next()
		if err != nil {
			t.Fatalf("Next[%d]: %v", i, err)
		}
		codes = append(codes, code)
	}

	// All codes must be unique
	seen := map[string]bool{}
	for _, c := range codes {
		if seen[c] {
			t.Errorf("duplicate code: %q", c)
		}
		seen[c] = true
	}

	// Counter suffix must increment: -001, -002, -003, -004, -005
	for i, code := range codes {
		expected := fmt.Sprintf("-%03d", i+1)
		if !strings.HasSuffix(code, expected) {
			t.Errorf("code[%d]=%q expected suffix %s", i, code, expected)
		}
	}
}

func TestLocalCodeGenerator_ResolvesDeviceIDFromSettingsLazily(t *testing.T) {
	_, db := newOrderEngineForTest(t)
	// Built UNPAIRED (empty device id) — matches startup before the first pair.
	gen := NewLocalCodeGenerator(db, "")

	// Pairing later writes device_id into settings; the generator must pick it
	// up on next use without a restart.
	if _, err := db.Exec(`INSERT INTO settings (key, value) VALUES ('device_id','ABCD1234')
		ON CONFLICT(key) DO UPDATE SET value = excluded.value`); err != nil {
		t.Fatalf("seed device_id: %v", err)
	}

	code, err := gen.Next()
	if err != nil {
		t.Fatalf("Next: %v", err)
	}
	if !strings.HasPrefix(code, "WS-ABCD-") {
		t.Errorf("expected lazily-resolved prefix WS-ABCD-, got %q", code)
	}
}

func TestLocalCodeGenerator_FallbackWhenUnpaired(t *testing.T) {
	_, db := newOrderEngineForTest(t)
	gen := NewLocalCodeGenerator(db, "") // no device id at build time or in settings

	code, err := gen.Next()
	if err != nil {
		t.Fatalf("Next: %v", err)
	}
	// Must NOT emit an empty device segment (the old "WS--..." bug).
	if strings.HasPrefix(code, "WS--") {
		t.Fatalf("emitted empty device segment: %q", code)
	}
	if !strings.HasPrefix(code, "WS-"+localCodeDeviceFallback+"-") {
		t.Errorf("expected WS-%s- fallback, got %q", localCodeDeviceFallback, code)
	}
}

func TestLocalCodeGenerator_DailyReset(t *testing.T) {
	_, db := newOrderEngineForTest(t)
	gen := NewLocalCodeGenerator(db, "ZZZZ")

	// Simulate "yesterday" counter by directly inserting a stale key.
	_, err := db.Exec(
		`INSERT INTO settings (key, value) VALUES ('order_code_counter_20260520', '99')
		 ON CONFLICT(key) DO UPDATE SET value = excluded.value`,
	)
	if err != nil {
		t.Fatalf("seed yesterday counter: %v", err)
	}

	// Next() for today should start at 001, not 100, because the key has a
	// different date prefix than today's date.
	code, err := gen.Next()
	if err != nil {
		t.Fatalf("Next: %v", err)
	}
	if !strings.HasSuffix(code, "-001") {
		t.Errorf("expected daily reset → suffix -001, got %q", code)
	}
}

func TestLocalCodeGenerator_ConcurrentNoDuplicates(t *testing.T) {
	_, db := newOrderEngineForTest(t)
	gen := NewLocalCodeGenerator(db, "CONC")

	const goroutines = 20
	results := make([]string, goroutines)
	errs := make([]error, goroutines)

	var wg sync.WaitGroup
	wg.Add(goroutines)
	for i := 0; i < goroutines; i++ {
		i := i
		go func() {
			defer wg.Done()
			results[i], errs[i] = gen.Next()
		}()
	}
	wg.Wait()

	for i, e := range errs {
		if e != nil {
			t.Errorf("goroutine %d error: %v", i, e)
		}
	}

	seen := map[string]bool{}
	for _, code := range results {
		if seen[code] {
			t.Errorf("duplicate code: %q", code)
		}
		seen[code] = true
	}
	if len(seen) != goroutines {
		t.Errorf("expected %d unique codes, got %d", goroutines, len(seen))
	}
}
