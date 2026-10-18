import { Download, Plus, Search } from 'lucide-react';
import {
  Avatar,
  AvatarFallback,
  Badge,
  Button,
  Checkbox,
  Input,
  Kbd,
  Label,
  Progress,
  RadioCard,
  RadioGroup,
  RadioGroupItem,
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectTrigger,
  SelectValue,
  Separator,
  Skeleton,
  Slider,
  Spinner,
  Switch,
  Textarea,
  Toggle,
  ToggleGroup,
  ToggleGroupItem,
} from '@bs/ui';
import type { GallerySection } from '../registry';

function ButtonShowcase() {
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2">
        <Button>Primary</Button>
        <Button variant="secondary">Secondary</Button>
        <Button variant="outline">Outline</Button>
        <Button variant="ghost">Ghost</Button>
        <Button variant="destructive">Destructive</Button>
        <Button variant="link">Link</Button>
      </div>
      <div className="flex flex-wrap items-center gap-2">
        <Button size="sm">Small</Button>
        <Button size="default">Default</Button>
        <Button size="lg">Large</Button>
        <Button size="icon" aria-label="Add"><Plus /></Button>
        <Button size="icon-sm" aria-label="Search"><Search /></Button>
      </div>
      <div className="flex flex-wrap items-center gap-2">
        <Button loading>Saving</Button>
        <Button disabled>Disabled</Button>
        <Button variant="outline">
          <Download /> Export
        </Button>
      </div>
    </div>
  );
}

function InputsShowcase() {
  return (
    <div className="grid max-w-xl gap-4">
      <div className="space-y-1">
        <Label htmlFor="g-title">Title</Label>
        <Input id="g-title" placeholder="Post title" />
      </div>
      <div className="space-y-1">
        <Label htmlFor="g-title-err">Title (error)</Label>
        <Input id="g-title-err" defaultValue="Hi" aria-invalid="true" />
        <p className="text-xs text-destructive">Title needs at least 3 characters.</p>
      </div>
      <div className="space-y-1">
        <Label htmlFor="g-bio">Bio</Label>
        <Textarea id="g-bio" defaultValue="Short author bio." showCounter maxLength={140} />
      </div>
      <div className="space-y-1">
        <Label htmlFor="g-readonly">Entry ID (read-only)</Label>
        <Input id="g-readonly" value="entry_01HXXXXXXX" readOnly />
      </div>
      <div className="flex items-center gap-2">
        <Switch id="g-comments" defaultChecked />
        <Label htmlFor="g-comments">Comments open</Label>
      </div>
    </div>
  );
}

function SelectionShowcase() {
  return (
    <div className="grid max-w-2xl gap-4 sm:grid-cols-2">
      <div className="space-y-2">
        <Checkbox id="g-check" /> <Label htmlFor="g-check">Unchecked</Label>
        <div className="flex items-center gap-2">
          <Checkbox id="g-check2" defaultChecked />
          <Label htmlFor="g-check2">Checked</Label>
        </div>
        <div className="flex items-center gap-2">
          <Checkbox id="g-check3" checked="indeterminate" onCheckedChange={() => {}} />
          <Label htmlFor="g-check3">Indeterminate</Label>
        </div>
        <div className="flex items-center gap-2">
          <Checkbox id="g-check4" disabled />
          <Label htmlFor="g-check4">Disabled</Label>
        </div>
      </div>
      <RadioGroup defaultValue="a" aria-label="Choose layout">
        <div className="flex items-center gap-2">
          <RadioGroupItem value="a" id="g-r1" />
          <Label htmlFor="g-r1">List view</Label>
        </div>
        <div className="flex items-center gap-2">
          <RadioGroupItem value="b" id="g-r2" />
          <Label htmlFor="g-r2">Card view</Label>
        </div>
      </RadioGroup>
      <RadioGroup defaultValue="article" aria-label="Editor profile" className="sm:col-span-2">
        <RadioCard value="article" title="Article" description="Full block editor for posts" />
        <RadioCard value="minimal" title="Minimal" description="Inline marks only" />
      </RadioGroup>
    </div>
  );
}

function SelectShowcase() {
  return (
    <div className="flex max-w-sm flex-col gap-2">
      <Select>
        <SelectTrigger aria-label="Status">
          <SelectValue placeholder="Choose status" />
        </SelectTrigger>
        <SelectContent>
          <SelectGroup>
            <SelectLabel>Status</SelectLabel>
            <SelectItem value="draft">Draft</SelectItem>
            <SelectItem value="review">In review</SelectItem>
            <SelectItem value="published">Published</SelectItem>
          </SelectGroup>
        </SelectContent>
      </Select>
      <Select disabled>
        <SelectTrigger aria-label="Disabled select">
          <SelectValue placeholder="Disabled" />
        </SelectTrigger>
      </Select>
    </div>
  );
}

