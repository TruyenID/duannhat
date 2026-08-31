package service

import (
	"context"
	"database/sql"
	"encoding/json"
	"fmt"
	"log/slog"
	"strconv"
	"time"
)

// Phase 2 sync DOWN — replica feeds added to the slow loop (5 min):
//   /api/v1/workstation/effective-payment-options → effective_payment_options (plan-047)
//   /api/v1/workstation/payment-methods           → payment_methods (legacy)
//   /api/v1/workstation/customers                 → customers (incremental)
//   /api/v1/workstation/menu-schedules            → menu_schedules
//
// Each method is safe to call independently; pullSlowPos() chains them with
// per-feed error logging so one bad endpoint can't poison the entire cycle.

const (
	pullPathEffectivePaymentOptions = "/api/v1/workstation/effective-payment-options"
	pullPathPaymentMethods          = "/api/v1/workstation/payment-methods"
	pullPathPeripheralDevices       = "/api/v1/workstation/peripheral-devices"
	pullPathPrinters                = "/api/v1/workstation/printers"

	paymentPolicyRevisionKey     = "sync.payment_policy.revision"
	paymentPolicySnapshotHashKey = "sync.payment_policy.snapshot_hash"
	pullPathCustomers            = "/api/v1/workstation/customers"
	pullPathMenuSchedules        = "/api/v1/workstation/menu-schedules"
	pullPathTill                 = "/api/v1/workstation/till"
	pullPathTillSessions         = "/api/v1/workstation/till-sessions/active"
	pullPathDenominations        = "/api/v1/workstation/till-denominations"
	pullPathTenderCategories     = "/api/v1/workstation/till-tender-categories"
	pullPathTenderTypes          = "/api/v1/workstation/till-tender-types"
	pullPathCoupons              = "/api/v1/workstation/coupons"
	pullPathPromotions           = "/api/v1/workstation/menu-promotions"
	pullPathMenuCatalog          = "/api/v1/workstation/menu-catalog"
	pullPathStaff                = "/api/v1/workstation/staff"

	customersCursorKey = "sync.customers.last_pulled"
)

// pullSlowPos is wired into the slow loop alongside PullBranch + PullLots.
func (p *SyncPuller) pullSlowPos() {
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()

	if err := p.PullEffectivePaymentOptions(ctx); err != nil {
		slog.Warn("sync_pull effective_payment_options", "err", err)
	}
	if err := p.PullPaymentMethods(ctx); err != nil {
		slog.Warn("sync_pull payment_methods", "err", err)
	}
	if err := p.PullPeripheralDevices(ctx); err != nil {
		slog.Warn("sync_pull peripheral_devices", "err", err)
	}
	if err := p.PullPrinters(ctx); err != nil {
		slog.Warn("sync_pull printers", "err", err)
	}
	if err := p.PullCustomers(ctx); err != nil {
		slog.Warn("sync_pull customers", "err", err)
	}
	if err := p.PullMenuSchedules(ctx); err != nil {
		slog.Warn("sync_pull menu_schedules", "err", err)
	}
	if err := p.PullTill(ctx); err != nil {
		slog.Warn("sync_pull till", "err", err)
	}
	if err := p.PullTillSessions(ctx); err != nil {
		slog.Warn("sync_pull till_sessions", "err", err)
	}
	if err := p.PullDenominations(ctx); err != nil {
		slog.Warn("sync_pull denominations", "err", err)
	}
	if err := p.PullTenderCategories(ctx); err != nil {
		slog.Warn("sync_pull tender_categories", "err", err)
	}
	if err := p.PullTenderTypes(ctx); err != nil {
		slog.Warn("sync_pull tender_types", "err", err)
	}
	if err := p.PullCoupons(ctx); err != nil {
		slog.Warn("sync_pull coupons", "err", err)
	}
	if err := p.PullPromotions(ctx); err != nil {
		slog.Warn("sync_pull promotions", "err", err)
	}
	if err := p.PullMenuCatalog(ctx); err != nil {
		slog.Warn("sync_pull menu_catalog", "err", err)
	}
	if err := p.PullStaff(ctx); err != nil {
		slog.Warn("sync_pull staff", "err", err)
	}
}

// PullStaff replaces the local staff replica. Small list (<50 rows per
// shop), Cloud is the only writer, full replace is the simplest correct
// semantic. Used by the Open Shift form's "Người mở ca" dropdown.
func (p *SyncPuller) PullStaff(ctx context.Context) error {
	var resp struct {
		Data []struct {
			ID        string `json:"id"`
			Name      string `json:"name"`
			Email     string `json:"email"`
			AvatarURL string `json:"avatar_url"`
		} `json:"data"`
	}
	if err := p.cloudGet(ctx, pullPathStaff, &resp); err != nil {
		return err
	}
	return p.atomic(func(tx *sql.Tx) error {
		if _, err := tx.Exec("DELETE FROM staff"); err != nil {
			return err
		}
		if len(resp.Data) == 0 {
			return nil
		}
		stmt, err := tx.Prepare(`
			INSERT INTO staff (id, full_name, role_slug, is_active,
			    cloud_updated_at, local_synced_at)
			VALUES (?, ?, NULL, 1, datetime('now'), datetime('now'))`)
		if err != nil {
			return err
		}
		defer stmt.Close()
		for _, u := range resp.Data {
			if _, err := stmt.Exec(u.ID, u.Name); err != nil {
				return err
			}
		}
		return nil
	})
}

