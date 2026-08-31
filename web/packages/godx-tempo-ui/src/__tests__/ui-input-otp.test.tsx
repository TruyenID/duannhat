import { render, screen } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import { InputOTP, InputOTPGroup, InputOTPSlot, InputOTPSeparator } from '@/input-otp';

describe('InputOTP', () => {
  it('renders OTP input with correct number of slots', () => {
    render(
      <InputOTP maxLength={6}>
        <InputOTPGroup>
          <InputOTPSlot index={0} />
          <InputOTPSlot index={1} />
          <InputOTPSlot index={2} />
        </InputOTPGroup>
        <InputOTPSeparator />
        <InputOTPGroup>
          <InputOTPSlot index={3} />
          <InputOTPSlot index={4} />
          <InputOTPSlot index={5} />
        </InputOTPGroup>
      </InputOTP>,
    );
    const slots = document.querySelectorAll('[data-slot="input-otp-slot"]');
    expect(slots.length).toBe(6);
  });

  it('renders separator between groups', () => {
    render(
      <InputOTP maxLength={4}>
        <InputOTPGroup>
          <InputOTPSlot index={0} />
          <InputOTPSlot index={1} />
        </InputOTPGroup>
        <InputOTPSeparator />
        <InputOTPGroup>
          <InputOTPSlot index={2} />
          <InputOTPSlot index={3} />
        </InputOTPGroup>
      </InputOTP>,
    );
    const separator = document.querySelector('[data-slot="input-otp-separator"]');
    expect(separator).toBeInTheDocument();
  });

  it('renders group container', () => {
    render(
      <InputOTP maxLength={3}>
        <InputOTPGroup>
          <InputOTPSlot index={0} />
          <InputOTPSlot index={1} />
          <InputOTPSlot index={2} />
        </InputOTPGroup>
      </InputOTP>,
    );
    const group = document.querySelector('[data-slot="input-otp-group"]');
    expect(group).toBeInTheDocument();
  });

  it('applies containerClassName', () => {
    const { container } = render(
      <InputOTP maxLength={2} containerClassName="my-otp">
        <InputOTPGroup>
          <InputOTPSlot index={0} />
          <InputOTPSlot index={1} />
        </InputOTPGroup>
      </InputOTP>,
    );
    expect(container.querySelector('.my-otp')).toBeInTheDocument();
  });
});
