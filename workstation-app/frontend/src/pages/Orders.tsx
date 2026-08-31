import { useCallback, useEffect, useState } from "react";
import { Card, CardContent, Button, Badge, Input, Separator } from "@godxjp/ui";
import { Plus, ChevronRight } from "lucide-react";
import {
  listActiveOrders,
  listPaidOrders,
  listMenuItems,
  createOrder,
  updateOrderStatus,
  getOrder,
  printOrder,
} from "../lib/api";
import type { Order, MenuItem } from "../lib/api";
import { useWsEvents } from "../lib/ws-context";
import { useTranslation } from "../providers/app-provider";
import { PageHeader } from "../components/layout/page-header";
import { PageContent } from "../components/layout/page-content";

const statusColorMap: Record<string, "info" | "warning" | "success" | "destructive"> = {
  open: "info",
  preparing: "warning",
  ready: "success",
  cancelled: "destructive",
};

export function Orders() {
  const { t } = useTranslation();
  const [orders, setOrders] = useState<Order[]>([]);
  const [filter, setFilter] = useState<"active" | "paid">("active");
  const [menuItems, setMenuItems] = useState<MenuItem[]>([]);
  const [showNewOrder, setShowNewOrder] = useState(false);
  const [selectedOrder, setSelectedOrder] = useState<Order | null>(null);
  const [tableId, setTableId] = useState("");
  const [orderType, setOrderType] = useState("dine_in");
  const [guestCount, setGuestCount] = useState(1);
  const [selectedItems, setSelectedItems] = useState<
    { menu_item_id: string; quantity: number; note: string }[]
  >([]);

  useEffect(() => {
    loadOrders();
    loadMenu();
    const interval = setInterval(loadOrders, 10000);
    return () => clearInterval(interval);
    // Re-run (and reset the poll) when the active/paid filter changes so the
    // list + its interval always fetch the selected tab.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filter]);

  // Realtime: refetch immediately when orders change via WS broadcast.
  const handleWsEvent = useCallback(() => { loadOrders(); }, [filter]);
  useWsEvents(handleWsEvent, ["order_created", "order_updated", "order_paid", "order_voided"]);

  async function loadOrders() {
    try {
      const result = filter === "paid" ? await listPaidOrders() : await listActiveOrders();
      if (result) setOrders(result);
    } catch {}
  }

  async function loadMenu() {
    try {
      const result = await listMenuItems();
      if (result) setMenuItems(result);
    } catch {}
  }

  async function handleCreateOrder() {
    if (selectedItems.length === 0) return;
    try {
      await createOrder({
        table_id: tableId || undefined,
        order_type: orderType,
        guest_count: guestCount,
        items: selectedItems,
      });
      setShowNewOrder(false);
      setTableId("");
      setOrderType("dine_in");
      setGuestCount(1);
      setSelectedItems([]);
      loadOrders();
    } catch (err) {
      console.error("create order failed:", err);
    }
  }

  async function handleUpdateStatus(orderId: string, status: string) {
    try {
      await updateOrderStatus(orderId, status);
      loadOrders();
      if (selectedOrder?.id === orderId) {
        const updated = await getOrder(orderId);
        if (updated) setSelectedOrder(updated);
      }
    } catch (err) {
      console.error("update status failed:", err);
    }
  }

  async function handlePrintOrder(orderId: string, type: string) {
    try {
      await printOrder(orderId, type);
    } catch (err) {
      console.error("print failed:", err);
    }
  }

  const formatPrice = (amount: number) =>
    new Intl.NumberFormat("ja-JP").format(amount);

  function addMenuItem(item: MenuItem) {
    const existing = selectedItems.find((s) => s.menu_item_id === item.id);
    if (existing) {
      setSelectedItems(
        selectedItems.map((s) =>
          s.menu_item_id === item.id ? { ...s, quantity: s.quantity + 1 } : s
        )
      );
    } else {
      setSelectedItems([...selectedItems, { menu_item_id: item.id, quantity: 1, note: "" }]);
    }
  }

  return (
    <>
      <PageHeader title={t("nav.orders")}>
        <div className="flex items-center gap-1 rounded-md border p-0.5">
          <Button
            size="sm"
            variant={filter === "active" ? "default" : "ghost"}
            onClick={() => setFilter("active")}
          >
            {t("orders.filter_active")}
          </Button>
          <Button
            size="sm"
            variant={filter === "paid" ? "default" : "ghost"}
            onClick={() => setFilter("paid")}
          >
            {t("orders.filter_paid")}
          </Button>
        </div>
        <Button color="primary" onClick={() => setShowNewOrder(true)}>
          <Plus size={16} />
          {t("ws.new_order")}
        </Button>
      </PageHeader>
      <PageContent>
        <div className="flex gap-4 h-full">
          {/* Orders list */}
          <div className="flex-1">
            <div className="space-y-2">
              {orders.length === 0 && (
                <p className="text-muted-foreground">{t("common.no_data")}</p>
              )}
              {orders.map((order) => (
                <Card
                  key={order.id}
                  className={`cursor-pointer transition-colors hover:bg-muted/50 ${
                    selectedOrder?.id === order.id ? "ring-2 ring-primary" : ""
                  }`}
                  onClick={() => setSelectedOrder(order)}
                >
                  <CardContent className="py-3">
                    <div className="flex items-center justify-between">
                      <div className="flex items-center gap-3">
                        <span className="font-bold">{order.order_code || `#${String(order.order_number ?? 0).padStart(3, "0")}`}</span>
                        {order.table_number && (
                          <span className="text-sm text-muted-foreground">Table {order.table_number}</span>
                        )}
                      </div>
                      <div className="flex items-center gap-2">
                        <Badge color={statusColorMap[order.status] || undefined} variant="soft">
                          {order.status}
                        </Badge>
                        <ChevronRight size={14} className="text-muted-foreground" />
                      </div>
                    </div>
                    <div className="flex items-center justify-between mt-1">
                      <span className="text-xs text-muted-foreground">{order.items?.length ?? 0} items</span>
                      <span className="text-sm font-medium">{formatPrice(order.total_amount)}</span>
                    </div>
                  </CardContent>
                </Card>
              ))}
            </div>
          </div>

          {/* Right panel */}
          <Card className="w-96 overflow-y-auto">
            <CardContent className="p-4">
              {showNewOrder ? (
                <div>
                  <h3 className="text-lg font-bold mb-4">{t("ws.new_order")}</h3>
                  <div className="space-y-3">
                    <div>
                      <label className="text-xs text-muted-foreground">Order Type</label>
                      <select
                        value={orderType}
                        onChange={(e) => setOrderType(e.target.value)}
                        className="w-full mt-1 px-3 py-2 rounded-lg text-sm bg-muted border border-border"
                      >
                        <option value="dine_in">Dine-in</option>
                        <option value="takeaway">Takeaway</option>
                        <option value="spot">Spot</option>
                      </select>
                    </div>
                    <div>
                      <label className="text-xs text-muted-foreground">Table ID (optional)</label>
                      <Input
                        value={tableId}
                        onChange={(e) => setTableId(e.target.value)}
                        placeholder="UUID from /tables (TODO: picker)"
                        className="mt-1"
                      />
                    </div>
                    <div>
                      <label className="text-xs text-muted-foreground">Guests</label>
                      <Input
                        type="number"
                        min={1}
                        value={String(guestCount)}
                        onChange={(e) => setGuestCount(Number(e.target.value))}
                        className="mt-1"
                      />
                    </div>
                    <div>
                      <label className="text-xs text-muted-foreground">Menu Items</label>
                      <div className="mt-1 grid grid-cols-2 gap-1">
                        {menuItems.map((item) => (
                          <button
                            key={item.id}
                            className="text-left px-2 py-1.5 rounded-md text-xs bg-muted hover:bg-muted/80 transition-colors"
                            onClick={() => addMenuItem(item)}
                          >
                            <div className="font-medium truncate">{item.name}</div>
                            <div className="text-muted-foreground">{formatPrice(item.price)}</div>
                          </button>
                        ))}
                      </div>
                    </div>
                    {selectedItems.length > 0 && (
                      <div>
                        <label className="text-xs text-muted-foreground">
                          Selected ({selectedItems.length})
                        </label>
                        <div className="mt-1 space-y-1">
                          {selectedItems.map((s, i) => {
                            const item = menuItems.find((m) => m.id === s.menu_item_id);
                            return (
                              <div key={i} className="flex items-center justify-between px-2 py-1 rounded-md bg-muted text-sm">
                                <span>x{s.quantity} {item?.name}</span>
                                <button
                                  className="text-xs text-destructive"
                                  onClick={() => setSelectedItems(selectedItems.filter((_, idx) => idx !== i))}
                                >
                                  {t("common.delete")}
                                </button>
                              </div>
                            );
                          })}
                        </div>
                      </div>
                    )}
                    <div className="flex gap-2">
                      <Button color="primary" className="flex-1" onClick={handleCreateOrder}>{t("common.create")}</Button>
                      <Button variant="ghost" onClick={() => setShowNewOrder(false)}>{t("common.cancel")}</Button>
                    </div>
                  </div>
                </div>
              ) : selectedOrder ? (
                <div>
                  <h3 className="text-lg font-bold mb-1">
                    {selectedOrder.order_code || `Order #${String(selectedOrder.order_number ?? 0).padStart(3, "0")}`}
                  </h3>
                  <p className="text-sm text-muted-foreground mb-4">
                    {selectedOrder.table_number ? `Table ${selectedOrder.table_number}` : selectedOrder.order_type}
                    {selectedOrder.guest_count ? ` · ${selectedOrder.guest_count} guests` : ""}
                  </p>
                  <div className="space-y-2 mb-4">
                    {selectedOrder.items?.map((item) => (
                      <div key={item.id} className="flex items-center justify-between text-sm">
                        <span>x{item.quantity} {item.menu_item_name}</span>
                        <span>{formatPrice(item.unit_price * item.quantity)}</span>
                      </div>
                    ))}
                  </div>
                  <Separator className="my-3" />
                  <div className="flex justify-between mb-4">
                    <span className="text-muted-foreground">Total</span>
                    <span className="font-bold text-lg">{formatPrice(selectedOrder.total_amount)}</span>
                  </div>
                  <div className="space-y-2">
                    {selectedOrder.status === "open" && (
                      <Button color="warning" className="w-full" onClick={() => handleUpdateStatus(selectedOrder.id, "preparing")}>
                        Start Preparing
                      </Button>
                    )}
                    {selectedOrder.status === "preparing" && (
                      <Button color="success" className="w-full" onClick={() => handleUpdateStatus(selectedOrder.id, "ready")}>
                        Mark Ready
                      </Button>
                    )}
                    {selectedOrder.status === "ready" && (
                      <Button color="primary" className="w-full" onClick={() => handleUpdateStatus(selectedOrder.id, "served")}>
                        Mark Served
                      </Button>
                    )}
                    {selectedOrder.status === "served" && (
                      <Button color="success" className="w-full" onClick={() => handleUpdateStatus(selectedOrder.id, "paid")}>
                        Record Payment
                      </Button>
                    )}
                    <Button variant="outline" className="w-full" onClick={() => handlePrintOrder(selectedOrder.id, "kitchen")}>
                      Print Kitchen Ticket
                    </Button>
                    <Button variant="outline" className="w-full" onClick={() => handlePrintOrder(selectedOrder.id, "hold")}>
                      Print Hold
                    </Button>
                    <Button variant="outline" className="w-full" onClick={() => handlePrintOrder(selectedOrder.id, "receipt")}>
                      Print Receipt
                    </Button>
                  </div>
                </div>
              ) : (
                <div className="flex items-center justify-center h-full text-sm text-muted-foreground">
                  Select an order or create a new one
                </div>
              )}
            </CardContent>
          </Card>
        </div>
      </PageContent>
    </>
  );
}
