import sharedConfig from "@tempo/prettier-config";

/** @type {import("prettier").Config} */
export default {
  ...sharedConfig,
  plugins: ["prettier-plugin-tailwindcss"],
};
