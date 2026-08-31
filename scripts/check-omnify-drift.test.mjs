import { test } from "node:test";
import assert from "node:assert/strict";

import { verdictFor, lockVerdictFor, propertiesInYaml } from "./check-omnify-drift.mjs";

/*
 * #1640 — the guard's own reading, pinned.
 *
 * The first version of this guard shelled out to `omnify diff` and asserted
 * "No changes detected". It passed on a tree whose state file was reverted to
 * the exact commit from the incident — `omnify diff` does not read
 * `.omnify/schemas.json` at all. It looked convincing because it DID react to a
 * YAML edit, which is the shape of a guard that covers one thing and answers
 * "yes" to a question about another.
 *
 * So these tests hold the comparison itself, and the last one reproduces the
 * incident.
 */

const yaml = (props) => ({ properties: Object.fromEntries(props.map((p) => [p, {}])) });
const state = (schemas) =>
  Object.fromEntries(
    Object.entries(schemas).map(([n, props]) => [n, { properties: Object.fromEntries(props.map((p) => [p, {}])) }]),
  );
const fromYaml = (schemas) => Object.fromEntries(Object.entries(schemas).map(([n, props]) => [n, new Set(props)]));

test("matched tree passes", () => {
  const v = verdictFor(state({ Device: ["id", "name"] }), fromYaml({ Device: ["id", "name"] }));
  assert.equal(v.ok, true);
});

test("a schema in the YAML but not in the state file fails", () => {
  const v = verdictFor(state({ Device: ["id"] }), fromYaml({ Device: ["id"], PostBranch: ["id"] }));

  assert.equal(v.ok, false);
  assert.match(v.reason, /\+ PostBranch/);
  // Name the consequence, not just the fact — a bare "drift detected" reads as noise.
  assert.match(v.reason, /DUPLICATE migration/);
});

test("a property in the YAML but not in the state file fails", () => {
  const v = verdictFor(state({ PointReward: ["id"] }), fromYaml({ PointReward: ["id", "stock_quantity"] }));

  assert.equal(v.ok, false);
  assert.match(v.reason, /\+ PointReward\.stock_quantity/);
});

test("a schema in the state file with no YAML fails too — drift has two directions", () => {
  const v = verdictFor(state({ Device: ["id"], Ghost: ["id"] }), fromYaml({ Device: ["id"] }));

  assert.equal(v.ok, false);
  assert.match(v.reason, /- Ghost/);
});

test("a YAML with no properties block is read as zero properties, not as a crash", () => {
  assert.deepEqual([...propertiesInYaml("displayName: Device\n")], []);
  assert.deepEqual([...propertiesInYaml("")], []);
});

test("properties are read from the `properties:` block", () => {
  const props = propertiesInYaml("displayName: X\nproperties:\n  id:\n    type: String\n  name:\n    type: String\n");
  assert.deepEqual([...props].sort(), ["id", "name"]);
});

test("reproduces the #1640 incident", () => {
  // What `252628e7b` left behind: the YAML carried PointReward's new properties
  // and the pivot schema; the state file did not.
  const v = verdictFor(
    state({ PointReward: ["id", "cost_points"] }),
    fromYaml({
      PointReward: ["id", "cost_points", "stock_quantity", "service_condition", "image", "redeemed_count"],
      PointRewardBranch: ["id"],
      PointRewardServiceCondition: ["id"],
    }),
  );

  assert.equal(v.ok, false);
  for (const expected of [
    "+ PointRewardBranch",
    "+ PointRewardServiceCondition",
    "+ PointReward.stock_quantity",
    "+ PointReward.service_condition",
    "+ PointReward.image",
    "+ PointReward.redeemed_count",
  ]) {
    assert.ok(v.reason.includes(expected), `missing from the report: ${expected}`);
  }
});

/*
 * #2317 — the second front: lock snapshot vs state file.
 *
 * `omnify diff` baselines on `.omnify/lock.json`'s snapshot, not on
 * `schemas.json`. The tree measured green on the check above while the lock sat
 * two versions behind, and the next `omnify generate` emitted three migrations
 * for changes that had already shipped — two of them DROPPING tables that a
 * pair of committed migrations had already dropped.
 */

test("lock snapshot in step with the state file is clean", () => {
  const same = { Brand: ["id", "slug"], Menu: ["id"] };
  assert.equal(lockVerdictFor(state(same), state(same)).ok, true);
});

test("reproduces the #2317 incident — lock behind the state file", () => {
  // Lock at v138: still carrying OrderAdjustment{,Allocation}, missing the two
  // Brand logo columns that had already shipped.
  const v = lockVerdictFor(
    state({
      Brand: ["id", "slug"],
      OrderAdjustment: ["id"],
      OrderAdjustmentAllocation: ["id"],
    }),
    state({
      Brand: ["id", "slug", "customer_header_logo_file_id", "customer_order_logo_file_id"],
    }),
  );

  assert.equal(v.ok, false);
  for (const expected of [
    "+ Brand.customer_header_logo_file_id",
    "+ Brand.customer_order_logo_file_id",
    "- OrderAdjustment",
    "- OrderAdjustmentAllocation",
  ]) {
    assert.ok(v.reason.includes(expected), `missing from the report: ${expected}`);
  }

  // The report must name the RIGHT two files. Wording inherited from the
  // YAML-vs-state comparator would send the reader to edit YAML, which is not
  // where this drift lives.
  assert.ok(v.reason.includes(".omnify/lock.json's snapshot"), "report must name the lock");
  assert.ok(!v.reason.includes("no YAML file"), "report must not blame the YAML");
});
