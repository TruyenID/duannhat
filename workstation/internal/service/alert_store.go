package service

import (
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
	"github.com/google/uuid"
)

// ErrAlertKindNotRegistered is returned when a caller tries to raise a kind
// that is not in the allowlist (alert_kinds.go). It is an error rather than a
// silent insert on purpose: the allowlist only holds if breaking it is loud.
var ErrAlertKindNotRegistered = errors.New("alert kind not registered")

// Alert is one condition a human should act on.
type Alert struct {
	ID          string          `json:"id"`
	Kind        AlertKind       `json:"kind"`
	Subject     string          `json:"subject"`
	Severity    AlertSeverity   `json:"severity"`
	Audience    AlertAudience   `json:"audience"`
	Title       string          `json:"title"`
	Detail      map[string]any  `json:"detail"`
	FirstSeenAt time.Time       `json:"first_seen_at"`
	LastSeenAt  time.Time       `json:"last_seen_at"`
	Count       int             `json:"count"`
	ResolvedAt  *time.Time      `json:"resolved_at,omitempty"`
	ResolvedBy  string          `json:"resolved_by,omitempty"`
	Resolution  AlertResolution `json:"resolution,omitempty"`
}

// AlertStore owns the `alerts` table (#1806 S1).
type AlertStore struct {
	db  *store.DB
	now func() time.Time // injectable for tests
}

func NewAlertStore(db *store.DB) *AlertStore {
	return &AlertStore{db: db, now: func() time.Time { return time.Now().UTC() }}
}

// Raise records that a condition EXISTS.
//
// It is deliberately idempotent on (kind, subject) among OPEN rows: raising the
// same condition again bumps `count` and moves `last_seen_at` instead of adding
// a row. A printer offline for an hour is one alert, not 3600 — without that the
// panel is a log with extra steps, and the alert that mattered is pushed off the
// screen by the one that repeats.
//
// A kind outside the allowlist is REFUSED, not inserted.
func (s *AlertStore) Raise(kind AlertKind, subject, title string, detail map[string]any) (string, error) {
	severity, audience, _, ok := AlertPolicyFor(kind)
	if !ok {
		return "", fmt.Errorf("%w: %s", ErrAlertKindNotRegistered, kind)
	}
	if detail == nil {
		detail = map[string]any{}
	}
	payload, err := json.Marshal(detail)
	if err != nil {
		return "", fmt.Errorf("alert detail: %w", err)
	}
	now := s.now().UTC().Format(time.RFC3339)

	// ONE atomic upsert, deliberately NOT select-then-insert.
	//
	// The read-then-write version had a TOCTOU race, and it was not theoretical:
	// 16 concurrent raisers of the same condition measured 1 failure with
	// `UNIQUE constraint failed: alerts.kind, alerts.subject`. It happens in a
	// real shop whenever two terminals hit the same condition at once — two
	// prints while the kitchen printer is unplugged, two POS seeing the same
	// retained cash — and because alerts are raised fire-and-forget, that error
	// gets swallowed and the alert is simply LOST. An alert centre that drops
	// alerts under load is worse than none: it looks like it is watching.
	//
	// The conflict target names the PARTIAL index (kind, subject) WHERE
	// resolved_at IS NULL, so a resolved row never absorbs a new occurrence and
	// recurrence still opens a fresh alert.
	var id string
	if err := s.db.QueryRow(
		`INSERT INTO alerts (id, kind, subject, severity, audience, title, detail,
		                     first_seen_at, last_seen_at, count)
		 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
		 ON CONFLICT (kind, subject) WHERE resolved_at IS NULL
		 DO UPDATE SET last_seen_at = excluded.last_seen_at,
		               count        = alerts.count + 1,
		               title        = excluded.title,
		               detail       = excluded.detail
		 RETURNING id`,
		uuid.NewString(), string(kind), subject, string(severity), string(audience),
		title, string(payload), now, now,
	).Scan(&id); err != nil {
		return "", err
	}
	slog.Info("alert raised", "kind", kind, "subject", subject, "severity", severity, "audience", audience)
	return id, nil
}

