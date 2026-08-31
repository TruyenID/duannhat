export type Translations = {
  retry: string;
  cancel: string;
  save: string;
  saving: string;
  loading: string;
  error: string;
  endOfList: string;
  tables: {
    loadError: string;
    empty: string;
    noOpenOrders: string;
    busyAlert: string;
    createOrderError: string;
    createOrderConfirmTitle: (tableCode: string) => string;
    createOrderConfirmMessage: string;
    createOrderConfirmButton: string;
  };
  tableStatus: {
    free: string;
    occupied: string;
    reserved: string;
    cleaning: string;
    out_of_service: string;
  };
  tableCard: {
    guestSeats: (n: number) => string;
    itemCount: (n: number) => string;
    readyItems: (n: number) => string;
    dotReady: (n: number) => string;
    dotPreparing: (n: number) => string;
  };
  guestDialog: {
    title: string;
    subtitle: string;
    confirm: string;
    creating: string;
  };
  order: {
    back: string;
    empty: string;
    addItem: string;
    loadError: string;
    guestCount: (n: number) => string;
    headerTitle: (tableCode: string) => string;
    itemCountSuffix: (n: number) => string;
    elapsedMin: (n: number) => string;
    elapsedHourMin: (h: number, m: number) => string;
    elapsedHour: (h: number) => string;
    statPending: string;
    statPreparing: string;
    statReady: string;
    statServed: string;
    statTotal: string;
    addMore: string;
  };
  orderStatus: {
    open: string;
    dining: string;
    checkout: string;
    paying: string;
    closed: string;
    voided: string;
  };
  orderItem: {
    noName: string;
    noteLabel: string;
    notePlaceholder: string;
    cancel: string;
  };
  voidOrder: {
    button: string;
    dialogTitle: string;
    dialogSubtitle: string;
    reasonLabel: string;
    reasonPlaceholder: string;
    confirm: string;
    confirming: string;
    blockedTitle: string;
    blockedMessage: string;
  };
  orderRow: {
    itemCount: (n: number) => string;
  };
  itemStatus: {
    pending: string;
    preparing: string;
    ready: string;
    served: string;
    voided: string;
  };
  itemStatusAction: {
    markServed: string;
  };
  summary: {
    subtotal: string;
    tax: string;
    serviceCharge: string;
    total: string;
    // plan-043 — per-rate consumption-tax labels (8%対象 / 内消費税 / 税込).
    taxRateLine: (rate: number) => string;
    taxIncludedInline: string;
    taxIncludedBadge: string;
  };
  menu: {
    title: (tableName?: string) => string;
    tableHeader: (code: string, seats?: string) => string;
    empty: string;
    noProducts: string;
    searchPlaceholder: string;
    selectMenu: string;
    noMenuSelected: string;
    noMenuSelectedHint: string;
    openMenuPicker: string;
    back: string;
    allSections: string;
  };
  cart: {
    title: (tableCode: string) => string;
    empty: string;
    addMore: string;
    subtotalLabel: string;
    submit: string;
    submitting: string;
    submitError: string;
    cancelItem: string;
    fireError: string;
    firePartial: string;
  };
  cartBar: {
    viewCart: (n: number) => string;
    subtotal: (amount: string) => string;
  };
  itemDetail: {
    selectSize: string;
    toppingRemove: string;
    topping: string;
    toppingHintSingle: string;
    toppingHintMultiple: (min: number, max: number) => string;
    toppingRequired: (min: number) => string;
    quickNotes: string;
    kitchenNote: string;
    kitchenNotePlaceholder: string;
    quantity: string;
    subtotal: string;
    addToOrder: string;
    addAnother: string;
    addedSuats: (n: number) => string;
    free: string;
    quickNotesList: string[];
  };
  pair: {
    title: string;
    instructions: string;
    placeholder: string;
    submit: string;
    submitting: string;
    genericError: string;
    wrongDeviceType: string;
    wsUrlLabel: string;
    wsUrlPlaceholder: string;
  };
  settings: {
    title: string;
    subtitle: string;
    deviceSection: string;
    unpairButton: string;
    unpairConfirmTitle: string;
    unpairConfirmMessage: string;
    unpairConfirm: string;
    dataSection: string;
    syncButton: string;
    syncingButton: string;
    syncSuccess: string;
    syncError: string;
  };
  errorBoundary: {
    title: string;
    retry: string;
  };
};

