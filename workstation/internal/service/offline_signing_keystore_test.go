package service

import (
	"crypto/ed25519"
	"encoding/base64"
	"errors"
	"testing"
	"time"
)

// #1094 — the device half of offline evidence. These tests care about one thing
// above all: a device must NEVER queue evidence Cloud is guaranteed to reject,
// because by then the money is already in the till.

func keystoreForTest(t *testing.T) *OfflineKeyStore {
	t.Helper()
	e, db := newOrderEngineForTest(t)
	_ = e

	return NewOfflineKeyStore(db)
}

func sampleOfflineSelection(menuSkuID string) OfflineSelection {
	id := menuSkuID

	return OfflineSelection{
		Lines: []OfflineSelectionLine{{
			LineID:           "line-1",
			MenuProductSkuID: &id,
			Quantity:         2,
		}},
		OrderType:  "dine_in",
		PickupType: "immediate",
		Locale:     "ja",
		Channel:    "workstation",
	}
}

func TestOfflineKeyStore_EnsureKeypairIsStableAcrossCalls(t *testing.T) {
	ks := keystoreForTest(t)

	first, err := ks.EnsureKeypair()
	if err != nil {
		t.Fatalf("ensure: %v", err)
	}
	second, err := ks.EnsureKeypair()
	if err != nil {
		t.Fatalf("ensure again: %v", err)
	}

	if first != second {
		t.Error("a second EnsureKeypair minted a NEW key — evidence already queued under the old key would become unverifiable")
	}

	raw, err := base64.StdEncoding.DecodeString(first)
	if err != nil || len(raw) != ed25519.PublicKeySize {
		t.Fatalf("public key is not a 32-byte Ed25519 key: err=%v len=%d", err, len(raw))
	}
}

func TestOfflineKeyStore_CannotSignWithoutARegisteredKeyID(t *testing.T) {
	ks := keystoreForTest(t)
	if _, err := ks.EnsureKeypair(); err != nil {
		t.Fatalf("ensure: %v", err)
	}

	// A keypair exists locally but Cloud has not issued a key id yet.
	if _, err := ks.Material(); !errors.Is(err, ErrNoSigningKey) {
		t.Errorf("expected ErrNoSigningKey before registration, got %v", err)
	}
}

func TestOfflineKeyStore_SignsOnlyWhatCloudCanVerify(t *testing.T) {
	ks := keystoreForTest(t)
	pub, err := ks.EnsureKeypair()
	if err != nil {
		t.Fatalf("ensure: %v", err)
	}
	if err := ks.AdoptRegisteredKey("key-1", time.Now().UTC().Add(180*24*time.Hour).Format(time.RFC3339), pub, ""); err != nil {
		t.Fatalf("adopt: %v", err)
	}

	now := time.Now().UTC()
	sel := sampleOfflineSelection("msku-1")

	t.Run("a menu-anchored topping-free order is signed and self-verifies", func(t *testing.T) {
		env, sig, err := ks.EvidenceFor("dev-1", 41, false, sel, now)
		if err != nil {
			t.Fatalf("evidence: %v", err)
		}
		if env.KeyID != "key-1" || env.DeviceID != "dev-1" || env.IssuerID != "dev-1" || env.CatalogRevision != 41 {
			t.Errorf("envelope not stamped as expected: %+v", env)
		}
		// Second precision, RFC3339 — the string is signed verbatim, so the
		// format is part of the contract with Cloud.
		if _, err := time.Parse(time.RFC3339, env.IssuedAt); err != nil {
			t.Errorf("issued_at is not RFC3339: %q", env.IssuedAt)
		}
		if !VerifyOfflineSignature(pub, sig, OfflineSigningMessage(env, OfflineSelectionDigest(sel))) {
			t.Error("the device produced a signature its own public key cannot verify")
		}
	})

	t.Run("the evidence window stays inside Cloud's 72h ceiling", func(t *testing.T) {
		env, _, err := ks.EvidenceFor("dev-1", 41, false, sel, now)
		if err != nil {
			t.Fatalf("evidence: %v", err)
		}
		issued, _ := time.Parse(time.RFC3339, env.IssuedAt)
		expires, _ := time.Parse(time.RFC3339, env.ExpiresAt)
		if window := expires.Sub(issued); window > 72*time.Hour {
			t.Errorf("evidence window %s exceeds Cloud's 72h ceiling — Cloud would reject every offline order", window)
		}
	})

	t.Run("refuses toppings when the revision carries no topping prices (pre-#1114 revision)", func(t *testing.T) {
		withTopping := sampleOfflineSelection("msku-1")
		withTopping.Lines[0].Toppings = []OfflineSelectionTopping{
			{ToppingGroupItemID: "tg-1", ProductSkuID: "sku-t", Quantity: 1},
		}
		if _, _, err := ks.EvidenceFor("dev-1", 41, false, withTopping, now); !errors.Is(err, ErrNoSigningKey) {
			t.Errorf("expected a refusal for a topping-bearing order, got %v", err)
		}
	})

	t.Run("signs a topping order when the revision snapshots topping prices (#1114)", func(t *testing.T) {
		withTopping := sampleOfflineSelection("msku-1")
		withTopping.Lines[0].Toppings = []OfflineSelectionTopping{
			{ToppingGroupItemID: "tg-1", ProductSkuID: "sku-t", Quantity: 1},
		}
		env, sig, err := ks.EvidenceFor("dev-1", 41, true, withTopping, now)
		if err != nil {
			t.Fatalf("a topping order against a topping-snapshotted revision must sign: %v", err)
		}
		// The toppings are part of the signed selection digest — a post-sign
		// topping rewrite must break the signature.
		if !VerifyOfflineSignature(pub, sig, OfflineSigningMessage(env, OfflineSelectionDigest(withTopping))) {
			t.Error("topping order signature does not self-verify")
		}
		withTopping.Lines[0].Toppings[0].Quantity = 5
		if VerifyOfflineSignature(pub, sig, OfflineSigningMessage(env, OfflineSelectionDigest(withTopping))) {
			t.Error("signature still verifies after the topping quantity was rewritten")
		}
	})

	t.Run("refuses to sign an off-menu line", func(t *testing.T) {
		offMenu := sampleOfflineSelection("msku-1")
		offMenu.Lines[0].MenuProductSkuID = nil
		if _, _, err := ks.EvidenceFor("dev-1", 41, false, offMenu, now); !errors.Is(err, ErrNoSigningKey) {
			t.Errorf("expected a refusal for an off-menu line, got %v", err)
		}
	})

	t.Run("refuses to sign before any catalog revision has synced", func(t *testing.T) {
		if _, _, err := ks.EvidenceFor("dev-1", 0, false, sel, now); !errors.Is(err, ErrNoSigningKey) {
			t.Errorf("expected a refusal with no catalog revision, got %v", err)
		}
	})

	t.Run("refuses to sign with an unknown device id", func(t *testing.T) {
		if _, _, err := ks.EvidenceFor("", 41, false, sel, now); !errors.Is(err, ErrNoSigningKey) {
			t.Errorf("expected a refusal without a device id, got %v", err)
		}
	})
}

