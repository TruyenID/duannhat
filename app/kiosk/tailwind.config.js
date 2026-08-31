/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./app/**/*.{js,ts,jsx,tsx}",
    "./src/**/*.{js,ts,jsx,tsx}",
  ],
  presets: [require("nativewind/preset")],
  theme: {
    extend: {
      colors: {
        // Synced from frontend/src/components/ui/internal/theme.css
        // OKLCH → hex converted for React Native compatibility
        // ── betoya brand palette ──────────────────────────────────────
        // Forest Green ~#3E7B4A · Warm Cream ~#F5EFD0 · Near Black ~#1A1A1A
        // Hex approximate from visual inspection — verify DevTools on betoya.jp
        primary: {
          DEFAULT: "#3E7B4A",    // betoya forest green (headline, CTA)
          foreground: "#FFFFFF",
        },
        secondary: {
          DEFAULT: "#FFFFFF",    // betoya white secondary button bg
          foreground: "#1A1A1A",
        },
        destructive: {
          DEFAULT: "#D4183D",
          foreground: "#FFFFFF",
        },
        success: {
          DEFAULT: "#10B981",
          foreground: "#FFFFFF",
        },
        warning: {
          DEFAULT: "#F59E0B",
          foreground: "#FFFFFF",
        },
        info: {
          DEFAULT: "#3B82F6",
          foreground: "#FFFFFF",
        },
        error: {
          DEFAULT: "#EF4444",
          foreground: "#FFFFFF",
        },
        background: "#F5EFD0",   // betoya warm cream (page bg)
        foreground: "#1A1A1A",   // betoya near black (body text)
        muted: {
          DEFAULT: "#EDE7CC",    // cream-tinted muted (warm, not cold gray)
          foreground: "#717182",
        },
        accent: {
          DEFAULT: "#E8E2CC",    // cream-tinted accent
          foreground: "#1A1A1A",
        },
        border: "#C8C0A0",       // betoya warm gray border
        input: "#D4CDB0",        // warm gray input border
        ring: "#3E7B4A",         // focus ring = brand green
        card: {
          DEFAULT: "#FFFFFF",    // cards float on cream bg (white surface)
          foreground: "#1A1A1A",
        },

        // Gray scale
        gray: {
          50: "#F9FAFB",
          100: "#F3F4F6",
          200: "#E5E7EB",
          300: "#D1D5DB",
          400: "#9CA3AF",
          500: "#6B7280",
          600: "#4B5563",
          700: "#374151",
          800: "#1F2937",
          900: "#111827",
        },

        // Blue palette (active states)
        blue: {
          50: "#EFF6FF",
          100: "#DBEAFE",
          500: "#3B82F6",
          600: "#2563EB",
          700: "#1D4ED8",
        },

        // Table status colors
        "table-available": "#10B981",
        "table-occupied": "#F59E0B",
        "table-reserved": "#3B82F6",
        "table-cleaning": "#9B8EC4",
        "table-blocked": "#8B8B8B",
      },
      fontFamily: {
        sans: [
          "Hiragino Sans",
          "Yu Gothic",
          "Noto Sans JP",
          "System",
        ],
      },
    },
  },
  plugins: [],
};
