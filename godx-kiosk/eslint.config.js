/**
 * ESLint flat config for godx-kiosk (Expo + React Native).
 *
 * Was missing entirely — 190+ source files shipped unlinted for the
 * lifetime of the repo. `eslint-config-expo` is the official Expo
 * recommendation; it pre-wires react/react-native/react-hooks +
 * TypeScript parser with the right globals (__DEV__, requestIdleCallback,
 * etc) and ignores Metro/Expo-managed artefacts.
 *
 * Local additions:
 *   - Ignore stitch/* design dumps (2.9 MB of Stitch-exported HTML)
 *     + src/types/models/base/* (omnify codegen — owned by the
 *     schema, not by hand-editing)
 *   - Warn instead of error on react-hooks/exhaustive-deps because
 *     several payment-flow useEffect calls deliberately omit deps
 *     to enforce single-shot semantics (see app/payment/card.tsx:87
 *     + qr.tsx:126 — both carry inline eslint-disable-next-line
 *     comments with rationale).
 */
const expoConfig = require("eslint-config-expo/flat");

module.exports = [
  // Apply Expo's recommended preset first.
  ...expoConfig,

  // Ignore patterns. flat-config requires this as its own block.
  {
    ignores: [
      "dist/",
      "build/",
      ".expo/",
      "android/",
      "ios/",
      // Stitch design dumps — generated HTML, not hand-edited
      "stitch/",
      // omnify codegen — owned by the schema, regenerated wholesale
      "src/types/models/base/",
      // build artefacts
      "node_modules/",
      "*.config.js",
      "*.config.ts",
    ],
  },

  // Project-specific overrides.
  {
    files: ["**/*.{ts,tsx}"],
    rules: {
      // Several payment-flow useEffects deliberately run once on mount
      // to prevent duplicate payment_id creation. Each carries an
      // inline disable comment with rationale; demoting to warn keeps
      // the protection without forcing the comment in every case.
      "react-hooks/exhaustive-deps": "warn",
      // RN style: prefer explicit anonymous functions for inline handlers
      "@typescript-eslint/no-explicit-any": "warn",
      // RN uses inline styles; turn off the warning for now.
      "react-native/no-inline-styles": "off",
      // React 19 hooks rules are stricter than the existing code's
      // patterns. Demoted to warn so the lint script LANDS as a
      // productivity gate without blocking on 24 pre-existing issues
      // (terminal-provider ref access in useEffect, idle-timer
      // setState in effect, etc). Future PRs see these as warnings
      // + can fix opportunistically. Tracked as follow-up cleanup.
      "react-hooks/set-state-in-effect": "warn",
      "react-hooks/refs": "warn",
      "react-hooks/immutability": "warn",
      // Same posture for react/no-children-prop — codegen template
      // pattern in src/components/ui/* uses children prop.
      "react/no-children-prop": "warn",
      // Empty-interface warnings from the omnify model layer — those
      // files are ignored via the globalIgnore block anyway, but
      // demote for any remaining edge.
      "@typescript-eslint/no-empty-object-type": "warn",
    },
  },
];
