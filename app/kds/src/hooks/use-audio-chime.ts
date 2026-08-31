import { useCallback, useEffect, useRef } from "react";

const CHIME_PATH = "/sounds/new-order.mp3";
const STORAGE_KEY = "kds_audio_enabled";

/**
 * Audio chime hook for new order notifications.
 *
 * Browser autoplay rules: a user gesture is required before any audio plays.
 * Call `unlock()` from a click handler (e.g., "Test sound" button on pairing
 * screen) — Audio.play() is invoked once to satisfy the gesture, then
 * paused immediately. Subsequent play() calls don't need a gesture.
 *
 * If file is missing or browser blocks playback, play() fails silently —
 * visual signal (ticket appearing in grid) remains the primary signal.
 */
export function useAudioChime() {
  const audioRef = useRef<HTMLAudioElement | null>(null);
  const unlockedRef = useRef(false);

  useEffect(() => {
    const a = new Audio(CHIME_PATH);
    a.preload = "auto";
    audioRef.current = a;
    return () => {
      audioRef.current = null;
    };
  }, []);

  const unlock = useCallback(async () => {
    if (unlockedRef.current || !audioRef.current) return;
    try {
      await audioRef.current.play();
      audioRef.current.pause();
      audioRef.current.currentTime = 0;
      unlockedRef.current = true;
    } catch {
      // Browser denied (e.g., no user gesture or file missing) — try again later
    }
  }, []);

  const play = useCallback(() => {
    // Respect user preference (Settings drawer can toggle)
    const enabled = localStorage.getItem(STORAGE_KEY) !== "0";
    if (!enabled || !audioRef.current) return;
    audioRef.current.currentTime = 0;
    audioRef.current.play().catch(() => {
      // Silent fail — visual signal is primary
    });
  }, []);

  return { unlock, play };
}
