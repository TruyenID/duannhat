// app/split/_layout.tsx
import { Stack } from "expo-router";

export default function SplitLayout() {
  // gestureEnabled: false — the split flow loops with router.replace, so older
  // (already-paid) slip screens stay underneath in history. Disabling the
  // swipe-back gesture stops a user from sliding back into a stale, pre-payment
  // state after a slip is settled. In-screen back buttons route explicitly.
  return (
    <Stack
      screenOptions={{
        headerShown: false,
        animation: "fade",
        gestureEnabled: false,
      }}
    />
  );
}
