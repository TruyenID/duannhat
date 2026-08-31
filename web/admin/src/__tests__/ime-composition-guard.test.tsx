/**
 * IME composition — Enter must not submit mid-conversion.
 *
 * On a Japanese or Vietnamese input method, Enter is how the user COMMITS the
 * conversion candidate; it does not mean "submit". A handler that reacts to the
 * raw `Enter` keydown therefore fires while the text is still half-composed,
 * and the value that lands in the option list is whatever the IME had produced
 * so far — 「とう」 instead of 「東京」, or an empty string. The user sees the
 * wrong tag appear and their typing vanish, which reads as the app eating input.
 *
 * `e.nativeEvent.isComposing` is true for exactly the span between
 * `compositionstart` and `compositionend`, so guarding on it is the whole fix.
 * Latin typists never enter that span and are unaffected.
 *
 * Two layers here, on purpose:
 *  - behaviour, on a component that renders standalone;
 *  - a source ratchet over ALL of web/admin, because the bug's real shape is
 *    "someone adds another Enter handler next month" — four sites needed
 *    fixing in this one PR, which is what a class of bug looks like.
 */

import { describe, it, expect, vi } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import { readFileSync, readdirSync, statSync } from "node:fs";
import { join } from "node:path";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { AppProvider } from "@/providers/app-provider";
import { ProductOptionsBuilder } from "@/app/hq/[brandSlug]/products/components/options-builder";
import type { DraftOption } from "@/app/hq/[brandSlug]/products/components/options-builder";

function draft(): DraftOption[] {
  return [
    {
      tempId: "opt-1",
      key: "size",
      name: "サイズ",
      position: 1,
      values: [{ tempId: "val-1", value: "s", label: "S" }],
    },
  ];
}

function renderBuilder(onChange = vi.fn()) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <AppProvider>
        <ProductOptionsBuilder options={draft()} onChange={onChange} />
      </AppProvider>
    </QueryClientProvider>,
  );

  // The "add value" input is the one carrying the guarded handler.
  const inputs = screen.getAllByRole("textbox") as HTMLInputElement[];
  const addValue = inputs[inputs.length - 1];

  return { onChange, addValue };
}

describe("#2488 — chữ gõ dở không được bốc hơi im lặng", () => {
  /*
   * Bản cũ của ô "値を追加" là input KHÔNG kiểm soát chỉ commit bằng Enter —
   * gõ 翠ジン rồi bấm thẳng nút lưu thì chữ biến mất mà toast vẫn báo thành
   * công. Đo được trong lượt browser-test #2488: đó chính là lượt "Đã lưu"
   * no-op đầu tiên của tôi. Giờ blur phải commit.
   */
  it("rời ô (blur) thì chữ đang gõ thành giá trị, không mất", () => {
    const { onChange, addValue } = renderBuilder();

    fireEvent.change(addValue, { target: { value: "翠ジン" } });
    expect(onChange).not.toHaveBeenCalled(); // chưa commit khi đang gõ

    fireEvent.blur(addValue);

    expect(onChange).toHaveBeenCalledTimes(1);
    const next = onChange.mock.calls[0][0] as DraftOption[];
    expect(next[0].values.map((v) => v.label)).toContain("翠ジン");
    expect(addValue.value).toBe(""); // ô đã xoá — không commit đôi ở blur sau
  });

  it("blur với ô rỗng / toàn khoảng trắng thì không tạo giá trị rác", () => {
    const { onChange, addValue } = renderBuilder();

    fireEvent.blur(addValue);
    fireEvent.change(addValue, { target: { value: "   " } });
    fireEvent.blur(addValue);

    expect(onChange).not.toHaveBeenCalled();
  });
});

describe("Enter during IME composition", () => {
  it("does NOT add a value while the IME is still composing", () => {
    const { onChange, addValue } = renderBuilder();

    fireEvent.change(addValue, { target: { value: "とう" } });
    fireEvent.keyDown(addValue, { key: "Enter", isComposing: true });

    expect(onChange).not.toHaveBeenCalled();
    // The half-composed text must survive — clearing it is the visible symptom
    // users describe as "the app ate what I typed".
    expect(addValue.value).toBe("とう");
  });

  it("adds the value once composition has ended", () => {
    const { onChange, addValue } = renderBuilder();

    fireEvent.change(addValue, { target: { value: "東京" } });
    fireEvent.keyDown(addValue, { key: "Enter", isComposing: false });

    expect(onChange).toHaveBeenCalledTimes(1);
    const next = onChange.mock.calls[0][0] as DraftOption[];
    expect(next[0].values.map((v) => v.label)).toContain("東京");
  });

  it("treats a missing isComposing as not composing — Latin keyboards still work", () => {
    // Not every environment populates the flag. Defaulting to "composing"
    // would break the ordinary path for everyone who never uses an IME.
    const { onChange, addValue } = renderBuilder();

    fireEvent.change(addValue, { target: { value: "Large" } });
    fireEvent.keyDown(addValue, { key: "Enter" });

    expect(onChange).toHaveBeenCalledTimes(1);
  });

  it("ignores a non-Enter key pressed during composition", () => {
    const { onChange, addValue } = renderBuilder();

    fireEvent.change(addValue, { target: { value: "とう" } });
    fireEvent.keyDown(addValue, { key: "a", isComposing: true });

    expect(onChange).not.toHaveBeenCalled();
  });

  it("still refuses a whitespace-only value after composition ends", () => {
    // The guard must not have widened what Enter accepts.
    const { onChange, addValue } = renderBuilder();

    fireEvent.change(addValue, { target: { value: "   " } });
    fireEvent.keyDown(addValue, { key: "Enter", isComposing: false });

    expect(onChange).not.toHaveBeenCalled();
  });
});