// PullMenuCatalog replaces the 5 menu-eager-load tables atomically. Cloud
// flattens the nested MenuResource shape into per-row payloads so the
// workstation doesn't need to walk relations on its side. List stays
// bounded for any realistic shop (<200 SKUs across all menus), so the
// full replace is the simplest correct semantic.
func (p *SyncPuller) PullMenuCatalog(ctx context.Context) error {
	var resp struct {
		Data struct {
			Menus []struct {
				ID          string `json:"id"`
				Name        string `json:"name"`
				Description string `json:"description"`
				Status      string `json:"status"`
				SortOrder   int    `json:"sort_order"`
				ServiceType string `json:"service_type"`
			} `json:"menus"`
			Sections []struct {
				ID        string `json:"id"`
				MenuID    string `json:"menu_id"`
				Name      string `json:"name"`
				SortOrder int    `json:"sort_order"`
				IsActive  bool   `json:"is_active"`
			} `json:"sections"`
			MenuProducts []struct {
				ID            string  `json:"id"`
				MenuID        string  `json:"menu_id"`
				ProductID     string  `json:"product_id"`
				MenuSectionID *string `json:"menu_section_id"`
				IsActive      bool    `json:"is_active"`
				DisplayOrder  int     `json:"display_order"`
			} `json:"menu_products"`
			Products []struct {
				ID              string  `json:"id"`
				Name            string  `json:"name"`
				NameJa          *string `json:"name_ja"`
				NameEn          *string `json:"name_en"`
				NameVi          *string `json:"name_vi"`
				Description     string  `json:"description"`
				IsActive        bool    `json:"is_active"`
				ProductTypeCode *string `json:"product_type_code"`
				ImageURL        *string `json:"image_url"`
			} `json:"products"`
			ProductGalleries []struct {
				ID           string  `json:"id"`
				ProductID    string  `json:"product_id"`
				URL          string  `json:"url"`
				OriginalName *string `json:"original_name"`
				MimeType     *string `json:"mime_type"`
				SortOrder    *int    `json:"sort_order"`
			} `json:"product_galleries"`
			Skus []struct {
				ID                string  `json:"id"`
				ProductID         string  `json:"product_id"`
				Name              string  `json:"name"`
				NameJa            *string `json:"name_ja"`
				NameEn            *string `json:"name_en"`
				NameVi            *string `json:"name_vi"`
				Sku               string  `json:"sku"`
				SellingPrice      int     `json:"selling_price"`
				DefaultPrice      *int    `json:"default_price"`
				IsPriceOverridden bool    `json:"is_price_overridden"`
				IsActive          bool    `json:"is_active"`
				ImageURL          *string `json:"image_url"`
				OptionValue1ID    *string `json:"option_value1_id"`
				OptionValue2ID    *string `json:"option_value2_id"`
				OptionValue3ID    *string `json:"option_value3_id"`
			} `json:"skus"`
			ProductOptions []struct {
				ID        string  `json:"id"`
				ProductID string  `json:"product_id"`
				Key       string  `json:"key"`
				Name      string  `json:"name"`
				NameJa    *string `json:"name_ja"`
				NameEn    *string `json:"name_en"`
				NameVi    *string `json:"name_vi"`
				Position  int     `json:"position"`
				IsActive  bool    `json:"is_active"`
			} `json:"product_options"`
			ProductOptionValues []struct {
				ID       string  `json:"id"`
				OptionID string  `json:"option_id"`
				Value    string  `json:"value"`
				Label    *string `json:"label"`
				LabelJa  *string `json:"label_ja"`
				LabelEn  *string `json:"label_en"`
				LabelVi  *string `json:"label_vi"`
				Position int     `json:"position"`
				IsActive bool    `json:"is_active"`
			} `json:"product_option_values"`
			ToppingGroups []struct {
				ID            string  `json:"id"`
				Name          string  `json:"name"`
				NameJa        *string `json:"name_ja"`
				NameEn        *string `json:"name_en"`
				NameVi        *string `json:"name_vi"`
				SelectionType string  `json:"selection_type"`
				ModifierType  string  `json:"modifier_type"`
				PriceStrategy string  `json:"price_strategy"`
				FreeQuantity  *int    `json:"free_quantity"`
				MinSelect     int     `json:"min_select"`
				MaxSelect     *int    `json:"max_select"`
				MaxQtyPerItem int     `json:"max_qty_per_item"`
				SortOrder     int     `json:"sort_order"`
				IsActive      bool    `json:"is_active"`
			} `json:"topping_groups"`
			ToppingGroupItems []struct {
				ID             string `json:"id"`
				ToppingGroupID string `json:"topping_group_id"`
				ProductID      string `json:"product_id"`
				SortOrder      int    `json:"sort_order"`
				IsDefault      bool   `json:"is_default"`
				// plan-043 (T3.3) — component alcohol flag, mirror of the
				// component product's is_alcohol so 酒類 escalation runs offline.
				// Additive: an old catalog feed omits it → false → no escalation.
				IsAlcohol bool `json:"is_alcohol"`
			} `json:"topping_group_items"`
			ToppingGroupItemSkus []struct {
				ID                 string  `json:"id"`
				ToppingGroupItemID string  `json:"topping_group_item_id"`
				ProductSkuID       *string `json:"product_sku_id"`
				ExtraPrice         int     `json:"extra_price"`
			} `json:"topping_group_item_skus"`
			ProductToppingGroups []struct {
				ProductID         string `json:"product_id"`
				ToppingGroupID    string `json:"topping_group_id"`
				SortOrder         int    `json:"sort_order"`
				MinSelectOverride *int   `json:"min_select_override"`
				MaxSelectOverride *int   `json:"max_select_override"`
			} `json:"product_topping_groups"`
			ProductToppingItemOverrides []struct {
				ID                 string  `json:"id"`
				ProductID          string  `json:"product_id"`
				ToppingGroupItemID string  `json:"topping_group_item_id"`
				ProductSkuID       *string `json:"product_sku_id"`
				OverridePrice      *int    `json:"override_price"`
				IsHidden           bool    `json:"is_hidden"`
			} `json:"product_topping_item_overrides"`
			MenuProductToppingOverrides []struct {
				ID                 string  `json:"id"`
				MenuProductID      string  `json:"menu_product_id"`
				ToppingGroupID     string  `json:"topping_group_id"`
				ToppingGroupItemID string  `json:"topping_group_item_id"`
				ProductSkuID       *string `json:"product_sku_id"`
				IsHidden           bool    `json:"is_hidden"`
				OverridePrice      *int    `json:"override_price"`
			} `json:"menu_product_topping_overrides"`
		} `json:"data"`
	}
	if err := p.cloudGet(ctx, pullPathMenuCatalog, &resp); err != nil {
		return err
	}

	return p.atomic(func(tx *sql.Tx) error {
		// Wipe in FK-safe order (children first, parents last). The
		// override tables + topping pivots are leaves, the
		// option_values reference options, and pos_product_galleries +
		// pos_product_skus + topping_group_items + options all
		// reference pos_products — so pos_products is last in the
		// "product family" wave.
		for _, t := range []string{
			"pos_menu_product_topping_overrides",
			"pos_product_topping_item_overrides",
			"pos_product_topping_groups",
			"pos_topping_group_item_skus",
			"pos_topping_group_items",
			"pos_topping_groups",
			"pos_product_option_values",
			"pos_product_options",
			"pos_product_galleries",
			"pos_product_skus",
			"pos_products",
			"pos_menu_products",
			"pos_menu_sections",
			"pos_menus",
		} {
			if _, err := tx.Exec("DELETE FROM " + t); err != nil {
				return err
			}
		}
		if len(resp.Data.Menus) == 0 {
			return nil
		}

		menuStmt, err := tx.Prepare(`
			INSERT INTO pos_menus (id, name, description, status, sort_order,
			    service_type, cloud_updated_at, local_synced_at)
			VALUES (?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))`)
		if err != nil {
			return err
		}
		defer menuStmt.Close()
		for _, m := range resp.Data.Menus {
			status := m.Status
			if status == "" {
				status = "published"
			}
			// #481 — Cloud emits the effective service type; default legacy /
			// empty feeds to Both so the menu stays visible under any gate.
			serviceType := m.ServiceType
			if serviceType == "" {
				serviceType = "Both"
			}
			if _, err := menuStmt.Exec(m.ID, m.Name, nullableString(m.Description),
				status, m.SortOrder, serviceType); err != nil {
				return err
			}
		}

		sectionStmt, err := tx.Prepare(`
			INSERT INTO pos_menu_sections (id, menu_id, name, sort_order, is_active,
			    cloud_updated_at, local_synced_at)
			VALUES (?, ?, ?, ?, ?, datetime('now'), datetime('now'))`)
		if err != nil {
			return err
		}
		defer sectionStmt.Close()
		for _, s := range resp.Data.Sections {
			active := 0
			if s.IsActive {
				active = 1
			}
			if _, err := sectionStmt.Exec(s.ID, s.MenuID, s.Name, s.SortOrder, active); err != nil {
				return err
			}
		}

		productStmt, err := tx.Prepare(`
			INSERT INTO pos_products (id, name, name_ja, name_en, name_vi, description, is_active,
			    product_type_code, image_url,
			    cloud_updated_at, local_synced_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))`)
		if err != nil {
			return err
		}
		defer productStmt.Close()
		for _, p := range resp.Data.Products {
			active := 0
			if p.IsActive {
				active = 1
			}
			if _, err := productStmt.Exec(p.ID, p.Name,
				nullableString(deref(p.NameJa)), nullableString(deref(p.NameEn)), nullableString(deref(p.NameVi)),
				nullableString(p.Description), active,
				nullableString(deref(p.ProductTypeCode)),
				nullableString(deref(p.ImageURL))); err != nil {
				return err
			}
		}

		galleryStmt, err := tx.Prepare(`
			INSERT INTO pos_product_galleries (id, product_id, url, original_name,
			    mime_type, sort_order, cloud_updated_at, local_synced_at)
			VALUES (?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))`)
		if err != nil {
			return err
		}
		defer galleryStmt.Close()
		for _, g := range resp.Data.ProductGalleries {
			var sortOrder any
			if g.SortOrder != nil {
				sortOrder = *g.SortOrder
			}
			if _, err := galleryStmt.Exec(g.ID, g.ProductID, g.URL,
				nullableString(deref(g.OriginalName)),
				nullableString(deref(g.MimeType)),
				sortOrder); err != nil {
				return err
			}
		}

		skuStmt, err := tx.Prepare(`
			INSERT INTO pos_product_skus (id, product_id, name, name_ja, name_en, name_vi, sku, selling_price,
			    is_active, image_url, default_price, is_price_overridden,
			    option_value1_id, option_value2_id, option_value3_id,
			    cloud_updated_at, local_synced_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))`)
		if err != nil {
			return err
		}
		defer skuStmt.Close()
		for _, sk := range resp.Data.Skus {
			active := 0
			if sk.IsActive {
				active = 1
			}
			overridden := 0
			if sk.IsPriceOverridden {
				overridden = 1
			}
			var defaultPrice any
			if sk.DefaultPrice != nil {
				defaultPrice = *sk.DefaultPrice
			}
			if _, err := skuStmt.Exec(sk.ID, sk.ProductID, nullableString(sk.Name),
				nullableString(deref(sk.NameJa)), nullableString(deref(sk.NameEn)), nullableString(deref(sk.NameVi)),
				nullableString(sk.Sku), sk.SellingPrice, active,
				nullableString(deref(sk.ImageURL)),
				defaultPrice, overridden,
				nullableString(deref(sk.OptionValue1ID)),
				nullableString(deref(sk.OptionValue2ID)),
				nullableString(deref(sk.OptionValue3ID))); err != nil {
				return err
			}
		}

		// ─── product_options + option_values ────────────────────────

		optStmt, err := tx.Prepare(`
			INSERT INTO pos_product_options (id, product_id, key, name, name_ja, name_en, name_vi, position, is_active,
			    cloud_updated_at, local_synced_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))`)
		if err != nil {
			return err
		}
		defer optStmt.Close()
		for _, o := range resp.Data.ProductOptions {
			active := 0
			if o.IsActive {
				active = 1
			}
			if _, err := optStmt.Exec(o.ID, o.ProductID, o.Key, o.Name,
				nullableString(deref(o.NameJa)), nullableString(deref(o.NameEn)), nullableString(deref(o.NameVi)),
				o.Position, active); err != nil {
				return err
			}
		}

		optValStmt, err := tx.Prepare(`
			INSERT INTO pos_product_option_values (id, option_id, value, label, label_ja, label_en, label_vi, position, is_active,
			    cloud_updated_at, local_synced_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))`)
		if err != nil {
			return err
		}
		defer optValStmt.Close()
		for _, v := range resp.Data.ProductOptionValues {
			active := 0
			if v.IsActive {
				active = 1
			}
			if _, err := optValStmt.Exec(v.ID, v.OptionID, v.Value,
				nullableString(deref(v.Label)),
				nullableString(deref(v.LabelJa)), nullableString(deref(v.LabelEn)), nullableString(deref(v.LabelVi)),
				v.Position, active); err != nil {
				return err
			}
		}

		// ─── topping_groups + items + item_skus ─────────────────────

		tgStmt, err := tx.Prepare(`
			INSERT INTO pos_topping_groups (id, name, name_ja, name_en, name_vi, selection_type, modifier_type, price_strategy,
			    free_quantity, min_select, max_select, max_qty_per_item, sort_order, is_active,
			    cloud_updated_at, local_synced_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))`)
		if err != nil {
			return err
		}
		defer tgStmt.Close()
		for _, g := range resp.Data.ToppingGroups {
			active := 0
			if g.IsActive {
				active = 1
			}
			var freeQty, maxSel any
			if g.FreeQuantity != nil {
				freeQty = *g.FreeQuantity
			}
			if g.MaxSelect != nil {
				maxSel = *g.MaxSelect
			}
			if _, err := tgStmt.Exec(g.ID, g.Name,
				nullableString(deref(g.NameJa)), nullableString(deref(g.NameEn)), nullableString(deref(g.NameVi)),
				g.SelectionType, g.ModifierType, g.PriceStrategy,
				freeQty, g.MinSelect, maxSel, g.MaxQtyPerItem, g.SortOrder, active); err != nil {
				return err
			}
		}

		itemStmt, err := tx.Prepare(`
			INSERT INTO pos_topping_group_items (id, topping_group_id, product_id, sort_order, is_default,
			    is_alcohol, cloud_updated_at, local_synced_at)
			VALUES (?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))`)
		if err != nil {
			return err
		}
		defer itemStmt.Close()
		for _, it := range resp.Data.ToppingGroupItems {
			isDefault := 0
			if it.IsDefault {
				isDefault = 1
			}
			isAlcohol := 0
			if it.IsAlcohol {
				isAlcohol = 1
			}
			if _, err := itemStmt.Exec(it.ID, it.ToppingGroupID, it.ProductID, it.SortOrder, isDefault, isAlcohol); err != nil {
				return err
			}
		}

		itemSkuStmt, err := tx.Prepare(`
			INSERT INTO pos_topping_group_item_skus (id, topping_group_item_id, product_sku_id, extra_price,
			    cloud_updated_at, local_synced_at)
			VALUES (?, ?, ?, ?, datetime('now'), datetime('now'))`)
		if err != nil {
			return err
		}
		defer itemSkuStmt.Close()
		for _, isk := range resp.Data.ToppingGroupItemSkus {
			if _, err := itemSkuStmt.Exec(isk.ID, isk.ToppingGroupItemID,
				nullableString(deref(isk.ProductSkuID)), isk.ExtraPrice); err != nil {
				return err
			}
		}

		// ─── pivot product↔group + tier-1/2 overrides ──────────────

		pivStmt, err := tx.Prepare(`
			INSERT INTO pos_product_topping_groups (product_id, topping_group_id, sort_order,
			    min_select_override, max_select_override, cloud_updated_at, local_synced_at)
			VALUES (?, ?, ?, ?, ?, datetime('now'), datetime('now'))
			ON CONFLICT(product_id, topping_group_id) DO NOTHING`)
		if err != nil {
			return err
		}
		defer pivStmt.Close()
		for _, piv := range resp.Data.ProductToppingGroups {
			var minOv, maxOv any
			if piv.MinSelectOverride != nil {
				minOv = *piv.MinSelectOverride
			}
			if piv.MaxSelectOverride != nil {
				maxOv = *piv.MaxSelectOverride
			}
			if _, err := pivStmt.Exec(piv.ProductID, piv.ToppingGroupID, piv.SortOrder, minOv, maxOv); err != nil {
				return err
			}
		}

		prodOvStmt, err := tx.Prepare(`
			INSERT INTO pos_product_topping_item_overrides (id, product_id, topping_group_item_id,
			    product_sku_id, override_price, is_hidden, cloud_updated_at, local_synced_at)
			VALUES (?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))`)
		if err != nil {
			return err
		}
		defer prodOvStmt.Close()
		for _, ov := range resp.Data.ProductToppingItemOverrides {
			hidden := 0
			if ov.IsHidden {
				hidden = 1
			}
			var op any
			if ov.OverridePrice != nil {
				op = *ov.OverridePrice
			}
			if _, err := prodOvStmt.Exec(ov.ID, ov.ProductID, ov.ToppingGroupItemID,
				nullableString(deref(ov.ProductSkuID)), op, hidden); err != nil {
				return err
			}
		}

		mpOvStmt, err := tx.Prepare(`
			INSERT INTO pos_menu_product_topping_overrides (id, menu_product_id, topping_group_id,
			    topping_group_item_id, product_sku_id, is_hidden, override_price,
			    cloud_updated_at, local_synced_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))`)
		if err != nil {
			return err
		}
		defer mpOvStmt.Close()
		for _, ov := range resp.Data.MenuProductToppingOverrides {
			hidden := 0
			if ov.IsHidden {
				hidden = 1
			}
			var op any
			if ov.OverridePrice != nil {
				op = *ov.OverridePrice
			}
			if _, err := mpOvStmt.Exec(ov.ID, ov.MenuProductID, ov.ToppingGroupID,
				ov.ToppingGroupItemID, nullableString(deref(ov.ProductSkuID)), hidden, op); err != nil {
				return err
			}
		}

		mpStmt, err := tx.Prepare(`
			INSERT INTO pos_menu_products (id, menu_id, product_id, menu_section_id,
			    is_active, display_order, cloud_updated_at, local_synced_at)
			VALUES (?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))`)
		if err != nil {
			return err
		}
		defer mpStmt.Close()
		for _, mp := range resp.Data.MenuProducts {
			active := 0
			if mp.IsActive {
				active = 1
			}
			var sectionID any
			if mp.MenuSectionID != nil {
				sectionID = *mp.MenuSectionID
			}
			if _, err := mpStmt.Exec(mp.ID, mp.MenuID, mp.ProductID, sectionID,
				active, mp.DisplayOrder); err != nil {
				return err
			}
		}
		return nil
	})
}

