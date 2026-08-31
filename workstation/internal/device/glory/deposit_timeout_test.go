package glory

import (
	"context"
	"errors"
	"testing"
	"time"
)

// recordingMachine captures the StartRequest so a test can assert exactly what
// number reached 取引開始 (POST /api/v1/transactions). It aborts the collection
// right after, because the timeout is decided at start and nothing later can
// change it.
type recordingMachine struct{ got StartRequest }

var errStopAfterStart = errors.New("stop: the assertion is on StartRequest")

func (m *recordingMachine) StartTransaction(_ context.Context, req StartRequest) (string, error) {
	m.got = req
	return "", errStopAfterStart
}
func (m *recordingMachine) GetTransaction(context.Context, string) (Transaction, error) {
	return Transaction{}, errStopAfterStart
}
func (m *recordingMachine) FixDeposit(context.Context) error { return errStopAfterStart }
func (m *recordingMachine) Cancel(context.Context) error     { return errStopAfterStart }

// #2422 — the deposit timeout is what decides how long the machine waits before
// giving up and KEEPING the customer's cash. It must be resolved per
// transaction, so a shop can retune it without restarting the workstation.
func TestCollector_SendsResolvedDepositTimeout(t *testing.T) {
	cases := []struct {
		name     string
		static   time.Duration
		resolver func() time.Duration
		want     int // seconds on the wire
	}{
		{
			name:   "no resolver — the 300s build default",
			static: 300 * time.Second,
			want:   300,
		},
		{
			name:     "resolver wins over the static default",
			static:   300 * time.Second,
			resolver: func() time.Duration { return 600 * time.Second },
			want:     600,
		},
		{
			name:     "resolver returning 0 falls back — never 'wait forever'",
			static:   300 * time.Second,
			resolver: func() time.Duration { return 0 },
			want:     300,
		},
		{
			name:     "resolver returning a negative falls back",
			static:   300 * time.Second,
			resolver: func() time.Duration { return -1 },
			want:     300,
		},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			m := &recordingMachine{}
			opts := []Option{WithDepositTimeout(tc.static)}
			if tc.resolver != nil {
				opts = append(opts, WithDepositTimeoutResolver(tc.resolver))
			}

			_, err := NewCollector(m, opts...).Collect(context.Background(), 1000)
			if !errors.Is(err, errStopAfterStart) {
				t.Fatalf("Collect err = %v, want the stop sentinel", err)
			}
			if m.got.Timeout != tc.want {
				t.Errorf("StartRequest.Timeout = %d, want %d", m.got.Timeout, tc.want)
			}
		})
	}
}

// The resolver is consulted on EVERY collection, not memoised at construction:
// an operator raising the timeout in admin must see it on the next sale.
func TestCollector_ResolvesDepositTimeoutPerCollection(t *testing.T) {
	current := 120 * time.Second
	m := &recordingMachine{}
	c := NewCollector(m,
		WithDepositTimeout(300*time.Second),
		WithDepositTimeoutResolver(func() time.Duration { return current }),
	)

	_, _ = c.Collect(context.Background(), 1000)
	if m.got.Timeout != 120 {
		t.Fatalf("first collection sent %d, want 120", m.got.Timeout)
	}

	current = 900 * time.Second // HQ edits it; the row syncs DOWN

	_, _ = c.Collect(context.Background(), 1000)
	if m.got.Timeout != 900 {
		t.Errorf("second collection sent %d, want 900 — the value was frozen", m.got.Timeout)
	}
}
