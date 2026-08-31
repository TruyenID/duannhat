import { defineConfig } from "vitest/config";
import path from "node:path";

// tms-app is an Expo / React Native app, so there is no Vite build to inherit
// a config from — this file exists only for vitest.
//
// `jsdom` is NOT here to pretend React Native is a browser. The two hook tests
// mock every native module they touch (AsyncStorage, @/lib/api, react-query),
// so what actually has to run is plain React state + effects, and React needs
// a DOM to reconcile against. Anything that reaches a real RN module belongs in
// an e2e test on a device, not here.
export default defineConfig({
  resolve: {
    // Mirrors the `paths` in tsconfig.json so `@/lib/api` resolves the same way
    // it does under Metro.
    alias: {
      "@": path.resolve(__dirname, "./src"),
      "~": path.resolve(__dirname, "./"),
    },
  },
  test: {
    environment: "jsdom",
    include: ["src/**/*.test.ts", "src/**/*.test.tsx"],
  },
});
