package handler

import (
	"database/sql"
	"fmt"
	"strings"
	"time"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// menuProductRow is the flat page row shared by the menu detail, searchable
// product collection and availability screens. Relations are attached only
// after the outer Rows has been drained.
type menuProductRow struct {
	id, menuID, productID, sectionID                        string
	productName, productDesc, productImage, productTypeCode string
	sectionName                                             sql.NullString
	active, displayOrder                                    int
	disabledReason, disabledAt, disabledByName              sql.NullString
}

// hydrateMenuProductRows materializes all rich menu relations with a bounded
// set of SQL statements. The previous loaders executed the same relation
// queries once per product, SKU, option and topping, turning a 60-product page
// into hundreds of local SQLite round trips.
func (s *Server) hydrateMenuProductRows(rows []menuProductRow, now time.Time, locale string, includeInactive, includeAvailabilityMetadata bool) ([]map[string]any, error) {
	out := []map[string]any{}
	if len(rows) == 0 {
		return out, nil
	}

	skus, err := s.loadMenuSkusBatch(rows, locale, includeInactive)
	if err != nil {
		return nil, err
	}
	galleries, err := s.loadMenuGalleriesBatch(rows)
	if err != nil {
		return nil, err
	}
	toppings, err := s.loadMenuToppingsBatch(rows, locale, includeInactive)
	if err != nil {
		return nil, err
	}
	promotions, err := s.loadMenuPromotionsBatch(rows, skus, now)
	if err != nil {
		return nil, err
	}
	taxRates, err := s.loadMenuTaxRatesBatch(rows)
	if err != nil {
		return nil, err
	}

	for _, row := range rows {
		productPayload := map[string]any{
			"id":                row.productID,
			"name":              row.productName,
			"description":       nilIfEmpty(row.productDesc),
			"is_active":         true,
			"image_url":         nilIfEmpty(row.productImage),
			"product_type_code": nilIfEmpty(row.productTypeCode),
			"gallery":           galleries[row.productID],
			"topping_groups":    toppings[row.id],
			"tax_rate":          nil,
		}
		if rate, ok := taxRates[row.productID]; ok {
			productPayload["tax_rate"] = rate
		}

		item := map[string]any{
			"id": row.id, "menu_id": row.menuID, "product_id": row.productID,
			"menu_section_id": nilIfEmpty(row.sectionID), "is_active": row.active == 1,
			"display_order": row.displayOrder, "skus": skus[row.id],
			"product": productPayload, "section": nil,
			"active_promotion": promotions[row.productID],
		}
		if includeAvailabilityMetadata {
			item["disabled_reason"] = nullableStringValue(row.disabledReason)
			item["disabled_at"] = nullableStringValue(row.disabledAt)
			item["disabled_by_name"] = nullableStringValue(row.disabledByName)
		}
		if includeInactive {
			item["topping_groups"] = toppings[row.id]
		}
		if row.sectionID != "" && row.sectionName.Valid {
			item["section"] = map[string]any{"id": row.sectionID, "name": row.sectionName.String}
		}
		out = append(out, item)
	}
	return out, nil
}

type menuSkuBaseRow struct {
	id, productID, name, sku, image, ov1, ov2, ov3 string
	price, active, overridden                      int
	defaultPrice                                   sql.NullInt64
}

type menuSkuPivotRow struct {
	id                                     string
	active                                 int
	disabledReason, disabledAt, disabledBy sql.NullString
}

func (s *Server) loadMenuSkusBatch(menuRows []menuProductRow, locale string, includeInactive bool) (map[string][]map[string]any, error) {
	productIDs, menuProductIDs := menuRowIDs(menuRows)
	productPH, productArgs := inPlaceholders(productIDs)
	rows, err := s.db.Query(fmt.Sprintf(`
		SELECT ps.id, ps.product_id, COALESCE(%s, ''), COALESCE(ps.sku, ''),
		       ps.selling_price, ps.is_active, COALESCE(ps.image_url, ''),
		       ps.default_price, ps.is_price_overridden,
		       COALESCE(ps.option_value1_id, ''), COALESCE(ps.option_value2_id, ''),
		       COALESCE(ps.option_value3_id, '')
		FROM pos_product_skus ps
		WHERE ps.product_id IN (`+productPH+`) AND ps.is_active = 1
		ORDER BY ps.product_id, ps.selling_price, ps.id`, localizedNameExpr("ps", "name", locale)), productArgs...)
	if err != nil {
		return nil, err
	}
	baseByProduct := make(map[string][]menuSkuBaseRow, len(productIDs))
	optionIDs := []string{}
	seenOptions := map[string]bool{}
	for rows.Next() {
		var row menuSkuBaseRow
		if err := rows.Scan(&row.id, &row.productID, &row.name, &row.sku, &row.price,
			&row.active, &row.image, &row.defaultPrice, &row.overridden,
			&row.ov1, &row.ov2, &row.ov3); err != nil {
			rows.Close()
			return nil, err
		}
		baseByProduct[row.productID] = append(baseByProduct[row.productID], row)
		for _, id := range []string{row.ov1, row.ov2, row.ov3} {
			if id != "" && !seenOptions[id] {
				seenOptions[id] = true
				optionIDs = append(optionIDs, id)
			}
		}
	}
	if err := rows.Err(); err != nil {
		rows.Close()
		return nil, err
	}
	rows.Close()

	pivots := map[string]menuSkuPivotRow{}
	mpPH, mpArgs := inPlaceholders(menuProductIDs)
	pivotRows, err := s.db.Query(`
		SELECT mps.menu_product_id, mps.product_sku_id, mps.id,
		       COALESCE(ov.is_active, mps.is_active, 1),
		       COALESCE(ov.reason, mps.disabled_reason), mps.disabled_at,
		       COALESCE(ov.actor_name, mps.disabled_by_name)
		FROM pos_menu_product_skus mps
		LEFT JOIN pos_menu_availability_overrides ov
		  ON ov.entity_type = 'menu_product_sku' AND ov.entity_id = mps.id
		WHERE mps.menu_product_id IN (`+mpPH+`)`, mpArgs...)
	if err != nil {
		return nil, err
	}
	for pivotRows.Next() {
		var mpID, skuID string
		var row menuSkuPivotRow
		if err := pivotRows.Scan(&mpID, &skuID, &row.id, &row.active,
			&row.disabledReason, &row.disabledAt, &row.disabledBy); err != nil {
			pivotRows.Close()
			return nil, err
		}
		pivots[menuRelationKey(mpID, skuID)] = row
	}
	if err := pivotRows.Err(); err != nil {
		pivotRows.Close()
		return nil, err
	}
	pivotRows.Close()

	optionValues := map[string]any{}
	if len(optionIDs) > 0 {
		optionPH, optionArgs := inPlaceholders(optionIDs)
		optionRows, err := s.db.Query(fmt.Sprintf(`
			SELECT v.id, v.option_id, v.value, COALESCE(%s, ''), v.position, v.is_active,
			       o.id, o.product_id, o.key, COALESCE(%s, ''), o.position, o.is_active
			FROM pos_product_option_values v
			JOIN pos_product_options o ON o.id = v.option_id
			WHERE v.id IN (`+optionPH+`)`,
			localizedNameExpr("v", "label", locale), localizedNameExpr("o", "name", locale)), optionArgs...)
		if err != nil {
			return nil, err
		}
		for optionRows.Next() {
			var valueID, optionID, value, label, optID, productID, key, name string
			var position, active, optPosition, optActive int
			if err := optionRows.Scan(&valueID, &optionID, &value, &label, &position, &active,
				&optID, &productID, &key, &name, &optPosition, &optActive); err != nil {
				optionRows.Close()
				return nil, err
			}
			optionValues[valueID] = map[string]any{
				"id": valueID, "option_id": optionID, "value": value,
				"label": nilIfEmpty(label), "position": position, "is_active": active == 1,
				"option": map[string]any{
					"id": optID, "product_id": productID, "key": key,
					"name": name, "position": optPosition, "is_active": optActive == 1,
				},
			}
		}
		if err := optionRows.Err(); err != nil {
			optionRows.Close()
			return nil, err
		}
		optionRows.Close()
	}

	out := make(map[string][]map[string]any, len(menuRows))
	for _, menuRow := range menuRows {
		out[menuRow.id] = []map[string]any{}
		for _, sku := range baseByProduct[menuRow.productID] {
			pivot, hasPivot := pivots[menuRelationKey(menuRow.id, sku.id)]
			if hasPivot && pivot.active == 0 && !includeInactive {
				continue
			}
			menuActive := true
			if hasPivot {
				menuActive = pivot.active == 1
			}
			ov1, ov2, ov3 := optionValues[sku.ov1], optionValues[sku.ov2], optionValues[sku.ov3]
			row := map[string]any{
				"id": sku.id, "product_sku_id": sku.id, "product_id": sku.productID,
				"name": nilIfEmpty(sku.name), "sku": nilIfEmpty(sku.sku),
				"selling_price": sku.price, "is_active": menuActive,
				"image_url": nilIfEmpty(sku.image), "is_price_overridden": sku.overridden == 1,
				"menu_product_sku_id": nil, "disabled_reason": nil,
				"disabled_at": nil, "disabled_by_name": nil,
				"option_value1": ov1, "option_value2": ov2, "option_value3": ov3,
			}
			if hasPivot {
				row["menu_product_sku_id"] = pivot.id
				row["disabled_reason"] = nullableStringValue(pivot.disabledReason)
				row["disabled_at"] = nullableStringValue(pivot.disabledAt)
				row["disabled_by_name"] = nullableStringValue(pivot.disabledBy)
			}
			if sku.overridden == 1 && sku.defaultPrice.Valid {
				row["default_price"] = sku.defaultPrice.Int64
			} else {
				row["default_price"] = nil
			}
			row["variant_label"] = variantLabelFrom(ov1, ov2, ov3, sku.name)
			row["options"] = optionShapesFrom(ov1, ov2, ov3)
			row["product_sku"] = map[string]any{
				"id": sku.id, "product_id": sku.productID, "name": nilIfEmpty(sku.name),
				"sku": nilIfEmpty(sku.sku), "selling_price": sku.price,
				"image_url": nilIfEmpty(sku.image), "option_value1": ov1,
				"option_value2": ov2, "option_value3": ov3,
			}
			out[menuRow.id] = append(out[menuRow.id], row)
		}
	}
	return out, nil
}

func (s *Server) loadMenuGalleriesBatch(menuRows []menuProductRow) (map[string][]map[string]any, error) {
	productIDs, _ := menuRowIDs(menuRows)
	out := make(map[string][]map[string]any, len(productIDs))
	for _, id := range productIDs {
		out[id] = []map[string]any{}
	}
	ph, args := inPlaceholders(productIDs)
	rows, err := s.db.Query(`
		SELECT product_id, id, url, COALESCE(original_name, ''), COALESCE(mime_type, ''), sort_order
		FROM pos_product_galleries WHERE product_id IN (`+ph+`)
		ORDER BY product_id, (sort_order IS NULL), sort_order, id`, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	for rows.Next() {
		var productID, id, url, originalName, mimeType string
		var sortOrder sql.NullInt64
		if err := rows.Scan(&productID, &id, &url, &originalName, &mimeType, &sortOrder); err != nil {
			return nil, err
		}
		row := map[string]any{
			"id": id, "url": url, "original_name": nilIfEmpty(originalName),
			"mime_type": nilIfEmpty(mimeType), "sort_order": nil,
		}
		if sortOrder.Valid {
			row["sort_order"] = sortOrder.Int64
		}
		out[productID] = append(out[productID], row)
	}
	return out, rows.Err()
}

type menuToppingGroupRow struct {
	id, name, selectionType, modifierType, priceStrategy string
	freeQuantity, maxSelect, minOverride, maxOverride    sql.NullInt64
	minSelect, maxQty, sortOrder, active                 int
}

type menuToppingItemRow struct {
	id, productID, name, image string
	sortOrder, isDefault       int
}

type menuToppingSkuRow struct {
	id, productSkuID, label, code string
	extra                         int
}

func (s *Server) loadMenuToppingsBatch(menuRows []menuProductRow, locale string, includeHidden bool) (map[string][]map[string]any, error) {
	productIDs, menuProductIDs := menuRowIDs(menuRows)
	productPH, productArgs := inPlaceholders(productIDs)

	groupsByProduct := make(map[string][]menuToppingGroupRow, len(productIDs))
	groupRows, err := s.db.Query(fmt.Sprintf(`
		SELECT pivot.product_id, g.id, %s, g.selection_type, g.modifier_type,
		       g.price_strategy, g.free_quantity, g.min_select, g.max_select,
		       g.max_qty_per_item, pivot.sort_order, g.is_active,
		       pivot.min_select_override, pivot.max_select_override
		FROM pos_product_topping_groups pivot
		JOIN pos_topping_groups g ON g.id = pivot.topping_group_id
		WHERE pivot.product_id IN (`+productPH+`) AND g.is_active = 1
		ORDER BY pivot.product_id, pivot.sort_order, g.name`, localizedNameExpr("g", "name", locale)), productArgs...)
	if err != nil {
		return nil, err
	}
	for groupRows.Next() {
		var productID string
		var row menuToppingGroupRow
		if err := groupRows.Scan(&productID, &row.id, &row.name, &row.selectionType,
			&row.modifierType, &row.priceStrategy, &row.freeQuantity, &row.minSelect,
			&row.maxSelect, &row.maxQty, &row.sortOrder, &row.active,
			&row.minOverride, &row.maxOverride); err != nil {
			groupRows.Close()
			return nil, err
		}
		groupsByProduct[productID] = append(groupsByProduct[productID], row)
	}
	if err := groupRows.Err(); err != nil {
		groupRows.Close()
		return nil, err
	}
	groupRows.Close()

	itemsByGroup := map[string][]menuToppingItemRow{}
	itemRows, err := s.db.Query(fmt.Sprintf(`
		SELECT pivot.product_id, item.topping_group_id, item.id, item.product_id,
		       COALESCE(%s, ''), COALESCE(p.image_url, ''), item.sort_order, item.is_default
		FROM pos_product_topping_groups pivot
		JOIN pos_topping_group_items item ON item.topping_group_id = pivot.topping_group_id
		LEFT JOIN pos_products p ON p.id = item.product_id
		WHERE pivot.product_id IN (`+productPH+`)
		ORDER BY pivot.product_id, item.topping_group_id, item.sort_order, item.id`, localizedNameExpr("p", "name", locale)), productArgs...)
	if err != nil {
		return nil, err
	}
	for itemRows.Next() {
		var productID, groupID string
		var row menuToppingItemRow
		if err := itemRows.Scan(&productID, &groupID, &row.id, &row.productID,
			&row.name, &row.image, &row.sortOrder, &row.isDefault); err != nil {
			itemRows.Close()
			return nil, err
		}
		itemsByGroup[menuRelationKey(productID, groupID)] = append(itemsByGroup[menuRelationKey(productID, groupID)], row)
	}
	if err := itemRows.Err(); err != nil {
		itemRows.Close()
		return nil, err
	}
	itemRows.Close()

	baseSkus := map[string][]menuToppingSkuRow{}
	baseRows, err := s.db.Query(fmt.Sprintf(`
		SELECT pivot.product_id, item.id, base.id, COALESCE(base.product_sku_id, ''),
		       base.extra_price, COALESCE(%s, ''), COALESCE(ps.sku, '')
		FROM pos_product_topping_groups pivot
		JOIN pos_topping_group_items item ON item.topping_group_id = pivot.topping_group_id
		JOIN pos_topping_group_item_skus base ON base.topping_group_item_id = item.id
		LEFT JOIN pos_product_skus ps ON ps.id = base.product_sku_id
		WHERE pivot.product_id IN (`+productPH+`)
		ORDER BY pivot.product_id, item.id, base.id`, localizedNameExpr("ps", "name", locale)), productArgs...)
	if err != nil {
		return nil, err
	}
	for baseRows.Next() {
		var productID, itemID string
		var row menuToppingSkuRow
		if err := baseRows.Scan(&productID, &itemID, &row.id, &row.productSkuID,
			&row.extra, &row.label, &row.code); err != nil {
			baseRows.Close()
			return nil, err
		}
		baseSkus[menuRelationKey(productID, itemID)] = append(baseSkus[menuRelationKey(productID, itemID)], row)
	}
	if err := baseRows.Err(); err != nil {
		baseRows.Close()
		return nil, err
	}
	baseRows.Close()

	tier1 := map[string]toppingOverrideRow{}
	tier1ItemHidden := map[string]bool{}
	mpPH, mpArgs := inPlaceholders(menuProductIDs)
	tier1Rows, err := s.db.Query(`
		SELECT menu_product_id, topping_group_item_id, COALESCE(product_sku_id, ''), is_hidden, override_price
		FROM pos_menu_product_topping_overrides WHERE menu_product_id IN (`+mpPH+`)`, mpArgs...)
	if err != nil {
		return nil, err
	}
	for tier1Rows.Next() {
		var mpID, itemID, skuID string
		var hidden int
		var price sql.NullInt64
		if err := tier1Rows.Scan(&mpID, &itemID, &skuID, &hidden, &price); err != nil {
			tier1Rows.Close()
			return nil, err
		}
		row := toppingOverrideRow{isHidden: hidden == 1}
		if price.Valid {
			v := int(price.Int64)
			row.overridePrice = &v
		}
		tier1[menuRelationKey(mpID, itemID, skuID)] = row
		if row.isHidden {
			tier1ItemHidden[menuRelationKey(mpID, itemID)] = true
		}
	}
	if err := tier1Rows.Err(); err != nil {
		tier1Rows.Close()
		return nil, err
	}
	tier1Rows.Close()

	tier2 := map[string]toppingOverrideRow{}
	tier2Rows, err := s.db.Query(`
		SELECT product_id, topping_group_item_id, COALESCE(product_sku_id, ''), is_hidden, override_price
		FROM pos_product_topping_item_overrides WHERE product_id IN (`+productPH+`)`, productArgs...)
	if err != nil {
		return nil, err
	}
	for tier2Rows.Next() {
		var productID, itemID, skuID string
		var hidden int
		var price sql.NullInt64
		if err := tier2Rows.Scan(&productID, &itemID, &skuID, &hidden, &price); err != nil {
			tier2Rows.Close()
			return nil, err
		}
		row := toppingOverrideRow{isHidden: hidden == 1}
		if price.Valid {
			v := int(price.Int64)
			row.overridePrice = &v
		}
		tier2[menuRelationKey(productID, itemID, skuID)] = row
	}
	if err := tier2Rows.Err(); err != nil {
		tier2Rows.Close()
		return nil, err
	}
	tier2Rows.Close()

	localHidden := map[string]bool{}
	localRows, err := s.db.Query(`SELECT entity_id, is_active
		FROM pos_menu_availability_overrides WHERE entity_type = 'topping_item'`)
	if err != nil {
		return nil, err
	}
	for localRows.Next() {
		var key string
		var active int
		if err := localRows.Scan(&key, &active); err != nil {
			localRows.Close()
			return nil, err
		}
		localHidden[key] = active == 0
	}
	if err := localRows.Err(); err != nil {
		localRows.Close()
		return nil, err
	}
	localRows.Close()

	out := make(map[string][]map[string]any, len(menuRows))
	for _, menuRow := range menuRows {
		out[menuRow.id] = []map[string]any{}
		for _, group := range groupsByProduct[menuRow.productID] {
			items := []map[string]any{}
			for _, item := range itemsByGroup[menuRelationKey(menuRow.productID, group.id)] {
				localKey := toppingOverrideKey(menuRow.id, item.id)
				itemHidden, hasLocal := localHidden[localKey]
				if !hasLocal {
					itemHidden = tier1ItemHidden[menuRelationKey(menuRow.id, item.id)]
				}
				if itemHidden && !includeHidden {
					continue
				}

				resolvedSkus := []map[string]any{}
				base := baseSkus[menuRelationKey(menuRow.productID, item.id)]
				allHidden := len(base) > 0
				for _, sku := range base {
					price := sku.extra
					hidden := false
					override, ok := tier1[menuRelationKey(menuRow.id, item.id, sku.productSkuID)]
					if !ok || (override.overridePrice == nil && !override.isHidden) {
						override, ok = tier2[menuRelationKey(menuRow.productID, item.id, sku.productSkuID)]
					}
					if ok {
						if override.overridePrice != nil {
							price = *override.overridePrice
						}
						hidden = override.isHidden
					}
					if hidden {
						continue
					}
					allHidden = false
					row := map[string]any{
						"id": sku.id, "topping_group_item_id": item.id,
						"product_sku_id": nil, "extra_price": fmt.Sprintf("%d", price),
						"sku_label": nilIfEmpty(sku.label), "sku_code": nilIfEmpty(sku.code),
					}
					if sku.productSkuID != "" {
						row["product_sku_id"] = sku.productSkuID
					}
					resolvedSkus = append(resolvedSkus, row)
				}
				hidden := itemHidden || allHidden
				if hidden && !includeHidden {
					continue
				}
				items = append(items, map[string]any{
					"id": item.id, "topping_group_id": group.id, "product_id": item.productID,
					"name": item.name, "image_url": nilIfEmpty(item.image),
					"is_default": item.isDefault == 1, "is_hidden": hidden,
					"is_active": !hidden, "sort_order": item.sortOrder, "skus": resolvedSkus,
				})
			}
			out[menuRow.id] = append(out[menuRow.id], map[string]any{
				"id": group.id, "name": group.name, "selection_type": group.selectionType,
				"modifier_type": group.modifierType, "price_strategy": group.priceStrategy,
				"free_quantity": nullableInt64(group.freeQuantity), "min_select": group.minSelect,
				"max_select": nullableInt64(group.maxSelect), "max_qty_per_item": group.maxQty,
				"effective_min_select": int64IfValid(group.minOverride, int64(group.minSelect)),
				"effective_max_select": int64IfValidPtr(group.maxOverride, nullableInt64(group.maxSelect)),
				"sort_order":           group.sortOrder, "is_active": group.active == 1, "items": items,
			})
		}
	}
	return out, nil
}

type menuPromotionCandidate struct {
	service.PromotionMatch
	appliesTo string
}

type menuPromotionSchedule struct {
	dayOfWeek sql.NullInt64
	from, to  sql.NullString
}

func (s *Server) loadMenuPromotionsBatch(menuRows []menuProductRow, skus map[string][]map[string]any, now time.Time) (map[string]any, error) {
	productIDs, _ := menuRowIDs(menuRows)
	out := make(map[string]any, len(productIDs))
	for _, id := range productIDs {
		out[id] = nil
	}
	nowISO := now.Format(time.RFC3339Nano)
	rows, err := s.db.Query(`
		SELECT id, name, discount_type, discount_value, exclusive_with_coupons,
		       priority, COALESCE(applies_to, 'products'),
		       COALESCE(promo_created_at, ''), COALESCE(ends_at, '')
		FROM menu_promotions
		WHERE is_active = 1
		  AND (starts_at IS NULL OR starts_at <= ?)
		  AND (ends_at IS NULL OR ends_at >= ?)`, nowISO, nowISO)
	if err != nil {
		return nil, err
	}
	candidates := []menuPromotionCandidate{}
	candidateIDs := []string{}
	for rows.Next() {
		var row menuPromotionCandidate
		var exclusive int
		if err := rows.Scan(&row.ID, &row.Name, &row.DiscountType, &row.DiscountValue,
			&exclusive, &row.Priority, &row.appliesTo, &row.CreatedAt, &row.EndsAt); err != nil {
			rows.Close()
			return nil, err
		}
		row.ExclusiveWithCoupons = exclusive == 1
		candidates = append(candidates, row)
		candidateIDs = append(candidateIDs, row.ID)
	}
	if err := rows.Err(); err != nil {
		rows.Close()
		return nil, err
	}
	rows.Close()
	if len(candidates) == 0 {
		return out, nil
	}

	pivots := map[string]bool{}
	productPH, productArgs := inPlaceholders(productIDs)
	pivotRows, err := s.db.Query(`SELECT promotion_id, product_id
		FROM menu_promotion_products WHERE product_id IN (`+productPH+`)`, productArgs...)
	if err != nil {
		return nil, err
	}
	for pivotRows.Next() {
		var promotionID, productID string
		if err := pivotRows.Scan(&promotionID, &productID); err != nil {
			pivotRows.Close()
			return nil, err
		}
		pivots[menuRelationKey(promotionID, productID)] = true
	}
	if err := pivotRows.Err(); err != nil {
		pivotRows.Close()
		return nil, err
	}
	pivotRows.Close()

	schedules := map[string][]menuPromotionSchedule{}
	promoPH, promoArgs := inPlaceholders(candidateIDs)
	scheduleRows, err := s.db.Query(`SELECT promotion_id, day_of_week, daily_time_from, daily_time_to
		FROM menu_promotion_schedules WHERE promotion_id IN (`+promoPH+`)`, promoArgs...)
	if err != nil {
		return nil, err
	}
	for scheduleRows.Next() {
		var promotionID string
		var row menuPromotionSchedule
		if err := scheduleRows.Scan(&promotionID, &row.dayOfWeek, &row.from, &row.to); err != nil {
			scheduleRows.Close()
			return nil, err
		}
		schedules[promotionID] = append(schedules[promotionID], row)
	}
	if err := scheduleRows.Err(); err != nil {
		scheduleRows.Close()
		return nil, err
	}
	scheduleRows.Close()

	weekday, hhmm := int(now.Weekday()), now.Format("15:04")
	for _, productID := range productIDs {
		var winner *service.PromotionMatch
		for _, candidate := range candidates {
			if candidate.appliesTo != "all_items" && !pivots[menuRelationKey(candidate.ID, productID)] {
				continue
			}
			if !menuPromotionScheduleMatches(schedules[candidate.ID], weekday, hhmm) {
				continue
			}
			match := candidate.PromotionMatch
			if winner == nil || betterMenuPromotion(match, *winner) {
				winner = &match
			}
		}
		if winner == nil {
			continue
		}
		var productSkus []map[string]any
		for _, row := range menuRows {
			if row.productID == productID {
				productSkus = skus[row.id]
				break
			}
		}
		out[productID] = menuPromotionBadge(*winner, productSkus)
	}
	return out, nil
}

func menuPromotionScheduleMatches(rows []menuPromotionSchedule, weekday int, hhmm string) bool {
	if len(rows) == 0 {
		return true
	}
	for _, row := range rows {
		if row.dayOfWeek.Valid && int(row.dayOfWeek.Int64) != weekday {
			continue
		}
		if row.from.Valid && row.to.Valid && row.from.String != "" && row.to.String != "" &&
			(hhmm < row.from.String || hhmm > row.to.String) {
			continue
		}
		return true
	}
	return false
}

func betterMenuPromotion(candidate, current service.PromotionMatch) bool {
	if candidate.DiscountValue != current.DiscountValue {
		return candidate.DiscountValue > current.DiscountValue
	}
	if candidate.CreatedAt != "" && current.CreatedAt != "" && candidate.CreatedAt != current.CreatedAt {
		return candidate.CreatedAt < current.CreatedAt
	}
	if candidate.CreatedAt != "" && current.CreatedAt == "" {
		return true
	}
	if current.CreatedAt != "" && candidate.CreatedAt == "" {
		return false
	}
	return candidate.ID < current.ID
}

func menuPromotionBadge(winner service.PromotionMatch, skus []map[string]any) any {
	cheapest := 0
	for _, sku := range skus {
		if price, ok := sku["selling_price"].(int); ok && (cheapest == 0 || price < cheapest) {
			cheapest = price
		}
	}
	percent := 0
	if winner.DiscountType == "percent" {
		percent = winner.DiscountValue
	}
	stacking := "stackable_with_coupons"
	if winner.ExclusiveWithCoupons {
		stacking = "exclusive_with_coupons"
	}
	return map[string]any{
		"id": winner.ID, "discount_percent": percent,
		"discounted_price": applyDiscountForBadge(cheapest, winner.DiscountType, winner.DiscountValue),
		"ends_at":          winner.EndsAt, "stacking_mode": stacking,
	}
}

func (s *Server) loadMenuTaxRatesBatch(menuRows []menuProductRow) (map[string]float64, error) {
	productIDs, _ := menuRowIDs(menuRows)
	productPH, productArgs := inPlaceholders(productIDs)
	explicit := map[string]string{}
	seen := map[string]bool{}
	rows, err := s.db.Query(`
		SELECT ps.product_id, mi.tax_type_id
		FROM menu_items mi
		JOIN pos_product_skus ps ON ps.id = mi.sku_id
		WHERE ps.product_id IN (`+productPH+`) AND mi.is_active = 1
		ORDER BY mi.rowid`, productArgs...)
	if err != nil {
		return nil, err
	}
	for rows.Next() {
		var productID string
		var taxTypeID sql.NullString
		if err := rows.Scan(&productID, &taxTypeID); err != nil {
			rows.Close()
			return nil, err
		}
		if !seen[productID] {
			seen[productID] = true
			if taxTypeID.Valid {
				explicit[productID] = taxTypeID.String
			}
		}
	}
	if err := rows.Err(); err != nil {
		rows.Close()
		return nil, err
	}
	rows.Close()

	var branchDefault string
	_ = s.db.QueryRow(`SELECT value FROM shop_settings WHERE key = 'default_tax_type_id'`).Scan(&branchDefault)
	rates := map[string]float64{}
	defaultID := ""
	taxRows, err := s.db.Query(`SELECT id, rate, is_default, is_active FROM tax_types`)
	if err != nil {
		return nil, err
	}
	for taxRows.Next() {
		var id string
		var rate float64
		var isDefault, active int
		if err := taxRows.Scan(&id, &rate, &isDefault, &active); err != nil {
			taxRows.Close()
			return nil, err
		}
		rates[id] = rate
		if defaultID == "" && isDefault == 1 && active == 1 {
			defaultID = id
		}
	}
	if err := taxRows.Err(); err != nil {
		taxRows.Close()
		return nil, err
	}
	taxRows.Close()

	out := map[string]float64{}
	for _, productID := range productIDs {
		taxTypeID := explicit[productID]
		if taxTypeID == "" {
			taxTypeID = branchDefault
			if taxTypeID == "" {
				taxTypeID = defaultID
			}
		}
		if rate, ok := rates[taxTypeID]; ok && taxTypeID != "" {
			out[productID] = rate
		}
	}
	return out, nil
}

func menuRowIDs(rows []menuProductRow) (productIDs, menuProductIDs []string) {
	seenProducts, seenMenuProducts := map[string]bool{}, map[string]bool{}
	for _, row := range rows {
		if row.productID != "" && !seenProducts[row.productID] {
			seenProducts[row.productID] = true
			productIDs = append(productIDs, row.productID)
		}
		if row.id != "" && !seenMenuProducts[row.id] {
			seenMenuProducts[row.id] = true
			menuProductIDs = append(menuProductIDs, row.id)
		}
	}
	return productIDs, menuProductIDs
}

func menuRelationKey(parts ...string) string {
	return strings.Join(parts, "\x00")
}
