import { render, act, renderHook } from "@testing-library/react";
import { describe, it, expect, beforeEach } from "vitest";
import { AppProvider } from "../AppProvider";
import { useTheme } from "../use-theme";

beforeEach(() => {
  localStorage.clear();
  document.documentElement.classList.remove("dark");
});

function wrap(children: React.ReactNode) {
  return <AppProvider>{children}</AppProvider>;
}

describe("AppProvider", () => {
  it("defaults to system theme", () => {
    const { result } = renderHook(() => useTheme(), { wrapper: AppProvider });
    expect(result.current.theme).toBe("system");
  });

  it("applies dark class when theme=dark", () => {
    render(wrap(<div>x</div>));
    const { result } = renderHook(() => useTheme(), { wrapper: AppProvider });
    act(() => result.current.setTheme("dark"));
    expect(document.documentElement.classList.contains("dark")).toBe(true);
  });

  it("removes dark class when theme=light", () => {
    document.documentElement.classList.add("dark");
    render(wrap(<div>x</div>));
    const { result } = renderHook(() => useTheme(), { wrapper: AppProvider });
    act(() => result.current.setTheme("light"));
    expect(document.documentElement.classList.contains("dark")).toBe(false);
  });

  it("persists theme to localStorage", () => {
    const { result } = renderHook(() => useTheme(), { wrapper: AppProvider });
    act(() => result.current.setTheme("dark"));
    expect(localStorage.getItem("kds_theme")).toBe("dark");
  });

  it("hydrates from localStorage on mount", () => {
    localStorage.setItem("kds_theme", "light");
    const { result } = renderHook(() => useTheme(), { wrapper: AppProvider });
    expect(result.current.theme).toBe("light");
  });
});
