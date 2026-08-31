package service

import (
	"crypto/ed25519"
	"crypto/rand"
	"database/sql"
	"encoding/base64"
	"errors"
	"fmt"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
)

// Device signing keypair storage (#1093/#1094 — workstation half).
//
// The PRIVATE key never leaves this machine: it is generated here, stored in
// the local settings table, and only the public half is registered with Cloud
// (at pair time, or via POST /workstation/keys/rotate). Cloud can therefore
// verify what this device signed without ever being able to sign as it.
//
// Settings keys (local `settings` table, same store as tenancy):
//   offline.signing.private_key  base64 Ed25519 private key (64 bytes)
//   offline.signing.public_key   base64 Ed25519 public key (32 bytes)
//   offline.signing.key_id       the id Cloud assigned to the public key
//   offline.signing.expires_at   RFC3339 expiry Cloud reported

// signingKeyRenewWindow is how long before expiry the device starts trying to
// rotate. Cloud issues 180-day keys and keeps the OLD one valid until its own
// expiry, so a 30-day window gives ~30 days of retries across shop closures,
// network outages and machines that only run during business hours — while the
// current key still signs perfectly well throughout.
const signingKeyRenewWindow = 30 * 24 * time.Hour

const (
	settingOfflinePrivateKey = "offline.signing.private_key"
	settingOfflinePublicKey  = "offline.signing.public_key"
	settingOfflineKeyID      = "offline.signing.key_id"
	settingOfflineExpiresAt  = "offline.signing.expires_at"

	// offlineEvidenceWindow is how long a signed offline order stays
	// verifiable. It must not exceed Cloud's ceiling
	// (OfflineOrderEvidenceVerifier::MAX_EVIDENCE_WINDOW_HOURS = 72h) or Cloud
	// rejects the evidence as an unbounded self-granted licence.
	offlineEvidenceWindow = 60 * time.Hour
)

// ErrNoSigningKey means this device has not registered a signing key yet, so it
// cannot produce offline evidence. Callers fall back to the legacy sync path.
var ErrNoSigningKey = errors.New("offline signing: no registered key on this device")

// OfflineKeyStore owns the device's signing material.
type OfflineKeyStore struct {
	db *store.DB
}

func NewOfflineKeyStore(db *store.DB) *OfflineKeyStore {
	return &OfflineKeyStore{db: db}
}

// EnsureKeypair returns the device's public key, generating and persisting a
// fresh keypair on first call. Idempotent: an existing keypair is reused, so a
// restart never invalidates evidence already queued for sync.
func (s *OfflineKeyStore) EnsureKeypair() (publicKeyBase64 string, err error) {
	if existing := s.setting(settingOfflinePublicKey); existing != "" {
		return existing, nil
	}

	pub, priv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		return "", fmt.Errorf("offline signing: generate keypair: %w", err)
	}

	pubB64 := base64.StdEncoding.EncodeToString(pub)
	if err := s.putSettings(map[string]string{
		settingOfflinePrivateKey: base64.StdEncoding.EncodeToString(priv),
		settingOfflinePublicKey:  pubB64,
	}); err != nil {
		return "", err
	}

	return pubB64, nil
}

// Rotate generates a NEW keypair and returns its public half for registration.
// The old private key is overwritten only after the caller confirms Cloud
// accepted the new one (see AdoptRegisteredKey) — otherwise a failed rotation
// would leave the device unable to sign at all.
func (s *OfflineKeyStore) Rotate() (publicKeyBase64 string, privateKeyBase64 string, err error) {
	pub, priv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		return "", "", fmt.Errorf("offline signing: rotate keypair: %w", err)
	}

	return base64.StdEncoding.EncodeToString(pub),
		base64.StdEncoding.EncodeToString(priv),
		nil
}

// AdoptRegisteredKey records the id Cloud assigned to a public key. Called
// after a successful pair or rotate; `privateKeyBase64` is non-empty only for a
// rotation (the pair flow already persisted its key via EnsureKeypair).
func (s *OfflineKeyStore) AdoptRegisteredKey(keyID, expiresAt, publicKeyBase64, privateKeyBase64 string) error {
	values := map[string]string{
		settingOfflineKeyID:     keyID,
		settingOfflineExpiresAt: expiresAt,
	}
	if privateKeyBase64 != "" {
		values[settingOfflinePrivateKey] = privateKeyBase64
		values[settingOfflinePublicKey] = publicKeyBase64
	}

	return s.putSettings(values)
}

// Forget clears every trace of the signing material — called on unpair, where
// Cloud has already revoked the keys. Leaving a private key behind on a device
// handed to another shop would be a real compromise.
func (s *OfflineKeyStore) Forget() error {
	_, err := s.db.Exec(
		`DELETE FROM settings WHERE key IN (?, ?, ?, ?)`,
		settingOfflinePrivateKey, settingOfflinePublicKey, settingOfflineKeyID, settingOfflineExpiresAt,
	)

	return err
}

