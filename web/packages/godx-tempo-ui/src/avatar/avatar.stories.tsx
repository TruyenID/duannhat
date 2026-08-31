import type { Meta, StoryObj } from "@storybook/nextjs-vite";

import { Avatar, AvatarFallback, AvatarImage } from "../avatar";

const meta: Meta<typeof Avatar> = {
  title: "UI/Avatar",
  component: Avatar,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "Circular user profile image with graceful fallback to initials when the image fails to load. Compose with `<AvatarImage>` and `<AvatarFallback>` — the fallback renders immediately while the image is loading, preventing layout shift.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof Avatar>;

const VALID_IMG =
  "data:image/svg+xml;utf8," +
  encodeURIComponent(
    `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80">
      <rect width="80" height="80" fill="#4c6cb3"/>
      <text x="50%" y="50%" text-anchor="middle" fill="white" font-family="sans-serif" font-size="32" font-weight="600" dy=".35em">JD</text>
    </svg>`,
  );

export const WithImage: Story = {
  render: () => (
    <Avatar>
      <AvatarImage src={VALID_IMG} alt="Jane Doe" />
      <AvatarFallback>JD</AvatarFallback>
    </Avatar>
  ),
};

export const Fallback: Story = {
  parameters: {
    docs: {
      description: {
        story:
          "When `<AvatarImage>` is missing or fails to load (broken URL), the `<AvatarFallback>` renders. Initials are the conventional fallback content — keep to 2 characters.",
      },
    },
  },
  render: () => (
    <Avatar>
      <AvatarImage src="/this-does-not-exist.jpg" alt="Broken" />
      <AvatarFallback>JD</AvatarFallback>
    </Avatar>
  ),
};

export const Sizes: Story = {
  render: () => (
    <div className="flex items-center gap-3">
      <Avatar className="size-6"><AvatarFallback className="text-[10px]">XS</AvatarFallback></Avatar>
      <Avatar className="size-8"><AvatarFallback className="text-xs">SM</AvatarFallback></Avatar>
      <Avatar><AvatarFallback>MD</AvatarFallback></Avatar>
      <Avatar className="size-12"><AvatarFallback>LG</AvatarFallback></Avatar>
      <Avatar className="size-16"><AvatarFallback className="text-base">XL</AvatarFallback></Avatar>
    </div>
  ),
};

export const Stack: Story = {
  parameters: {
    docs: {
      description: {
        story: "Common pattern: stacked avatars with negative margin overlap, used in collaborator lists.",
      },
    },
  },
  render: () => (
    <div className="flex -space-x-2">
      {["JD", "TT", "MK", "AY"].map((init, i) => (
        <Avatar key={init} className={`ring-2 ring-background ${i === 0 ? "" : "-ml-0"}`}>
          <AvatarFallback>{init}</AvatarFallback>
        </Avatar>
      ))}
      <Avatar className="ring-2 ring-background">
        <AvatarFallback className="bg-muted text-xs text-muted-foreground">+5</AvatarFallback>
      </Avatar>
    </div>
  ),
};
