import { defineConfig } from "tsup";
import path from "path";

export default defineConfig({
  entry: {
    index: "src/index.ts",
  },
  format: ["esm"],
  dts: true,
  sourcemap: true,
  clean: true,
  external: [
    "react",
    "react-dom",
    "react/jsx-runtime",
    "@tanstack/react-table",
    "@tanstack/react-query",
    "@tiptap/react",
    "@tiptap/starter-kit",
    "@tiptap/pm",
    /^@tiptap\//,
    "react-hook-form",
    "date-fns",
    "next",
    "next/navigation",
    "next/themes",
    // Radix primitives must stay external: their context providers
    // (dropdown, popover, etc.) leak state when duplicated across the
    // bundled and consumer copies.
    /^@radix-ui\//,
    "lucide-react",
    "class-variance-authority",
    "clsx",
    "cmdk",
    "embla-carousel-react",
    "input-otp",
    "react-day-picker",
    "react-resizable-panels",
    "sonner",
    "tailwind-merge",
    "vaul",
    // CJS-only packages that use require("react") internally.
    // Must be external so tsup does not inline their CJS code into ESM output,
    // which causes "Dynamic require of 'react' is not supported" at runtime.
    "use-sync-external-store",
    "use-sync-external-store/shim",
    "use-sync-external-store/shim/with-selector",
    "use-sync-external-store/with-selector.js",
    "es-toolkit",
    "es-toolkit/compat",
    "eventemitter3",
  ],
  splitting: true,
  treeshake: true,
  onSuccess: async () => {
    // Prepend "use client" directive to ESM output for Next.js RSC compatibility
    const fs = await import("fs");
    const files = fs.readdirSync("dist").filter((f: string) => f.endsWith(".js"));
    for (const file of files) {
      const path = `dist/${file}`;
      const content = fs.readFileSync(path, "utf-8");
      if (!content.startsWith('"use client"')) {
        fs.writeFileSync(path, `"use client";\n${content}`);
      }
    }
  },
  esbuildOptions(options) {
    options.alias = {
      "@": path.resolve(__dirname, "src"),
    };
  },
});
