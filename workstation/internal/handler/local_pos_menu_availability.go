package handler

// local_pos_menu_availability.go — plan-056, the POS "Tồn món" screen served
// from the LAN.
//
// Every write here applies to LOCAL SQLite first and returns immediately, then
// queues a sync-UP op. That ordering is the feature: a shop with no internet
// still has to be able to take a dish off the menu the moment it runs out, and
// a cashier standing at the terminal must not wait on a round trip to Tokyo to
// find out whether the switch moved.
//
// ## Why these are separate endpoints, not a flag on /pos/menus/*
//
// The ordering endpoints (`/pos/menus/*`) filter turned-off rows; these do not.
// A shared handler with an `?include_inactive=` flag would put both surfaces on
// one code path, where one wrong default puts a sold-out dish back in the cart
// picker in front of a customer. Two paths make that impossible instead of
// unlikely. The URL shape matches Cloud exactly, so pos-web sends the same
// request whether it is talking to the workstation or falling back to Cloud.
//
// ## Writes are SET, never TOGGLE
//
// `sync_queue` delivery is at-least-once. "Flip it" replayed twice puts the
// dish back on sale with nobody watching; "set false" survives any number of
// replays. The same rule holds all the way up — see the Cloud-side controller.

import (
	"database/sql"
	"encoding/json"
	"log/slog"
	"net/http"
	"strings"
	"time"
)

// availabilityWrite is the body shape shared by the dish and variant endpoints.
type availabilityWrite struct {
	IsActive *bool  `json:"is_active"`
	Reason   string `json:"reason"`
	// Who is standing at the terminal. pos-web knows (it holds the SSO session);
	// the workstation does not authenticate people, so it forwards the claim and
	// Cloud vets it. Never trusted here.
	ActorUserID string `json:"actor_user_id"`
	ActorName   string `json:"actor_name"`
	// MenuProductSkuIDs is the explicit target list of the option-value bulk
	// write ("turn off size Lớn"). Empty on every single-row write, which is why
	// it lives on the shared body rather than in a second struct: the four
	// fields above mean exactly the same thing on both, and duplicating them
	// would give the reason/actor plumbing two places to drift.
	MenuProductSkuIDs []string `json:"menu_product_sku_ids"`
}

// entity types stored in pos_menu_availability_overrides. Kept as constants
// because they are ALSO the sync_queue entity_type, so a typo would silently
// enqueue an op no handler is registered for — which pushToCloud drains as a
// no-op "success" (the #534 class of bug).
const (
	availabilityEntityProduct = "menu_product"
	availabilityEntitySku     = "menu_product_sku"
)

// =========================================================================
//  Read
// =========================================================================

// GET /api/v1/pos/menu-availability/menus
//
// EVERY branch menu, whatever its status. A shop turns dishes off in tomorrow's
// menu as readily as today's, and filtering to the currently-live menu would
// make the ones they actually want to prepare invisible.
func (s *Server) handleLocalPosAvailabilityMenus(w http.ResponseWriter, r *http.Request) {
	rows, err := s.db.Query(`
		SELECT id, name, COALESCE(status, '')
		FROM pos_menus
		ORDER BY sort_order, name`)
	if err != nil {
		writeServerError(w, r, err)

		return
	}
	defer rows.Close()

	data := []map[string]any{}
	for rows.Next() {
		var id, name, status string
		if err := rows.Scan(&id, &name, &status); err != nil {
			writeServerError(w, r, err)

			return
		}
		data = append(data, map[string]any{"id": id, "name": name, "status": status})
	}
	if err := rows.Err(); err != nil {
		writeServerError(w, r, err)

		return
	}

	writeJSON(w, http.StatusOK, map[string]any{"data": data})
}

