package service

import (
	"crypto/ed25519"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"errors"
	"strconv"
	"strings"
)

// Offline-order evidence signing (#1092/#1094) — the Go half of
// backend/app/Services/Order/Offline/OfflineOrderSigningMessage.php.
//
// WHY A DELIMITED FORM AND NOT CANONICAL JSON: the signature is produced here
// and verified in PHP. Reproducing another language's json encoder byte for
// byte (key order, unicode/slash escaping, float formatting) is a silent-drift
// trap, and the first divergence rejects HONEST orders in production. So the
// signed bytes are a fixed-order, newline-delimited field list where every
// embedded value is a UUID, a decimal integer, an enum token, or lowercase
// sha256 hex — alphabets that cannot contain the delimiter — and free text is
// hashed instead of embedded.
//
// Both halves are pinned to the same committed fixture:
//   internal/service/testdata/offline_signing_golden.json   (this repo)
//   backend/tests/Fixtures/offline_signing_golden.json      (Cloud)
// The two files must stay byte-identical; a drift fails a test in BOTH repos
// before it can reach a device.

const (
	// offlineSigningVersion must equal OfflineOrderSigningMessage::VERSION.
	// Bump ONLY with a coordinated fleet rollout — it changes every signature.
	offlineSigningVersion = "tempo-offline-order-v1"

	// offlineAbsent marks an absent optional field.
	offlineAbsent = "~"
)

// OfflineSelectionTopping mirrors OrderToppingSelectionPayload's wire shape.
type OfflineSelectionTopping struct {
	ToppingGroupItemID string  `json:"topping_group_item_id"`
	ProductSkuID       string  `json:"product_sku_id"`
	Quantity           int     `json:"quantity"`
	Note               *string `json:"note"`
}

// OfflineSelectionLine mirrors OrderLineSelectionPayload's wire shape.
type OfflineSelectionLine struct {
	LineID           string                    `json:"line_id"`
	MenuProductSkuID *string                   `json:"menu_product_sku_id"`
	ProductSkuID     *string                   `json:"product_sku_id"`
	Quantity         int                       `json:"quantity"`
	Toppings         []OfflineSelectionTopping `json:"toppings"`
	Note             *string                   `json:"note"`
}

// OfflineSelection mirrors OrderSelectionPayload's wire shape. Only fields that
// take part in the digest are modelled; `contact` is deliberately excluded on
// both sides (it carries no ordering intent and no money).
type OfflineSelection struct {
	Lines             []OfflineSelectionLine `json:"lines"`
	OrderType         string                 `json:"order_type"`
	PickupType        string                 `json:"pickup_type"`
	ScheduledPickupAt *string                `json:"scheduled_pickup_at"`
	CustomerID        *string                `json:"customer_id"`
	GuestCount        *int                   `json:"guest_count"`
	TableIDs          []string               `json:"table_ids"`
	Locale            string                 `json:"locale"`
	Channel           string                 `json:"channel"`
	DeviceID          *string                `json:"device_id"`
	CouponCode        *string                `json:"coupon_code"`
	SplitMode         *string                `json:"split_mode"`
	SplitPeopleCount  *int                   `json:"split_people_count"`
	Note              *string                `json:"note"`
}

// OfflineEvidenceEnvelope is the signed envelope around a selection digest.
type OfflineEvidenceEnvelope struct {
	DeviceID        string `json:"device_id"`
	IssuerID        string `json:"issuer_id"`
	CatalogRevision int    `json:"catalog_revision"`
	// IssuedAt/ExpiresAt are RFC3339 UTC at SECOND precision — the string is
	// signed verbatim, so the format is part of the contract.
	IssuedAt  string `json:"issued_at"`
	ExpiresAt string `json:"expires_at"`
	KeyID     string `json:"key_id"`
}