// ResolveAuto closes an open alert because the workstation RE-MEASURED and the
// condition was gone (the printer printed, the queue drained, pairing landed).
//
// It refuses kinds whose policy says they cannot auto-resolve. Cash physically
// inside a machine is the case that matters: the only thing that proves it ended
// is a person opening the machine and counting, so a probe that merely knows the
// session is over must not be allowed to clear it.
func (s *AlertStore) ResolveAuto(kind AlertKind, subject string) error {
	_, _, autoResolvable, ok := AlertPolicyFor(kind)
	if !ok {
		return fmt.Errorf("%w: %s", ErrAlertKindNotRegistered, kind)
	}
	if !autoResolvable {
		return nil // not an error — the condition simply cannot be cleared this way
	}
	_, err := s.db.Exec(
		`UPDATE alerts SET resolved_at = ?, resolution = ?
		 WHERE kind = ? AND subject = ? AND resolved_at IS NULL`,
		s.now().UTC().Format(time.RFC3339), string(ResolutionAuto), string(kind), subject,
	)
	return err
}

// Ack closes an alert because a HUMAN asserted it is handled. Works for every
// kind, including the ones with no probe.
func (s *AlertStore) Ack(id, by string) error {
	res, err := s.db.Exec(
		`UPDATE alerts SET resolved_at = ?, resolved_by = ?, resolution = ?
		 WHERE id = ? AND resolved_at IS NULL`,
		s.now().UTC().Format(time.RFC3339), by, string(ResolutionAck), id,
	)
	if err != nil {
		return err
	}
	if n, _ := res.RowsAffected(); n == 0 {
		return sql.ErrNoRows
	}
	return nil
}

// AckKind closes EVERY open alert of one kind in a single human assertion —
// the bulk path for kinds whose subject is fine-grained (order id) and whose
// một-đợt-lệch rải ra hàng chục dòng (#2167). `by` bắt buộc như Ack. Trả về số
// dòng đã đóng; 0 không phải lỗi (không còn gì để ack là một câu trả lời).
func (s *AlertStore) AckKind(kind AlertKind, by string) (int, error) {
	if _, _, _, ok := AlertPolicyFor(kind); !ok {
		return 0, fmt.Errorf("%w: %s", ErrAlertKindNotRegistered, kind)
	}
	res, err := s.db.Exec(
		`UPDATE alerts SET resolved_at = ?, resolved_by = ?, resolution = ?
		 WHERE kind = ? AND resolved_at IS NULL`,
		s.now().UTC().Format(time.RFC3339), by, string(ResolutionAck), string(kind),
	)
	if err != nil {
		return 0, err
	}
	n, _ := res.RowsAffected()
	return int(n), nil
}

// ListOpen returns the open alerts, worst first then freshest first — the order
// the panel reads in.
func (s *AlertStore) ListOpen() ([]Alert, error) {
	rows, err := s.db.Query(
		`SELECT id, kind, subject, severity, audience, title, detail,
		        first_seen_at, last_seen_at, count
		 FROM alerts
		 WHERE resolved_at IS NULL
		 ORDER BY CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END,
		          last_seen_at DESC`,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	out := []Alert{}
	for rows.Next() {
		var a Alert
		var kind, severity, audience, detail, first, last string
		if err := rows.Scan(&a.ID, &kind, &a.Subject, &severity, &audience,
			&a.Title, &detail, &first, &last, &a.Count); err != nil {
			return nil, err
		}
		a.Kind = AlertKind(kind)
		a.Severity = AlertSeverity(severity)
		a.Audience = AlertAudience(audience)
		a.FirstSeenAt, _ = time.Parse(time.RFC3339, first)
		a.LastSeenAt, _ = time.Parse(time.RFC3339, last)
		a.Detail = map[string]any{}
		_ = json.Unmarshal([]byte(detail), &a.Detail)
		out = append(out, a)
	}
	return out, rows.Err()
}