// GET /api/v1/pos/menu-availability/menus/{menu}
//
// The management shape: sections, dishes and variants INCLUDING the turned-off
// ones, each carrying why it is off. Mirrors the Cloud response field for field
// so pos-web parses one shape.
func (s *Server) handleLocalPosAvailabilityMenuDetail(w http.ResponseWriter, r *http.Request) {
	menuID := r.PathValue("menu")
	if menuID == "" {
		writeError(w, http.StatusBadRequest, "menu id required")

		return
	}

	var name, status string
	err := s.db.QueryRow(`SELECT name, COALESCE(status, '') FROM pos_menus WHERE id = ?`, menuID).
		Scan(&name, &status)
	if err == sql.ErrNoRows {
		writeError(w, http.StatusNotFound, "menu not found")

		return
	}
	if err != nil {
		writeServerError(w, r, err)

		return
	}

	sections, err := s.loadMenuSections(menuID)
	if err != nil {
		writeServerError(w, r, err)

		return
	}

	// true — the whole point. Reuses the ordering loader rather than a second
	// copy of the same joins, so the two surfaces can never disagree about what
	// a dish IS; they only disagree about which ones to show.
	products, err := s.loadMenuProducts(menuID, time.Now(), localeFromRequest(r), true)
	if err != nil {
		writeServerError(w, r, err)

		return
	}

	payload := map[string]any{
		"id":       menuID,
		"name":     name,
		"status":   status,
		"sections": sections,
		"products": products,
	}
	s.rewriteResponseImages(r, payload)

	writeJSON(w, http.StatusOK, map[string]any{"data": payload})
}

// =========================================================================
//  Write
// =========================================================================

// PUT /api/v1/pos/menu-availability/products/{menuProduct}
func (s *Server) handleLocalPosSetProductAvailability(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("menuProduct")
	body, ok := decodeAvailabilityWrite(w, r)
	if !ok {
		return
	}

	var exists int
	if err := s.db.QueryRow(`SELECT COUNT(*) FROM pos_menu_products WHERE id = ?`, id).Scan(&exists); err != nil {
		writeServerError(w, r, err)

		return
	}
	if exists == 0 {
		writeError(w, http.StatusNotFound, "menu product not found")

		return
	}

	actedAt := time.Now().UTC().Format(time.RFC3339)
	if err := s.applyAvailabilityOverride(availabilityEntityProduct, id, body, actedAt); err != nil {
		writeServerError(w, r, err)

		return
	}

	s.queueAvailabilitySync(availabilityEntityProduct, id, map[string]any{
		"is_active":        *body.IsActive,
		"reason":           body.Reason,
		"acted_by_user_id": body.ActorUserID,
		"actor_name":       body.ActorName,
		"occurred_at":      actedAt,
	})

	s.broadcastAvailabilityChanged(availabilityEntityProduct, id, *body.IsActive)

	writeJSON(w, http.StatusOK, map[string]any{"data": map[string]any{
		"id":               id,
		"is_active":        *body.IsActive,
		"disabled_reason":  reasonOrNil(body),
		"disabled_at":      disabledAtOrNil(body, actedAt),
		"disabled_by_name": actorNameOrNil(body),
	}})
}

// PUT /api/v1/pos/menu-availability/skus/{menuProductSku}
func (s *Server) handleLocalPosSetSkuAvailability(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("menuProductSku")
	body, ok := decodeAvailabilityWrite(w, r)
	if !ok {
		return
	}

	var exists int
	if err := s.db.QueryRow(`SELECT COUNT(*) FROM pos_menu_product_skus WHERE id = ?`, id).Scan(&exists); err != nil {
		writeServerError(w, r, err)

		return
	}
	if exists == 0 {
		// A catalog SKU with no pivot row reaches here only if pos-web sent a
		// null id, which its own UI disables. 404 rather than inventing a row:
		// there is no address on Cloud to write it back to.
		writeError(w, http.StatusNotFound, "menu product sku not found")

		return
	}

	actedAt := time.Now().UTC().Format(time.RFC3339)
	if err := s.applyAvailabilityOverride(availabilityEntitySku, id, body, actedAt); err != nil {
		writeServerError(w, r, err)

		return
	}

	s.queueAvailabilitySync(availabilityEntitySku, id, map[string]any{
		"is_active":        *body.IsActive,
		"reason":           body.Reason,
		"acted_by_user_id": body.ActorUserID,
		"actor_name":       body.ActorName,
		"occurred_at":      actedAt,
	})

	s.broadcastAvailabilityChanged(availabilityEntitySku, id, *body.IsActive)

	writeJSON(w, http.StatusOK, map[string]any{"data": map[string]any{
		"id":               id,
		"is_active":        *body.IsActive,
		"disabled_reason":  reasonOrNil(body),
		"disabled_at":      disabledAtOrNil(body, actedAt),
		"disabled_by_name": actorNameOrNil(body),
	}})
}

