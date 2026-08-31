import type { Meta, StoryObj } from "@storybook/react-vite";
import { useState } from "react";

import { SlugInput } from "../slug-input";
import { Input } from "../input";
import { Label } from "../label";

const meta: Meta<typeof SlugInput> = {
  title: "Shared/SlugInput",
  component: SlugInput,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "URL slug input that auto-generates from a parent title. Handles Vietnamese diacritics + Latin letters.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof SlugInput>;

export const Default: Story = {
  render: () => {
    const [title, setTitle] = useState("Bít tết cá hồi nướng");
    const [slug, setSlug] = useState("");
    return (
      <div className="flex flex-col gap-3 w-96">
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="title">Title</Label>
          <Input id="title" value={title} onChange={(e) => setTitle(e.target.value)} />
        </div>
        <SlugInput title={title} slug={slug} onSlugChange={setSlug} />
      </div>
    );
  },
};

export const WithError: Story = {
  render: () => {
    const [title] = useState("Hello world");
    const [slug, setSlug] = useState("hello-world");
    return (
      <div className="w-96">
        <SlugInput
          title={title}
          slug={slug}
          onSlugChange={setSlug}
          error="Slug already exists"
        />
      </div>
    );
  },
};