// PullCoupons replaces the local coupons replica. Replace-all is safe — the
// list is small (<100 rows per shop) and Cloud is the authority on
// usage_limit + status. Workstation pre-validates against this replica
// before sync UP; Cloud re-validates and rolls back on cap exceeded.
func (p *SyncPuller) PullCoupons(ctx context.Context) error {
	var resp struct {
		Data []struct {
			ID                      string   `json:"id"`
			Code                    string   `json:"code"`
			Name                    string   `json:"name"`
			Description             string   `json:"description"`
			DiscountType            string   `json:"discount_type"`
			DiscountValue           int      `json:"discount_value"`
			MinOrderSubtotal        int      `json:"min_order_subtotal"`
			MaxDiscountCap          *int     `json:"max_discount_cap"`
			ValidFrom               string   `json:"valid_from"`
			ValidUntil              string   `json:"valid_until"`
			UsageLimitTotal         *int     `json:"usage_limit_total"`
			TimesUsed               int      `json:"times_used"`
			UsageLimitPerCustomer   *int     `json:"usage_limit_per_customer"`
			Status                  string   `json:"status"`
			StackingMode            string   `json:"stacking_mode"`
			ExclusiveWithPromotions bool     `json:"exclusive_with_promotions"`
			AppliesTo               string   `json:"applies_to"`
			BrandID                 string   `json:"brand_id"`
			OrganizationID          string   `json:"organization_id"`
			Branches                []string `json:"branches"`
			// Phase C: i18n payload — each entry is one row in the
			// coupon_translations replica. Empty / missing = no i18n
			// rows; the device falls back to the canonical name/description.
			Translations []struct {
				Locale      string `json:"locale"`
				Name        string `json:"name"`
				Description string `json:"description"`
			} `json:"translations"`
			// Backwards-compat: tolerate the legacy field names so an
			// older Cloud build (before the renamed-payload rollout)
			// still drives a usable replica.
			LegacyMinOrderAmount   int  `json:"min_order_amount"`
			LegacyMaxDiscount      *int `json:"max_discount"`
			LegacyPerCustomerLimit *int `json:"per_customer_limit"`
		} `json:"data"`
	}
	if err := p.cloudGet(ctx, pullPathCoupons, &resp); err != nil {
		return err
	}
	if len(resp.Data) == 0 {
		slog.Info("PullCoupons empty payload — Cloud returned 0 rows", "url", pullPathCoupons)
		return nil
	}
	return p.atomic(func(tx *sql.Tx) error {
		// Bulletproof sync (Plan: workstation full coupon mirror,
		// follow-up #2). Three guards against the "new coupons stopped
		// syncing" failure mode the user reported:
		//
		//  1. UPSERT semantics — coupon_redemptions has FK → coupons(id)
		//     under PRAGMA foreign_keys=ON (store/db.go:40), so a plain
		//     `DELETE FROM coupons` aborts the whole tx the moment any
		//     customer has redeemed. Upsert never deletes the parent
		//     row, so the FK stays valid AND every Cloud-owned column
		//     refreshes on each tick.
		//
		//  2. Per-row SAVEPOINT — one malformed row from Cloud (rare,
		//     but anything from a NOT-NULL violation to a future schema
		//     drift) used to roll back the entire batch. Now each row's
		//     upsert + pivots run inside their own savepoint; a bad row
		//     ROLLBACK TO and the rest keep streaming in. Failed rows
		//     get logged with their id+code so HQ can spot the offender.
		//
		//  3. UNIQUE(code) dropped (migration 030) — pre-030 the
		//     workstation had its own UNIQUE index on `code`, so an
		//     orphan local row from a soft-deleted coupon would block
		//     a brand-new coupon Cloud created reusing the same code.
		//     Backend already enforces uniqueness per org at the API
		//     layer; the local UNIQUE was redundant + footgun.
		stmt, err := tx.Prepare(`
			INSERT INTO coupons (id, code, name, description,
			    discount_type, discount_value,
			    min_order_subtotal, max_discount_cap, valid_from, valid_until,
			    usage_limit_total, times_used, usage_limit_per_customer,
			    status, stacking_mode, exclusive_with_promotions, applies_to,
			    brand_id, organization_id,
			    cloud_updated_at, local_synced_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))
			ON CONFLICT(id) DO UPDATE SET
			    code                      = excluded.code,
			    name                      = excluded.name,
			    description               = excluded.description,
			    discount_type             = excluded.discount_type,
			    discount_value            = excluded.discount_value,
			    min_order_subtotal        = excluded.min_order_subtotal,
			    max_discount_cap          = excluded.max_discount_cap,
			    valid_from                = excluded.valid_from,
			    valid_until               = excluded.valid_until,
			    usage_limit_total         = excluded.usage_limit_total,
			    times_used                = excluded.times_used,
			    usage_limit_per_customer  = excluded.usage_limit_per_customer,
			    status                    = excluded.status,
			    stacking_mode             = excluded.stacking_mode,
			    exclusive_with_promotions = excluded.exclusive_with_promotions,
			    applies_to                = excluded.applies_to,
			    brand_id                  = excluded.brand_id,
			    organization_id           = excluded.organization_id,
			    local_synced_at           = datetime('now')`)
		if err != nil {
			return err
		}
		defer stmt.Close()
		// Per-coupon pivot wipe. Run BEFORE inserting branch rows so
		// a removed branch on Cloud's side actually drops on the
		// device. Scoped to the incoming coupon ids only — coupons
		// not in this feed keep their existing pivot rows (they'll
		// fail the engine's status/window checks anyway, so the rows
		// are harmless until next pull surfaces them again).
		clearBranches, err := tx.Prepare(`DELETE FROM coupon_branches WHERE coupon_id = ?`)
		if err != nil {
			return err
		}
		defer clearBranches.Close()
		clearTranslations, err := tx.Prepare(`DELETE FROM coupon_translations WHERE coupon_id = ?`)
		if err != nil {
			return err
		}
		defer clearTranslations.Close()
		branchStmt, err := tx.Prepare(`
			INSERT OR IGNORE INTO coupon_branches (coupon_id, branch_id)
			VALUES (?, ?)`)
		if err != nil {
			return err
		}
		defer branchStmt.Close()
		translationStmt, err := tx.Prepare(`
			INSERT OR IGNORE INTO coupon_translations
			    (id, coupon_id, locale, name, description, local_synced_at)
			VALUES (?, ?, ?, ?, ?, datetime('now'))`)
		if err != nil {
			return err
		}
		defer translationStmt.Close()

		ok := 0
		failed := 0
		summary := make([]string, 0, len(resp.Data))
		for idx, c := range resp.Data {
			// Per-row SAVEPOINT name — alphanumeric so SQLite parser
			// is happy. Idx-based so a duplicate id wouldn't reuse a
			// name across iterations.
			spName := "couponsp_" + strconv.Itoa(idx)
			if _, err := tx.Exec("SAVEPOINT " + spName); err != nil {
				return fmt.Errorf("savepoint open: %w", err)
			}
			if err := upsertOneCoupon(tx, stmt, clearBranches, clearTranslations,
				branchStmt, translationStmt, c); err != nil {
				slog.Warn("PullCoupons skip row",
					"id", c.ID, "code", c.Code,
					"discount_value", c.DiscountValue, "err", err)
				if _, rerr := tx.Exec("ROLLBACK TO " + spName); rerr != nil {
					return fmt.Errorf("savepoint rollback: %w", rerr)
				}
				if _, rerr := tx.Exec("RELEASE " + spName); rerr != nil {
					return fmt.Errorf("savepoint release after rollback: %w", rerr)
				}
				failed++
				summary = append(summary, c.Code+"=SKIPPED")
				continue
			}
			if _, err := tx.Exec("RELEASE " + spName); err != nil {
				return fmt.Errorf("savepoint release: %w", err)
			}
			ok++
			summary = append(summary, c.Code+"="+strconv.Itoa(c.DiscountValue))
		}
		slog.Info("PullCoupons synced",
			"ok", ok, "failed", failed, "total", len(resp.Data),
			"values", summary)
		// FK-safe cleanup of coupons removed from Cloud's feed.
		// `id NOT IN (...) AND id NOT IN (SELECT ...)` keeps the
		// per-row DELETE from tripping the coupon_redemptions FK on
		// any coupon that's been redeemed at least once. Stale
		// orphans (Cloud-deleted AND ever-redeemed) survive on the
		// device — harmless because their last-synced status will
		// reject them at apply time, and the redemption ledger
		// stays intact for audit. Plain coupons that never got used
		// drop out as expected, which is what the test pinned.
		incomingIDs := make([]any, 0, len(resp.Data))
		placeholders := ""
		for i, c := range resp.Data {
			incomingIDs = append(incomingIDs, c.ID)
			if i > 0 {
				placeholders += ","
			}
			placeholders += "?"
		}
		if len(incomingIDs) > 0 {
			query := `DELETE FROM coupons
				WHERE id NOT IN (` + placeholders + `)
				  AND id NOT IN (SELECT coupon_id FROM coupon_redemptions)`
			if _, err := tx.Exec(query, incomingIDs...); err != nil {
				return err
			}
		}
		return nil
	})
}

