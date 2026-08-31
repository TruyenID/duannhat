---
title: Setup KDS Device
category: guide
tags: [setup, kds, kitchen, hardware, pairing, pwa]
summary: How to set up a Kitchen Display System (KDS) tablet for a restaurant branch. Device creation, pairing, PWA installation, sound/audio setup, troubleshooting, and hardware requirements.
related:
  - guide/setup-docker.md
---

# Setup KDS Device

Step-by-step guide for support teams to provision and troubleshoot TempoFast Kitchen Display System (KDS) tablets in restaurants.

KDS is a tablet app (iPad or Android) that displays kitchen orders in realtime. Kitchen staff use KDS to track item preparation progress: move items through states (cook → ready → served) with a tap.

## Prerequisites

**Hardware:**
- iPad (Safari 16.4+) or Android tablet (Chrome 90+)
- Stable shop WiFi connection (same as workstation + POS)
- Optional: external speaker or headphones for audio alerts

**Software/Infrastructure:**
- TempoFast admin-web already running (for device creation)
- Workstation app running on the same shop network (for LAN sync, optional but recommended)
- Cloud API accessible (as fallback if workstation unreachable)

**User:**
- Kitchen staff member with the tablet
- Trained on KDS bump workflow

## Step 1: Create KDS Device in Admin Web

**Who**: Shop manager or HQ admin (using admin-web).

1. Log in to admin-web (`http://localhost:5430` or cloud instance)
2. Navigate to **Admin** → **Devices** (or shop-specific devices section)
3. Click **Add New Device**
   - **Type**: KDS (Kitchen Display System)
   - **Branch**: Select the branch/restaurant for this tablet
   - **Name** (optional): e.g., "Kitchen Display 1" (helps staff identify device)
