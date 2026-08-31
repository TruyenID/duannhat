import type { Meta, StoryObj } from "@storybook/nextjs-vite";
import { useForm } from "react-hook-form";

import {
  Form,
  FormControl,
  FormDescription,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "../form";
import { Input } from "../input";
import { Button } from "../button";

const meta: Meta<typeof Form> = {
  title: "UI/Form",
  component: Form,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "react-hook-form integration with field-level validation messages. Compose `<Form>` (provider) → `<FormField>` → `<FormItem>` → `<FormLabel>` + `<FormControl>` + `<FormMessage>`. Errors render automatically from RHF state.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof Form>;

interface FormValues {
  email: string;
  username: string;
}

export const Default: Story = {
  render: () => {
    const form = useForm<FormValues>({
      defaultValues: { email: "", username: "" },
    });
    return (
      <Form {...form}>
        <form
          onSubmit={form.handleSubmit((v) => alert(JSON.stringify(v, null, 2)))}
          className="w-80 space-y-4"
        >
          <FormField
            control={form.control}
            name="email"
            rules={{ required: "Email is required" }}
            render={({ field }) => (
              <FormItem>
                <FormLabel>Email</FormLabel>
                <FormControl>
                  <Input type="email" placeholder="you@example.com" {...field} />
                </FormControl>
                <FormDescription>We&apos;ll never share your email.</FormDescription>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="username"
            rules={{
              required: "Username is required",
              minLength: { value: 3, message: "Min 3 characters" },
            }}
            render={({ field }) => (
              <FormItem>
                <FormLabel>Username</FormLabel>
                <FormControl>
                  <Input placeholder="satoshi01" {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <Button type="submit">Submit</Button>
        </form>
      </Form>
    );
  },
};
