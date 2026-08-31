import type { Meta, StoryObj } from "@storybook/nextjs-vite";

import { HoverCard, HoverCardContent, HoverCardTrigger } from "../hover-card";
import { Avatar, AvatarFallback } from "../avatar";

const meta: Meta<typeof HoverCard> = {
  title: "UI/HoverCard",
  component: HoverCard,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "Rich-content version of `<Tooltip>` — appears on hover but shows structured content (avatar, name, bio) instead of a single text label. Common for @mention previews and user cards.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof HoverCard>;

export const Default: Story = {
  render: () => (
    <HoverCard openDelay={0}>
      <HoverCardTrigger asChild>
        <button className="text-primary underline">@satoshi01</button>
      </HoverCardTrigger>
      <HoverCardContent>
        <div className="flex gap-3">
          <Avatar><AvatarFallback>SO</AvatarFallback></Avatar>
          <div>
            <div className="text-sm font-medium">satoshi01</div>
            <p className="text-xs text-muted-foreground">Engineer at TempoFast. Built the design foundation system in 2026.</p>
          </div>
        </div>
      </HoverCardContent>
    </HoverCard>
  ),
};
