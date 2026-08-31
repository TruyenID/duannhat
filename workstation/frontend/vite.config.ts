import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";
import wails from "@wailsio/runtime/plugins/vite";

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss(), wails("./bindings")],
  resolve: {
    preserveSymlinks: false,
    dedupe: ["react", "react-dom"],
  },
  // @godxjp/ui 18.x is published to npm as proper ESM, so none of the
  // CJS-interop scaffolding the old github-linked 0.2.x build needed
  // (optimizeDeps.include shims + commonjs transformMixedEsModules) applies.
});
