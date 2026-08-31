import { Loader2 } from "lucide-react";

interface PageSpinnerProps {
	/** Optional caption rendered below the spinner. */
	message?: string;
	/** Background variant — defaults to neutral page background. */
	variant?: "page" | "card" | "transparent";
	/** Size of the spinner glyph in Tailwind units. Default `10` = 40px. */
	size?: 6 | 8 | 10 | 12;
}

/**
 * Canonical fullscreen loading spinner used by every customer-web
 * `loading.tsx` boundary. Centralised so the brand-green color
 * (`text-primary`, oklch 0.54 0.14 145) stays in lock-step across every
 * Suspense / route-transition surface — no more drift between
 * `text-emerald-600` (`#16A34A`) and the OKLCH design-system primary.
 *
 * The `LoadingProvider` overlay (`context/loading-context.tsx`) renders a
 * matching spinner inside a white card on a dimmed backdrop so the two
 * surfaces feel like the same UI.
 */
export function PageSpinner({ message, variant = "page", size = 10 }: PageSpinnerProps) {
	const bg =
		variant === "page"
			? "bg-[#FAFAFA]"
			: variant === "card"
				? "bg-muted/30"
				: "bg-transparent";

	// Tailwind JIT requires class names to appear literally so the
	// arbitrary `size-${size}` template wouldn't survive purging.
	const sizeClass = (
		{
			6: "size-6",
			8: "size-8",
			10: "size-10",
			12: "size-12",
		} as const
	)[size];

	return (
		<div className={`flex min-h-screen flex-col items-center justify-center gap-3 ${bg}`}>
			<Loader2
				className={`${sizeClass} animate-spin text-primary`}
				role="status"
				aria-label="Loading"
			/>
			{message && <p className="text-sm text-neutral-600">{message}</p>}
		</div>
	);
}