export const ja: Translations = {
  // Common
  retry: '再試行',
  cancel: 'キャンセル',
  save: '保存',
  saving: '保存中...',
  loading: '読み込み中...',
  error: 'エラー',
  endOfList: '— リスト終了 —',

  // Tables screen
  tables: {
    loadError: 'データを読み込めませんでした。',
    empty: 'テーブルがありません',
    noOpenOrders: 'オープン中のオーダーはありません',
    busyAlert: 'は現在使用できません。',
    createOrderError: 'オーダーを作成できませんでした。もう一度お試しください。',
    createOrderConfirmTitle: (tableCode: string) => `卓 ${tableCode} — オーダー作成`,
    createOrderConfirmMessage: 'このテーブルのオーダーを新しく作成しますか？',
    createOrderConfirmButton: '作成する',
  },

  // Table status
  tableStatus: {
    free: '空席',
    occupied: '使用中',
    reserved: '予約',
    cleaning: '清掃中',
    out_of_service: '使用不可',
  },

  // Table card
  tableCard: {
    guestSeats: (n: number) => `${n}席`,
    itemCount: (n: number) => `${n}品`,
    readyItems: (n: number) => `${n}品 提供可`,
    dotReady: (n: number) => `🟢 ${n}品 提供可`,
    dotPreparing: (n: number) => `🟡 ${n}品 調理中`,
  },

  // Guest count dialog
  guestDialog: {
    title: 'テーブル',
    subtitle: '何名様ですか？',
    confirm: 'オーダー作成',
    creating: '作成中...',
  },

  // Order detail
  order: {
    back: '‹ 戻る',
    empty: 'まだ注文がありません',
    addItem: '＋ メニューから追加',
    loadError: '読み込みに失敗しました',
    guestCount: (n: number) => `${n}名`,
    headerTitle: (tableCode: string) => `注文履歴 · 卓 ${tableCode}`,
    itemCountSuffix: (n: number) => `· ${n}品`,
    elapsedMin: (n: number) => `${n}分`,
    elapsedHourMin: (h: number, m: number) => `${h}時間${m}分`,
    elapsedHour: (h: number) => `${h}時間`,
    statPending: '厨房待ち',
    statPreparing: '調理中',
    statReady: '完成',
    statServed: '提供済',
    statTotal: '合計',
    addMore: '＋ 追加注文',
  },

  // Order status
  orderStatus: {
    open: '受付中',
    dining: '食事中',
    checkout: '会計待ち',
    paying: '支払中',
    closed: 'クローズ',
    voided: 'キャンセル',
  },

  voidOrder: {
    button: 'オーダーをキャンセル',
    dialogTitle: 'オーダーをキャンセルしますか？',
    dialogSubtitle: 'この操作は元に戻せません。',
    reasonLabel: 'キャンセル理由',
    reasonPlaceholder: '例: お客様都合、注文ミス...',
    confirm: 'キャンセル確定',
    confirming: '処理中...',
    blockedTitle: 'キャンセルできません',
    blockedMessage: '調理中または提供済みの商品があります。',
  },

  // Order item row
  orderItem: {
    noName: '(商品名なし)',
    noteLabel: '備考を入力してください',
    notePlaceholder: '例: 辛さ控えめ、アレルギー対応...',
    cancel: '取消',
  },

  // Order row
  orderRow: {
    itemCount: (n: number) => `${n}品`,
  },

  // Order item status
  itemStatus: {
    pending: '待機',
    preparing: '調理中',
    ready: '準備完了',
    served: '提供済',
    voided: 'キャンセル',
  },

  itemStatusAction: {
    markServed: '提供済にする',
  },

  // Order summary
  summary: {
    subtotal: '小計',
    tax: '消費税',
    serviceCharge: 'サービス料',
    total: '合計',
    // plan-043 — per-rate consumption-tax labels.
    taxRateLine: (rate: number) => `${rate}%対象`,
    taxIncludedInline: '内消費税',
    taxIncludedBadge: '税込',
  },

  // Menu screen
  menu: {
    title: (tableName?: string) => tableName ? `メニュー · ${tableName}` : 'メニュー',
    tableHeader: (code: string, seats?: string) => seats ? `卓 ${code}・${seats}席` : `卓 ${code}`,
    empty: '本日のメニューがありません',
    noProducts: '商品が見つかりません',
    searchPlaceholder: 'メニューを検索...',
    selectMenu: 'メニューを選択',
    noMenuSelected: 'メニューが未選択です',
    noMenuSelectedHint: 'メニューを選択して商品を表示してください',
    openMenuPicker: 'メニューを選ぶ',
    back: '←',
    allSections: '全て',
  },

  // Cart screen
  cart: {
    title: (tableCode: string) => `注文確認 · テーブル ${tableCode}`,
    empty: 'カートが空です',
    addMore: 'メニューに戻る',
    subtotalLabel: '合計（税抜き目安）',
    submit: '厨房へ送信',
    submitting: '送信中...',
    submitError: '注文を送信できませんでした。もう一度お試しください。',
    cancelItem: '取消',
    fireError: '厨房への送信に失敗しました。プリンターを確認してください。',
    firePartial: '一部のプリンターに送信できませんでした。',
  },

  // Cart bar
  cartBar: {
    viewCart: (n: number) => `カート (${n}点)`,
    subtotal: (amount: string) => `${amount} ›`,
  },

  // Item detail modal
  itemDetail: {
    selectSize: 'サイズを選択',
    toppingRemove: '取り除く',
    topping: 'トッピング',
    toppingHintSingle: '1つ選択',
    toppingHintMultiple: (min: number, max: number) => min > 0 ? `${min}〜${max}つ選択` : `最大${max}つ選択`,
    toppingRequired: (min: number) => `${min}つ以上選んでください`,
    quickNotes: 'よく使うメモ',
    kitchenNote: '厨房へのメモ',
    kitchenNotePlaceholder: '例: ねぎ少なめ、化学調味料なし…',
    quantity: '数量',
    subtotal: '小計',
    addToOrder: '注文に追加',
    addAnother: '＋ もう1品',
    addedSuats: (n: number) => `追加済み ${n}品`,
    free: '無料',
    quickNotesList: ['ねぎ抜き', 'パクチー抜き', '辛さ控えめ', '辛口', '氷少なめ', '砂糖なし', 'テイクアウト', '別盛り'],
  },

  // Pair screen
  pair: {
    title: 'デバイス認証',
    instructions: '管理者がウェブでデバイスを作成し、表示された6桁のコードを入力してください（15分で有効期限切れ）。',
    placeholder: '・',
    submit: '認証する',
    submitting: '認証中...',
    genericError: '認証に失敗しました。もう一度お試しください。',
    wrongDeviceType: 'このコードはハンディ以外のデバイス用です。ハンディ端末のコードで再度お試しください。',
    wsUrlLabel: 'ワークステーション IP（任意）',
    wsUrlPlaceholder: '192.168.1.10 または http://192.168.1.10:8080',
  },

  // Settings
  settings: {
    title: '設定',
    subtitle: 'アプリの設定を変更',
    deviceSection: 'デバイス',
    unpairButton: 'このデバイスの認証を解除',
    unpairConfirmTitle: '認証を解除しますか？',
    unpairConfirmMessage: 'デバイスのペアリングを解除します。再度使用するには6桁コードが必要です。',
    unpairConfirm: '解除する',
    dataSection: 'データ',
    syncButton: '店舗設定を同期',
    syncingButton: '同期中...',
    syncSuccess: '同期しました',
    syncError: '同期失敗',
  },

  // Error boundary
  errorBoundary: {
    title: 'エラーが発生しました',
    retry: '再試行',
  },
};
