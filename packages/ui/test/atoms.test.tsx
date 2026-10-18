import { describe, expect, it } from 'vitest';
import { render, screen } from './test-utils';
import { runAxe } from './axe';
import { Avatar, AvatarFallback, AvatarImage } from '../src/components/avatar';
import { Badge } from '../src/components/badge';
import { Kbd } from '../src/components/kbd';
import { Separator } from '../src/components/separator';
import { Skeleton } from '../src/components/skeleton';

describe('Badge', () => {
  it('renders every status colour variant', () => {
    render(
      <div>
        {(
          ['default', 'secondary', 'outline', 'destructive', 'success', 'warning', 'info', 'ai', 'slate'] as const
        ).map((variant) => (
          <Badge key={variant} variant={variant} data-testid={`badge-${variant}`}>
            {variant}
          </Badge>
        ))}
      </div>
    );
    expect(screen.getByTestId('badge-success')).toHaveTextContent('success');
    expect(screen.getByTestId('badge-ai')).toBeInTheDocument();
  });
});

describe('Avatar', () => {
  it('shows initials fallback when no image loads', async () => {
    render(
      <Avatar>
        <AvatarImage src="https://invalid.example/missing.png" alt="Ada Lovelace" />
        <AvatarFallback name="Ada Lovelace" />
      </Avatar>
    );
    // jsdom never loads the image, so the fallback must carry the initials.
    const fallback = await screen.findByText('AL');
    expect(fallback).toBeInTheDocument();
  });

  it('accepts custom fallback content', () => {
    render(
      <Avatar>
        <AvatarFallback>AI</AvatarFallback>
      </Avatar>
    );
    expect(screen.getByText('AI')).toBeInTheDocument();
  });
});

describe('Separator', () => {
  it('renders horizontal and vertical separators', () => {
    render(
      <div>
        <Separator data-testid="h" />
        <Separator orientation="vertical" data-testid="v" />
      </div>
    );
    expect(screen.getByTestId('h')).toHaveAttribute('data-orientation', 'horizontal');
    expect(screen.getByTestId('v')).toHaveAttribute('data-orientation', 'vertical');
  });
});

describe('Kbd', () => {
  it('renders shortcut text', () => {
    render(
      <span>
        <Kbd>Ctrl</Kbd>
        <Kbd>K</Kbd>
      </span>
    );
    expect(screen.getByText('Ctrl')).toBeInTheDocument();
    expect(screen.getByText('K')).toBeInTheDocument();
  });
});

describe('Skeleton', () => {
  it('is hidden from assistive technology', () => {
    render(<Skeleton data-testid="skeleton" className="h-8 w-full" />);
    const skeleton = screen.getByTestId('skeleton');
    expect(skeleton).toHaveAttribute('aria-hidden', 'true');
  });
});

describe('atoms axe', () => {
  it('passes axe across badge variants, avatar, separator, kbd and skeleton', async () => {
    const { container } = render(
      <div>
        <Badge variant="success">Published</Badge>
        <Avatar>
          <AvatarFallback name="Grace Hopper" />
        </Avatar>
        <Separator />
        <Kbd>?</Kbd>
        <Skeleton className="h-8 w-full" />
      </div>
    );
    const results = await runAxe(container);
    expect(results.violations).toEqual([]);
  });
});
