import type { Meta, StoryObj } from "@storybook/nextjs-vite";

import {
  Carousel,
  CarouselContent,
  CarouselItem,
  CarouselNext,
  CarouselPrevious,
} from "../carousel";

const meta: Meta<typeof Carousel> = {
  title: "UI/Carousel",
  component: Carousel,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "Embla-powered carousel for image galleries / product hero rotators. Use `<CarouselPrevious>` / `<CarouselNext>` for keyboard + mouse navigation. For touch swipe, embla handles it natively.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof Carousel>;

export const Default: Story = {
  render: () => (
    <Carousel className="w-72">
      <CarouselContent>
        {Array.from({ length: 5 }, (_, i) => (
          <CarouselItem key={i}>
            <div className="aspect-video flex items-center justify-center rounded-md bg-muted text-2xl font-medium">
              Slide {i + 1}
            </div>
          </CarouselItem>
        ))}
      </CarouselContent>
      <CarouselPrevious />
      <CarouselNext />
    </Carousel>
  ),
};

export const MultiVisible: Story = {
  parameters: {
    docs: {
      description: {
        story: "Show multiple items per page via `basis-1/3` on each `<CarouselItem>` — common gallery pattern.",
      },
    },
  },
  render: () => (
    <Carousel className="w-96">
      <CarouselContent className="-ml-2">
        {Array.from({ length: 8 }, (_, i) => (
          <CarouselItem key={i} className="pl-2 basis-1/3">
            <div className="aspect-square flex items-center justify-center rounded-md bg-muted text-sm">
              {i + 1}
            </div>
          </CarouselItem>
        ))}
      </CarouselContent>
      <CarouselPrevious />
      <CarouselNext />
    </Carousel>
  ),
};
