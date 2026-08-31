/**
 * Shop-overlay helpers — plan-053 M4 (#1171), T4.2.
 *
 * A shop does NOT publish a whole slip; it publishes a partial OVERLAY of the
 * few paths its brand delegated (`shop_editable`). Sending the whole document
 * would trip `SHOP_FIELD_NOT_EDITABLE` on every inherited field, and — worse —
 * would freeze the shop on today's brand layout, because a later brand change
 * to the rest of the slip would have nothing left to merge into (TR-02).
 *
 * An allow-list path is either a block id (`footer_text` — any editable prop of
 * that block) or `blockId.prop` (`qr_block.enabled` — that prop only), matching
 * the backend's `DefinitionMerger::filterToAllowList`.
 */

import {
  editablePropsOf,
  type PrintBlock,
  type PrintTemplateCatalog,
  type PrintTemplateDefinition,
} from "@/types/models/PrintTemplate";

/**
 * `catalog` is the SERVER's (#2043). It used to be `catalogFromDefinition()`,
 * which rebuilt the catalog from hand-copied constants because the shop surface
 * had no catalog read — four silent drifts later the shop endpoint serves it.
 */
export function buildOverlay(
  definition: PrintTemplateDefinition,
  catalog: PrintTemplateCatalog,
  allowedPaths: string[]
): PrintTemplateDefinition {
  const wholeBlocks = new Set<string>();
  const blockProps = new Map<string, Set<string>>();

  for (const path of allowedPaths) {
    const [blockId, prop] = path.split(".", 2);
    if (!prop) {
      wholeBlocks.add(blockId);
      continue;
    }
    const props = blockProps.get(blockId) ?? new Set<string>();
    props.add(prop);
    blockProps.set(blockId, props);
  }

  const blocks: PrintBlock[] = [];
  for (const block of definition.blocks) {
    const whole = wholeBlocks.has(block.id);
    if (!whole && !blockProps.has(block.id)) continue;

    const props = whole
      ? editablePropsOf(catalog, block.id)
      : Array.from(blockProps.get(block.id) ?? []);

    const kept: Record<string, unknown> = { id: block.id };
    for (const prop of props) {
      const value = (block as unknown as Record<string, unknown>)[prop];
      if (value !== undefined) kept[prop] = value;
    }
    if (Object.keys(kept).length > 1) blocks.push(kept as unknown as PrintBlock);
  }

  return { schema: definition.schema, blocks };
}

/*
 * `catalogFromDefinition()` lived here until #2043. It rebuilt the block catalog
 * on the client from five hand-copied constants, because the only catalog read
 * was HQ-scoped and a shop manager holds no `menu.manage`. It also lied by
 * omission — `required: []`, so the shop editor never badged a required block.
 * `GET /shops/{slug}/print-templates/{kind}` now returns `data.catalog`.
 */

/** Merge a stored draft overlay onto the resolved slip, block by block. */
export function applyOverlay(
  base: PrintTemplateDefinition,
  overlay: PrintTemplateDefinition | null | undefined
): PrintTemplateDefinition {
  if (!overlay?.blocks) return base;
  const blocks = base.blocks.map((block) => {
    const patch = overlay.blocks.find((candidate) => candidate.id === block.id);
    return patch ? { ...block, ...patch } : block;
  });
  return { ...base, blocks };
}
