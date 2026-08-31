package handler

import (
	"database/sql"
	"encoding/json"
	"net/http"
)

// GET /api/v1/pos/effective-payment-options
// GET /api/v1/kiosk/effective-payment-options
//
// Serves the workstation-local effective policy snapshot synced DOWN from
// Cloud (plan-047 T6.3/T6.4). Shape matches
// PaymentPolicyEvaluationService::effectiveOptions so POS/kiosk clients can
// switch from legacy payment-methods without a Cloud round-trip.
func (s *Server) handleLocalEffectivePaymentOptions(w http.ResponseWriter, r *http.Request) {
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

	rows, err := s.db.Query(`
		SELECT id, display_name, provider, rail, effective,
		       source, reason, error_code,
		       connection_id, connection_option_id, shop_option_id, owner_scope,
		       shop_preference, device_preference,
		       capabilities_json, connection_display_json
		FROM effective_payment_options
		ORDER BY sort_order, id`)
	if err != nil {
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}
	defer rows.Close()

	options := []map[string]any{}
	for rows.Next() {
		var (
			id, displayName, provider, rail string
			source, reason, errorCode       sql.NullString
			connectionID, connOptionID      sql.NullString
			shopOptionID, ownerScope        sql.NullString
			shopPref, devicePref            sql.NullString
			capJSON, connJSON               string
			effective                       int
		)
		if err := rows.Scan(
			&id, &displayName, &provider, &rail, &effective,
			&source, &reason, &errorCode,
			&connectionID, &connOptionID, &shopOptionID, &ownerScope,
			&shopPref, &devicePref,
			&capJSON, &connJSON,
		); err != nil {
			writeError(w, http.StatusInternalServerError, err.Error())
			return
		}

		trace := []any{}
		if capJSON != "" && capJSON != "{}" {
			_ = json.Unmarshal([]byte(capJSON), &trace)
		}

		opt := map[string]any{
			"id":                id,
			"display_name":      displayName,
			"provider":          provider,
			"rail":              rail,
			"effective":         effective == 1,
			"source":            nullStringOr(source, ""),
			"reason":            nullStringOr(reason, ""),
			"error_code":        nullStringOr(errorCode, ""),
			"shop_preference":   nullStringOr(shopPref, "inherit"),
			"device_preference": nullStringOr(devicePref, "inherit"),
			"trace":             trace,
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
	err := s.db.QueryRow(`
		SELECT connection_id, connection_option_id
		FROM effective_payment_options WHERE id = ?`, optionID,
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