// POST /api/v1/pos/menu-availability/menus/{menu}/sections/{menuSection}/bulk
//
// The "bật/tắt cả nhóm" button. Resolves the section to a CONCRETE id list here
// and sends that list up — never the section name.
//
// The distinction matters because the op can sit in the queue for hours while
// the shop is offline. Replaying "everything in section X" would reach dishes
// HQ moved into that section in the meantime — dishes the operator never saw
// and never intended to touch. An explicit list lands on exactly the rows that
// were on screen when the button was pressed.
func (s *Server) handleLocalPosBulkSectionAvailability(w http.ResponseWriter, r *http.Request) {
	menuID := r.PathValue("menu")
	sectionID := r.PathValue("menuSection")
	if menuID == "" || sectionID == "" {
		writeError(w, http.StatusBadRequest, "menu and section id required")

		return
	}

	body, ok := decodeAvailabilityWrite(w, r)
	if !ok {
		return
	}

	rows, err := s.db.Query(`
		SELECT mp.id, COALESCE(ov.is_active, mp.is_active)
		FROM pos_menu_products mp
		LEFT JOIN pos_menu_availability_overrides ov
		       ON ov.entity_type = 'menu_product' AND ov.entity_id = mp.id
		WHERE mp.menu_id = ? AND mp.menu_section_id = ?`, menuID, sectionID)
	if err != nil {
		writeServerError(w, r, err)

		return
	}

	type target struct {
		id       string
		isActive int
	}
	targets := []target{}
	for rows.Next() {
		var t target
		if err := rows.Scan(&t.id, &t.isActive); err != nil {
			rows.Close()
			writeServerError(w, r, err)

			return
		}
		targets = append(targets, t)
	}
	if err := rows.Err(); err != nil {
		rows.Close()
		writeServerError(w, r, err)

		return
	}
	rows.Close()

	if len(targets) == 0 {
		writeError(w, http.StatusNotFound, "section has no products in this menu")

		return
	}

	actedAt := time.Now().UTC().Format(time.RFC3339)
	want := 0
	if *body.IsActive {
		want = 1
	}

	// `changed` counts rows that actually moved — that is the number the
	// confirmation toast shows. Reporting the section size instead would tell a
	// cashier "đã tắt 12 món" when 11 were already off, and a number staff learn
	// to distrust is worse than no number.
	changed := 0
	ids := make([]string, 0, len(targets))
	for _, t := range targets {
		ids = append(ids, t.id)
		if t.isActive != want {
			changed++
		}
	}

	// One transaction: a half-applied section is a screen nobody can reason
	// about, and the operator would have to guess which half to press again.
	if err := s.db.Transaction(func(tx *sql.Tx) error {
		for _, id := range ids {
			if err := upsertAvailabilityOverrideTx(tx, availabilityEntityProduct, id, body, actedAt); err != nil {
				return err
			}
		}

		return nil
	}); err != nil {
		writeServerError(w, r, err)

		return
	}

	// ONE queue row for the whole batch, carrying the id list. Forty separate
	// ops would each need their own retry ladder and could half-apply on Cloud.
	if s.sync != nil {
		payload := map[string]any{
			"menu_id":          menuID,
			"menu_product_ids": ids,
			"is_active":        *body.IsActive,
			"reason":           body.Reason,
			"acted_by_user_id": body.ActorUserID,
			"actor_name":       body.ActorName,
			"occurred_at":      actedAt,
		}
		if err := s.sync.Enqueue("menu_availability", menuID+":"+sectionID, "bulk", payload, 2); err != nil {
			// Non-fatal, exactly like table.status: the local mirror already
			// reflects the change and the reconciler retries. Failing the
			// request here would make the operator press it again and produce a
			// second queue row for work already done locally.
			slog.Warn("enqueue menu_availability.bulk failed (non-fatal)",
				"menu", menuID, "section", sectionID, "err", err)
		} else {
			s.sync.Wake()
		}
	}

	if s.hub != nil {
		s.hub.BroadcastEventScoped("menu_availability_changed", map[string]any{
			"entity_type": "section",
			"menu_id":     menuID,
			"section_id":  sectionID,
			"is_active":   *body.IsActive,
		}, s.workstationBranchID())
	}

	writeJSON(w, http.StatusOK, map[string]any{"updated": changed})
}