// schedulePayload + translationPayload are the lifted-from-anonymous
// shapes the savepoint helpers consume. Lifting them out of the inline
// `resp.Data[i].Schedules` / `.Translations` struct keeps the helpers'
// signatures readable.
type schedulePayload struct {
	ID            string
	DayOfWeek     *int
	DailyTimeFrom string
	DailyTimeTo   string
}

type translationPayload struct {
	Locale      string
	Name        string
	Description string
}

func schedulesAsAny(in []struct {
	ID            string `json:"id"`
	DayOfWeek     *int   `json:"day_of_week"`
	DailyTimeFrom string `json:"daily_time_from"`
	DailyTimeTo   string `json:"daily_time_to"`
}) []schedulePayload {
	out := make([]schedulePayload, 0, len(in))
	for _, s := range in {
		out = append(out, schedulePayload{
			ID: s.ID, DayOfWeek: s.DayOfWeek,
			DailyTimeFrom: s.DailyTimeFrom, DailyTimeTo: s.DailyTimeTo,
		})
	}
	return out
}

func translationsAsAny(in []struct {
	Locale      string `json:"locale"`
	Name        string `json:"name"`
	Description string `json:"description"`
}) []translationPayload {
	out := make([]translationPayload, 0, len(in))
	for _, t := range in {
		out = append(out, translationPayload{
			Locale: t.Locale, Name: t.Name, Description: t.Description,
		})
	}
	return out
}

// upsertOnePromotion runs the full per-row apply for one menu_promotion
// (upsert + product pivot rebuild + schedule rebuild + i18n rebuild).
// Caller wraps in a SAVEPOINT so any one row's failure is isolated.
func upsertOnePromotion(tx *sql.Tx,
	insPromo, clearProducts, clearSchedules, clearTranslations,
	insProduct, insSched, insTranslation *sql.Stmt,
	id, name, description, discountType string, discountValue int,
	startsAt, endsAt string, active, excl int,
	stackingMode, appliesTo string,
	branchID, brandID, organizationID, promoCreatedAt string,
	priority int,
	productIDs, productSkuIDs []string,
	schedules []schedulePayload,
	translations []translationPayload,
) error {
	if _, err := insPromo.Exec(id, name, nullableString(description),
		discountType, discountValue,
		nullableString(startsAt), nullableString(endsAt),
		active, excl,
		stackingMode, appliesTo,
		nullableString(branchID), nullableString(brandID), nullableString(organizationID),
		nullableString(promoCreatedAt), priority,
	); err != nil {
		return fmt.Errorf("upsert menu_promotion: %w", err)
	}
	if _, err := clearProducts.Exec(id); err != nil {
		return fmt.Errorf("clear products pivot: %w", err)
	}
	if _, err := clearSchedules.Exec(id); err != nil {
		return fmt.Errorf("clear schedules: %w", err)
	}
	if _, err := clearTranslations.Exec(id); err != nil {
		return fmt.Errorf("clear translations: %w", err)
	}
	seen := map[string]bool{}
	for _, pid := range productIDs {
		if pid == "" || seen[pid] {
			continue
		}
		seen[pid] = true
		if _, err := insProduct.Exec(id, pid); err != nil {
			return fmt.Errorf("insert product %s: %w", pid, err)
		}
	}
	for _, skuID := range productSkuIDs {
		if skuID == "" {
			continue
		}
		var resolvedProductID string
		_ = tx.QueryRow(
			`SELECT product_id FROM pos_product_skus WHERE id = ?`, skuID,
		).Scan(&resolvedProductID)
		if resolvedProductID == "" || seen[resolvedProductID] {
			continue
		}
		seen[resolvedProductID] = true
		if _, err := insProduct.Exec(id, resolvedProductID); err != nil {
			return fmt.Errorf("insert resolved product %s: %w", resolvedProductID, err)
		}
	}
	for _, sc := range schedules {
		if _, err := insSched.Exec(sc.ID, id, intPtrToNullable(sc.DayOfWeek),
			nullableString(sc.DailyTimeFrom), nullableString(sc.DailyTimeTo)); err != nil {
			return fmt.Errorf("insert schedule %s: %w", sc.ID, err)
		}
	}
	for _, tr := range translations {
		if tr.Locale == "" {
			continue
		}
		rowID := id + ":" + tr.Locale
		if _, err := insTranslation.Exec(
			rowID, id, tr.Locale, tr.Name, nullableString(tr.Description),
		); err != nil {
			return fmt.Errorf("insert translation %s: %w", tr.Locale, err)
		}
	}
	return nil
}

// upsertOneCoupon runs the full per-row apply (upsert + branch pivot
// rebuild + i18n pivot rebuild) for a single coupon. The caller wraps
// this in a SAVEPOINT so any one row's failure (constraint violation,
// FK trip, anything) gets isolated to that row instead of poisoning
// the rest of the batch.
func upsertOneCoupon(tx *sql.Tx, stmt, clearBranches, clearTranslations,
	branchStmt, translationStmt *sql.Stmt, c struct {
	ID                      string   `json:"id"`
	Code                    string   `json:"code"`
	Name                    string   `json:"name"`
	Description             string   `json:"description"`
	DiscountType            string   `json:"discount_type"`
	DiscountValue           int      `json:"discount_value"`
	MinOrderSubtotal        int      `json:"min_order_subtotal"`
	MaxDiscountCap          *int     `json:"max_discount_cap"`
	ValidFrom               string   `json:"valid_from"`
	ValidUntil              string   `json:"valid_until"`
	UsageLimitTotal         *int     `json:"usage_limit_total"`
	TimesUsed               int      `json:"times_used"`
	UsageLimitPerCustomer   *int     `json:"usage_limit_per_customer"`
	Status                  string   `json:"status"`
	StackingMode            string   `json:"stacking_mode"`
	ExclusiveWithPromotions bool     `json:"exclusive_with_promotions"`
	AppliesTo               string   `json:"applies_to"`
	BrandID                 string   `json:"brand_id"`
	OrganizationID          string   `json:"organization_id"`
	Branches                []string `json:"branches"`
	Translations            []struct {
		Locale      string `json:"locale"`
		Name        string `json:"name"`
		Description string `json:"description"`
	} `json:"translations"`
	LegacyMinOrderAmount   int  `json:"min_order_amount"`
	LegacyMaxDiscount      *int `json:"max_discount"`
	LegacyPerCustomerLimit *int `json:"per_customer_limit"`
}) error {
	excl := 0
	if c.ExclusiveWithPromotions {
		excl = 1
	}
	appliesTo := c.AppliesTo
	if appliesTo == "" {
		appliesTo = "order"
	}
	// Legacy-field fallback (older Cloud builds before the renamed-
	// payload rollout shipped min_order_amount / max_discount /
	// per_customer_limit). Take the new field when present; otherwise
	// fall back to legacy.
	minSubtotal := c.MinOrderSubtotal
	if minSubtotal == 0 && c.LegacyMinOrderAmount != 0 {
		minSubtotal = c.LegacyMinOrderAmount
	}
	maxCap := c.MaxDiscountCap
	if maxCap == nil {
		maxCap = c.LegacyMaxDiscount
	}
	perCust := c.UsageLimitPerCustomer
	if perCust == nil {
		perCust = c.LegacyPerCustomerLimit
	}
	stackingMode := c.StackingMode
	if stackingMode == "" {
		stackingMode = "exclusive"
	}
	if _, err := stmt.Exec(c.ID, c.Code, c.Name, nullableString(c.Description),
		c.DiscountType, c.DiscountValue,
		minSubtotal, intPtrToNullable(maxCap),
		nullableString(c.ValidFrom), nullableString(c.ValidUntil),
		intPtrToNullable(c.UsageLimitTotal), c.TimesUsed,
		intPtrToNullable(perCust),
		c.Status, stackingMode, excl, appliesTo,
		nullableString(c.BrandID), nullableString(c.OrganizationID),
	); err != nil {
		return fmt.Errorf("upsert coupons: %w", err)
	}
	if _, err := clearBranches.Exec(c.ID); err != nil {
		return fmt.Errorf("clear branches: %w", err)
	}
	if _, err := clearTranslations.Exec(c.ID); err != nil {
		return fmt.Errorf("clear translations: %w", err)
	}
	for _, bID := range c.Branches {
		if bID == "" {
			continue
		}
		if _, err := branchStmt.Exec(c.ID, bID); err != nil {
			return fmt.Errorf("insert branch %s: %w", bID, err)
		}
	}
	for _, tr := range c.Translations {
		if tr.Locale == "" {
			continue
		}
		rowID := c.ID + ":" + tr.Locale
		if _, err := translationStmt.Exec(
			rowID, c.ID, tr.Locale, tr.Name, nullableString(tr.Description),
		); err != nil {
			return fmt.Errorf("insert translation %s: %w", tr.Locale, err)
		}
	}
	return nil
}

