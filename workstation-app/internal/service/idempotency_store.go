package service

import (
	"database/sql"
	"errors"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
)

// IdempotencyStore wraps the idempotency_keys table — KDS bump dedup.
// Caches PATCH responses by (key, device_id) so retries return cached
// response instead of double-applying status changes.
type IdempotencyStore struct {
	db *store.DB
}

func NewIdempotencyStore(db *store.DB) *IdempotencyStore {
	return &IdempotencyStore{db: db}
}

func (s *IdempotencyStore) Get(key, deviceID string) (string, bool, error) {
	var response string
	err := s.db.QueryRow(
		`SELECT response FROM idempotency_keys WHERE key = ? AND device_id = ?`,
		key, deviceID,
	).Scan(&response)
	if errors.Is(err, sql.ErrNoRows) {
		return "", false, nil
	}
	if err != nil {
		return "", false, err
	}
	return response, true, nil
}

func (s *IdempotencyStore) Put(key, deviceID, requestHash, response string) error {
	// Composite ON CONFLICT target matches migration 012's (key, device_id)
	// primary key. Previously ON CONFLICT(key) silently swallowed a second
	// device's write — see migration 012 header for the full repro.
	_, err := s.db.Exec(
		`INSERT INTO idempotency_keys (key, device_id, request_hash, response) VALUES (?, ?, ?, ?)
		 ON CONFLICT(key, device_id) DO NOTHING`,
		key, deviceID, requestHash, response,
	)
	return err
}

func (s *IdempotencyStore) CleanupOlderThan(maxAge time.Duration) (int64, error) {
	cutoff := time.Now().Add(-maxAge)
	res, err := s.db.Exec(`DELETE FROM idempotency_keys WHERE created_at < ?`, cutoff)
	if err != nil {
		return 0, err
	}
	return res.RowsAffected()
}