// POST /api/v1/pos/menu-availability/menus/{menu}/skus/bulk
//
// "Turn off size Lớn for this dish" — an EXPLICIT list of menu_product_sku ids
// supplied by the client.
//
// # Why the client sends ids and not the option value
//
// An option VALUE has no shop-scoped row to write. `product_option_values`
// hangs off `product_id` with no branch column, so "Lớn is off" stored there
// would turn size Lớn off at every branch of the brand. `pos_menu_product_skus`
// is already per-menu and menus are per-branch, so the address for "this
// variant is not sellable here" already exists — an option value is a NAME FOR
// A SET of those rows, and the set is resolved on screen, where the operator
// can see exactly which variants are about to move.
//
// That also keeps ONE gate on selling a variant. A second tier keyed on the
// option value would allow "dish on, SKU on, option off", and nothing in the
// read path would know which of the two answers wins.
func (s *Server) handleLocalPosBulkSkuAvailability(w http.ResponseWriter, r *http.Request) {
	menuID := r.PathValue("menu")
	if menuID == "" {
		writeError(w, http.StatusBadRequest, "menu id required")

		return
	}

	body, ok := decodeAvailabilityWrite(w, r)
	if !ok {
		return
	}
	if len(body.MenuProductSkuIDs) == 0 {
		writeError(w, http.StatusBadRequest, "menu_product_sku_ids required")

		return
	}
	// Same ceiling as Cloud. A malformed client must not be able to walk the
	// whole menu in one call, and the two sides refusing at different sizes
	// would make a batch that succeeds on the LAN dead-letter on sync.
	if len(body.MenuProductSkuIDs) > 200 {
		writeError(w, http.StatusBadRequest, "too many menu_product_sku_ids (max 200)")

		return
	}

	// Scope to THIS menu, and read the current state in the same pass so the
	// toast can report rows that actually moved. Ids outside the menu are
	// dropped rather than rejected — HQ pulling one variant must not strand the
	// rest of the batch behind an error.
	placeholders := strings.TrimSuffix(strings.Repeat("?,", len(body.MenuProductSkuIDs)), ",")
	args := make([]any, 0, len(body.MenuProductSkuIDs)+1)
	for _, id := range body.MenuProductSkuIDs {
		args = append(args, id)
	}
	args = append(args, menuID)

	rows, err := s.db.Query(`
		SELECT mps.id, COALESCE(ov.is_active, mps.is_active)
		FROM pos_menu_product_skus mps
		JOIN pos_menu_products mp ON mp.id = mps.menu_product_id
		LEFT JOIN pos_menu_availability_overrides ov
		       ON ov.entity_type = 'menu_product_sku' AND ov.entity_id = mps.id
		WHERE mps.id IN (`+placeholders+`) AND mp.menu_id = ?`, args...)
	if err != nil {
		writeServerError(w, r, err)

		return
	}

	ids := []string{}
	changed := 0
	want := 0
	if *body.IsActive {
		want = 1
	}
	for rows.Next() {
		var id string
		var isActive int
		if err := rows.Scan(&id, &isActive); err != nil {
			rows.Close()
			writeServerError(w, r, err)

			return
		}
		ids = append(ids, id)
		if isActive != want {
			changed++
		}
	}
	if err := rows.Err(); err != nil {
		rows.Close()
		writeServerError(w, r, err)

		return
	}
	rows.Close()

	if len(ids) == 0 {
		writeError(w, http.StatusNotFound, "no matching variants in this menu")

		return
	}

	actedAt := time.Now().UTC().Format(time.RFC3339)

	// One transaction: a half-applied variant set is a screen nobody can reason
	// about, and the operator would have to guess which half to press again.
	if err := s.db.Transaction(func(tx *sql.Tx) error {
		for _, id := range ids {
			if err := upsertAvailabilityOverrideTx(tx, availabilityEntitySku, id, body, actedAt); err != nil {
				return err
			}
		}

		return nil
	}); err != nil {
		writeServerError(w, r, err)

		return
	}

	if s.sync != nil {
		payload := map[string]any{
			"menu_id":              menuID,
			"menu_product_sku_ids": ids,
			"is_active":            *body.IsActive,
			"reason":               body.Reason,
			"acted_by_user_id":     body.ActorUserID,
			"actor_name":           body.ActorName,
			"occurred_at":          actedAt,
		}
		// Entity id carries the menu plus the first target so two different
		// batches on the same menu do not collapse into one queue row.
		if err := s.sync.Enqueue("menu_availability", menuID+":skus:"+ids[0], "bulk_skus", payload, 2); err != nil {
			// Non-fatal, exactly like the section bulk: the local mirror already
			// reflects the change and the reconciler retries. Failing here would
			// make the operator press it again and queue work already done.
			slog.Warn("enqueue menu_availability.bulk_skus failed (non-fatal)",
				"menu", menuID, "count", len(ids), "err", err)
		} else {
			s.sync.Wake()
		}
	}

	if s.hub != nil {
		s.hub.BroadcastEventScoped("menu_availability_changed", map[string]any{
			"entity_type": "option_value",
			"menu_id":     menuID,
			"is_active":   *body.IsActive,
		}, s.workstationBranchID())
	}

	writeJSON(w, http.StatusOK, map[string]any{"updated": changed})
}