// OfflineSelectionDigest hashes WHAT was ordered (never money).
//
// Line order is significant (the selection is an ordered list); topping order
// comes from the payload's canonical set on the Cloud side, so this function
// preserves the order it is given rather than re-sorting — the caller must
// build toppings in that same canonical order.
func OfflineSelectionDigest(sel OfflineSelection) string {
	parts := []string{
		"sel-v1",
		sel.OrderType,
		sel.PickupType,
		offlineOptional(sel.ScheduledPickupAt),
		offlineOptional(sel.CustomerID),
		offlineOptionalInt(sel.GuestCount),
		offlineTableIDs(sel.TableIDs),
		sel.Locale,
		sel.Channel,
		offlineOptional(sel.DeviceID),
		offlineOptional(sel.CouponCode),
		offlineOptional(sel.SplitMode),
		offlineOptionalInt(sel.SplitPeopleCount),
		offlineTextDigest(sel.Note),
		strconv.Itoa(len(sel.Lines)),
	}

	for _, line := range sel.Lines {
		parts = append(parts,
			"L",
			line.LineID,
			offlineOptional(line.MenuProductSkuID),
			offlineOptional(line.ProductSkuID),
			strconv.Itoa(line.Quantity),
			offlineTextDigest(line.Note),
			strconv.Itoa(len(line.Toppings)),
		)
		for _, t := range line.Toppings {
			parts = append(parts,
				"T",
				t.ToppingGroupItemID,
				t.ProductSkuID,
				strconv.Itoa(t.Quantity),
				offlineTextDigest(t.Note),
			)
		}
	}

	sum := sha256.Sum256([]byte(strings.Join(parts, "\n")))

	return hex.EncodeToString(sum[:])
}

// OfflineSigningMessage builds the exact bytes the device key signs.
func OfflineSigningMessage(env OfflineEvidenceEnvelope, selectionDigest string) string {
	return strings.Join([]string{
		offlineSigningVersion,
		env.DeviceID,
		env.IssuerID,
		strconv.Itoa(env.CatalogRevision),
		env.IssuedAt,
		env.ExpiresAt,
		env.KeyID,
		selectionDigest,
	}, "\n")
}

// SignOfflineOrder digests the selection, builds the message and signs it.
// Returns the base64 signature plus the digest that was bound into it (the
// caller persists both alongside the order so sync UP can ship the evidence).
func SignOfflineOrder(priv ed25519.PrivateKey, env OfflineEvidenceEnvelope, sel OfflineSelection) (signature, selectionDigest string, err error) {
	if len(priv) != ed25519.PrivateKeySize {
		return "", "", ErrOfflineSigningKeyInvalid
	}

	selectionDigest = OfflineSelectionDigest(sel)
	sig := ed25519.Sign(priv, []byte(OfflineSigningMessage(env, selectionDigest)))

	return base64.StdEncoding.EncodeToString(sig), selectionDigest, nil
}

// VerifyOfflineSignature is the local mirror of Cloud's check — used by tests
// and by the pre-sync self-audit so a device never queues evidence it knows
// Cloud will reject.
func VerifyOfflineSignature(publicKeyBase64, signatureBase64, message string) bool {
	pub, err := base64.StdEncoding.DecodeString(publicKeyBase64)
	if err != nil || len(pub) != ed25519.PublicKeySize {
		return false
	}
	sig, err := base64.StdEncoding.DecodeString(signatureBase64)
	if err != nil || len(sig) != ed25519.SignatureSize {
		return false
	}

	return ed25519.Verify(ed25519.PublicKey(pub), []byte(message), sig)
}

func offlineOptional(v *string) string {
	if v == nil || *v == "" {
		return offlineAbsent
	}

	return *v
}

func offlineOptionalInt(v *int) string {
	if v == nil {
		return offlineAbsent
	}

	return strconv.Itoa(*v)
}

func offlineTableIDs(ids []string) string {
	if len(ids) == 0 {
		return offlineAbsent
	}

	return strings.Join(ids, ",")
}

// offlineTextDigest hashes free text so a newline inside a note can never
// shift a field boundary.
func offlineTextDigest(text *string) string {
	if text == nil || *text == "" {
		return offlineAbsent
	}
	sum := sha256.Sum256([]byte(*text))

	return hex.EncodeToString(sum[:])
}

// ErrOfflineSigningKeyInvalid is returned rather than signing with a key that
// cannot produce a verifiable signature.
var ErrOfflineSigningKeyInvalid = errors.New("offline signing: private key is not a valid Ed25519 key")

// encodeBase64 is the single place the base64 alphabet is chosen, so the
// signing, storage and wire encodings can never disagree.
func encodeBase64(raw []byte) string {
	return base64.StdEncoding.EncodeToString(raw)
}
