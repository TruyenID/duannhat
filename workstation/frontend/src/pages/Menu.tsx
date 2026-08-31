import { Badge } from "@godxjp/ui/admin";
import { Card, CardContent } from "@godxjp/ui/data-display";
import { Input } from "@godxjp/ui/data-entry";
import { Button } from "@godxjp/ui/general";
import { useEffect, useState } from "react";
import { Plus, Pencil, Trash2 } from "lucide-react";
import {
  listMenuItems,
  createMenuItem,
  updateMenuItem,
  deleteMenuItem,
  seedDemoMenu,
} from "../lib/api";
import type { MenuItem } from "../lib/api";
import { useTranslation } from "../providers/app-provider";
import { PageHeader } from "../components/layout/page-header";
import { PageContent } from "../components/layout/page-content";

const printerGroups = ["kitchen", "bar"];

export function Menu() {
  const { t } = useTranslation();
  const [items, setItems] = useState<MenuItem[]>([]);
  const [showForm, setShowForm] = useState(false);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [name, setName] = useState("");
  const [nameJa, setNameJa] = useState("");
  const [category, setCategory] = useState("");
  const [price, setPrice] = useState(0);
  const [printerGroup, setPrinterGroup] = useState("kitchen");

  // Poll every 10s so newly pulled items after a re-pair (PullMenu runs
  // asynchronously) surface without a manual refresh. Menu changes are
  // infrequent in real ops, so 10s is plenty.
  useEffect(() => {
    loadItems();
    const interval = setInterval(loadItems, 10_000);
    return () => clearInterval(interval);
  }, []);

  async function loadItems() {
    try {
      const result = await listMenuItems();
      if (result) setItems(result);
    } catch {}
  }

  async function seedDemo() {
    try {
      await seedDemoMenu();
      loadItems();
    } catch (err) { console.error("seed failed:", err); }
  }

  async function saveItem() {
    try {
      if (editingId) {
        await updateMenuItem(editingId, name, nameJa, category, price, printerGroup);
      } else {
        await createMenuItem(name, nameJa, category, price, printerGroup);
      }
      resetForm();
      loadItems();
    } catch (err) { console.error("save failed:", err); }
  }

  async function handleDeleteItem(id: string) {
    try {
      await deleteMenuItem(id);
      loadItems();
    } catch (err) { console.error("delete failed:", err); }
  }

  function startEdit(item: MenuItem) {
    setEditingId(item.id);
    setName(item.name);
    setNameJa(item.name_ja);
    setCategory(item.category);
    setPrice(item.price);
    setPrinterGroup(item.printer_group);
    setShowForm(true);
  }

  function resetForm() {
    setEditingId(null); setName(""); setNameJa(""); setCategory("");
    setPrice(0); setPrinterGroup("kitchen"); setShowForm(false);
  }

  const formatPrice = (amount: number) => new Intl.NumberFormat("ja-JP").format(amount);

  // Derive groups dynamically from items so we render whatever categories
  // Cloud returns (e.g. "🍜 メイン", "🥤 ドリンク") instead of a hard-coded
  // English enum. Map preserves first-seen insertion order, which mirrors
  // the menu_sections.sort_order returned by Cloud.
  const grouped = (() => {
    const groups = new Map<string, MenuItem[]>();
    for (const item of items) {
      const cat = item.category || "Other";
      if (!groups.has(cat)) groups.set(cat, []);
      groups.get(cat)!.push(item);
    }
    return Array.from(groups, ([category, catItems]) => ({ category, items: catItems }));
  })();

  // For the create/edit form's category dropdown: offer existing categories
  // so the new item joins an existing group, but let the user type anything new.
  const knownCategories = Array.from(new Set(items.map((i) => i.category).filter(Boolean) as string[]));

  return (
    <>
      <PageHeader title={t("nav.menu")}>
        {items.length === 0 && (
          <Button variant="outline" onClick={seedDemo}>Load Demo Menu</Button>
        )}
        <Button color="primary" onClick={() => { resetForm(); setShowForm(true); }}>
          <Plus size={16} /> {t("common.create")}
        </Button>
      </PageHeader>
      <PageContent>
        {showForm && (
          <Card className="mb-6">
            <CardContent className="p-4">
              <h3 className="font-semibold mb-3">{editingId ? t("common.edit") : t("common.create")}</h3>
              <div className="grid grid-cols-3 gap-3">
                <div>
                  <label className="text-xs text-muted-foreground">{t("common.name")}</label>
                  <Input value={name} onChange={(e) => setName(e.target.value)} className="mt-1" />
                </div>
                <div>
                  <label className="text-xs text-muted-foreground">{t("common.name")} (JA)</label>
                  <Input value={nameJa} onChange={(e) => setNameJa(e.target.value)} className="mt-1" />
                </div>
                <div>
                  <label className="text-xs text-muted-foreground">Price</label>
                  <Input type="number" value={String(price)} onChange={(e) => setPrice(Number(e.target.value))} className="mt-1" />
                </div>
                <div>
                  <label className="text-xs text-muted-foreground">Category</label>
                  <Input
                    list="menu-categories"
                    value={category}
                    onChange={(e) => setCategory(e.target.value)}
                    placeholder="🍜 メイン"
                    className="mt-1"
                  />
                  <datalist id="menu-categories">
                    {knownCategories.map((c) => <option key={c} value={c} />)}
                  </datalist>
                </div>
                <div>
                  <label className="text-xs text-muted-foreground">Printer Group</label>
                  <select
                    value={printerGroup}
                    onChange={(e) => setPrinterGroup(e.target.value)}
                    className="w-full mt-1 px-3 py-2 rounded-lg text-sm bg-muted border border-border"
                  >
                    {printerGroups.map((o) => <option key={o} value={o}>{o}</option>)}
                  </select>
                </div>
              </div>
              <div className="flex gap-2 mt-3">
                <Button color="primary" onClick={saveItem}>{editingId ? t("common.save") : t("common.create")}</Button>
                <Button variant="ghost" onClick={resetForm}>{t("common.cancel")}</Button>
              </div>
            </CardContent>
          </Card>
        )}

        {grouped.map((group) =>
          group.items.length > 0 && (
            <div key={group.category} className="mb-6">
              <h3 className="text-sm font-semibold uppercase text-muted-foreground mb-2">{group.category}</h3>
              <div className="grid grid-cols-3 gap-2">
                {group.items.map((item) => (
                  <Card key={item.id}>
                    <CardContent className="p-3">
                      <div className="flex items-start justify-between">
                        <div>
                          <div className="font-medium">{item.name}</div>
                          {item.name_ja && <div className="text-xs text-muted-foreground">{item.name_ja}</div>}
                        </div>
                        <div className="flex gap-1">
                          <button className="p-1 rounded text-muted-foreground hover:text-foreground" onClick={() => startEdit(item)}>
                            <Pencil size={14} />
                          </button>
                          <button className="p-1 rounded text-destructive hover:text-destructive/80" onClick={() => handleDeleteItem(item.id)}>
                            <Trash2 size={14} />
                          </button>
                        </div>
                      </div>
                      <div className="mt-1 flex items-center justify-between">
                        <span className="font-bold">{formatPrice(item.price)}</span>
                        <Badge tone="neutral">{item.printer_group}</Badge>
                      </div>
                    </CardContent>
                  </Card>
                ))}
              </div>
            </div>
          )
        )}

        {items.length === 0 && !showForm && (
          <p className="text-muted-foreground">{t("common.no_data")}</p>
        )}
      </PageContent>
    </>
  );
}
