import type { Meta, StoryObj } from "@storybook/nextjs-vite";

import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from "../accordion";

const meta: Meta<typeof Accordion> = {
  title: "UI/Accordion",
  component: Accordion,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "Vertically stacked collapsible sections. `type=\"single\"` opens one at a time (FAQ pattern); `type=\"multiple\"` allows multiple sections open simultaneously.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof Accordion>;

export const Single: Story = {
  render: () => (
    <Accordion type="single" collapsible className="w-96">
      <AccordionItem value="a">
        <AccordionTrigger>What is TempoFast?</AccordionTrigger>
        <AccordionContent>
          A restaurant management platform built with Japanese enterprise density.
        </AccordionContent>
      </AccordionItem>
      <AccordionItem value="b">
        <AccordionTrigger>Is it free?</AccordionTrigger>
        <AccordionContent>
          Free during private beta. Paid tiers will be announced before GA.
        </AccordionContent>
      </AccordionItem>
      <AccordionItem value="c">
        <AccordionTrigger>How do I sign up?</AccordionTrigger>
        <AccordionContent>
          Contact your account manager or visit the website.
        </AccordionContent>
      </AccordionItem>
    </Accordion>
  ),
};

export const Multiple: Story = {
  render: () => (
    <Accordion type="multiple" defaultValue={["a", "b"]} className="w-96">
      <AccordionItem value="a">
        <AccordionTrigger>Section A</AccordionTrigger>
        <AccordionContent>Content A</AccordionContent>
      </AccordionItem>
      <AccordionItem value="b">
        <AccordionTrigger>Section B</AccordionTrigger>
        <AccordionContent>Content B (also open)</AccordionContent>
      </AccordionItem>
    </Accordion>
  ),
};
