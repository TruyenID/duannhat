"use client";

/**
 * Global loading overlay context.
 *
 * Use case: async actions that end with a route transition (place order,
 * login, navigate to a heavy page) need a continuous loading indicator
 * across the API call AND the Next.js route mount gap. A button-level
 * spinner stops the moment the originating page unmounts, leaving a
 * blank ~500ms-1s while the next page bundle loads.
 *
 * Pattern:
 *   const { showLoading } = useGlobalLoading();
 *   showLoading();
 *   await apiFetch(...);
 *   router.push("/next-route");
 *   // No need to hide — the overlay auto-dismisses when pathname
 *   // changes (i.e. the next route has mounted).
 *
 * Auto-dismiss key: a `usePathname` effect inside the provider clears
 * `isLoading` whenever the current path changes. So callers MUST NOT call
 * `hideLoading()` after `router.push` — let the path-change effect do it.
 * Call `hideLoading()` explicitly only on error paths where you stay on
 * the same page.
 */

import { createContext, useCallback, useContext, useEffect, useRef, useState } from "react";
import { usePathname } from "next/navigation";
import { Loader2 } from "lucide-react";

interface LoadingContextValue {
	isLoading: boolean;
	showLoading: (message?: string) => void;
	hideLoading: () => void;
}

const LoadingContext = createContext<LoadingContextValue | null>(null);

export function useGlobalLoading(): LoadingContextValue {
	const ctx = useContext(LoadingContext);
	if (!ctx) {
		throw new Error("useGlobalLoading must be used inside LoadingProvider");
	}
	return ctx;
}

export function LoadingProvider({ children }: { children: React.ReactNode }) {
	const [isLoading, setIsLoading] = useState(false);
	const [message, setMessage] = useState<string | undefined>(undefined);
	const pathname = usePathname();
	const lastShownPathRef = useRef<string | null>(null);

	const showLoading = useCallback((msg?: string) => {
		lastShownPathRef.current = pathname;
		setMessage(msg);
		setIsLoading(true);
	}, [pathname]);

	const hideLoading = useCallback(() => {
		setIsLoading(false);
		setMessage(undefined);
	}, []);

	// Auto-dismiss when the pathname changes after the overlay was shown.
	// This is what makes the overlay survive the navigation gap: the caller
	// fires showLoading() before router.push, and we clear it only once the
	// destination route has mounted (pathname updated).
	useEffect(() => {
		if (isLoading && lastShownPathRef.current && pathname !== lastShownPathRef.current) {
			setIsLoading(false);
			setMessage(undefined);
			lastShownPathRef.current = null;
		}
	}, [pathname, isLoading]);

	return (
		<LoadingContext.Provider value={{ isLoading, showLoading, hideLoading }}>
			{children}
			{isLoading && (
				<div
					aria-live="polite"
					aria-busy="true"
					className="fixed inset-0 z-[200] flex items-center justify-center bg-black/30 backdrop-blur-sm"
				>
					<div className="flex flex-col items-center gap-3 rounded-2xl bg-white px-6 py-5 shadow-xl">
						<Loader2 className="size-8 animate-spin text-primary" role="status" aria-label="Loading" />
						{message && (
							<p className="text-sm font-medium text-neutral-700">{message}</p>
						)}
					</div>
				</div>
			)}
		</LoadingContext.Provider>
	);
}