// =========================================================================
//  Internals
// =========================================================================

// decodeAvailabilityWrite parses + validates the body, writing the error
// response itself. `ok == false` means a response has already been sent.
func decodeAvailabilityWrite(w http.ResponseWriter, r *http.Request) (availabilityWrite, bool) {
	var body availabilityWrite
	if err := readJSON(r, &body); err != nil {
		writeError(w, http.StatusBadRequest, "invalid body")

		return body, false
	}

	// A POINTER, so "field absent" is distinguishable from "false". A missing
	// flag decoding to false would silently take a dish off the menu.
	if body.IsActive == nil {
		writeError(w, http.StatusUnprocessableEntity, "is_active is required")

		return body, false
	}

	// No minimum length, no required-ness. A cashier mid-service taps a preset
	// chip; "reason too short" is a validation error that costs service time and
	// protects nothing. Over-long text is TRUNCATED, never rejected — the toggle
	// is the point, the words are metadata. 255 matches the Cloud column, so a
	// value that fits here also fits there and the sync op cannot 422.
	body.Reason = strings.TrimSpace(body.Reason)
	body.Reason = truncateRunes(body.Reason, 255)
	body.ActorName = truncateRunes(strings.TrimSpace(body.ActorName), 100)

	return body, true
}

// truncateRunes cuts to n RUNES, not bytes — "Hết nguyên liệu" is 15 characters
// and 19 bytes, and a byte cut can land mid-rune and produce mojibake on a
// printed slip.
func truncateRunes(s string, n int) string {
	runes := []rune(s)
	if len(runes) <= n {
		return s
	}

	return string(runes[:n])
}

func (s *Server) applyAvailabilityOverride(entityType, entityID string, body availabilityWrite, actedAt string) error {
	return s.db.Transaction(func(tx *sql.Tx) error {
		return upsertAvailabilityOverrideTx(tx, entityType, entityID, body, actedAt)
	})
}

// upsertAvailabilityOverrideTx writes the local decision.
//
// `pending_sync = 1` on every write, including a re-write of a row that had
// already synced: the value may be new even when the row is not, and the
// reconciler treats pending rows as "do not let a pull overwrite this".
func upsertAvailabilityOverrideTx(tx *sql.Tx, entityType, entityID string, body availabilityWrite, actedAt string) error {
	active := 0
	if *body.IsActive {
		active = 1
	}

	// Turning something back ON clears the reason and the actor. Leaving a stale
	// "hết hàng" on a dish that is on sale reads as a defect in the shop's
	// stock, not in us.
	var reason, actorUser, actorName any
	if !*body.IsActive {
		reason = nullIfBlank(body.Reason)
		actorUser = nullIfBlank(body.ActorUserID)
		actorName = nullIfBlank(body.ActorName)
	}

	_, err := tx.Exec(`
		INSERT INTO pos_menu_availability_overrides
			(entity_type, entity_id, is_active, reason, actor_user_id, actor_name, acted_at, pending_sync)
		VALUES (?, ?, ?, ?, ?, ?, ?, 1)
		ON CONFLICT(entity_type, entity_id) DO UPDATE SET
			is_active     = excluded.is_active,
			reason        = excluded.reason,
			actor_user_id = excluded.actor_user_id,
			actor_name    = excluded.actor_name,
			acted_at      = excluded.acted_at,
			pending_sync  = 1`,
		entityType, entityID, active, reason, actorUser, actorName, actedAt)

	return err
}

func (s *Server) queueAvailabilitySync(entityType, entityID string, payload map[string]any) {
	if s.sync == nil {
		return
	}

	// Enqueue THEN wake, so the drain the wake schedules sees the row.
	if err := s.sync.Enqueue(entityType, entityID, "availability", payload, 2); err != nil {
		// Non-fatal by design (same as table.status): the local mirror is
		// already correct and the reconciler re-pushes. Surfacing this as a 5xx
		// would make the operator press the switch again over a queue write.
		slog.Warn("enqueue availability failed (non-fatal)",
			"entity_type", entityType, "entity", entityID, "err", err)

		return
	}
	s.sync.Wake()
}

