import React from "react";
import { render, screen } from "@testing-library/react";
import { describe, it, expect } from "vitest";
import { useForm } from "react-hook-form";
import { Form, FormField, FormItem, FormLabel, FormMessage } from "../form";
import { Input } from "../input";

function TestForm() {
  const form = useForm({ defaultValues: { name: "" } });
  return (<Form {...form}><form><FormField control={form.control} name="name" render={({ field }) => (<FormItem><FormLabel>Name</FormLabel><Input {...field} /><FormMessage /></FormItem>)} /></form></Form>);
}

describe("Form", () => {
  it("renders form field with label", () => {
    render(<TestForm />);
    expect(screen.getByText("Name")).toBeInTheDocument();
  });
});
