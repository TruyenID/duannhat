import type { Meta, StoryObj } from "@storybook/nextjs-vite";

import { AspectRatio } from "../aspect-ratio";

const meta: Meta<typeof AspectRatio> = {
  title: "UI/AspectRatio",
  component: AspectRatio,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "Generic aspect-ratio container (Radix). For decorative ratios in our system see Foundations / Aspect Ratios — including 白銀比 (silver, 1.4142) for culturally Japanese feel.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof AspectRatio>;

const PLACEHOLDER =
  "data:image/svg+xml;utf8," +
  encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 600 400"><rect width="600" height="400" fill="#4c6cb3"/></svg>`);

export const Video: Story = {
  render: () => (
    <div className="w-80">
      <AspectRatio ratio={16 / 9}>
        <img src={PLACEHOLDER} alt="" className="size-full rounded-md object-cover" />
      </AspectRatio>
    </div>
  ),
};

export const Square: Story = {
  render: () => (
    <div className="w-48">
      <AspectRatio ratio={1}>
        <img src={PLACEHOLDER} alt="" className="size-full rounded-md object-cover" />
      </AspectRatio>
    </div>
  ),
};

export const Silver: Story = {
  parameters: {
    docs: { story: { title: "白銀比 1.4142" } },
  },
  render: () => (
    <div className="w-72">
      <AspectRatio ratio={1.4142}>
        <img src={PLACEHOLDER} alt="" className="size-full rounded-md object-cover" />
      </AspectRatio>
    </div>
  ),
};
