import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi } from 'vitest';
import { RadioGroup, RadioGroupItem } from '@/radio-group';
import { Label } from '@/label';

describe('RadioGroup', () => {
  it('renders radio items', () => {
    render(
      <RadioGroup>
        <RadioGroupItem value="a" />
        <RadioGroupItem value="b" />
      </RadioGroup>,
    );
    const radios = screen.getAllByRole('radio');
    expect(radios).toHaveLength(2);
  });

  it('selects default value', () => {
    render(
      <RadioGroup defaultValue="b">
        <RadioGroupItem value="a" />
        <RadioGroupItem value="b" />
      </RadioGroup>,
    );
    const radios = screen.getAllByRole('radio');
    expect(radios[1]).toBeChecked();
    expect(radios[0]).not.toBeChecked();
  });

  it('calls onValueChange when selection changes', async () => {
    const onChange = vi.fn();
    render(
      <RadioGroup onValueChange={onChange}>
        <RadioGroupItem value="a" />
        <RadioGroupItem value="b" />
      </RadioGroup>,
    );
    await userEvent.click(screen.getAllByRole('radio')[1]);
    expect(onChange).toHaveBeenCalledWith('b');
  });

  it('renders with labels', () => {
    render(
      <RadioGroup>
        <div className="flex items-center gap-2">
          <RadioGroupItem value="light" id="light" />
          <Label htmlFor="light">Light</Label>
        </div>
        <div className="flex items-center gap-2">
          <RadioGroupItem value="dark" id="dark" />
          <Label htmlFor="dark">Dark</Label>
        </div>
      </RadioGroup>,
    );
    expect(screen.getByText('Light')).toBeInTheDocument();
    expect(screen.getByText('Dark')).toBeInTheDocument();
  });

  it('sets data-slot on group', () => {
    const { container } = render(
      <RadioGroup>
        <RadioGroupItem value="a" />
      </RadioGroup>,
    );
    expect(container.querySelector('[data-slot="radio-group"]')).toBeInTheDocument();
  });
});
