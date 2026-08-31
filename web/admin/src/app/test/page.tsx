"use client";

import { useState } from "react";
import { useLocale, useTheme, useTranslation } from "@/providers/app-provider";

export default function TestPage() {
  const [count, setCount] = useState(0);
  const { locale, setLocale, locales } = useLocale();
  const { theme, setTheme } = useTheme();
  const { t } = useTranslation();

  return (
    <div style={{ padding: 20, fontFamily: "sans-serif" }}>
      <h1>Debug Test Page</h1>
      <p>
        Render count: {count} <button onClick={() => setCount((c) => c + 1)}>+1</button>
      </p>
      <hr />
      <p>
        Locale: <strong>{locale}</strong>
      </p>
      <p>
        Theme: <strong>{theme}</strong>
      </p>
      <p>
        t(&ldquo;nav.products&rdquo;): <strong>{t("nav.products")}</strong>
      </p>
      <hr />
      <div style={{ display: "flex", gap: 8 }}>
        {(Object.keys(locales) as Array<keyof typeof locales>).map((loc) => (
          <button
            key={loc}
            onClick={() => setLocale(loc)}
            style={{ fontWeight: locale === loc ? "bold" : "normal", padding: "4px 12px" }}
          >
            {locales[loc]}
          </button>
        ))}
      </div>
      <hr />
      <div style={{ display: "flex", gap: 8 }}>
        <button onClick={() => setTheme("light")}>Light</button>
        <button onClick={() => setTheme("dark")}>Dark</button>
        <button onClick={() => setTheme("system")}>System</button>
      </div>
    </div>
  );
}
