import type { Preview } from "@storybook/react-vite";
import * as React from "react";
import { UIProvider } from "../src/internal/ui-provider";
import "./preview.css";

const preview: Preview = {
  decorators: [
    (Story) => (
      <UIProvider
        locales={{ ja: "日本語", en: "English", vi: "Tiếng Việt" }}
        defaultLocale="ja"
        fallbackLocale="ja"
      >
        <Story />
      </UIProvider>
    ),
  ],
  parameters: {
    backgrounds: {
      default: "light",
      values: [
        { name: "light", value: "#ffffff" },
        { name: "dark", value: "#0a0a0a" },
      ],
    },
    controls: {
      matchers: {
        color: /(background|color)$/i,
        date: /Date$/i,
      },
    },
    a11y: {
      test: "todo",
    },
  },
};

export default preview;
