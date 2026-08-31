import { useEffect, useMemo, useState } from 'react';
import {
  ActivityIndicator,
  FlatList,
  Modal,
  Pressable,
  SectionList,
  StyleSheet,
  View,
} from 'react-native';
import { SafeAreaView, useSafeAreaInsets } from 'react-native-safe-area-context';

import { router, useLocalSearchParams } from 'expo-router';
import Feather from '@expo/vector-icons/Feather';

import { CartBar } from '@/components/CartBar';
import { ItemDetailModal } from '@/components/ItemDetailModal';
import { ALL_SECTION_ID, MenuSectionTabs } from '@/components/MenuSectionTabs';
import { ProductCard } from '@/components/ProductCard';
import { SearchBar } from '@/components/SearchBar';
import { ThemedText } from '@/components/ThemedText';
import { ThemedView } from '@/components/ThemedView';
import { Layout, Spacing } from '@/constants/theme';
import { useT } from '@/i18n';
import { useTheme } from '@/hooks/use-theme';
import { useMenuByDay } from '@/hooks/use-menu-by-day';
import { useMenuProducts } from '@/hooks/use-menu-products';
import { useShopOrderSettings } from '@/hooks/use-shop-order-settings';
import { getStoredDevice } from '@/lib/auth';
import { useCartStore } from '@/store/cart-store';
import type { ShopMenuByDayResource, ShopMenuProduct } from '@/types/pos';