4. Click **Create**
5. Cloud generates a **6-character pairing code** (e.g., `A1B2C3`)
   - Valid for 15 minutes
   - Single-use (can't pair twice with same code)
   - Display on tablet or write on paper for staff

## Step 2: Install KDS Web App on Tablet

**Who**: Kitchen staff member.

1. On the tablet, open a web browser:
   - **iOS**: Safari (not Chrome, Chrome doesn't support PWA install)
   - **Android**: Chrome or Samsung Internet
2. Navigate to the KDS URL:
   - **Cloud**: `https://<your-instance>.tempo-fast.com/kds`
   - **Local dev**: `http://localhost:5460` (if running locally)
3. You should see a **pairing form** with a text field for the pairing code

## Step 3: Pair the Device

**Who**: Kitchen staff member.

1. On the pairing form, enter the **6-character code** (from Step 1)
   - Not case-sensitive, can skip spaces
   - Example: `a1 b2 c3` or `A1B2C3` both work
2. Click **Pair** button
3. If pairing succeeds:
   - Device token is saved to browser storage
   - Redirect to **dashboard** (shows current orders)
4. If pairing fails:
   - Error message: "Invalid code" — check code is correct + within 15 min
   - Error message: "Device already paired" — code was used once, request new code from admin
   - Error message: "Network error" — tablet not connected to WiFi

## Step 4: Install PWA (Optional but Recommended)

**Who**: Kitchen staff member.

Installing KDS as a PWA (Progressive Web App) adds an app icon to the home screen for quicker access and fullscreen mode.

### iOS (iPad)

1. With KDS dashboard open in Safari
2. Tap the **Share** button (square with arrow, bottom toolbar)
3. Select **Add to Home Screen**
4. Confirm the app name and icon
5. Tap **Add**
6. KDS now appears as an app icon on the home screen
7. Tap the icon to launch in fullscreen (no browser chrome)

### Android (Tablet)

1. With KDS dashboard open in Chrome
2. Look for the **Install** prompt in the address bar (may say "Install app" or show an icon)
   - If no prompt: tap the **menu** (3 dots) → **Install app**
3. Confirm the app name
4. KDS now appears as an app icon on the home screen
5. Tap the icon to launch in fullscreen

## Step 5: Enable Audio Alerts (Required for Sound)

**Who**: Kitchen staff member.

KDS plays a chime sound when new orders arrive. To ensure audio works:

1. On KDS dashboard, tap the **Settings** icon (gear ⚙️) in the top-right corner
2. Look for **"Test Sound"** button
3. Tap it once — you should hear a chime
4. If you **hear the sound**: audio is working ✓
5. If you **don't hear it**:
   - Check tablet volume is not muted (physical switch on side of iPad)
   - Check WiFi/app permissions allow audio (iOS: Settings → Privacy → Microphone + Sound)
   - Try again after restarting the app

**Note**: First time a browser plays audio, Apple/Android requires a user gesture (tap, scroll, etc.). The test button provides this gesture.

## Step 6: Verify Dashboard

**Who**: Kitchen staff member.

1. Return to the main KDS dashboard (tap close or back from settings)
2. Verify you see:
   - **Kitchen order tickets** (if there are active orders)
   - **Order count** at the top
   - **Connection badge** (top-right corner, showing "LAN" or "Cloud")
   - **Empty state** message if no orders yet (this is normal)

**What does the connection badge mean?**
- **"Connection: LAN"** — Tablet is connected to workstation (fast, offline-capable)
- **"Connection: Cloud"** — Tablet is using cloud direct (workstation unreachable or offline)
- **"Connecting..."** — Establishing realtime connection, wait a few seconds

## Step 7: Test Workflow

**Who**: Kitchen staff member + another staff member (to create a test order).

1. Using the POS or admin-web, create a **test order** with 1-2 items
2. KDS dashboard should **update instantly** with the new order (realtime)
3. Tap an item to see the status buttons:
   - **Cook** (default) — staff is starting to prepare
   - **Ready** — item is ready for pickup
   - **Served** — item delivered to customer
4. Tap through each state to verify buttons work
5. Other KDS tablets should **see the status change immediately**

**If order doesn't appear:**
- Check WiFi connection (ping the workstation: terminal → `ping workstation.local`)
- Check connection badge — if "Cloud" and you're still offline, wait 30s for auto-fallback
- Hard refresh: tap the KDS app icon again or reload in browser

## Step 8: Adjust Settings

**Who**: Kitchen staff member.

1. Tap **Settings** (gear ⚙️)
2. Available options:
   - **Theme**: Light or Dark mode (restaurant preference)
   - **Connection Mode**: "Auto" (default, uses LAN then cloud) or "Cloud" (force cloud, bypass LAN)
   - **Language**: ja, en, vi (based on shop staffing)
3. Close settings to apply

## Troubleshooting

### Connection Badge Shows "Cloud" Persistently

**Problem**: KDS always says "Connection: Cloud", never shows "Connection: LAN".

**Likely cause**: Workstation is unreachable (powered off, wrong network, network cable unplugged).

**Fix**:
1. Verify workstation is powered on and connected to shop WiFi
2. On a different device (laptop, phone), try to ping workstation: `ping workstation.local` (or ask your IT)
3. If workstation is unreachable, it's a network issue — contact IT
4. Workstation unreachable for >30s triggers auto-fallback to cloud, which is normal operation

**Is this a problem?** Not immediately — cloud is slower but functional. Long-term, fix the workstation network.

### Token Revoked — Tablet Returns to Pairing Screen

**Problem**: KDS was working, then suddenly jumped to the pairing form. Message: "Device unpaired."

**Likely cause**: Admin revoked the device token in admin-web (e.g., replacing a tablet).

**Fix**:
1. Ask admin to create a **new pairing code** for this tablet
2. Admin opens admin-web → Devices → find the device record → regenerate code (or delete + re-create)
3. Staff enters the new code on the pairing form
4. KDS re-pairs and returns to dashboard

**If the device record is gone:**
- Admin needs to create a fresh device (Step 1)

### Audio Not Playing

**Problem**: KDS shows new orders but no chime sounds.

**Causes & fixes**:

1. **Muted device** (iPad)
   - Check the physical **mute switch** on the side of the iPad (flip it to unmute)
   - Or check Settings → Sound & Haptics → Volume (is it at 0?)

2. **Microphone permission denied**
   - iOS: Settings → Privacy → Microphone → Allow [KDS app]
   - Android: Settings → Apps → [KDS app] → Permissions → Microphone/Audio

3. **Audio placeholder**
   - Current KDS uses a placeholder sound file (will be replaced with proper chime)
   - If you hear nothing even after testing, try external speaker (may be hardware issue)

4. **Auto-silence on**
   - iOS: Focus mode or Do Not Disturb is on → Settings → Focus → Turn off

**Test again:**
- Settings → Test Sound button
- If still silent, restart the app or try on a different tablet

### App Stuck on "Offline Snapshot" Banner

**Problem**: Amber banner shows "Offline snapshot" and won't disappear, even though WiFi is connected.

**Likely cause**: App has stale cache and hasn't reconnected.

**Fix**:
1. **Force-quit the KDS app:**
   - iOS: Swipe up from bottom (PWA) or close browser tab
   - Android: Swipe app up from recent apps or close Chrome
2. **Reopen KDS** (tap home screen icon or re-visit URL)
3. App should reconnect, banner should disappear within 5s

**If it persists:**
- Check WiFi is actually working (open another website)
- Try toggling WiFi off/on
- Try "Cloud" mode in settings (bypass LAN)

### Orders List Empty

**Problem**: KDS shows no orders, even though POS created them.

**Likely causes**:

1. **No active orders for this branch** — orders are already marked "paid" or "served"
   - Check POS to confirm there are open orders

2. **Wrong branch selected** — device was created for Branch A, but orders are in Branch B
   - Verify device and order are in same branch/restaurant

3. **Network latency** — orders are in system but haven't synced to tablet yet
   - Wait 5-10 seconds, then pull down to refresh (if supported)

4. **Old app version** — cached data doesn't match server
   - Hard refresh (reload in browser) or restart PWA

**To verify orders exist:**
- Check workstation app (should show orders)
- Check admin-web (orders list in shop view)
- If neither shows orders, order wasn't created or was already paid

### Tablet Freezes or Crashes

**Problem**: KDS app becomes unresponsive or closes unexpectedly.

**Causes & fixes**:

1. **Browser memory leak** (rare)
   - Restart the app (close browser tab / quit PWA)
   - If it happens daily, upgrade to latest browser version (iOS Safari or Chrome)

2. **Low device storage**
   - On tablet: Settings → Storage, check free space (need >500 MB)
   - Clear browser cache (Settings → Safari/Chrome → Clear history + cache)

3. **Too many orders in memory**
   - Older tickets should be marked "served" to clean up
   - Current session should have <50 open orders for smooth performance

4. **Network overload**
   - If WiFi is shared with many devices (POS terminals, customers), bandwidth may be congested
   - Separate KDS to a dedicated access point if possible

**Hard recovery:**
1. Force-quit the app
2. Restart tablet (full power off/on)
3. Reopen KDS

### "Connection: Cloud" When Workstation Is Online

**Problem**: Badge says "Cloud" but workstation is up and responding.

**Likely cause**: KDS was set to "Cloud" mode in settings, or a recent failover cached the preference.

**Fix**:
1. Open **Settings**
2. Check **Connection Mode** dropdown
3. If it says "Cloud" → change to "Auto"
4. Close settings, dashboard should reconnect to LAN within 5s

## Hardware Checklist

Before deploying KDS tablets to a restaurant:

- [ ] **iPad or Android tablet** with 128+ GB storage (avoid 32 GB if possible)
- [ ] **Tablet browser version**: iOS Safari 16.4+ or Android Chrome 90+
- [ ] **WiFi connectivity**: Tablet joins shop WiFi (same network as workstation + POS)
- [ ] **Network test**: Ping workstation from tablet (IT can verify)
- [ ] **Speaker/audio**: External speaker OR tablet speaker at full volume (test with Test Sound button)
- [ ] **Display zoom**: Browser zoom set to 100% (KDS sets viewport but user can override; reset if too small/large)
- [ ] **Auto-lock disabled** (if on WiFi only, no LTE): iOS Settings → Display & Brightness → Auto-Lock → Never (so screen stays on during service)
  - *Note*: Phase 6+ Wake Lock API prevents auto-lock on modern browsers (iOS 16.4+), but older Safari may still sleep
- [ ] **Fullscreen PWA installed** (optional but recommended for clean UX — removes browser chrome)
- [ ] **Staff trained on pairing + bump workflow** (see Step 3-7)
- [ ] **Test with real orders**: Create 2-3 orders in POS, verify they appear on KDS, bump through states

## See Also

- [KDS Architecture](../../app/kds/docs/ARCHITECTURE.md) — System design (LAN + cloud topology)
- [KDS Flow Diagrams](../../app/kds/docs/FLOW_DIAGRAMS.md) — ASCII diagrams for pairing, bump, failover, offline
- [KDS Integration Gaps](../../app/kds/docs/INTEGRATION_GAPS.md) — Known issues and deferred features
- [Setup with Docker](./setup-docker.md) — Local dev environment setup
- Umbrella [KDS Domain](../explanation/kds-domain.md) — Item lifecycle and state machine concepts
