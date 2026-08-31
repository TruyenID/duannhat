import type { Meta, StoryObj } from "@storybook/nextjs-vite";

import {
  Table,
  TableBody,
  TableCaption,
  TableCell,
  TableFooter,
  TableHead,
  TableHeader,
  TableRow,
} from "../table";
import { Badge } from "../badge";

const meta: Meta<typeof Table> = {
  title: "UI/Table",
  component: Table,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "Semantic HTML `<table>` primitive with design-system styling. For data-heavy enterprise screens (kintone-style list views) use this directly. For interactive sortable / filterable tables, compose with `<DataTable>` from `@/components/shared/data-table`.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof Table>;

const ORDERS = [
  { id: "ORD-1042", customer: "Tanaka Hiroshi", total: 12000, status: "delivered" },
  { id: "ORD-1043", customer: "Yamada Saki", total: 8400, status: "in-transit" },
  { id: "ORD-1044", customer: "Suzuki Ken", total: 5200, status: "cancelled" },
  { id: "ORD-1045", customer: "Watanabe Aya", total: 18900, status: "processing" },
];

const STATUS_COLOR: Record<string, "success" | "warning" | "destructive" | "info"> = {
  delivered: "success",
  "in-transit": "warning",
  cancelled: "destructive",
  processing: "info",
};

export const Default: Story = {
  render: () => (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>Order #</TableHead>
          <TableHead>Customer</TableHead>
          <TableHead className="text-right">Total</TableHead>
          <TableHead>Status</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {ORDERS.map((o) => (
          <TableRow key={o.id}>
            <TableCell className="font-mono text-xs">{o.id}</TableCell>
            <TableCell>{o.customer}</TableCell>
            <TableCell className="text-right">¥{o.total.toLocaleString("ja-JP")}</TableCell>
            <TableCell>
              <Badge variant="soft" color={STATUS_COLOR[o.status]}>
                {o.status}
              </Badge>
            </TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  ),
};

export const WithCaption: Story = {
  render: () => (
    <Table>
      <TableCaption>Recent orders from the last 7 days.</TableCaption>
      <TableHeader>
        <TableRow>
          <TableHead>Order #</TableHead>
          <TableHead>Customer</TableHead>
          <TableHead className="text-right">Total</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {ORDERS.map((o) => (
          <TableRow key={o.id}>
            <TableCell className="font-mono text-xs">{o.id}</TableCell>
            <TableCell>{o.customer}</TableCell>
            <TableCell className="text-right">¥{o.total.toLocaleString("ja-JP")}</TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  ),
};

export const WithFooter: Story = {
  render: () => {
    const total = ORDERS.reduce((sum, o) => sum + o.total, 0);
    return (
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Order #</TableHead>
            <TableHead>Customer</TableHead>
            <TableHead className="text-right">Total</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {ORDERS.map((o) => (
            <TableRow key={o.id}>
              <TableCell className="font-mono text-xs">{o.id}</TableCell>
              <TableCell>{o.customer}</TableCell>
              <TableCell className="text-right">¥{o.total.toLocaleString("ja-JP")}</TableCell>
            </TableRow>
          ))}
        </TableBody>
        <TableFooter>
          <TableRow>
            <TableCell colSpan={2}>Total</TableCell>
            <TableCell className="text-right">¥{total.toLocaleString("ja-JP")}</TableCell>
          </TableRow>
        </TableFooter>
      </Table>
    );
  },
};

export const Empty: Story = {
  render: () => (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>Order #</TableHead>
          <TableHead>Customer</TableHead>
          <TableHead>Total</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        <TableRow>
          <TableCell colSpan={3} className="text-center text-muted-foreground py-8">
            No orders yet
          </TableCell>
        </TableRow>
      </TableBody>
    </Table>
  ),
};
