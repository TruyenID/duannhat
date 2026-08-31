/**
 * The `?` button's two load-bearing behaviours.
 *
 * 1. It opens the guide for the topic it was given, in the operator's language.
 * 2. Opening it from INSIDE a dialog does not close that dialog.
 *
 * (2) is the whole reason the drawer is a Radix `Sheet` rather than a
 * hand-rolled overlay, and the failure mode it prevents is expensive: most of
 * these buttons live inside the payment dialog, the void dialog, the settle
 * confirmation. A help panel that dismissed the payment dialog under it would
 * lose a cashier's tender entry with a customer at the counter — and it would
 * do it only in the nested case, which is exactly the case a quick manual click
 * on a page-level button never exercises.
 */

import { describe, expect, it } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import { Dialog, DialogTitle } from "@godxjp/ui";
import { DialogContent } from "@/components/ui/dialog";
import { AppProvider } from "@/providers/app-provider";
import { LOCALE_STORAGE_KEY } from "@/i18n";
import { HelpButton } from "./help-button";
import { getHelpTopic } from ".";

function renderWithLocale(node: React.ReactNode, locale: "ja" | "en" | "vi") {
  localStorage.setItem(LOCALE_STORAGE_KEY, locale);
  return render(<AppProvider>{node}</AppProvider>);
}

/**
 * Target the trigger by its data-slot, not by role or name: a dialog renders
 * its own close button, and the accessible name is translated — both make a
 * role/name query resolve to the wrong element (or to two).
 */
function clickHelp() {
  const trigger = document.querySelector<HTMLButtonElement>(
    '[data-slot="help-button"]',
  );
  expect(trigger).not.toBeNull();
  fireEvent.click(trigger!);
}

describe("HelpButton", () => {
  it("opens the drawer for its topic", () => {
    renderWithLocale(<HelpButton topic="payment" />, "vi");

    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    clickHelp();

    const topic = getHelpTopic("payment", "vi");
    expect(screen.getByText(topic.title)).toBeInTheDocument();
    expect(screen.getByText(topic.purpose)).toBeInTheDocument();
  });

  it("renders the external-prerequisites section — the reason this exists", () => {
    renderWithLocale(<HelpButton topic="payment" />, "vi");
    clickHelp();

    // "No payment methods" is a shop policy problem, not a POS bug. If the
    // setup bullets stop rendering, the drawer answers the easy half of the
    // question and silently drops the half that sends a cashier to the right
    // person.
    const setup = getHelpTopic("payment", "vi").setup ?? [];
    expect(setup.length).toBeGreaterThan(0);
    for (const line of setup) {
      expect(screen.getByText(line)).toBeInTheDocument();
    }
  });

  it("follows the operator's language", () => {
    renderWithLocale(<HelpButton topic="shift-close" />, "ja");
    clickHelp();

    expect(screen.getByText(getHelpTopic("shift-close", "ja").title)).toBeInTheDocument();
    expect(
      screen.queryByText(getHelpTopic("shift-close", "vi").title),
    ).not.toBeInTheDocument();
  });

  it("does NOT dismiss the dialog it was opened from", () => {
    const orderMarker = "ORD-2026-2426";

    renderWithLocale(
      <Dialog open>
        <DialogContent>
          <DialogTitle>{orderMarker}</DialogTitle>
          <HelpButton topic="payment" />
        </DialogContent>
      </Dialog>,
      "vi",
    );

    clickHelp();

    // Both are on screen: the help drawer AND the dialog underneath it. A
    // sibling overlay portaled to document.body would have been read as an
    // outside interaction and taken the dialog with it.
    expect(screen.getByText(getHelpTopic("payment", "vi").title)).toBeInTheDocument();
    expect(screen.getByText(orderMarker)).toBeInTheDocument();
  });

  it("does not submit the form it sits in", () => {
    // Pairing and the cash-event dialog are real <form>s. A bare <button>
    // defaults to type="submit", so asking for help would have fired the form.
    let submitted = false;
    renderWithLocale(
      <form
        onSubmit={(e) => {
          e.preventDefault();
          submitted = true;
        }}
      >
        <HelpButton topic="pairing" />
      </form>,
      "vi",
    );

    clickHelp();
    expect(submitted).toBe(false);
  });
});