// PullPromotions upserts the promotion catalog. UPSERT semantics (not
// DELETE+INSERT) — order_items.promotion_id has FK → menu_promotions(id),
// so a wholesale DELETE FROM menu_promotions aborts the sync the moment
// any past order_item carries a promotion stamp. UPSERT keeps the
// existing row identity so the FK stays valid, AND refreshes every
// Cloud-owned column on each tick.
func (p *SyncPuller) PullPromotions(ctx context.Context) error {
	var resp struct {
		Data []struct {
			ID                   string `json:"id"`
			Name                 string `json:"name"`
			Description          string `json:"description"`
			DiscountType         string `json:"discount_type"`
			DiscountValue        int    `json:"discount_value"`
			StartsAt             string `json:"starts_at"`
			EndsAt               string `json:"ends_at"`
			IsActive             bool   `json:"is_active"`
			ExclusiveWithCoupons bool   `json:"exclusive_with_coupons"`
			StackingMode         string `json:"stacking_mode"`
			AppliesTo            string `json:"applies_to"`
			Priority             int    `json:"priority"`
			BranchID             string `json:"branch_id"`
			BrandID              string `json:"brand_id"`
			OrganizationID       string `json:"organization_id"`
			PromoCreatedAt       string `json:"created_at"`
			// product_ids[] is the canonical Phase B field — backend
			// flattens categories into the same product_id space so
			// workstation doesn't need a pos_product_categories pivot.
			ProductIDs []string `json:"product_ids"`
			// Legacy: pre-028 wire used per-SKU pivots. Fallback so
			// older Cloud builds still drive a usable replica during
			// the rollout window.
			ProductSkuIDs []string `json:"product_sku_ids"`
			Schedules     []struct {
				ID            string `json:"id"`
				DayOfWeek     *int   `json:"day_of_week"`
				DailyTimeFrom string `json:"daily_time_from"`
				DailyTimeTo   string `json:"daily_time_to"`
			} `json:"schedules"`
			// Phase C: i18n payload.
			Translations []struct {
				Locale      string `json:"locale"`
				Name        string `json:"name"`
				Description string `json:"description"`
			} `json:"translations"`
		} `json:"data"`
	}
	if err := p.cloudGet(ctx, pullPathPromotions, &resp); err != nil {
		return err
	}
	return p.atomic(func(tx *sql.Tx) error {
		if len(resp.Data) == 0 {
			return nil
		}

		// UPSERT keyed on promotion id. Replaces DELETE+INSERT because
		// order_items.promotion_id FK → menu_promotions(id) was
		// causing the cascading DELETE to abort every sync tick the
		// moment a customer order carried an applied promo. Without
		// UPSERT, an HQ edit (e.g. discount_percent 10 → 20) never
		// reached the device — identical failure mode to the coupon
		// "trừ 1000 vẫn còn" bug.
		insPromo, err := tx.Prepare(`
			INSERT INTO menu_promotions (id, name, description, discount_type, discount_value,
			    starts_at, ends_at, is_active, exclusive_with_coupons,
			    stacking_mode, applies_to, branch_id, brand_id, organization_id,
			    promo_created_at, priority, cloud_updated_at, local_synced_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))
			ON CONFLICT(id) DO UPDATE SET
			    name                   = excluded.name,
			    description            = excluded.description,
			    discount_type          = excluded.discount_type,
			    discount_value         = excluded.discount_value,
			    starts_at              = excluded.starts_at,
			    ends_at                = excluded.ends_at,
			    is_active              = excluded.is_active,
			    exclusive_with_coupons = excluded.exclusive_with_coupons,
			    stacking_mode          = excluded.stacking_mode,
			    applies_to             = excluded.applies_to,
			    branch_id              = excluded.branch_id,
			    brand_id               = excluded.brand_id,
			    organization_id        = excluded.organization_id,
			    promo_created_at       = excluded.promo_created_at,
			    priority               = excluded.priority,
			    local_synced_at        = datetime('now')`)
		if err != nil {
			return err
		}
		defer insPromo.Close()
		// Per-promotion pivot wipe + re-insert. Pivots have no
		// inbound FKs of their own, so deletion is safe even when
		// the parent promotion has been around long enough to be
		// referenced by past order_items.
		clearProducts, err := tx.Prepare(`DELETE FROM menu_promotion_products WHERE promotion_id = ?`)
		if err != nil {
			return err
		}
		defer clearProducts.Close()
		clearSchedules, err := tx.Prepare(`DELETE FROM menu_promotion_schedules WHERE promotion_id = ?`)
		if err != nil {
			return err
		}
		defer clearSchedules.Close()
		clearPromoTranslations, err := tx.Prepare(`DELETE FROM menu_promotion_translations WHERE menu_promotion_id = ?`)
		if err != nil {
			return err
		}
		defer clearPromoTranslations.Close()
		insProduct, err := tx.Prepare(
			"INSERT OR IGNORE INTO menu_promotion_products (promotion_id, product_id) VALUES (?, ?)")
		if err != nil {
			return err
		}
		defer insProduct.Close()
		insSched, err := tx.Prepare(`
			INSERT INTO menu_promotion_schedules (id, promotion_id, day_of_week,
			    daily_time_from, daily_time_to)
			VALUES (?, ?, ?, ?, ?)`)
		if err != nil {
			return err
		}
		defer insSched.Close()
		insPromoTr, err := tx.Prepare(`
			INSERT OR IGNORE INTO menu_promotion_translations
			    (id, menu_promotion_id, locale, name, description, local_synced_at)
			VALUES (?, ?, ?, ?, ?, datetime('now'))`)
		if err != nil {
			return err
		}
		defer insPromoTr.Close()

		ok := 0
		failed := 0
		summary := make([]string, 0, len(resp.Data))
		for idx, pc := range resp.Data {
			// Skip unknown discount_type before opening a savepoint —
			// it's a known harmless drop (e.g. a future-Cloud type),
			// not a row-level failure worth a rollback.
			dt := normalizePromotionDiscountType(pc.DiscountType)
			if dt == "" {
				slog.Warn("PullPromotions skip unknown discount_type", "promotion_id", pc.ID, "got", pc.DiscountType)
				failed++
				continue
			}

			spName := "promosp_" + strconv.Itoa(idx)
			if _, err := tx.Exec("SAVEPOINT " + spName); err != nil {
				return fmt.Errorf("savepoint open: %w", err)
			}

			active := 0
			if pc.IsActive {
				active = 1
			}
			excl := 0
			if pc.ExclusiveWithCoupons {
				excl = 1
			}
			appliesTo := pc.AppliesTo
			if appliesTo == "" {
				appliesTo = "products"
			}
			stackingMode := pc.StackingMode
			if stackingMode == "" {
				if pc.ExclusiveWithCoupons {
					stackingMode = "exclusive_with_coupons"
				} else {
					stackingMode = "stackable_with_coupons"
				}
			}

			rowErr := upsertOnePromotion(tx,
				insPromo, clearProducts, clearSchedules, clearPromoTranslations,
				insProduct, insSched, insPromoTr,
				pc.ID, pc.Name, pc.Description, dt, pc.DiscountValue,
				pc.StartsAt, pc.EndsAt, active, excl, stackingMode, appliesTo,
				pc.BranchID, pc.BrandID, pc.OrganizationID, pc.PromoCreatedAt, pc.Priority,
				pc.ProductIDs, pc.ProductSkuIDs,
				schedulesAsAny(pc.Schedules),
				translationsAsAny(pc.Translations),
			)
			if rowErr != nil {
				slog.Warn("PullPromotions skip row",
					"id", pc.ID, "name", pc.Name,
					"discount_type", pc.DiscountType, "err", rowErr)
				if _, rerr := tx.Exec("ROLLBACK TO " + spName); rerr != nil {
					return fmt.Errorf("savepoint rollback: %w", rerr)
				}
				if _, rerr := tx.Exec("RELEASE " + spName); rerr != nil {
					return fmt.Errorf("savepoint release after rollback: %w", rerr)
				}
				failed++
				summary = append(summary, pc.Name+"=SKIPPED")
				continue
			}
			if _, err := tx.Exec("RELEASE " + spName); err != nil {
				return fmt.Errorf("savepoint release: %w", err)
			}
			ok++
			summary = append(summary, pc.Name+"="+strconv.Itoa(pc.DiscountValue))
		}
		slog.Info("PullPromotions synced",
			"ok", ok, "failed", failed, "total", len(resp.Data),
			"values", summary)
		// FK-safe cleanup. Same rationale as PullCoupons — drop
		// promotions removed from Cloud's feed only when no
		// order_item still references them (FK ON RESTRICT).
		incomingIDs := make([]any, 0, len(resp.Data))
		placeholders := ""
		for i, p := range resp.Data {
			incomingIDs = append(incomingIDs, p.ID)
			if i > 0 {
				placeholders += ","
			}
			placeholders += "?"
		}
		if len(incomingIDs) > 0 {
			query := `DELETE FROM menu_promotions
				WHERE id NOT IN (` + placeholders + `)
				  AND id NOT IN (SELECT promotion_id FROM order_items WHERE promotion_id IS NOT NULL)`
			if _, err := tx.Exec(query, incomingIDs...); err != nil {
				return err
			}
		}
		return nil
	})
}

// normalizePromotionDiscountType maps the Cloud-emitted discount_type
// onto the local CHECK enum. Returns "" when the value is unknown so
// the caller can skip + log rather than abort the whole sync.
func normalizePromotionDiscountType(t string) string {
	switch t {
	case "percent", "amount", "fixed_unit_price":
		return t
	case "percentage":
		// Legacy alias on Cloud — the only discount_type the
		// MenuPromotion model currently emits.
		return "percent"
	default:
		return ""
	}
}

func intPtrToNullable(p *int) any {
	if p == nil {
		return nil
	}
	return *p
}

// PullTill mirrors the per-branch Till row (variance tolerance, currency,
// cloud's view of current_session_id). Single-row replace.
//
// Race protection: when the cashier opens a shift via LAN, workstation
// records the row in local till_sessions + bumps tills.current_session_id
// AND enqueues a sync UP. The sync push may not have reached Cloud yet
// when PullTill runs on the 5s tick — Cloud still reports
// current_session_id = null. Naively writing Cloud's view would wipe the
// LAN-opened pointer; pos-web would then see "no shift open" and the
// cashier would be prompted to open a fresh shift on top of the existing
// (unsynced) one. We keep the local pointer whenever it points at a
// session that is still open|closing locally and has not yet been
// acknowledged by Cloud (synced_at IS NULL or status disagrees). Once
// Cloud catches up, its current_session_id will match ours and the
// preservation is a no-op.
func (p *SyncPuller) PullTill(ctx context.Context) error {
	var resp struct {
		Data struct {
			ID                      string    `json:"id"`
			BranchID                string    `json:"branch_id"`
			Code                    string    `json:"code"`
			DefaultCurrencyCode     string    `json:"default_currency_code"`
			VarianceToleranceAmount flexFloat `json:"variance_tolerance_amount"`
			CurrentSessionID        *string   `json:"current_session_id"`
		} `json:"data"`
	}
	if err := p.cloudGet(ctx, pullPathTill, &resp); err != nil {
		return err
	}
	if resp.Data.ID == "" {
		return nil
	}
	d := resp.Data
	return p.atomic(func(tx *sql.Tx) error {
		// Find the existing locally-held current_session_id BEFORE we
		// wipe the tills row. If it points at a session that's still
		// open|closing locally and hasn't been confirmed by Cloud
		// (synced_at IS NULL), keep it; otherwise trust Cloud.
		preserved := preserveLocalCurrentSession(tx, deref(d.CurrentSessionID))

		if _, err := tx.Exec("DELETE FROM tills"); err != nil {
			return err
		}
		_, err := tx.Exec(`
			INSERT INTO tills (id, branch_id, code, default_currency_code,
			    variance_tolerance_amount, current_session_id,
			    cloud_updated_at, local_synced_at)
			VALUES (?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))`,
			d.ID, d.BranchID, d.Code, d.DefaultCurrencyCode,
			d.VarianceToleranceAmount.Float(), nullableString(preserved))
		return err
	})
}

