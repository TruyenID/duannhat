"use client";

import { useEffect, type ReactNode } from "react";

/**
 * Per-tenant brand color overrides applied at runtime.
 *
 * Reads brand color fields from a `Brand` row (omnify-generated; see
 * `schemas/Sso/Brand.yaml`) and applies them as CSS variable overrides on
 * `document.body`. The framework defaults defined in `globals.css` continue
 * to apply for any color the brand leaves null.
 *
 * Two ways to scope a tenant theme:
 * 1. **Static CSS block** (preferred for known tenants) — open
 *    `src/app/globals.css`, copy the `[data-tenant="betoya"]` block, replace
 *    slug + OKLCH values. The block can override any token (radius, shadow,
 *    density) not just colors. Switch active tenant with
 *    `<body data-tenant="<slug>">`.
 * 2. **Runtime override** (this provider) — for tenants whose colors come
 *    from the database / Console API. Reads the four hex columns
 *    (`primary_color`, `secondary_color`, `accent_color`, `text_color`)
 *    from the active brand and writes them to `--primary`, `--ring`,
 *    `--color-brand-secondary`, `--color-brand-accent`, `--tenant-foreground`.
 *    The last token is consumed only in light mode so a light-surface brand
 *    color cannot override the contrast-safe semantic foreground in dark mode.
 *
 * Mount this once near the root of any layout that's tenant-scoped (e.g.
 * the `(brand)` segment layout in HQ pages). Children re-render normally
 * — the override is just CSS, not a React context.
 *
 * @example
 * ```tsx
 * // app/hq/[brandSlug]/layout.tsx
 * import { TenantThemeProvider } from "@/providers/tenant-theme-provider";
 *
 * export default async function HqBrandLayout({ children, params }) {
 *   const brand = await fetchBrand(params.brandSlug);
 *   return (
 *     <TenantThemeProvider brand={brand}>
 *       {children}
 *     </TenantThemeProvider>
 *   );
 * }
 * ```
 */
/**
 * The minimum brand shape this provider needs. Color fields use
 * `string | null | undefined` so the provider accepts both:
 * - the omnify-generated `Brand` interface (`string | undefined`), and
 * - JSON-deserialized API responses that preserve `null` for absent values.
 */
export interface TenantBrand {
  slug?: string;
  primary_color?: string | null;
  secondary_color?: string | null;
  accent_color?: string | null;
  text_color?: string | null;
}

interface TenantThemeProviderProps {
  /** The active brand whose colors should override the framework defaults. */
  brand?: TenantBrand | null;
  children: ReactNode;
}

const HEX_RE = /^#[0-9A-Fa-f]{6}$/;

function isValidHex(value: string | null | undefined): value is string {
  return typeof value === "string" && HEX_RE.test(value);
}

export function TenantThemeProvider({ brand, children }: TenantThemeProviderProps) {
  useEffect(() => {
    if (typeof document === "undefined" || !brand) return;

    const body = document.body;
    const previous: Record<string, string> = {};

    function setVar(name: string, value: string) {
      previous[name] = body.style.getPropertyValue(name);
      body.style.setProperty(name, value);
    }

    // Apply slug as data-tenant so any matching [data-tenant="<slug>"] CSS
    // block in globals.css is also activated. The runtime overrides below
    // win for tokens both layers set, but keeping the attribute lets static
    // blocks supply density / radius / shadow tokens that can't easily flow
    // through inline style overrides.
    const previousTenant = body.dataset.tenant;
    if (brand.slug) {
      body.dataset.tenant = brand.slug;
    }

    if (isValidHex(brand.primary_color)) {
      setVar("--primary", brand.primary_color);
      setVar("--ring", brand.primary_color);
    }
    if (isValidHex(brand.secondary_color)) {
      setVar("--color-brand-secondary", brand.secondary_color);
    }
    if (isValidHex(brand.accent_color)) {
      setVar("--color-brand-accent", brand.accent_color);
    }
    if (isValidHex(brand.text_color)) {
      setVar("--tenant-foreground", brand.text_color);
    }

    return () => {
      // Restore everything on unmount so navigating to a non-tenant page
      // doesn't leave the previous tenant's colors stuck on body.
      for (const [name, value] of Object.entries(previous)) {
        if (value) {
          body.style.setProperty(name, value);
        } else {
          body.style.removeProperty(name);
        }
      }
      if (previousTenant === undefined) {
        delete body.dataset.tenant;
      } else {
        body.dataset.tenant = previousTenant;
      }
    };
  }, [
    brand?.slug,
    brand?.primary_color,
    brand?.secondary_color,
    brand?.accent_color,
    brand?.text_color,
    brand,
  ]);

  return <>{children}</>;
}
