"use client";

import { useEffect } from "react";

/**
 * ScrollReveal — observer thuần, không render UI.
 *
 * Quy ước:
 * - Element có `data-reveal` → chạy `anim-reveal-kf` khi vào viewport.
 * - Element có `data-reveal-stagger` → bản thân nó reveal, đồng thời các con
 *   trực tiếp được stagger theo thứ tự (delay 80ms/child, cap 10).
 *
 * CSS hỗ trợ ở `globals.css` (`[data-reveal]`, `[data-reveal-stagger] > *`).
 */
export default function ScrollReveal() {
  useEffect(() => {
    if (typeof window === "undefined") return;

    // Browser hỗ trợ CSS scroll-driven animation (animation-timeline: view()) →
    // CSS tự lo reveal theo scroll progress, JS không cần làm gì.
    if (
      typeof CSS !== "undefined" &&
      CSS.supports?.("animation-timeline", "view()")
    ) {
      return;
    }

    const mql = window.matchMedia("(prefers-reduced-motion: reduce)");
    if (mql.matches) {
      document
        .querySelectorAll<HTMLElement>("[data-reveal], [data-reveal-stagger]")
        .forEach((el) => el.classList.add("anim-revealed"));
      return;
    }

    const observer = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          if (entry.isIntersecting) {
            entry.target.classList.add("anim-revealed");
            observer.unobserve(entry.target);
          }
        }
      },
      { threshold: 0.12, rootMargin: "0px 0px -8% 0px" },
    );

    const targets = document.querySelectorAll<HTMLElement>(
      "[data-reveal], [data-reveal-stagger]",
    );
    targets.forEach((el) => observer.observe(el));

    return () => observer.disconnect();
  }, []);

  return null;
}
