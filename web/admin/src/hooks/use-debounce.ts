import { useEffect, useState } from "react";

/**
 * Defers updating `value` until `delay` ms have passed without it changing.
 * Use for search boxes that shouldn't fire a query on every keystroke.
 */
export function useDebounce<T>(value: T, delay: number): T {
  const [debounced, setDebounced] = useState(value);
  useEffect(() => {
    const timer = setTimeout(() => setDebounced(value), delay);
    return () => clearTimeout(timer);
  }, [value, delay]);
  return debounced;
}
