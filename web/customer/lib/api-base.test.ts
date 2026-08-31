import assert from "node:assert/strict";
import { describe, it } from "node:test";

import { resolveApiBase } from "./api-base.ts";

describe("resolveApiBase", () => {
  it("uses the Amplify same-origin proxy in production browsers", () => {
    assert.equal(resolveApiBase("https://legacy-api.example.test/tempo", "production", true), "");
  });

  it("preserves an absolute origin during server rendering", () => {
    assert.equal(
      resolveApiBase("https://tempo-prod.godx.jp", "production", false),
      "https://tempo-prod.godx.jp",
    );
  });

  it("keeps the configured local API during development", () => {
    assert.equal(resolveApiBase("http://localhost:5400", "development", true), "http://localhost:5400");
  });
});
