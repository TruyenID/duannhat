/**
 * Minimal `react-native` stand-in for unit tests (see vitest.config.ts).
 * Only the surface the shell's pure logic touches lives here — anything more
 * would be a second, drifting copy of the RN runtime.
 */
export const Platform = { OS: "ios" as "ios" | "android" | "web" };