// SigningMaterial is everything needed to sign one offline order.
type SigningMaterial struct {
	KeyID      string
	PrivateKey ed25519.PrivateKey
	ExpiresAt  time.Time
}

// Material loads the current signing material, or ErrNoSigningKey when this
// device cannot sign (never registered, or the key has expired locally). The
// expiry check is local defence in depth: signing with a key Cloud will reject
// only produces evidence that fails verification later, when the money is
// already collected.
func (s *OfflineKeyStore) Material() (SigningMaterial, error) {
	keyID := s.setting(settingOfflineKeyID)
	privB64 := s.setting(settingOfflinePrivateKey)
	if keyID == "" || privB64 == "" {
		return SigningMaterial{}, ErrNoSigningKey
	}

	raw, err := base64.StdEncoding.DecodeString(privB64)
	if err != nil || len(raw) != ed25519.PrivateKeySize {
		return SigningMaterial{}, ErrOfflineSigningKeyInvalid
	}

	material := SigningMaterial{KeyID: keyID, PrivateKey: ed25519.PrivateKey(raw)}

	if expires := s.setting(settingOfflineExpiresAt); expires != "" {
		parsed, perr := time.Parse(time.RFC3339, expires)
		if perr == nil {
			material.ExpiresAt = parsed
			if !time.Now().UTC().Before(parsed) {
				return SigningMaterial{}, fmt.Errorf("%w: key %s expired at %s", ErrNoSigningKey, keyID, expires)
			}
		}
	}

	return material, nil
}

// EvidenceFor signs one offline order and returns the envelope + signature to
// persist beside it. `catalogRevision` is the version pulled with the menu; 0
// means the device has no revision yet and therefore cannot produce verifiable
// evidence (Cloud would reject `unknown_catalog_revision`).
//
// `toppingsSnapshotted` is the menu payload's `catalog_revision_has_toppings`
// flag (#1114): true when the claimed revision carries the '@toppings' price
// branch, so Cloud can verify topping money. False (old Cloud, or a revision
// minted before topping snapshots) keeps the old behaviour — a topping-bearing
// order is refused here and takes the legacy path instead of queueing evidence
// the verifier is guaranteed to reject.
func (s *OfflineKeyStore) EvidenceFor(deviceID string, catalogRevision int, toppingsSnapshotted bool, sel OfflineSelection, now time.Time) (env OfflineEvidenceEnvelope, signature string, err error) {
	if deviceID == "" {
		return env, "", fmt.Errorf("%w: device id unknown", ErrNoSigningKey)
	}
	if catalogRevision <= 0 {
		return env, "", fmt.Errorf("%w: no catalog revision synced yet", ErrNoSigningKey)
	}
	for _, line := range sel.Lines {
		if len(line.Toppings) > 0 && !toppingsSnapshotted {
			return env, "", fmt.Errorf("%w: order has toppings but revision %d carries no topping prices", ErrNoSigningKey, catalogRevision)
		}
		// The OFF-MENU guard stays unconditionally: the catalog snapshot is
		// keyed by menu line, so Cloud has no recorded historical price to
		// verify an off-menu line against and would reject it — better the
		// legacy path than evidence guaranteed to fail after the money is
		// already in the till.
		if line.MenuProductSkuID == nil || *line.MenuProductSkuID == "" {
			return env, "", fmt.Errorf("%w: line is not menu-anchored", ErrNoSigningKey)
		}
	}

	material, err := s.Material()
	if err != nil {
		return env, "", err
	}

	utc := now.UTC().Truncate(time.Second)
	env = OfflineEvidenceEnvelope{
		DeviceID:        deviceID,
		IssuerID:        deviceID,
		CatalogRevision: catalogRevision,
		IssuedAt:        utc.Format(time.RFC3339),
		ExpiresAt:       utc.Add(offlineEvidenceWindow).Format(time.RFC3339),
		KeyID:           material.KeyID,
	}

	signature, _, err = SignOfflineOrder(material.PrivateKey, env, sel)
	if err != nil {
		return OfflineEvidenceEnvelope{}, "", err
	}

	return env, signature, nil
}

func (s *OfflineKeyStore) setting(key string) string {
	var val string
	_ = s.db.QueryRow(`SELECT value FROM settings WHERE key = ?`, key).Scan(&val)

	return val
}

func (s *OfflineKeyStore) putSettings(values map[string]string) error {
	// One transaction: a half-written keypair (private without public, or a key
	// id pointing at a key that was not stored) would leave the device signing
	// with material Cloud does not know.
	return s.db.Transaction(func(tx *sql.Tx) error {
		for key, value := range values {
			if _, err := tx.Exec(`
				INSERT INTO settings (key, value) VALUES (?, ?)
				ON CONFLICT(key) DO UPDATE SET value = excluded.value`, key, value); err != nil {
				return fmt.Errorf("offline signing: persist %s: %w", key, err)
			}
		}

		return nil
	})
}