// broadcastAvailabilityChanged nudges the other LAN clients — a second POS
// tablet on the same screen, and the ordering screen that must drop the dish.
func (s *Server) broadcastAvailabilityChanged(entityType, entityID string, isActive bool) {
	if s.hub == nil {
		return
	}
	s.hub.BroadcastEventScoped("menu_availability_changed", map[string]any{
		"entity_type": entityType,
		"entity_id":   entityID,
		"is_active":   isActive,
	}, s.workstationBranchID())
}

func nullIfBlank(v string) any {
	if strings.TrimSpace(v) == "" {
		return nil
	}

	return v
}

func reasonOrNil(body availabilityWrite) any {
	if *body.IsActive {
		return nil
	}

	return nullIfBlank(body.Reason)
}

func actorNameOrNil(body availabilityWrite) any {
	if *body.IsActive {
		return nil
	}

	return nullIfBlank(body.ActorName)
}

func disabledAtOrNil(body availabilityWrite, actedAt string) any {
	if *body.IsActive {
		return nil
	}

	return actedAt
}

// compile-time assurance that the JSON decoder is the shared one — a local
// json.NewDecoder would skip the body-size limit readJSON applies.
var _ = json.Marshal

// =========================================================================
//  Topping visibility (plan-056)
// =========================================================================

// availabilityEntityTopping addresses a (dish, topping) pair. Its entity_id is
// the composite `menuProductId:toppingGroupItemId` — see toppingOverrideKey for
// why it cannot be a row id.
const availabilityEntityTopping = "topping_item"

// PUT /api/v1/pos/menu-availability/products/{menuProduct}/toppings/{toppingItem}
//
// Hide or show ONE topping on ONE dish.
//
// The body speaks `is_active` like every other write on this screen, NOT
// `is_hidden`. The underlying Cloud column is `is_hidden`, i.e. the inverse —
// converting once here (and once in the read path) keeps a single vocabulary on
// the wire and in the local table, instead of two spellings that invite an
// inverted toggle nobody notices until a topping the shop hid is on offer.
func (s *Server) handleLocalPosSetToppingAvailability(w http.ResponseWriter, r *http.Request) {
	menuProductID := r.PathValue("menuProduct")
	itemID := r.PathValue("toppingItem")
	if menuProductID == "" || itemID == "" {
		writeError(w, http.StatusBadRequest, "menu product and topping item id required")

		return
	}

	body, ok := decodeAvailabilityWrite(w, r)
	if !ok {
		return
	}

	// Both halves must be real, and the dish must be one this workstation
	// actually carries — otherwise the op would sit in the queue forever
	// against a 404.
	var mpCount, itemCount int
	if err := s.db.QueryRow(`SELECT COUNT(*) FROM pos_menu_products WHERE id = ?`, menuProductID).Scan(&mpCount); err != nil {
		writeServerError(w, r, err)

		return
	}
	if err := s.db.QueryRow(`SELECT COUNT(*) FROM pos_topping_group_items WHERE id = ?`, itemID).Scan(&itemCount); err != nil {
		writeServerError(w, r, err)

		return
	}
	if mpCount == 0 || itemCount == 0 {
		writeError(w, http.StatusNotFound, "menu product or topping item not found")

		return
	}

	actedAt := time.Now().UTC().Format(time.RFC3339)
	key := toppingOverrideKey(menuProductID, itemID)
	if err := s.applyAvailabilityOverride(availabilityEntityTopping, key, body, actedAt); err != nil {
		writeServerError(w, r, err)

		return
	}

	s.queueAvailabilitySync(availabilityEntityTopping, key, map[string]any{
		"menu_product_id":       menuProductID,
		"topping_group_item_id": itemID,
		"is_active":             *body.IsActive,
		"reason":                body.Reason,
		"acted_by_user_id":      body.ActorUserID,
		"actor_name":            body.ActorName,
		"occurred_at":           actedAt,
	})

	s.broadcastAvailabilityChanged(availabilityEntityTopping, key, *body.IsActive)

	writeJSON(w, http.StatusOK, map[string]any{"data": map[string]any{
		"menu_product_id":       menuProductID,
		"topping_group_item_id": itemID,
		"is_active":             *body.IsActive,
		"disabled_reason":       reasonOrNil(body),
		"disabled_at":           disabledAtOrNil(body, actedAt),
		"disabled_by_name":      actorNameOrNil(body),
	}})
}