function AtomsShowcase() {
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2">
        <Badge>Default</Badge>
        <Badge variant="secondary">Secondary</Badge>
        <Badge variant="outline">Outline</Badge>
        <Badge variant="success">Published</Badge>
        <Badge variant="warning">In review</Badge>
        <Badge variant="info">Scheduled</Badge>
        <Badge variant="destructive">Failed</Badge>
        <Badge variant="ai">AI-created</Badge>
        <Badge variant="slate">Archived</Badge>
      </div>
      <div className="flex items-center gap-3">
        <Avatar>
          <AvatarFallback name="Ada Lovelace" />
        </Avatar>
        <Avatar>
          <AvatarFallback>AI</AvatarFallback>
        </Avatar>
        <Separator className="w-16" />
        <span>Press <Kbd>Ctrl</Kbd> <Kbd>K</Kbd></span>
        <Spinner />
      </div>
      <div className="max-w-xs space-y-2">
        <Skeleton className="h-4 w-full" />
        <Skeleton className="h-4 w-3/4" />
        <Progress value={40} aria-label="Upload" />
        <Progress aria-label="Indeterminate" />
      </div>
    </div>
  );
}

function TogglesShowcase() {
  return (
    <div className="flex flex-wrap items-center gap-4">
      <Toggle aria-label="Bold">B</Toggle>
      <Toggle variant="outline" aria-label="Underline">U</Toggle>
      <ToggleGroup type="single" defaultValue="compact" aria-label="Density">
        <ToggleGroupItem value="compact">Compact</ToggleGroupItem>
        <ToggleGroupItem value="default">Default</ToggleGroupItem>
        <ToggleGroupItem value="comfortable">Comfortable</ToggleGroupItem>
      </ToggleGroup>
      <div className="w-40">
        <Slider defaultValue={[30]} aria-label="Max width" />
      </div>
    </div>
  );
}

export const primitivesSection: GallerySection = {
  id: 'primitives',
  name: 'Primitives',
  entries: [
    {
      id: 'button',
      name: 'Button',
      description:
        'Variants, sizes, loading and icon-only with an accessible name. Defaults to type="button".',
      render: () => <ButtonShowcase />,
      props: [
        { name: 'variant', type: "'default' | 'secondary' | 'destructive' | 'outline' | 'ghost' | 'link'", defaultValue: "'default'", description: 'Visual weight of the action.' },
        { name: 'size', type: "'sm' | 'default' | 'lg' | 'icon' | 'icon-sm'", defaultValue: "'default'", description: 'Icon sizes require an accessible name.' },
        { name: 'loading', type: 'boolean', defaultValue: 'false', description: 'Spinner + disabled + aria-busy.' },
        { name: 'asChild', type: 'boolean', defaultValue: 'false', description: 'Slot a Link/anchor as the button.' },
      ],
    },
    {
      id: 'input',
      name: 'Input, Label, Textarea, Switch',
      description: 'Text fields with error state (aria-invalid), auto-grow textarea with counter, read-only, switch.',
      render: () => <InputsShowcase />,
      props: [
        { name: 'autoGrow', type: 'boolean (Textarea)', defaultValue: 'true', description: 'Grows to content up to maxRows, then scrolls.' },
        { name: 'showCounter', type: 'boolean (Textarea)', defaultValue: 'false', description: 'n-of-max counter tied to maxLength.' },
        { name: 'aria-invalid', type: 'boolean', description: 'Switches border and ring to destructive.' },
      ],
    },
    {
      id: 'checkbox-radio',
      name: 'Checkbox, Radio Group, Radio Cards',
      description: 'Checked, unchecked, indeterminate and disabled states; radios with roving focus.',
      render: () => <SelectionShowcase />,
      props: [
        { name: 'checked', type: 'boolean | "indeterminate"', description: 'Indeterminate renders the mixed state for select-all headers.' },
        { name: 'onCheckedChange', type: '(checked: boolean) => void', description: 'Change callback.' },
      ],
    },
    {
      id: 'select',
      name: 'Select',
      description: 'Single select with groups and labels; keyboard navigation; disabled state.',
      render: () => <SelectShowcase />,
      props: [
        { name: 'value / onValueChange', type: 'string', description: 'Controlled selection.' },
        { name: 'disabled', type: 'boolean', description: 'Locked trigger.' },
      ],
    },
    {
      id: 'atoms',
      name: 'Badge, Avatar, Separator, Kbd, Spinner, Skeleton, Progress',
      description: 'Badge status colours; avatar with initials fallback; determinate and indeterminate progress.',
      render: () => <AtomsShowcase />,
      props: [
        { name: 'variant (Badge)', type: "'default' | 'secondary' | 'outline' | 'destructive' | 'success' | 'warning' | 'info' | 'ai' | 'slate'", description: 'Status colours per docs/02-ui-spec.md 3.4.' },
        { name: 'value (Progress)', type: 'number | undefined', description: 'Omit for the indeterminate job animation.' },
      ],
    },
    {
      id: 'toggle-slider',
      name: 'Toggle, Toggle Group, Slider',
      description: 'Pressed state toggles; single and multiple groups; slider with one or two thumbs.',
      render: () => <TogglesShowcase />,
      props: [
        { name: 'type (ToggleGroup)', type: "'single' | 'multiple'", description: 'single keeps one item pressed.' },
        { name: 'defaultValue (Slider)', type: 'number[]', description: 'Two values render a range with start/end thumbs.' },
      ],
    },
  ],
};
