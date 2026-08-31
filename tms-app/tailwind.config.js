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
        primary: {
          DEFAULT: "#030213",
          foreground: "#FFFFFF",
        },
        secondary: {
          DEFAULT: "#ECEEF2",
          foreground: "#030213",
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
        background: "#FFFFFF",
        foreground: "#0A0A0A",
        muted: {
          DEFAULT: "#ECECF0",
          foreground: "#717182",
        },
        accent: {
          DEFAULT: "#E9EBEF",
          foreground: "#030213",
        },
        border: "#E5E5E5",       // rgba(0,0,0,0.1) on white
        input: "#D9D9D9",        // rgba(0,0,0,0.15) on white
        ring: "#A1A1A1",
        card: {
          DEFAULT: "#FFFFFF",
          foreground: "#0A0A0A",
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
