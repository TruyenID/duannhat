import { describe, expect, it } from "vitest";
import { bootStatusFor } from "./boot-status";

/**
 * The rule the shop feels every morning.
 *
 * Boot lands on the POS whenever the tablet KNOWS a workstation — whether that
 * workstation is answering right now is not part of the decision. It used to
 * be: one failed 4s health probe demoted a configured tablet to `needs_setup`,
 * so tablets that woke on a charging dock before the shop's mini-PC (and every
 * tablet after a mid-shift assisted update) put staff back in the Connect
 * wizard.
 *
 * What is really pinned here is an ABSENCE — health takes no part — and that is
 * why the signature is the test: a function that only receives the stored URL
 * cannot consult the probe even if someone later wants it to.
 */
describe("bootStatusFor", () => {
  it("opens the POS for a tablet that already knows its workstation", () => {
    expect(bootStatusFor("http://192.168.1.10:8080")).toBe("ready");
  });

  it("sends a tablet with no workstation to setup", () => {
    expect(bootStatusFor(null)).toBe("needs_setup");
  });

  it("treats an empty stored value as no workstation", () => {
    // SecureStore can hand back "" where a write was interrupted; that is not a
    // workstation, and opening the POS on it would show an unusable WebView.
    expect(bootStatusFor("")).toBe("needs_setup");
  });

  it("takes ONLY the stored URL — health cannot influence it", () => {
    // The regression, stated as a property rather than a scenario: the function
    // accepts one argument, so an unreachable workstation is unrepresentable
    // here. Re-adding a health parameter is what this fails on.
    expect(bootStatusFor.length).toBe(1);
  });
});
