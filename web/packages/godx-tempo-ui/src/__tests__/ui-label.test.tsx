import { render, screen } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import { Label } from '@/label';

describe('Label', () => {
  it('renders text content', () => {
    render(<Label>Username</Label>);
    expect(screen.getByText('Username')).toBeInTheDocument();
  });

  it('sets htmlFor attribute', () => {
    render(<Label htmlFor="email">Email</Label>);
    const label = screen.getByText('Email');
    expect(label).toHaveAttribute('for', 'email');
  });

  it('sets data-slot attribute', () => {
    render(<Label>Test</Label>);
    expect(screen.getByText('Test')).toHaveAttribute('data-slot', 'label');
  });

  it('applies custom className', () => {
    render(<Label className="text-red-500">Error Label</Label>);
    expect(screen.getByText('Error Label').className).toContain('text-red-500');
  });

  it('renders children elements', () => {
    render(
      <Label>
        Name <span className="text-red-500">*</span>
      </Label>,
    );
    expect(screen.getByText('Name')).toBeInTheDocument();
    expect(screen.getByText('*')).toBeInTheDocument();
  });
});