func TestOfflineKeyStore_RefusesToSignWithALocallyExpiredKey(t *testing.T) {
	ks := keystoreForTest(t)
	pub, _ := ks.EnsureKeypair()
	// Cloud said this key expired yesterday.
	if err := ks.AdoptRegisteredKey("key-1", time.Now().UTC().Add(-24*time.Hour).Format(time.RFC3339), pub, ""); err != nil {
		t.Fatalf("adopt: %v", err)
	}

	if _, err := ks.Material(); !errors.Is(err, ErrNoSigningKey) {
		t.Errorf("expected ErrNoSigningKey for an expired key, got %v", err)
	}
	if _, _, err := ks.EvidenceFor("dev-1", 41, false, sampleOfflineSelection("msku-1"), time.Now()); err == nil {
		t.Error("signed with an expired key — the evidence would be rejected after the money was taken")
	}
}

func TestOfflineKeyStore_RotationKeepsSigningPossibleAndSwapsTheKey(t *testing.T) {
	ks := keystoreForTest(t)
	oldPub, _ := ks.EnsureKeypair()
	expiry := time.Now().UTC().Add(180 * 24 * time.Hour).Format(time.RFC3339)
	if err := ks.AdoptRegisteredKey("key-old", expiry, oldPub, ""); err != nil {
		t.Fatalf("adopt: %v", err)
	}

	newPub, newPriv, err := ks.Rotate()
	if err != nil {
		t.Fatalf("rotate: %v", err)
	}
	if newPub == oldPub {
		t.Fatal("rotation produced the same public key")
	}

	// Before adoption the device still signs with the OLD key — a rotation that
	// failed to register must not leave the device unable to sell.
	if material, err := ks.Material(); err != nil || material.KeyID != "key-old" {
		t.Errorf("pre-adoption material = %+v (err %v), want the old key still usable", material, err)
	}

	if err := ks.AdoptRegisteredKey("key-new", expiry, newPub, newPriv); err != nil {
		t.Fatalf("adopt rotated: %v", err)
	}

	material, err := ks.Material()
	if err != nil {
		t.Fatalf("material after rotation: %v", err)
	}
	if material.KeyID != "key-new" {
		t.Errorf("key id after rotation = %q, want key-new", material.KeyID)
	}

	// The new signature verifies under the NEW public key, not the old one.
	sel := sampleOfflineSelection("msku-1")
	env, sig, err := ks.EvidenceFor("dev-1", 7, false, sel, time.Now())
	if err != nil {
		t.Fatalf("evidence: %v", err)
	}
	msg := OfflineSigningMessage(env, OfflineSelectionDigest(sel))
	if !VerifyOfflineSignature(newPub, sig, msg) {
		t.Error("signature does not verify under the rotated key")
	}
	if VerifyOfflineSignature(oldPub, sig, msg) {
		t.Error("signature verifies under the RETIRED key — the private key was not actually swapped")
	}
}

