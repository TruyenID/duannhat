import React from "react";
import { render, screen, fireEvent } from "@testing-library/react";
import { Button } from "../components/button";
import { Text } from "../components/text";

describe("Button", () => {
  it("renders with text child", () => {
    render(
      <Button>
        <Text>Press me</Text>
      </Button>,
    );
    expect(screen.getByText("Press me")).toBeTruthy();
  });

  it("calls onPress when pressed", () => {
    const onPress = jest.fn();
    render(
      <Button onPress={onPress}>
        <Text>Click</Text>
      </Button>,
    );
    fireEvent.click(screen.getByText("Click"));
    expect(onPress).toHaveBeenCalledTimes(1);
  });

  it("does not call onPress when disabled", () => {
    const onPress = jest.fn();
    render(
      <Button onPress={onPress} disabled>
        <Text>Disabled</Text>
      </Button>,
    );
    fireEvent.click(screen.getByText("Disabled"));
    expect(onPress).not.toHaveBeenCalled();
  });

  it("renders with role button", () => {
    render(
      <Button>
        <Text>Action</Text>
      </Button>,
    );
    expect(screen.getByRole("button")).toBeTruthy();
  });
});
