import { createContext, useContext } from "react";

export type Theme = "light" | "dark" | "system";

export interface ThemeCtx {
  theme: Theme;
  setTheme: (t: Theme) => void;
}

export const ThemeContext = createContext<ThemeCtx | null>(null);

export function useTheme(): ThemeCtx {
  const c = useContext(ThemeContext);
  if (!c) throw new Error("useTheme must be used inside AppProvider");
  return c;
}