// preserveLocalCurrentSession returns the session pointer to write into
// tills.current_session_id when Cloud's view + our pending LAN writes
// disagree. Logic:
//
//   - Cloud says session X is current → trust Cloud (X).
//   - Cloud says null AND we locally point at a session that is still
//     `open|closing` and `synced_at IS NULL` → keep local (sync UP
//     racing — Cloud will catch up shortly).
//   - Cloud says null AND no qualifying local session → trust Cloud (null).
//
// Falling back to Cloud on any unexpected SQL error is the safe choice:
// the worst outcome is a stale pointer for one 5 s cycle.
func preserveLocalCurrentSession(tx *sql.Tx, cloudCurrent string) string {
	if cloudCurrent != "" {
		return cloudCurrent
	}
	var localCurrent sql.NullString
	if err := tx.QueryRow(`SELECT current_session_id FROM tills LIMIT 1`).Scan(&localCurrent); err != nil {
		return cloudCurrent
	}
	if !localCurrent.Valid || localCurrent.String == "" {
		return cloudCurrent
	}
	var status string
	var syncedAt sql.NullString
	err := tx.QueryRow(`
		SELECT status, synced_at FROM till_sessions WHERE id = ?`,
		localCurrent.String).Scan(&status, &syncedAt)
	if err != nil {
		return cloudCurrent
	}
	stillOpen := status == "open" || status == "closing"
	notAcked := !syncedAt.Valid || syncedAt.String == ""
	if stillOpen && notAcked {
		return localCurrent.String
	}
	return cloudCurrent
}

// PullTillSessions mirrors active TillSession rows (status `open` or
// `closing`) for the device's branch. Without it, a shift opened on
// Cloud is invisible to LAN mode: workstation only pulls the
// `till.current_session_id` pointer (via PullTill) — the underlying
// session row never crosses the wire, so the LAN-mode `/pos/till/current`
// handler returns `open_session: null` and the cashier is prompted to
// open a fresh shift on top of the existing one.
//
// Local rows that the workstation owns (status `open|closing` AND
// `synced_at IS NULL`) are preserved — those are still racing their own
// sync UP. Once Cloud acknowledges and emits them in the active list
// they get the same shape we already have locally, and INSERT OR REPLACE
// is a no-op modulo the `synced_at` stamp.
func (p *SyncPuller) PullTillSessions(ctx context.Context) error {
	type sessionRow struct {
		ID                  string  `json:"id"`
		SessionCode         string  `json:"session_code"`
		Status              string  `json:"status"`
		BusinessDate        string  `json:"business_date"`
		DefaultCurrencyCode string  `json:"default_currency_code"`
		OpeningFloatAmount  float64 `json:"opening_float_amount"`
		OpeningNote         *string `json:"opening_note"`
		OpenedByID          *string `json:"opened_by_id"`
		OpenerName          *string `json:"opener_name"`
		OpenedAt            string  `json:"opened_at"`
		ClosedAt            *string `json:"closed_at"`
		ClosingNote         *string `json:"closing_note"`
		AbandonReason       *string `json:"abandon_reason"`
		TillID              string  `json:"till_id"`
		BranchID            string  `json:"branch_id"`
		// Plan-046 (T5.6, P7-3) — chain grouping for a Cloud-direct-opened session
		// pulled while still active. settlement_snapshot is NOT pulled: the active
		// feed only carries open/closing rows (never settled), so it is always null
		// here — the settled snapshot is adopted via the sync-UP response (R7).
		ChainID        *string `json:"chain_id"`
		ChainSequence  *int    `json:"chain_sequence"`
		SettlementKind *string `json:"settlement_kind"`
	}
	var resp struct {
		Data []sessionRow `json:"data"`
	}
	if err := p.cloudGet(ctx, pullPathTillSessions, &resp); err != nil {
		return err
	}
	if len(resp.Data) == 0 {
		return nil
	}

	return p.atomic(func(tx *sql.Tx) error {
		// INSERT OR REPLACE keyed on id. Local-only rows (different
		// id) are untouched; Cloud-acked rows update in place. The
		// `cloud_id` column on the workstation schema doubles as the
		// "session arrived from Cloud" flag — we stamp it with the
		// same id Cloud sent so reconciliation logic can tell
		// LAN-owned from Cloud-owned without re-querying.
		stmt, err := tx.Prepare(`
			INSERT INTO till_sessions (id, cloud_id, session_code, status,
			    business_date, default_currency_code, opening_float_amount,
			    opening_note, opened_by_id, opener_name, opened_at,
			    closed_at, closing_note, abandon_reason,
			    till_id, branch_id, chain_id, chain_sequence, settlement_kind,
			    synced_at, created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
			    datetime('now'), datetime('now'), datetime('now'))
			ON CONFLICT(id) DO UPDATE SET
			    cloud_id              = excluded.cloud_id,
			    session_code          = excluded.session_code,
			    status                = excluded.status,
			    business_date         = excluded.business_date,
			    default_currency_code = excluded.default_currency_code,
			    opening_float_amount  = excluded.opening_float_amount,
			    opening_note          = excluded.opening_note,
			    opened_by_id          = excluded.opened_by_id,
			    opener_name           = excluded.opener_name,
			    opened_at             = excluded.opened_at,
			    closed_at             = excluded.closed_at,
			    closing_note          = excluded.closing_note,
			    abandon_reason        = excluded.abandon_reason,
			    till_id               = excluded.till_id,
			    branch_id             = excluded.branch_id,
			    chain_id              = excluded.chain_id,
			    chain_sequence        = excluded.chain_sequence,
			    settlement_kind       = excluded.settlement_kind,
			    synced_at             = datetime('now'),
			    updated_at            = datetime('now')
			-- The workstation OWNS the shift lifecycle: it settles locally, then
			-- syncs UP. This feed returns Cloud's open/closing sessions, so in the
			-- window between a local settle and its sync-UP landing, Cloud still
			-- reports the shift as open — and this upsert used to drag the local
			-- row back to open, wiping status/closed_at/settlement_kind while
			-- leaving counted_cash + settlement_snapshot behind (a half-settled
			-- row that no later pull could repair, because once Cloud DOES settle
			-- it the session leaves the active feed for good).
			--
			-- The damage was silent and total: chain continuation reads the till's
			-- most recent TERMINAL session, so with every settle reverted to open
			-- no chain ever continued — each shift opened a brand-new chain with
			-- sequence 1, and the final close aggregated exactly one member.
			--
			-- A local terminal state is therefore never moved backwards. Cloud can
			-- still push a session terminal (force-abandon/expire) because that
			-- path updates a row that is still locally open.
			WHERE till_sessions.status NOT IN ('settled','abandoned','expired')`)
		if err != nil {
			return err
		}
		defer stmt.Close()

		for _, s := range resp.Data {
			chainSeq := 1
			if s.ChainSequence != nil && *s.ChainSequence >= 1 {
				chainSeq = *s.ChainSequence
			}
			if _, err := stmt.Exec(
				s.ID, s.ID, s.SessionCode, s.Status,
				s.BusinessDate, s.DefaultCurrencyCode, s.OpeningFloatAmount,
				nullableString(deref(s.OpeningNote)),
				nullableString(deref(s.OpenedByID)),
				nullableString(deref(s.OpenerName)),
				s.OpenedAt,
				nullableString(deref(s.ClosedAt)),
				nullableString(deref(s.ClosingNote)),
				nullableString(deref(s.AbandonReason)),
				s.TillID, s.BranchID,
				nullableString(deref(s.ChainID)), chainSeq,
				nullableString(deref(s.SettlementKind)),
			); err != nil {
				return err
			}
		}
		return nil
	})
}

func (p *SyncPuller) PullDenominations(ctx context.Context) error {
	var resp struct {
		Data []struct {
			ID           string    `json:"id"`
			CurrencyCode string    `json:"currency_code"`
			Value        flexFloat `json:"value"`
			Kind         string    `json:"kind"`
			Label        string    `json:"label"`
			SortOrder    int       `json:"sort_order"`
			IsActive     bool      `json:"is_active"`
		} `json:"data"`
	}
	if err := p.cloudGet(ctx, pullPathDenominations, &resp); err != nil {
		slog.Warn("PullDenominations cloud error", "path", pullPathDenominations, "err", err.Error())
		return err
	}
	// Always log the row count Cloud returned — operators investigating
	// "empty dropdown" can confirm at a glance whether Cloud is empty,
	// the puller short-circuits on guard, or rows arrived but didn't
	// write. Without this log the silent-empty case looks identical to
	// a successful pull from outside.
	slog.Info("PullDenominations", "rows_from_cloud", len(resp.Data))
	if len(resp.Data) == 0 {
		return nil
	}
	return p.atomic(func(tx *sql.Tx) error {
		if _, err := tx.Exec("DELETE FROM denominations"); err != nil {
			return err
		}
		stmt, err := tx.Prepare(`
			INSERT INTO denominations (id, currency_code, value, kind, label,
			    sort_order, is_active, cloud_updated_at, local_synced_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))`)
		if err != nil {
			return err
		}
		defer stmt.Close()
		for _, d := range resp.Data {
			active := 0
			if d.IsActive {
				active = 1
			}
			if _, err := stmt.Exec(d.ID, d.CurrencyCode, d.Value.Float(), d.Kind,
				nullableString(d.Label), d.SortOrder, active); err != nil {
				return err
			}
		}
		return nil
	})
}

func (p *SyncPuller) PullTenderCategories(ctx context.Context) error {
	var resp struct {
		Data []struct {
			ID        string `json:"id"`
			Key       string `json:"key"`
			Name      string `json:"name"`
			SortOrder int    `json:"sort_order"`
			IsSystem  bool   `json:"is_system"`
		} `json:"data"`
	}
	if err := p.cloudGet(ctx, pullPathTenderCategories, &resp); err != nil {
		return err
	}
	if len(resp.Data) == 0 {
		return nil
	}
	return p.atomic(func(tx *sql.Tx) error {
		if _, err := tx.Exec("DELETE FROM till_tender_categories"); err != nil {
			return err
		}
		stmt, err := tx.Prepare(`
			INSERT INTO till_tender_categories (id, key, name, sort_order,
			    is_system, cloud_updated_at, local_synced_at)
			VALUES (?, ?, ?, ?, ?, datetime('now'), datetime('now'))`)
		if err != nil {
			return err
		}
		defer stmt.Close()
		for _, c := range resp.Data {
			sys := 0
			if c.IsSystem {
				sys = 1
			}
			if _, err := stmt.Exec(c.ID, c.Key, c.Name, c.SortOrder, sys); err != nil {
				return err
			}
		}
		return nil
	})
}

