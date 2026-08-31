"use client";

/**
 * plan-034 — subscribe to the public Reverb channel
 * `table-session.{sessionId}` so every device in the same dine-in session
 * sees order mutations + POS edit-lock changes in real time.
 *
 * The channel is intentionally PUBLIC: customer-web is a guest flow with
 * no authenticated user, and the sessionId is a UUID v4 that expires
 * on payment / 4h timeout — that's the security model.
 *
 * Events we react to (see backend `app/Events/`):
 *
 *   - `order.item-added`       → cart-state stale, refetch order
 *   - `order.editing-started`  → POS is touching the order, set lock
 *   - `order.editing-ended`    → POS released, clear lock
 */

import { useEffect, useLayoutEffect, useRef, useState } from "react";

/**
 * `useLayoutEffect` trên trình duyệt, `useEffect` khi render phía server.
 *
 * Next.js render client component ở server cho HTML đầu tiên, và
 * `useLayoutEffect` cảnh báo ở đó ("does nothing on the server"). Trên server
 * lại không có socket lẫn timer nào để chen vào giữa render và effect, nên hạ
 * xuống `useEffect` ở đó không mất gì — chỉ tắt một cảnh báo vô nghĩa.
 */
const useIsomorphicLayoutEffect =
	typeof window !== "undefined" ? useLayoutEffect : useEffect;

interface TableSessionEvents {
	/** Called when any device in the session adds an item. */
	onItemAdded?: () => void;
	/** Called when POS staff acquires the edit soft-lock. */
	onEditingStarted?: () => void;
	/** Called when POS staff releases the edit soft-lock. */
	onEditingEnded?: () => void;
}

interface TableSessionRealtimeState {
	connected: boolean;
	editingByStaff: boolean;
}

export function useTableSessionRealtime(
	sessionId: string | null | undefined,
	handlers: TableSessionEvents = {},
): TableSessionRealtimeState {
	// Handler mới nhất, đọc trong callback của WebSocket — socket sống qua nhiều
	// lần render nên phải đi qua ref, không đóng gói handler của lần render đầu.
	//
	// `useLayoutEffect`, KHÔNG phải gán thẳng trong render và cũng không phải
	// `useEffect` (#2602):
	//
	//   gán trong render — `react-hooks/refs` chặn.
	//   useEffect        — bị HOÃN, nên một message WebSocket đến giữa render và
	//                      effect sẽ gọi handler của lượt TRƯỚC.
	//   useLayoutEffect  — đồng bộ ngay sau commit, cùng task, trước khi trình
	//                      duyệt kịp giao message nào ⇒ ngữ nghĩa y hệt bản cũ.
	//
	// Khác pos-web: đây là Next.js, và client component VẪN được render phía
	// server cho HTML đầu tiên. `useLayoutEffect` thuần sẽ cảnh báo ở đó, nên
	// chọn theo môi trường. Trên server không có socket lẫn timer nào để chen
	// vào, nên hạ xuống `useEffect` ở đó không mất gì.
	const handlersRef = useRef(handlers);
	useIsomorphicLayoutEffect(() => {
		handlersRef.current = handlers;
	});

	const [connected, setConnected] = useState(false);
	const [editingByStaff, setEditingByStaff] = useState(false);

	useEffect(() => {
		if (!sessionId) return;
		if (typeof window === "undefined") return;

		let echo: import("laravel-echo").default<"pusher"> | null = null;
		let cancelled = false;

		const setup = async () => {
			try {
				const reverbConfig = {
					key: process.env.NEXT_PUBLIC_REVERB_APP_KEY ?? "tempo-local-key",
					host:
						process.env.NEXT_PUBLIC_REVERB_HOST ??
						(typeof window !== "undefined" ? window.location.hostname : "localhost"),
					port: Number(process.env.NEXT_PUBLIC_REVERB_PORT ?? 443),
					scheme: process.env.NEXT_PUBLIC_REVERB_SCHEME ?? "https",
				};

				const Echo = (await import("laravel-echo")).default;
				const Pusher = (await import("pusher-js")).default;

				(window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher;

				if (cancelled) return;

				echo = new Echo({
					broadcaster: "pusher",
					key: reverbConfig.key,
					cluster: "mt1",
					wsHost: reverbConfig.host,
					wsPort: reverbConfig.port,
					wssPort: reverbConfig.port,
					forceTLS: reverbConfig.scheme === "https",
					enabledTransports: ["ws", "wss"],
					disableStats: true,
				});

				const channel = echo.channel(`table-session.${sessionId}`);

				channel.listen(".order.item-added", () => {
					handlersRef.current.onItemAdded?.();
				});

				channel.listen(".order.editing-started", () => {
					setEditingByStaff(true);
					handlersRef.current.onEditingStarted?.();
				});

				channel.listen(".order.editing-ended", () => {
					setEditingByStaff(false);
					handlersRef.current.onEditingEnded?.();
				});

				// Pusher connection state.
				const pusher = (echo.connector as unknown as { pusher: import("pusher-js").default })
					.pusher;
				pusher.connection.bind("connected", () => setConnected(true));
				pusher.connection.bind("disconnected", () => setConnected(false));
				pusher.connection.bind("error", () => setConnected(false));
			} catch (err) {
				console.warn("[useTableSessionRealtime] connect failed:", err);
			}
		};

		void setup();

		return () => {
			cancelled = true;
			try {
				echo?.leave(`table-session.${sessionId}`);
				echo?.disconnect();
			} catch {
				// noop
			}
		};
	}, [sessionId]);

	return { connected, editingByStaff };
}
