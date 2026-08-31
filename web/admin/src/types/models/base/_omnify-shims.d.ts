/**
 * Ambient shims for Omnify codegen base files.
 *
 * Context: `src/types/models/base/*.ts` is regenerated from schemas.json
 * and per project convention must NOT be edited manually. The codegen has
 * a long-standing bug where relation fields reference sibling interfaces
 * (Organization, Brand, Branch) without emitting the corresponding
 * `import type` — so `tsc --noEmit` and `next build` fail with
 * `TS2304: Cannot find name 'Organization'` across ~16 files.
 *
 * Until the upstream omnify-ts codegen fix lands, this file declares the
 * affected relation types as globals inside the `base/` folder so the
 * broken files resolve. The declarations mirror the real interfaces — if
 * those schemas change shape, regenerate this shim too.
 *
 * This file lives alongside the base files on purpose: the broken files
 * only need these globals available in their own directory, and keeping
 * the shim colocated makes it obvious what it's patching.
 */

import type { Organization as _Organization } from "./Organization";
import type { Brand as _Brand } from "./Brand";
import type { Branch as _Branch } from "./Branch";

declare global {
  type Organization = _Organization;
  type Brand = _Brand;
  type Branch = _Branch;
}

export {};