func (p *SyncPuller) PullTenderTypes(ctx context.Context) error {
	var resp struct {
		Data []struct {
			ID                    string  `json:"id"`
			TenderKey             string  `json:"tender_key"`
			Name                  any     `json:"name"`
			Category              string  `json:"category"`
			ParentTenderKey       *string `json:"parent_tender_key"`
			CurrencyCode          string  `json:"currency_code"`
			PaymentMethodCode     *string `json:"payment_method_code"`
			IsExpectedAnchor      bool    `json:"is_expected_anchor"`
			RequiresTerminalTotal bool    `json:"requires_terminal_total"`
			SortOrder             int     `json:"sort_order"`
		} `json:"data"`
	}
	if err := p.cloudGet(ctx, pullPathTenderTypes, &resp); err != nil {
		return err
	}
	if len(resp.Data) == 0 {
		return nil
	}
	return p.atomic(func(tx *sql.Tx) error {
		if _, err := tx.Exec("DELETE FROM till_tender_types"); err != nil {
			return err
		}
		stmt, err := tx.Prepare(`
			INSERT INTO till_tender_types (id, tender_key, name, category,
			    parent_tender_key, currency_code, payment_method_code,
			    is_expected_anchor, requires_terminal_total, sort_order,
			    is_active, cloud_updated_at, local_synced_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, datetime('now'), datetime('now'))`)
		if err != nil {
			return err
		}
		defer stmt.Close()
		for _, tt := range resp.Data {
			anchor := 0
			if tt.IsExpectedAnchor {
				anchor = 1
			}
			term := 0
			if tt.RequiresTerminalTotal {
				term = 1
			}
			if _, err := stmt.Exec(tt.ID, tt.TenderKey, flattenName(tt.Name),
				tt.Category, nullableString(deref(tt.ParentTenderKey)),
				tt.CurrencyCode, nullableString(deref(tt.PaymentMethodCode)),
				anchor, term, tt.SortOrder); err != nil {
				return err
			}
		}
		return nil
	})
}

// flattenName resolves the eager-loaded Astrotomic translation map to a
// single display string. Backend may emit either a plain string or
// {ja, en, vi}; we collapse to the first non-empty value with a vi/en/ja
// fallback ladder.
func flattenName(v any) string {
	switch n := v.(type) {
	case string:
		return n
	case map[string]any:
		for _, locale := range []string{"vi", "en", "ja"} {
			if s, ok := n[locale].(string); ok && s != "" {
				return s
			}
		}
		for _, val := range n {
			if s, ok := val.(string); ok && s != "" {
				return s
			}
		}
	}
	return ""
}

func deref(p *string) string {
	if p == nil {
		return ""
	}
	return *p
}

// effectivePaymentOptionPull mirrors Cloud's device-effective option row
// (PaymentPolicyEvaluationService + EffectivePaymentOptionsPresenter).
type effectivePaymentOptionPull struct {
	ID               string           `json:"id"`
	DisplayName      string           `json:"display_name"`
	Provider         string           `json:"provider"`
	Rail             string           `json:"rail"`
	Effective        bool             `json:"effective"`
	Source           string           `json:"source"`
	Reason           string           `json:"reason"`
	ErrorCode        string           `json:"error_code"`
	ConnectionID     *string          `json:"connection_id"`
	ConnectionOption *string          `json:"connection_option_id"`
	ShopOptionID     *string          `json:"shop_option_id"`
	OwnerScope       *string          `json:"owner_scope"`
	ShopPreference   string           `json:"shop_preference"`
	DevicePreference string           `json:"device_preference"`
	Trace            []map[string]any `json:"trace"`
}

// PullEffectivePaymentOptions replaces the local effective-payment projection
// when Cloud's monotonic revision or snapshot hash changes (plan-047 T6.4).
// Stores only non-secret display identity — no credentials.
func (p *SyncPuller) PullEffectivePaymentOptions(ctx context.Context) error {
	var resp struct {
		Data struct {
			Revision          int                          `json:"revision"`
			SnapshotHash      *string                      `json:"snapshot_hash"`
			OwnershipRevision string                       `json:"ownership_revision"`
			PublishedAt       *string                      `json:"published_at"`
			Options           []effectivePaymentOptionPull `json:"options"`
		} `json:"data"`
	}
	if err := p.cloudGet(ctx, pullPathEffectivePaymentOptions, &resp); err != nil {
		return err
	}

	newRev := strconv.Itoa(resp.Data.Revision)
	newHash := ""
	if resp.Data.SnapshotHash != nil {
		newHash = *resp.Data.SnapshotHash
	}
	storedRev := p.getCursor(paymentPolicyRevisionKey)
	storedHash := p.getCursor(paymentPolicySnapshotHashKey)
	if storedRev == newRev && storedHash == newHash && storedRev != "" {
		return nil
	}
	if len(resp.Data.Options) == 0 && storedRev != "" {
		// Defensive: transient empty snapshot must not wipe a good local replica.
		return nil
	}

	capJSON := func(trace []map[string]any) string {
		if len(trace) == 0 {
			return "{}"
		}
		b, err := json.Marshal(trace)
		if err != nil {
			return "{}"
		}
		return string(b)
	}
	connJSON := func(opt effectivePaymentOptionPull) string {
		display := map[string]any{
			"provider": opt.Provider,
			"rail":     opt.Rail,
		}
		if opt.ConnectionID != nil && *opt.ConnectionID != "" {
			display["connection_id"] = *opt.ConnectionID
		}
		if opt.ConnectionOption != nil && *opt.ConnectionOption != "" {
			display["connection_option_id"] = *opt.ConnectionOption
		}
		if opt.OwnerScope != nil && *opt.OwnerScope != "" {
			display["owner_scope"] = *opt.OwnerScope
		}
		b, err := json.Marshal(display)
		if err != nil {
			return "{}"
		}
		return string(b)
	}

	err := p.atomic(func(tx *sql.Tx) error {
		publishedAt := ""
		if resp.Data.PublishedAt != nil {
			publishedAt = *resp.Data.PublishedAt
		}
		if _, err := tx.Exec(`
			INSERT INTO payment_policy_snapshot
				(id, revision, snapshot_hash, ownership_revision, published_at, local_synced_at)
			VALUES (1, ?, ?, ?, ?, datetime('now'))
			ON CONFLICT(id) DO UPDATE SET
				revision = excluded.revision,
				snapshot_hash = excluded.snapshot_hash,
				ownership_revision = excluded.ownership_revision,
				published_at = excluded.published_at,
				local_synced_at = datetime('now')`,
			resp.Data.Revision, newHash, resp.Data.OwnershipRevision, nullIfEmpty(publishedAt),
		); err != nil {
			return err
		}

		if _, err := tx.Exec("DELETE FROM effective_payment_options"); err != nil {
			return err
		}
		stmt, err := tx.Prepare(`
			INSERT INTO effective_payment_options (
				id, display_name, provider, rail, effective,
				source, reason, error_code,
				connection_id, connection_option_id, shop_option_id, owner_scope,
				shop_preference, device_preference,
				capabilities_json, connection_display_json,
				sort_order, local_synced_at
			) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))`)
		if err != nil {
			return err
		}
		defer stmt.Close()

		for i, opt := range resp.Data.Options {
			effective := 0
			if opt.Effective {
				effective = 1
			}
			if _, err := stmt.Exec(
				opt.ID, opt.DisplayName, opt.Provider, opt.Rail, effective,
				opt.Source, opt.Reason, opt.ErrorCode,
				deref(opt.ConnectionID), deref(opt.ConnectionOption), deref(opt.ShopOptionID), deref(opt.OwnerScope),
				opt.ShopPreference, opt.DevicePreference,
				capJSON(opt.Trace), connJSON(opt),
				i,
			); err != nil {
				return err
			}
		}
		return nil
	})
	if err != nil {
		return err
	}

	if err := p.setCursor(paymentPolicyRevisionKey, newRev); err != nil {
		return err
	}
	return p.setCursor(paymentPolicySnapshotHashKey, newHash)
}

func nullIfEmpty(s string) any {
	if s == "" {
		return nil
	}
	return s
}

// PullPaymentMethods replaces the local payment_methods table with whatever
// Cloud returns. Replace-all is safe here because Cloud is the only writer
// and the list is small (<20 rows for any realistic branch).
func (p *SyncPuller) PullPaymentMethods(ctx context.Context) error {
	var resp struct {
		Data []struct {
			ID               string `json:"id"`
			Code             string `json:"code"`
			Name             string `json:"name"`
			IsActive         bool   `json:"is_active"`
			SortOrder        int    `json:"sort_order"`
			IsAutoConfirm    bool   `json:"is_auto_confirm"`
			RequiresTendered bool   `json:"requires_tendered"`
		} `json:"data"`
	}
	if err := p.cloudGet(ctx, pullPathPaymentMethods, &resp); err != nil {
		return err
	}
	if len(resp.Data) == 0 {
		// Empty list is treated as "no data — keep local" rather than wipe,
		// defending against transient Cloud bugs that briefly return [].
		return nil
	}

	return p.atomic(func(tx *sql.Tx) error {
		if _, err := tx.Exec("DELETE FROM payment_methods"); err != nil {
			return err
		}
		stmt, err := tx.Prepare(`
			INSERT INTO payment_methods (id, code, name, is_active, sort_order,
			    is_auto_confirm, requires_tendered,
			    cloud_updated_at, local_synced_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))`)
		if err != nil {
			return err
		}
		defer stmt.Close()
		for _, m := range resp.Data {
			active, autoConf, tendered := 0, 0, 0
			if m.IsActive {
				active = 1
			}
			if m.IsAutoConfirm {
				autoConf = 1
			}
			if m.RequiresTendered {
				tendered = 1
			}
			if _, err := stmt.Exec(m.ID, m.Code, m.Name, active, m.SortOrder,
				autoConf, tendered); err != nil {
				return err
			}
		}
		return nil
	})
}

