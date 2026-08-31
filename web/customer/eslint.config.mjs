import { defineConfig, globalIgnores } from "eslint/config";
import nextVitals from "eslint-config-next/core-web-vitals";
import nextTs from "eslint-config-next/typescript";

const eslintConfig = defineConfig([
  ...nextVitals,
  ...nextTs,
  // #1201 — 84 console.log statements had shipped to the customer app, one of
  // which dumped a guest's whole order history into the browser console on
  // every read. `warn`/`error`/`info` stay allowed: they report real problems,
  // or (for `info`) sit behind a `process.env.NODE_ENV !== "production"` fence
  // that the production build strips. Plain `console.log` is debug scaffolding
  // and has no place in shipped code.
  {
    rules: {
      // `info` dropped from the allow-list (#1259): console.info carries
      // anything console.log can, and the NODE_ENV fence the comment above
      // describes is a convention, not something the rule enforces. There are
      // no console.info calls left, so this costs nothing and closes the door.
      "no-console": ["error", { allow: ["warn", "error"] }],
      // Raw fetch bypasses apiFetch, which stamps Accept-Language and throws the
      // shared error shape. A missing Accept-Language makes the backend return
      // default-locale strings — Vietnamese guests quietly shown Japanese, which
      // has happened twice. Every other app in the monorepo carries this rule.
      "no-restricted-globals": [
        "error",
        {
          name: "fetch",
          message:
            "Use apiFetch from @/lib/api instead of raw fetch(). apiFetch centralises Accept-Language, auth and the shared error shape.",
        },
      ],
    },
  },
  {
    // lib/api.ts IS the wrapper. The other exception, the takeaway metadata
    // layout, carries an inline disable instead: its path contains [locale],
    // and square brackets are a character class to minimatch, so a `files` glob
    // naming it silently matches nothing.
    files: ["lib/api.ts"],
    rules: { "no-restricted-globals": "off" },
  },
  // Override default ignores of eslint-config-next.
  globalIgnores([
    // Default ignores of eslint-config-next:
    ".next/**",
    "out/**",
    "build/**",
    "next-env.d.ts",
  ]),
]);

export default eslintConfig;
