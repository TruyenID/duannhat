import sharedConfig from "@tempo/prettier-config";

/** @type {import("prettier").Config} */
export default {
  ...sharedConfig,
  // Tailwind-specific — stays per-app because the stylesheet path is local
  plugins: ["prettier-plugin-tailwindcss"],
  tailwindStylesheet: "./src/app/globals.css",
};
