import { beforeAll, describe, expect, it } from "vitest";
import { installDomMutationGuard } from "./dom-mutation-guard";

beforeAll(() => {
  installDomMutationGuard();
});

describe("installDomMutationGuard — removeChild", () => {
  it("still removes a genuine child", () => {
    const parent = document.createElement("div");
    const child = document.createElement("span");
    parent.appendChild(child);

    expect(parent.removeChild(child)).toBe(child);
    expect(child.parentNode).toBeNull();
  });

  it("does not throw when the node was re-parented by an extension", () => {
    const container = document.createElement("div");
    const child = document.createElement("span");
    container.appendChild(child);

    // Simulate Google Translate moving the text node elsewhere: appending it
    // to another parent auto-detaches it from `container`.
    const stolen = document.createElement("div");
    stolen.appendChild(child);
    expect(child.parentNode).toBe(stolen);

    // React still believes `child` lives under `container` and tries to remove
    // it. Before the guard this threw NotFoundError and crashed the tree.
    expect(() => container.removeChild(child)).not.toThrow();
    expect(container.removeChild(child)).toBe(child);
  });
});

describe("installDomMutationGuard — insertBefore", () => {
  it("inserts before a genuine reference child", () => {
    const parent = document.createElement("div");
    const ref = document.createElement("i");
    parent.appendChild(ref);
    const inserted = document.createElement("b");

    parent.insertBefore(inserted, ref);
    expect(parent.firstChild).toBe(inserted);
    expect(inserted.nextSibling).toBe(ref);
  });

  it("appends (null reference) without throwing", () => {
    const parent = document.createElement("div");
    const inserted = document.createElement("b");

    expect(() => parent.insertBefore(inserted, null)).not.toThrow();
    expect(parent.firstChild).toBe(inserted);
  });

  it("falls back to append when the reference anchor was moved away", () => {
    const parent = document.createElement("div");
    const anchor = document.createElement("i"); // never attached to `parent`
    const inserted = document.createElement("b");

    expect(() => parent.insertBefore(inserted, anchor)).not.toThrow();
    expect(inserted.parentNode).toBe(parent);
  });
});

describe("installDomMutationGuard — idempotency", () => {
  it("is safe to call more than once", () => {
    expect(() => {
      installDomMutationGuard();
      installDomMutationGuard();
    }).not.toThrow();

    const parent = document.createElement("div");
    const child = document.createElement("span");
    parent.appendChild(child);
    expect(parent.removeChild(child)).toBe(child);
  });
});
