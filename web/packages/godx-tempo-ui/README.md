# @godxjp/ui

> GodX Design System — React UI components with i18n, translatable fields, and metadata-driven forms/tables.

## Install

```sh
npm install @godxjp/ui
```

## Usage

```tsx
import { Button, Input, Card, UIProvider } from "@godxjp/ui";

function App() {
  return (
    <UIProvider locales={{ ja: "日本語", en: "English" }} defaultLocale="ja">
      <Card>
        <Input placeholder="Enter text..." />
        <Button>Submit</Button>
      </Card>
    </UIProvider>
  );
}
```

## Components

60+ components including: Accordion, Alert, Avatar, Badge, Breadcrumb, Button, Calendar, Card, Checkbox, ColorPicker, Combobox, DatePicker, Dialog, Drawer, DropdownMenu, FileUpload (5 variants), Input, Label, Menubar, Pagination, PasswordInput, Popover, Progress, RadioGroup, Rating, SchemaField, SchemaTable, Select, Separator, Sheet, Skeleton, Slider, Switch, Table, Tabs, Textarea, TimePicker, Toggle, Tooltip, TranslatableField.

## Key Features

- **Translatable fields** — locale tab switcher on Input/Textarea
- **SchemaField** — metadata-driven form fields (15 input types)
- **SchemaTable** — metadata-driven data table (sort, filter, paginate, actions)
- **FileUpload** — 5 variants (dropzone, compact, avatar, gallery, inline)
- **i18n** — UIProvider with locale context, all labels locale-aware

## Build

Source lives in `frontend/src/components/ui/` (sibling repo). This package builds from there:

```sh
npm run build    # tsup → dist/
```

## License

Private — GodX Japan.
