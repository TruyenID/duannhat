package handler

import (
	"testing"
	"time"
)

// #2422 — how long the 釣銭機 waits before giving up is a per-shop number, and
// the machine KEEPS the customer's cash when it fires. It is read from the
// Cloud-synced peripheral row PER TRANSACTION (like the adapter URL), so an
// edit in admin takes effect on the next sale without restarting the
// workstation.
//
// Bad values read as the default rather than erroring: this sits on the sales
// path, Cloud already rejects them at registration, and a workstation that
// refused to sell over an odd number would be choosing the worse failure.
func TestCashChangerDepositTimeout_FromRegistryMetadata(t *testing.T) {
	cases := []struct {
		name     string
		metadata string // "" = no coin_changer row at all
		want     time.Duration
	}{
		{"unset — every existing shop keeps 300s", `{"host":"192.168.251.120"}`, 300 * time.Second},
		{"no machine registered", "", 300 * time.Second},
		{"configured 600s", `{"host":"h","deposit_timeout_seconds":600}`, 600 * time.Second},
		{"configured as a string (metadata is free-form JSON)", `{"host":"h","deposit_timeout_seconds":"90"}`, 90 * time.Second},
		{"lower bound accepted", `{"host":"h","deposit_timeout_seconds":30}`, 30 * time.Second},
		{"upper bound accepted", `{"host":"h","deposit_timeout_seconds":86400}`, 86400 * time.Second},

		// 0 is the Glory API's "wait forever". Honouring it would leave the
		// machine holding cash with no terminal state for the POS to clear, so
		// it reads as unset.
		{"zero is NOT wait-forever", `{"host":"h","deposit_timeout_seconds":0}`, 300 * time.Second},
		{"negative", `{"host":"h","deposit_timeout_seconds":-5}`, 300 * time.Second},
		{"below the floor", `{"host":"h","deposit_timeout_seconds":29}`, 300 * time.Second},
		{"above the ceiling", `{"host":"h","deposit_timeout_seconds":86401}`, 300 * time.Second},
		{"not a number", `{"host":"h","deposit_timeout_seconds":"soon"}`, 300 * time.Second},
		{"null", `{"host":"h","deposit_timeout_seconds":null}`, 300 * time.Second},
		{"metadata is not valid JSON", `{oops`, 300 * time.Second},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			s := newFireTestServer(t)
			if tc.metadata != "" {
				if _, err := s.db.Exec(
					`INSERT INTO peripheral_devices (id, name, type, is_active, metadata)
					 VALUES ('cc1', 'Counter 釣銭機', 'coin_changer', 1, ?)`, tc.metadata,
				); err != nil {
					t.Fatal(err)
				}
			}

			if got := s.cashChangerDepositTimeout(); got != tc.want {
				t.Errorf("cashChangerDepositTimeout() = %v, want %v", got, tc.want)
			}
		})
	}
}

// An INACTIVE machine must not supply the timeout — same rule the adapter URL
// already follows, so a decommissioned row cannot steer a live sale.
func TestCashChangerDepositTimeout_IgnoresInactiveMachine(t *testing.T) {
	s := newFireTestServer(t)
	if _, err := s.db.Exec(
		`INSERT INTO peripheral_devices (id, name, type, is_active, metadata)
		 VALUES ('cc-old', 'Retired 釣銭機', 'coin_changer', 0, ?)`,
		`{"host":"h","deposit_timeout_seconds":900}`,
	); err != nil {
		t.Fatal(err)
	}

	if got := s.cashChangerDepositTimeout(); got != 300*time.Second {
		t.Errorf("an inactive machine steered the timeout: got %v", got)
	}
}

// The value is read per call, not cached at construction: editing it in admin
// (synced DOWN into the same row) must change the NEXT sale.
func TestCashChangerDepositTimeout_ResolvesPerCallNotAtStartup(t *testing.T) {
	s := newFireTestServer(t)
	if _, err := s.db.Exec(
		`INSERT INTO peripheral_devices (id, name, type, is_active, metadata)
		 VALUES ('cc1', 'Counter 釣銭機', 'coin_changer', 1, ?)`,
		`{"host":"h","deposit_timeout_seconds":120}`,
	); err != nil {
		t.Fatal(err)
	}
	if got := s.cashChangerDepositTimeout(); got != 120*time.Second {
		t.Fatalf("precondition: got %v", got)
	}

	// HQ raises it; the row syncs DOWN. No restart.
	if _, err := s.db.Exec(
		`UPDATE peripheral_devices SET metadata = ? WHERE id = 'cc1'`,
		`{"host":"h","deposit_timeout_seconds":600}`,
	); err != nil {
		t.Fatal(err)
	}

	if got := s.cashChangerDepositTimeout(); got != 600*time.Second {
		t.Errorf("timeout did not follow the updated registry row: got %v", got)
	}
}
