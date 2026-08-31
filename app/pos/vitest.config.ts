import { defineConfig } from "vitest/config";
import { fileURLToPath } from "node:url";

const root = fileURLToPath(new URL(".", import.meta.url));

export default defineConfig({
  // Expo modules read the `__DEV__` global the RN runtime injects; without it
  // importing anything that touches expo-modules-core throws at collection
  // time. Same one-liner app/kiosk uses.
  define: {
    __DEV__: "true",
  },
  resolve: {
    alias: {
      // Matches tsconfig `paths` — sources import as "@/src/lib/url".
      "@": root.replace(/\/$/, ""),
      // The shell's testable logic is pure TS that happens to sit next to RN
      // imports (Platform). Stub the native runtime rather than pulling the
      // whole Metro/Babel pipeline into a unit test.
      "react-native": fileURLToPath(
        new URL("./src/test/react-native-stub.ts", import.meta.url),
      ),
    },
  },
  test: {
    environment: "node",
    globals: true,
    include: ["src/**/*.test.ts"],
  },
});
