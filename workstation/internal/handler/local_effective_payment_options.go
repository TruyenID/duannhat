package handler

import (
	"database/sql"
	"encoding/json"
	"net/http"
	"strings"
)

// GET /api/v1/pos/effective-payment-options
// GET /api/v1/kiosk/effective-payment-options
//
// Serves the workstation-local effective policy snapshot synced DOWN from
// Cloud (plan-047 T6.3/T6.4). Shape matches
// PaymentPolicyEvaluationService::effectiveOptions so POS/kiosk clients can
// switch from legacy payment-methods without a Cloud round-trip.
//
// #1080 — the mirror is now (device_id, channel)-scoped: the caller's channel
// comes from the ROUTE (pos vs kiosk), its device id from the auth context.
// Row selection: device-specific set → branch fallback for the channel →
// legacy channel="" rows (old Cloud without the matrix endpoint). This is
// what makes DevicePaymentOption `disabled` hold on LAN reads.
func (s *Server) handleLocalEffectivePaymentOptions(w http.ResponseWriter, r *http.Request) {
	channel := "pos"
	if strings.HasPrefix(r.URL.Path, "/api/v1/kiosk/") {
		channel = "kiosk"
	}
	deviceID := ""
	if d, ok := DeviceFromContext(r.Context()); ok && d.IdentityType == "device" {
		deviceID = d.ID
	}
	var revision int
	var snapshotHash, ownershipRevision, publishedAt sql.NullString
	err := s.db.QueryRow(`
		SELECT revision, snapshot_hash, ownership_revision, published_at
		FROM payment_policy_snapshot WHERE id = 1`,
	).Scan(&revision, &snapshotHash, &ownershipRevision, &publishedAt)
	if err != nil {
		if err == sql.ErrNoRows {
			writeJSON(w, http.StatusOK, map[string]any{
				"data": map[string]any{
					"revision":           0,
					"snapshot_hash":      nil,
					"ownership_revision": nil,
					"published_at":       nil,
					"options":            []any{},
				},
			})
			return
		}
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}

	// display_name is resolved PER REQUEST from Accept-Language for the same
	// reason the tender chips below it are: the sync client pulls this feed
	// once for the whole shop with no locale, so a single mirrored string
	// showed every terminal Cloud's `config('app.locale')` — "Cash (internal
	// ledger)" to a cashier running the POS in 日本語. Only internal tenders
	// carry translations (a connection-backed option's display_name is a
	// method-type slug); everything else falls back to the base column.
	//
	// dataLocaleFromRequest, NOT localeFromRequest: a caller that states no
	// language gets the untranslated base column, never a silent "ja".
	displayNameExpr := localizedNameExpr("", "display_name", dataLocaleFromRequest(r))
	baseSelect := `
		SELECT id, ` + displayNameExpr + `, provider, rail, effective,
		       source, reason, error_code,
		       connection_id, connection_option_id, shop_option_id, owner_scope,
		       shop_preference, device_preference,
		       method_type, legacy_payment_method_id, legacy_payment_method_code, client_json,
		       capabilities_json, connection_display_json
		FROM effective_payment_options `

	var rows *sql.Rows
	var err2 error
	tryQuery := func(where string, args ...any) bool {
		rows, err2 = s.db.Query(baseSelect+where+" ORDER BY sort_order, id", args...)
		if err2 != nil {
			return false
		}
		if !rows.Next() {
			rows.Close()
			rows = nil
			return false
		}
		return true
	}

	// Preference ladder: device-specific → branch fallback per channel →
	// legacy flat rows. tryQuery leaves the cursor ON the first row when it
	// succeeds, so the scan loop below starts with the current row.
	matched := (deviceID != "" && tryQuery("WHERE channel = ? AND device_id = ?", channel, deviceID)) ||
		tryQuery("WHERE channel = ? AND device_id IS NULL", channel) ||
		tryQuery("WHERE channel = '' AND device_id IS NULL")
	if err2 != nil {
		writeError(w, http.StatusInternalServerError, err2.Error())
		return
	}
	if matched {
		defer rows.Close()
	}

	options := []map[string]any{}
	for matched {
		var (
			id, displayName, provider, rail string
			source, reason, errorCode       sql.NullString
			connectionID, connOptionID      sql.NullString
			shopOptionID, ownerScope        sql.NullString
			shopPref, devicePref            sql.NullString
			methodType, legacyID, legacy    sql.NullString
			clientJSON, capJSON, connJSON   string
			effective                       int
		)
		if err := rows.Scan(
			&id, &displayName, &provider, &rail, &effective,
			&source, &reason, &errorCode,
			&connectionID, &connOptionID, &shopOptionID, &ownerScope,
			&shopPref, &devicePref,
			&methodType, &legacyID, &legacy, &clientJSON,
			&capJSON, &connJSON,
		); err != nil {
			writeError(w, http.StatusInternalServerError, err.Error())
			return
		}

		trace := []any{}
		if capJSON != "" && capJSON != "{}" {
			_ = json.Unmarshal([]byte(capJSON), &trace)
		}

		// pos-web reads option.client.supports_pos_checkout unconditionally, so
		// the `client` block is mandatory even for rows the enricher never
		// touched (kiosk channel, legacy old-Cloud pull) — those default to
		// all-false rather than being omitted.
		client := map[string]any{
			"requires_tendered":     false,
			"immediate_settlement":  false,
			"supports_pos_checkout": false,
		}
		if clientJSON != "" && clientJSON != "{}" {
			_ = json.Unmarshal([]byte(clientJSON), &client)
		}

		opt := map[string]any{
			"id":                         id,
			"display_name":               displayName,
			"provider":                   provider,
			"rail":                       rail,
			"effective":                  effective == 1,
			"source":                     nullStringOr(source, ""),
			"reason":                     nullStringOr(reason, ""),
			"error_code":                 nullStringOr(errorCode, ""),
			"shop_preference":            nullStringOr(shopPref, "inherit"),
			"device_preference":          nullStringOr(devicePref, "inherit"),
			"method_type":                nil,
			"legacy_payment_method_id":   nil,
			"legacy_payment_method_code": nil,
			"client":                     client,
			"trace":                      trace,
		}
		if methodType.Valid && methodType.String != "" {
			opt["method_type"] = methodType.String
		}
		if legacyID.Valid && legacyID.String != "" {
			opt["legacy_payment_method_id"] = legacyID.String
		}
		if legacy.Valid && legacy.String != "" {
			opt["legacy_payment_method_code"] = legacy.String
		}
		if connectionID.Valid && connectionID.String != "" {
			opt["connection_id"] = connectionID.String
		}
		if connOptionID.Valid && connOptionID.String != "" {
			opt["connection_option_id"] = connOptionID.String
		}
		if shopOptionID.Valid && shopOptionID.String != "" {
			opt["shop_option_id"] = shopOptionID.String
		}
		if ownerScope.Valid && ownerScope.String != "" {
			opt["owner_scope"] = ownerScope.String
		}
		options = append(options, opt)

		if !rows.Next() {
			break
		}
	}

	data := map[string]any{
		"revision":           revision,
		"ownership_revision": nullStringOr(ownershipRevision, nil),
		"options":            options,
	}
	if snapshotHash.Valid && snapshotHash.String != "" {
		data["snapshot_hash"] = snapshotHash.String
	} else {
		data["snapshot_hash"] = nil
	}
	if publishedAt.Valid && publishedAt.String != "" {
		data["published_at"] = publishedAt.String
	} else {
		data["published_at"] = nil
	}

	writeJSON(w, http.StatusOK, map[string]any{"data": data})
}

