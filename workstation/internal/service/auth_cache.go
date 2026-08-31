package service

import (
	"context"
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"errors"
	"log/slog"
	"time"

	"github.com/dxs-platform/workstation-app/internal/domain"
	"github.com/dxs-platform/workstation-app/internal/store"
)

// DefaultAuthCacheTTL is how long a verified token stays valid in cache
// before the middleware must re-verify with Cloud.
const DefaultAuthCacheTTL = 5 * time.Minute

// AuthCacheStore wraps the auth_token_cache table.
// All entries are keyed by SHA-256 hex of the plain Bearer token.
type AuthCacheStore struct {
	db  *store.DB
	ttl time.Duration
}

func NewAuthCacheStore(db *store.DB, ttl time.Duration) *AuthCacheStore {
	if ttl <= 0 {
		ttl = DefaultAuthCacheTTL
	}
	return &AuthCacheStore{db: db, ttl: ttl}
}

// HashToken returns the SHA-256 hex of a plain Bearer token.
func HashToken(plain string) string {
	sum := sha256.Sum256([]byte(plain))
	return hex.EncodeToString(sum[:])
}

// ErrAuthCacheMiss is returned when the token hash is not present.
var ErrAuthCacheMiss = errors.New("auth cache miss")

// Get returns the cache entry for a token hash. ok=true if found and not expired.
// stale=true means an entry exists but is past expires_at (caller may use it
// as a soft fallback when Cloud is unreachable).
func (s *AuthCacheStore) Get(tokenHash string) (entry *domain.AuthTokenCache, ok bool, stale bool) {
	row := s.db.QueryRow(`
		SELECT token_hash, identity_type, device_id, device_type,
		       user_id, user_name, user_email,
		       branch_id, device_info, verified_at, expires_at
		FROM auth_token_cache
		WHERE token_hash = ?`, tokenHash)

	var (
		e                                                 domain.AuthTokenCache
		deviceID, deviceType                              sql.NullString
		userID, userName, userEmail, branchID, deviceInfo sql.NullString
		verifiedRaw, expiresRaw                           string
	)
	if err := row.Scan(&e.TokenHash, &e.IdentityType,
		&deviceID, &deviceType,
		&userID, &userName, &userEmail,
		&branchID, &deviceInfo, &verifiedRaw, &expiresRaw); err != nil {
		return nil, false, false
	}
	e.DeviceID = deviceID.String
	e.DeviceType = deviceType.String
	e.UserID = userID.String
	e.UserName = userName.String
	e.UserEmail = userEmail.String
	e.BranchID = branchID.String
	e.DeviceInfo = deviceInfo.String
	e.VerifiedAt, _ = parseSQLiteTime(verifiedRaw)
	e.ExpiresAt, _ = parseSQLiteTime(expiresRaw)

	if time.Now().After(e.ExpiresAt) {
		return &e, false, true
	}
	return &e, true, false
}

// Put inserts or refreshes a cache entry for a device-token identity. Kept
// for backward compatibility with existing kiosk/tms code; new code should
// use PutIdentity which also supports SSO user tokens.
func (s *AuthCacheStore) Put(tokenHash, deviceID, deviceType, branchID, deviceInfo string) error {
	return s.PutIdentity(tokenHash, &Identity{
		Type:       domain.IdentityTypeDevice,
		DeviceID:   deviceID,
		DeviceType: deviceType,
		BranchID:   branchID,
	}, deviceInfo)
}

// PutIdentity inserts or refreshes a cache entry from a verified Identity.
// Replaces the older Put(deviceID, deviceType, branchID, deviceInfo) signature.
func (s *AuthCacheStore) PutIdentity(tokenHash string, identity *Identity, deviceInfo string) error {
	now := time.Now().UTC()
	expires := now.Add(s.ttl)
	_, err := s.db.Exec(`
		INSERT INTO auth_token_cache
		    (token_hash, identity_type, device_id, device_type,
		     user_id, user_name, user_email,
		     branch_id, device_info, verified_at, expires_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
		ON CONFLICT(token_hash) DO UPDATE SET
		    identity_type=excluded.identity_type,
		    device_id=excluded.device_id,
		    device_type=excluded.device_type,
		    user_id=excluded.user_id,
		    user_name=excluded.user_name,
		    user_email=excluded.user_email,
		    branch_id=excluded.branch_id,
		    device_info=excluded.device_info,
		    verified_at=excluded.verified_at,
		    expires_at=excluded.expires_at`,
		tokenHash, identity.Type,
		nullableString(identity.DeviceID), nullableString(identity.DeviceType),
		nullableString(identity.UserID), nullableString(identity.UserName), nullableString(identity.UserEmail),
		nullableString(identity.BranchID), nullableString(deviceInfo),
		now.Format(time.RFC3339Nano), expires.Format(time.RFC3339Nano))
	return err
}

// Delete removes a cache entry by token hash. No-op if not present.
func (s *AuthCacheStore) Delete(tokenHash string) error {
	_, err := s.db.Exec(`DELETE FROM auth_token_cache WHERE token_hash = ?`, tokenHash)
	return err
}

// DeleteByDeviceID removes ALL cache entries for a device. Used when Cloud
// pushes a device.revoked event.
func (s *AuthCacheStore) DeleteByDeviceID(deviceID string) (int, error) {
	res, err := s.db.Exec(`DELETE FROM auth_token_cache WHERE device_id = ?`, deviceID)
	if err != nil {
		return 0, err
	}
	n, _ := res.RowsAffected()
	return int(n), nil
}

// Cleanup removes entries whose expires_at is older than now - staleThreshold.
// Returns the number of rows deleted.
func (s *AuthCacheStore) Cleanup(staleThreshold time.Duration) (int, error) {
	cutoff := time.Now().Add(-staleThreshold).UTC().Format(time.RFC3339Nano)
	res, err := s.db.Exec(`DELETE FROM auth_token_cache WHERE expires_at < ?`, cutoff)
	if err != nil {
		return 0, err
	}
	n, _ := res.RowsAffected()
	return int(n), nil
}

// RunCleanupLoop periodically removes very-old expired entries. Block until ctx is done.
func (s *AuthCacheStore) RunCleanupLoop(ctx context.Context, interval, staleThreshold time.Duration) {
	t := time.NewTicker(interval)
	defer t.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-t.C:
			if n, err := s.Cleanup(staleThreshold); err != nil {
				slog.Error("auth cache cleanup", "err", err)
			} else if n > 0 {
				slog.Debug("auth cache cleanup", "removed", n)
			}
		}
	}
}

func nullableString(s string) any {
	if s == "" {
		return nil
	}
	return s
}

// parseSQLiteTime handles RFC3339Nano (our writer), RFC3339 (legacy), and
// "YYYY-MM-DD HH:MM:SS" (SQLite default for datetime('now')).
func parseSQLiteTime(s string) (time.Time, error) {
	for _, layout := range []string{time.RFC3339Nano, time.RFC3339, "2006-01-02 15:04:05"} {
		if t, err := time.Parse(layout, s); err == nil {
			return t, nil
		}
	}
	return time.Time{}, errors.New("unrecognized time format: " + s)
}