/*
 * Source ratchet. The behavioural cases above cover one input; this covers the
 * class. Four separate sites needed the same guard in one PR, so the next
 * unguarded handler is a matter of when, not if — and it fails silently for
 * anyone typing Latin, which is most reviewers.
 */
describe("every Enter handler in web/admin guards composition", () => {
  it("leaves no TEXT INPUT reacting to a raw Enter", () => {
    // Scope matters. An `onKeyDown` on a `role="button"` / `role="combobox"`
    // div is the standard keyboard-accessibility handler: no IME can be
    // composing there because there is nothing to type into, and demanding the
    // guard would be noise that teaches people to add it without thinking.
    // What must never appear again is an Enter that COMMITS a value typed by a
    // human — five of those were still open when this PR landed, all of them
    // price fields.
    const files: string[] = [];
    const walk = (dir: string): void => {
      for (const entry of readdirSync(dir)) {
        const path = join(dir, entry);
        if (statSync(path).isDirectory()) {
          if (entry === "node_modules" || entry === "__tests__") continue;
          walk(path);
        } else if (entry.endsWith(".tsx")) {
          files.push(path);
        }
      }
    };
    walk("src");

    // Vacuity floor #1 — the walker. This whole test is a filesystem crawl, and
    // a crawl that finds nothing produces an empty offender list, which is
    // exactly what a clean tree produces. A renamed `src/`, a `.tsx` → `.ts`
    // migration, or a thrown-and-swallowed statSync would all read as "green".
    // 373 .tsx files under src/ when this floor was written.
    expect(files.length).toBeGreaterThan(250);

    const offenders: string[] = [];
    // Sites where the lookback says BOTH "text input" and "widget role". The
    // widget-role excuse used to silently pass these (#3190). It has never
    // actually excused anything — measured over 373 files / 24 Enter sites, the
    // excuse fires 0 times — so its only live effect was pre-authorising
    // whatever future text input happened to sit within 700 chars of a
    // `role="button"`. Proven, not argued: a probe file with an `<input>` whose
    // Enter commits, nested in a `<div role="button">`, passed the old version
    // of this test green. Now such a site must be read by a human.
    const ambiguous: string[] = [];
    let enterSites = 0;
    for (const file of files) {
      const source = readFileSync(file, "utf8");
      const pattern = /key\s*===\s*["']Enter["']/g;
      let match: RegExpExecArray | null;
      while ((match = pattern.exec(source)) !== null) {
        enterSites += 1;
        if (source.slice(match.index, match.index + 200).includes("isComposing")) continue;

        // Look back for what this handler is attached to.
        const before = source.slice(Math.max(0, match.index - 700), match.index);
        const isTextEntry =
          before.includes("HTMLInputElement") ||
          before.includes("HTMLTextAreaElement") ||
          /<(Input|input|Textarea|textarea)\b/.test(before);
        const isWidgetRole = /role=\{?["'](button|combobox|option|menuitem)["']/.test(before);

        const at = `${file}:${source.slice(0, match.index).split("\n").length}`;
        if (isTextEntry && isWidgetRole) {
          ambiguous.push(at);
        } else if (isTextEntry) {
          offenders.push(at);
        }
      }
    }

    // Vacuity floor #2 — the matcher. `key === "Enter"` is one spelling among
    // several (`e.key == 'Enter'`, `code === "Enter"`, a shared helper). If a
    // refactor moves the codebase onto another spelling, this regex matches
    // nothing and the ratchet goes quiet without a single red line. 24 Enter
    // sites when this floor was written.
    expect(enterSites).toBeGreaterThan(15);

    expect(
      ambiguous,
      "These Enter handlers look like a TEXT INPUT and carry a widget role within 700 chars of " +
        "lookback, so the source scan cannot tell which one it is. Decide by reading: add the " +
        "isComposing guard if a human types there, or move the role attribute out of the lookback " +
        "window if it is genuinely a button. Do not re-add a blanket excuse — an unguarded IME " +
        "commit is invisible to anyone typing Latin."
    ).toEqual([]);

    expect(offenders).toEqual([]);
  });
});