// paymentPolicyIdentity captures the immutable option/connection/revision
// selected at payment capture time (plan-047 T6.5 / F2).
type paymentPolicyIdentity struct {
	PaymentOptionID       string
	PolicyRevision        int
	ConnectionID          string
	ConnectionOptionID    string
	AttemptIdempotencyKey string
}

func (s *Server) resolvePaymentPolicyIdentity(optionID, idempotencyKey string) paymentPolicyIdentity {
	out := paymentPolicyIdentity{AttemptIdempotencyKey: idempotencyKey}
	if optionID == "" || s.db == nil {
		return out
	}

	var revision int
	var snapshotHash sql.NullString
	_ = s.db.QueryRow(`
		SELECT revision, snapshot_hash FROM payment_policy_snapshot WHERE id = 1`,
	).Scan(&revision, &snapshotHash)

	var connectionID, connectionOptionID sql.NullString
	// #1080 — the mirror now holds one row per (device, channel); connection
	// identity is invariant across them, so any row for the option id works.
	err := s.db.QueryRow(`
		SELECT connection_id, connection_option_id
		FROM effective_payment_options WHERE id = ? LIMIT 1`, optionID,
	).Scan(&connectionID, &connectionOptionID)
	if err != nil {
		return out
	}

	out.PaymentOptionID = optionID
	out.PolicyRevision = revision
	if connectionID.Valid {
		out.ConnectionID = connectionID.String
	}
	if connectionOptionID.Valid {
		out.ConnectionOptionID = connectionOptionID.String
	}
	return out
}

func appendPaymentPolicySyncFields(payload map[string]any, ident paymentPolicyIdentity) {
	if ident.PaymentOptionID != "" {
		payload["payment_option_id"] = ident.PaymentOptionID
	}
	if ident.PolicyRevision > 0 {
		payload["policy_revision"] = ident.PolicyRevision
	}
	if ident.ConnectionID != "" {
		payload["connection_id"] = ident.ConnectionID
	}
	if ident.ConnectionOptionID != "" {
		payload["connection_option_id"] = ident.ConnectionOptionID
	}
	if ident.AttemptIdempotencyKey != "" {
		payload["attempt_idempotency_key"] = ident.AttemptIdempotencyKey
	}
}