export default function MenuScreen() {
  const t = useT();
  const theme = useTheme();
  const insets = useSafeAreaInsets();
  const { orderId, shopSlug: slugParam, tableCode } = useLocalSearchParams<{ orderId: string; shopSlug?: string; tableCode?: string }>();
  const [shopSlug, setShopSlug] = useState(slugParam ?? '');
  const [hidePrice, setHidePrice] = useState(false);

  useEffect(() => {
    if (!slugParam) {
      getStoredDevice().then((d) => { if (d?.shopSlug) setShopSlug(d.shopSlug); });
    }
  }, [slugParam]);

  const setOrderId = useCartStore((s) => s.setOrderId);
  useEffect(() => { if (orderId) setOrderId(orderId); }, [orderId, setOrderId]);

  const { data: settings } = useShopOrderSettings(shopSlug);
  const currencyCode = settings?.currency_code ?? 'JPY';

  const { data: menus, isLoading: menusLoading } = useMenuByDay(shopSlug);
  const [selectedMenuId, setSelectedMenuId] = useState<string>('');
  const [pickerVisible, setPickerVisible] = useState(false);
  const [pickerDismissed, setPickerDismissed] = useState(false);
  const [selectedSection, setSelectedSection] = useState(ALL_SECTION_ID);
  const [search, setSearch] = useState('');
  const [detailItem, setDetailItem] = useState<ShopMenuProduct | null>(null);

  useEffect(() => {
    if (!menus || menus.length === 0) return;
    if (menus.length === 1) {
      setSelectedMenuId(menus[0].id);
    } else if (!selectedMenuId && !pickerDismissed) {
      setPickerVisible(true);
    }
  }, [menus, selectedMenuId, pickerDismissed]);

  const { data: productPages, isFetchingNextPage, fetchNextPage, hasNextPage } =
    useMenuProducts(shopSlug, selectedMenuId, { search, per_page: 100 });

  const allProducts: ShopMenuProduct[] = useMemo(() => productPages?.pages.flat() ?? [], [productPages]);

  const sections = useMemo(() => {
    const map = new Map<string, string>();
    allProducts.forEach((p) => { if (p.section) map.set(p.section.id, p.section.name); });
    return Array.from(map.entries()).map(([id, name]) => ({ id, name }));
  }, [allProducts]);

  const filteredProducts = useMemo(() => {
    if (selectedSection === ALL_SECTION_ID) return allProducts;
    return allProducts.filter((p) => p.menu_section_id === selectedSection);
  }, [allProducts, selectedSection]);

  const sectionedProducts = useMemo(() => {
    if (selectedSection !== ALL_SECTION_ID) return null;
    const map = new Map<string, { id: string; name: string; data: ShopMenuProduct[] }>();
    allProducts.forEach((p) => {
      const sectionId = p.menu_section_id ?? '__none__';
      const sectionName = p.section?.name ?? '';
      if (!map.has(sectionId)) map.set(sectionId, { id: sectionId, name: sectionName, data: [] });
      map.get(sectionId)!.data.push(p);
    });
    return Array.from(map.values());
  }, [allProducts, selectedSection]);

  function handleSearch(text: string) {
    setSearch(text);
    if (text) setSelectedSection(ALL_SECTION_ID);
  }

  const selectedMenu: ShopMenuByDayResource | undefined = menus?.find((m) => m.id === selectedMenuId);

  return (
    <SafeAreaView style={[styles.root, { backgroundColor: theme.background }]} edges={['top', 'left', 'right']}>
      {/* Header */}
      <ThemedView type="card" style={[styles.header, { borderBottomColor: theme.divider }]}>
        <Pressable onPress={() => router.back()} style={styles.backBtn} hitSlop={8}>
          <Feather name="chevron-left" size={24} color={theme.primary} />
        </Pressable>
        <Pressable style={styles.headerTitle} onPress={() => (menus?.length ?? 0) > 1 && setPickerVisible(true)}>
          <ThemedText type="subtitle" numberOfLines={1}>
            {tableCode ? t.menu.tableHeader(tableCode) : t.menu.title(selectedMenu?.name)}
          </ThemedText>
          {selectedMenu && (
            <View style={styles.menuNameRow}>
              <ThemedText type="caption" themeColor="textSecondary" numberOfLines={1}>{selectedMenu.name}</ThemedText>
              <ThemedText type="caption" themeColor="textMuted" numberOfLines={1}>
                {' '}· {selectedMenu.start_time.slice(0, 5)}–{selectedMenu.end_time.slice(0, 5)}
              </ThemedText>
              {(menus?.length ?? 0) > 1 && <ThemedText style={[styles.chevronDown, { color: theme.textSecondary }]}>▾</ThemedText>}
            </View>
          )}
        </Pressable>
        <View style={styles.headerIcons}>
          <Pressable onPress={() => setHidePrice((v) => !v)} style={styles.iconBtn} hitSlop={8}>
            <Feather name={hidePrice ? 'eye-off' : 'eye'} size={20} color={hidePrice ? theme.primary : theme.textSecondary} />
          </Pressable>
        </View>
      </ThemedView>

      {/* Search + category tabs — two visually separated sticky blocks */}
      <ThemedView type="card" style={[styles.searchBlock, { borderBottomColor: theme.borderSoft }]}>
        <SearchBar value={search} onChangeText={handleSearch} placeholder={t.menu.searchPlaceholder} />
      </ThemedView>
      <MenuSectionTabs sections={sections} selected={selectedSection} onSelect={setSelectedSection} />

      {menusLoading ? (
        <View style={styles.center}>
          <ActivityIndicator color={theme.primary} />
        </View>
      ) : menus?.length === 0 ? (
        <View style={styles.center}>
          <ThemedText type="small" themeColor="textSecondary">{t.menu.empty}</ThemedText>
        </View>
      ) : !selectedMenuId ? (
        <View style={styles.center}>
          <ThemedText type="subtitle" style={styles.emptyStateTitle}>{t.menu.noMenuSelected}</ThemedText>
          <ThemedText type="small" themeColor="textSecondary" style={styles.emptyStateHint}>{t.menu.noMenuSelectedHint}</ThemedText>
          <Pressable
            style={[styles.openPickerBtn, { backgroundColor: theme.primary }]}
            onPress={() => { setPickerDismissed(false); setPickerVisible(true); }}
          >
            <ThemedText style={[styles.openPickerBtnText, { color: theme.background }]}>{t.menu.openMenuPicker}</ThemedText>
          </Pressable>
        </View>
      ) : sectionedProducts ? (
        <SectionList
          sections={sectionedProducts}
          keyExtractor={(p) => p.id}
          contentContainerStyle={[styles.list, { paddingBottom: insets.bottom + 4 }]}
          renderItem={({ item, index }) => (
            <ProductCard item={item} index={index} hidePrice={hidePrice} currencyCode={currencyCode} onOpenDetail={setDetailItem} />
          )}
          renderSectionHeader={({ section }) => section.name ? (
            <ThemedView type="background" style={[styles.sectionHeader, { borderBottomColor: theme.borderSoft }]}>
              <ThemedText type="caption" themeColor="textSecondary" style={styles.sectionHeaderText}>
                {section.name}
              </ThemedText>
            </ThemedView>
          ) : null}
          onEndReached={() => hasNextPage && !isFetchingNextPage && fetchNextPage()}
          onEndReachedThreshold={0.4}
          ListFooterComponent={
            isFetchingNextPage
              ? <ActivityIndicator color={theme.primary} style={styles.footer} />
              : (
                <View style={styles.endOfList}>
                  <ThemedText type="caption" themeColor="textMuted">{t.endOfList}</ThemedText>
                </View>
              )
          }
          ListEmptyComponent={
            <View style={styles.center}>
              <ThemedText type="small" themeColor="textSecondary">{t.menu.noProducts}</ThemedText>
            </View>
          }
          stickySectionHeadersEnabled={false}
        />
      ) : (
        <FlatList
          data={filteredProducts}
          keyExtractor={(p) => p.id}
          contentContainerStyle={[styles.list, { paddingBottom: insets.bottom + 4 }]}
          renderItem={({ item, index }) => (
            <ProductCard item={item} index={index} hidePrice={hidePrice} currencyCode={currencyCode} onOpenDetail={setDetailItem} />
          )}
          onEndReached={() => hasNextPage && !isFetchingNextPage && fetchNextPage()}
          onEndReachedThreshold={0.4}
          ListFooterComponent={
            isFetchingNextPage
              ? <ActivityIndicator color={theme.primary} style={styles.footer} />
              : (
                <View style={styles.endOfList}>
                  <ThemedText type="caption" themeColor="textMuted">{t.endOfList}</ThemedText>
                </View>
              )
          }
          ListEmptyComponent={
            <View style={styles.center}>
              <ThemedText type="small" themeColor="textSecondary">{t.menu.noProducts}</ThemedText>
            </View>
          }
        />
      )}

      <CartBar currencyCode={currencyCode} />

      {detailItem && (
        <ItemDetailModal key={detailItem.id} item={detailItem} visible={!!detailItem} hidePrice={hidePrice} currencyCode={currencyCode} onClose={() => setDetailItem(null)} />
      )}

      {/* Menu picker modal */}
      <Modal visible={pickerVisible} transparent animationType="fade">
        <Pressable style={styles.overlay} onPress={() => { setPickerVisible(false); setPickerDismissed(true); }}>
          <ThemedView type="card" style={styles.picker}>
            <ThemedText type="subtitle" style={{ marginBottom: Spacing.sm }}>{t.menu.selectMenu}</ThemedText>
            {menus?.map((m) => (
              <Pressable
                key={m.id}
                style={[styles.pickerItem, { borderTopColor: theme.divider }]}
                onPress={() => { setSelectedMenuId(m.id); setPickerVisible(false); }}
              >
                <ThemedText type="small">{m.name}</ThemedText>
                <ThemedText type="caption" themeColor="textSecondary">
                  {m.start_time.slice(0, 5)} – {m.end_time.slice(0, 5)}
                </ThemedText>
              </Pressable>
            ))}
          </ThemedView>
        </Pressable>
      </Modal>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1 },
  header: {
    minHeight: Layout.headerHeight,
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: Spacing.md,
    paddingVertical: Spacing.xs,
    borderBottomWidth: 1,
  },
  backBtn: { width: 44, height: 44, justifyContent: 'center', alignItems: 'center' },
  headerTitle: { flex: 1, minWidth: 0, justifyContent: 'center' },
  menuNameRow: { flexDirection: 'row', alignItems: 'center', gap: 3, marginTop: 1 },
  chevronDown: { fontSize: 10, lineHeight: 14 },
  headerIcons: { flexDirection: 'row', alignItems: 'center' },
  iconBtn: { width: 40, height: 44, justifyContent: 'center', alignItems: 'center' },
  priceIcon: { fontSize: 18, fontWeight: '700' },
  searchBlock: {
    paddingBottom: Spacing.sm,
    borderBottomWidth: 1,
  },
  list: {},
  sectionHeader: {
    paddingHorizontal: Layout.screenPaddingH,
    paddingVertical: 6,
    borderBottomWidth: StyleSheet.hairlineWidth,
  },
  sectionHeaderText: {
    textTransform: 'uppercase',
    letterSpacing: 0.5,
    fontWeight: '600',
  },
  center: { flex: 1, justifyContent: 'center', alignItems: 'center', paddingHorizontal: Spacing.xl },
  emptyStateTitle: { marginBottom: Spacing.xs, textAlign: 'center' },
  emptyStateHint: { marginBottom: Spacing.lg, textAlign: 'center' },
  openPickerBtn: { paddingHorizontal: Spacing.xl, paddingVertical: Spacing.sm, borderRadius: 8 },
  openPickerBtnText: { fontSize: 15, fontWeight: '600' },
  footer: { paddingVertical: Spacing.lg },
  endOfList: { paddingVertical: 2, alignItems: 'center', marginTop: -1 },
  overlay: { flex: 1, backgroundColor: 'rgba(0,0,0,0.4)', justifyContent: 'center', alignItems: 'center' },
  picker: { borderRadius: 12, padding: Spacing.lg, width: 280 },
  pickerItem: { paddingVertical: Spacing.sm, borderTopWidth: 1 },
});