func TestOfflineKeyStore_ForgetWipesThePrivateKey(t *testing.T) {
	ks := keystoreForTest(t)
	pub, _ := ks.EnsureKeypair()
	_ = ks.AdoptRegisteredKey("key-1", time.Now().UTC().Add(time.Hour).Format(time.RFC3339), pub, "")

	if err := ks.Forget(); err != nil {
		t.Fatalf("forget: %v", err)
	}

	if _, err := ks.Material(); !errors.Is(err, ErrNoSigningKey) {
		t.Errorf("material survived Forget: %v", err)
	}
	if got := ks.setting(settingOfflinePrivateKey); got != "" {
		t.Error("the PRIVATE key survived unpair — handing this device to another shop would leak it")
	}
}

// #1311 — the renewal window is the whole reason a rotation ever happens in
// time.
//
// Cloud issues 180-day keys and nothing on the device ever asked for a new one,
// so the key simply lapsed and every offline order fell back to the unverified
// legacy path — permanently, and without a symptom. Rotation has to start while
// the current key still works: Cloud keeps the old key valid until its own
// expiry, so the window is a grace period in which either key verifies.
func TestSigningKeyRenewWindowLeavesRoomToRetry(t *testing.T) {
	// A shop that only opens some days, a flaky uplink, a machine that is off
	// overnight — the window has to be long enough to survive all of that while
	// the current key is still signing normally.
	if signingKeyRenewWindow < 7*24*time.Hour {
		t.Fatalf("renew window %v is too short to survive an outage or a closed shop", signingKeyRenewWindow)
	}
	// And short enough that it is a renewal, not a second lifetime: Cloud's key
	// lives 180 days.
	if signingKeyRenewWindow > 90*24*time.Hour {
		t.Fatalf("renew window %v is more than half the key lifetime", signingKeyRenewWindow)
	}
}

// A rotation must not disturb the working key until Cloud has confirmed the new
// one. Rotate() therefore persists nothing — the device keeps signing with what
// it has if the network call never lands.
func TestRotateDoesNotDisturbTheCurrentKeyUntilAdopted(t *testing.T) {
	ks := keystoreForTest(t)

	pub, err := ks.EnsureKeypair()
	if err != nil {
		t.Fatalf("EnsureKeypair: %v", err)
	}
	if err := ks.AdoptRegisteredKey("key-1", time.Now().Add(24*time.Hour).Format(time.RFC3339), pub, ""); err != nil {
		t.Fatalf("AdoptRegisteredKey: %v", err)
	}

	before, err := ks.Material()
	if err != nil {
		t.Fatalf("Material before rotate: %v", err)
	}

	// Generate a replacement, then walk away as a failed network call would.
	if _, _, err := ks.Rotate(); err != nil {
		t.Fatalf("Rotate: %v", err)
	}

	after, err := ks.Material()
	if err != nil {
		t.Fatalf("Material after abandoned rotate: %v", err)
	}
	if after.KeyID != before.KeyID {
		t.Fatalf("an abandoned rotation replaced the live key: %s → %s", before.KeyID, after.KeyID)
	}
	if !after.PrivateKey.Equal(before.PrivateKey) {
		t.Fatal("an abandoned rotation replaced the live private key")
	}
}

// And once Cloud confirms, the new key takes over with the new expiry.
func TestAdoptingARotatedKeyTakesOver(t *testing.T) {
	ks := keystoreForTest(t)

	pub, _ := ks.EnsureKeypair()
	_ = ks.AdoptRegisteredKey("key-1", time.Now().Add(24*time.Hour).Format(time.RFC3339), pub, "")
	before, _ := ks.Material()

	newPub, newPriv, err := ks.Rotate()
	if err != nil {
		t.Fatalf("Rotate: %v", err)
	}
	newExpiry := time.Now().Add(180 * 24 * time.Hour)
	if err := ks.AdoptRegisteredKey("key-2", newExpiry.Format(time.RFC3339), newPub, newPriv); err != nil {
		t.Fatalf("AdoptRegisteredKey: %v", err)
	}

	after, err := ks.Material()
	if err != nil {
		t.Fatalf("Material after adopt: %v", err)
	}
	if after.KeyID != "key-2" {
		t.Fatalf("key id did not take over: %s", after.KeyID)
	}
	if after.PrivateKey.Equal(before.PrivateKey) {
		t.Fatal("the private key did not change on adoption")
	}
	if !after.ExpiresAt.After(before.ExpiresAt) {
		t.Fatalf("expiry did not move forward: %v → %v", before.ExpiresAt, after.ExpiresAt)
	}
}
