import { defineConfig } from 'vitest/config';

export default defineConfig({
  // React Native / Expo modules reference the `__DEV__` global that the RN
  // runtime injects. Define it for the vitest transform so importing RN-backed
  // modules (e.g. the API client pulled in via the split-bill sidebar) doesn't
  // throw `__DEV__ is not defined` at collection time.
  define: {
    __DEV__: 'true',
  },
  test: {
    environment: 'happy-dom',
    globals: true,
  },
});
