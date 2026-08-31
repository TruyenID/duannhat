import { describe, expect, it } from "vitest";

import { loginDestination } from "./sso";

describe("Platform SSO BFF navigation", () => {
  it("starts the backend flow without exposing OAuth credentials", () => {
    const destination = loginDestination(
      "/select-context",
      "cb77c7a3-62b0-54c2-b6dd-091429113b31"
    );
    const url = new URL(destination, "http://localhost:5430");

    expect(url.pathname).toBe("/auth/redirect");
    expect(url.searchParams.get("return")).toBe("/select-context");
    expect(url.searchParams.get("organization_context_id")).toBe(
      "cb77c7a3-62b0-54c2-b6dd-091429113b31"
    );
    expect(url.searchParams.has("client_id")).toBe(false);
    expect(url.searchParams.has("client_secret")).toBe(false);
  });
});
