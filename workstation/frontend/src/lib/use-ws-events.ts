import { useEffect, useRef } from "react";
import { createWsClient, type WsEvent } from "./ws";

/**
 * Subscribe to workstation WebSocket events.
 * Calls `onEvent` for each broadcast message after auth_ok.
 * Reconnects automatically with exponential backoff.
 *
 * @param token - device token from /api/device/status (pass empty string if not paired)
 * @param onEvent - stable callback (wrap in useCallback if needed)
 * @param eventTypes - optional filter; if provided only matching types trigger onEvent
 */
export function useWsEvents(
  token: string,
  onEvent: (event: WsEvent) => void,
  eventTypes?: string[],
) {
  const onEventRef = useRef(onEvent);
  onEventRef.current = onEvent;

  useEffect(() => {
    if (!token) return; // wait until token is available
    const client = createWsClient((event) => {
      if (eventTypes && !eventTypes.includes(event.type)) return;
      onEventRef.current(event);
    }, token);
    client.connect();
    return () => client.disconnect();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token]); // reconnect if token changes (e.g. after pairing)
}