// PullPeripheralDevices mirrors the cloud's external peripheral registry for the
// local workstation/POS runtime. The list is authoritative for printer/payment/
// terminal identity and aliases; secrets are never included in the feed shape.
func (p *SyncPuller) PullPeripheralDevices(ctx context.Context) error {
	var resp struct {
		Data []struct {
			ID                   string          `json:"id"`
			Name                 string          `json:"name"`
			Type                 string          `json:"type"`
			IsActive             bool            `json:"is_active"`
			Metadata             json.RawMessage `json:"metadata"`
			RegisteredByDeviceID *string         `json:"registered_by_device_id"`
			OrganizationID       string          `json:"organization_id"`
			BranchID             string          `json:"branch_id"`
			CreatedAt            string          `json:"created_at"`
			UpdatedAt            string          `json:"updated_at"`
		} `json:"data"`
	}
	if err := p.cloudGet(ctx, pullPathPeripheralDevices, &resp); err != nil {
		return err
	}

	return p.atomic(func(tx *sql.Tx) error {
		// Offline-first merge: preserve rows the workstation created/edited/deleted
		// locally but hasn't synced UP yet (pending_sync / pending_delete). Only
		// synced rows are reconciled against Cloud — delete the ones Cloud dropped,
		// then upsert Cloud's set (never clobbering a pending local edit).
		cloudIDs := make(map[string]struct{}, len(resp.Data))
		for _, d := range resp.Data {
			cloudIDs[d.ID] = struct{}{}
		}

		clean, err := tx.Query(`SELECT id FROM peripheral_devices WHERE pending_sync = 0 AND pending_delete = 0`)
		if err != nil {
			return err
		}
		var staleLocal []string
		for clean.Next() {
			var id string
			if err := clean.Scan(&id); err != nil {
				clean.Close()
				return err
			}
			if _, inCloud := cloudIDs[id]; !inCloud {
				staleLocal = append(staleLocal, id)
			}
		}
		clean.Close()
		for _, id := range staleLocal {
			if _, err := tx.Exec("DELETE FROM peripheral_devices WHERE id = ?", id); err != nil {
				return err
			}
		}

		// Upsert Cloud rows, but skip any id with a pending local mutation so a
		// stale Cloud snapshot can't overwrite an unsynced edit or resurrect a
		// local delete.
		stmt, err := tx.Prepare(`
			INSERT INTO peripheral_devices (
				id, name, type, is_active, metadata,
				registered_by_device_id, organization_id, branch_id,
				created_at, updated_at, local_synced_at, pending_sync, pending_delete
			) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), 0, 0)
			ON CONFLICT(id) DO UPDATE SET
				name = excluded.name, type = excluded.type, is_active = excluded.is_active,
				metadata = excluded.metadata, registered_by_device_id = excluded.registered_by_device_id,
				organization_id = excluded.organization_id, branch_id = excluded.branch_id,
				created_at = excluded.created_at, updated_at = excluded.updated_at,
				local_synced_at = datetime('now')
			WHERE peripheral_devices.pending_sync = 0 AND peripheral_devices.pending_delete = 0`)
		if err != nil {
			return err
		}
		defer stmt.Close()

		for _, d := range resp.Data {
			active := 0
			if d.IsActive {
				active = 1
			}
			var metadata any = nil
			if len(d.Metadata) > 0 {
				metadata = string(d.Metadata)
			}
			if _, err := stmt.Exec(
				d.ID, d.Name, d.Type, active, metadata,
				d.RegisteredByDeviceID, d.OrganizationID, d.BranchID,
				nullableString(d.CreatedAt), nullableString(d.UpdatedAt),
			); err != nil {
				return err
			}
		}

		return nil
	})
}

// PullPrinters mirrors the Cloud printer config (admin-web → shop printers)
// into the local `printers` table under origin='cloud'. Cloud owns the CONFIG
// (name / roles / LAN address / paper); the workstation does the actual
// printing off this local replica.
//
// Two lanes, kept strictly separate by the `origin` column:
//   - origin='cloud' — fully owned here. Every pull replaces the whole set:
//     delete all cloud rows, re-insert the feed. A printer removed in admin-web
//     therefore disappears from the workstation on the next tick.
//   - origin='local' — added by hand in the WS App. NEVER touched by this pull,
//     so it survives as the offline fallback when Cloud is unreachable (cloudGet
//     errors out and we return before mutating anything).
//
// After a successful commit the onPrintersSynced callback reloads the in-memory
// printer manager (LoadFromDB) so the new routing takes effect without a
// restart. The DELETE+INSERT runs inside one transaction so a mid-pull crash
// never leaves the shop with zero cloud printers.
func (p *SyncPuller) PullPrinters(ctx context.Context) error {
	var resp struct {
		Data []struct {
			ID             string   `json:"id"`
			Name           string   `json:"name"`
			Roles          []string `json:"roles"`
			ConnectionType string   `json:"connection_type"`
			Address        string   `json:"address"`
			PaperWidth     int      `json:"paper_width"`
			CutType        string   `json:"cut_type"`
			Encoding       string   `json:"encoding"`
			IsActive       bool     `json:"is_active"`
			CreatedAt      string   `json:"created_at"`
			UpdatedAt      string   `json:"updated_at"`
		} `json:"data"`
	}
	if err := p.cloudGet(ctx, pullPathPrinters, &resp); err != nil {
		// Cloud unreachable — leave the local `printers` table (both cloud and
		// local rows) exactly as it is so printing keeps working offline.
		return err
	}

	err := p.atomic(func(tx *sql.Tx) error {
		// Replace only the cloud-owned lane; local printers are untouched.
		if _, err := tx.Exec("DELETE FROM printers WHERE origin = 'cloud'"); err != nil {
			return err
		}
		if len(resp.Data) == 0 {
			return nil
		}

		stmt, err := tx.Prepare(`
			INSERT INTO printers (
				id, type, name, connection_type, address, config, roles,
				is_active, origin, created_at, updated_at
			) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'cloud', ?, ?)`)
		if err != nil {
			return err
		}
		defer stmt.Close()

		for _, d := range resp.Data {
			roles := d.Roles
			if len(roles) == 0 {
				continue // a printer with no role can't be routed — skip it
			}
			// `type` mirrors the primary role for back-compat, same as
			// manager.AddPrinter does for locally-added printers.
			primaryType := roles[0]

			rolesJSON, err := json.Marshal(roles)
			if err != nil {
				return err
			}
			configJSON, err := json.Marshal(PrinterConfigDTO{
				PaperWidth: d.PaperWidth,
				CutType:    d.CutType,
				Encoding:   d.Encoding,
			})
			if err != nil {
				return err
			}

			active := 0
			if d.IsActive {
				active = 1
			}

			if _, err := stmt.Exec(
				d.ID, primaryType, d.Name, d.ConnectionType, nullableString(d.Address),
				string(configJSON), string(rolesJSON), active,
				nullableString(d.CreatedAt), nullableString(d.UpdatedAt),
			); err != nil {
				return err
			}
		}
		return nil
	})
	if err != nil {
		return err
	}

	// Reload the in-memory manager so cloud printers route without a restart.
	if p.onPrintersSynced != nil {
		p.onPrintersSynced()
	}
	return nil
}

// PrinterConfigDTO is the JSON shape stored in printers.config, matching the
// printer.PrinterConfig fields LoadFromDB unmarshals. Duplicated here (rather
// than imported) to keep the service package free of a printer-package
// dependency — the field tags are the contract.
type PrinterConfigDTO struct {
	PaperWidth int    `json:"paper_width"`
	CutType    string `json:"cut_type"`
	Encoding   string `json:"encoding"`
}

// PullCustomers does incremental sync via the `updated_since` query param. The
// cursor is the highest updated_at observed locally; on the first pull it is
// empty and Cloud returns the most recent 1000 rows (server-side LIMIT).
func (p *SyncPuller) PullCustomers(ctx context.Context) error {
	cursor := p.getCursor(customersCursorKey)
	path := pullPathCustomers
	if cursor != "" {
		path += "?updated_since=" + cursor
	}

	var resp struct {
		Data []struct {
			ID             string `json:"id"`
			FirstName      string `json:"first_name"`
			LastName       string `json:"last_name"`
			FullName       string `json:"full_name"`
			Phone          string `json:"phone"`
			Email          string `json:"email"`
			OrganizationID string `json:"organization_id"`
			BrandID        string `json:"brand_id"`
			BranchID       string `json:"branch_id"`
			UpdatedAt      string `json:"updated_at"`
		} `json:"data"`
	}
	if err := p.cloudGet(ctx, path, &resp); err != nil {
		return err
	}
	if len(resp.Data) == 0 {
		return nil
	}

	maxUpdated := cursor
	err := p.atomic(func(tx *sql.Tx) error {
		stmt, err := tx.Prepare(`
			INSERT INTO customers (id, first_name, last_name, full_name, phone, email,
			    organization_id, brand_id, branch_id,
			    local_pending_sync, cloud_updated_at, local_synced_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, datetime('now'))
			ON CONFLICT(id) DO UPDATE SET
			    first_name = excluded.first_name,
			    last_name = excluded.last_name,
			    full_name = excluded.full_name,
			    phone = excluded.phone,
			    email = excluded.email,
			    organization_id = excluded.organization_id,
			    brand_id = excluded.brand_id,
			    branch_id = excluded.branch_id,
			    local_pending_sync = 0,
			    cloud_updated_at = excluded.cloud_updated_at,
			    local_synced_at = datetime('now')`)
		if err != nil {
			return err
		}
		defer stmt.Close()
		for _, c := range resp.Data {
			if _, err := stmt.Exec(c.ID, c.FirstName, nullableString(c.LastName), c.FullName,
				nullableString(c.Phone), nullableString(c.Email),
				c.OrganizationID, c.BrandID, c.BranchID, c.UpdatedAt); err != nil {
				return err
			}
			if c.UpdatedAt > maxUpdated {
				maxUpdated = c.UpdatedAt
			}
		}
		return nil
	})
	if err != nil {
		return err
	}
	if maxUpdated != cursor {
		if err := p.setCursor(customersCursorKey, maxUpdated); err != nil {
			slog.Warn("update customers cursor", "err", err)
		}
	}
	return nil
}

// PullMenuSchedules replaces the local menu_schedules table. Cloud returns
// already-flattened (menu_id × day_of_week) pairs so the workstation does
// no parsing here.
func (p *SyncPuller) PullMenuSchedules(ctx context.Context) error {
	var resp struct {
		Data []struct {
			ID        string `json:"id"`
			MenuID    string `json:"menu_id"`
			DayOfWeek int    `json:"day_of_week"`
			StartTime string `json:"start_time"`
			EndTime   string `json:"end_time"`
			Priority  int    `json:"priority"`
			IsActive  bool   `json:"is_active"`
		} `json:"data"`
	}
	if err := p.cloudGet(ctx, pullPathMenuSchedules, &resp); err != nil {
		return err
	}
	if len(resp.Data) == 0 {
		return nil
	}

	return p.atomic(func(tx *sql.Tx) error {
		if _, err := tx.Exec("DELETE FROM menu_schedules"); err != nil {
			return err
		}
		// start_time + end_time + priority added in 024 so the by-day
		// handler can resolve the highest-priority `(menu_id, dow)`
		// schedule and emit its window to pos-web's dropdown chip.
		stmt, err := tx.Prepare(`
			INSERT INTO menu_schedules (id, menu_id, day_of_week,
			    start_time, end_time, priority, is_active,
			    cloud_updated_at, local_synced_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))`)
		if err != nil {
			return err
		}
		defer stmt.Close()
		for _, s := range resp.Data {
			active := 0
			if s.IsActive {
				active = 1
			}
			if _, err := stmt.Exec(s.ID, s.MenuID, s.DayOfWeek,
				nullableString(s.StartTime), nullableString(s.EndTime),
				s.Priority, active); err != nil {
				return err
			}
		}
		return nil
	})
}
