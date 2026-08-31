import type { Translations } from './ja';

export const vi: Translations = {
  // Common
  retry: 'Thử lại',
  cancel: 'Hủy',
  save: 'Lưu',
  saving: 'Đang lưu...',
  loading: 'Đang tải...',
  error: 'Lỗi',
  endOfList: '— Hết danh sách —',

  // Tables screen
  tables: {
    loadError: 'Không thể tải dữ liệu.',
    empty: 'Không có bàn nào',
    noOpenOrders: 'Không có đơn hàng đang mở',
    busyAlert: 'hiện không khả dụng.',
    createOrderError: 'Không thể tạo đơn hàng. Vui lòng thử lại.',
    createOrderConfirmTitle: (tableCode: string) => `Bàn ${tableCode} — Tạo đơn hàng`,
    createOrderConfirmMessage: 'Bạn có muốn tạo đơn hàng mới cho bàn này không?',
    createOrderConfirmButton: 'Tạo đơn',
  },

  // Table status
  tableStatus: {
    free: 'Trống',
    occupied: 'Đang dùng',
    reserved: 'Đặt trước',
    cleaning: 'Đang dọn',
    out_of_service: 'Không dùng được',
  },

  // Table card
  tableCard: {
    guestSeats: (n: number) => `${n} ghế`,
    itemCount: (n: number) => `${n} món`,
    readyItems: (n: number) => `${n} món sẵn sàng`,
    dotReady: (n: number) => `🟢 ${n} món ra`,
    dotPreparing: (n: number) => `🟡 ${n} đang làm`,
  },

  // Guest count dialog
  guestDialog: {
    title: 'Bàn',
    subtitle: 'Bao nhiêu khách?',
    confirm: 'Tạo đơn hàng',
    creating: 'Đang tạo...',
  },

  // Order detail
  order: {
    back: '‹ Quay lại',
    empty: 'Chưa có món nào',
    addItem: '＋ Thêm từ thực đơn',
    loadError: 'Tải thất bại',
    guestCount: (n: number) => `${n} khách`,
    headerTitle: (tableCode: string) => `Đơn hàng · Bàn ${tableCode}`,
    itemCountSuffix: (n: number) => `· ${n} món`,
    elapsedMin: (n: number) => `${n} phút`,
    elapsedHourMin: (h: number, m: number) => `${h} giờ ${m} phút`,
    elapsedHour: (h: number) => `${h} giờ`,
    statPending: 'Chờ bếp',
    statPreparing: 'Đang làm',
    statReady: 'Xong bếp',
    statServed: 'Đã phục vụ',
    statTotal: 'Tổng',
    addMore: '＋ Thêm món',
  },

  // Order status
  orderStatus: {
    open: 'Đang mở',
    dining: 'Đang ăn',
    checkout: 'Chờ thanh toán',
    paying: 'Đang thanh toán',
    closed: 'Đã đóng',
    voided: 'Đã hủy',
  },

  voidOrder: {
    button: 'Huỷ đơn hàng',
    dialogTitle: 'Huỷ đơn hàng?',
    dialogSubtitle: 'Hành động này không thể hoàn tác.',
    reasonLabel: 'Lý do huỷ',
    reasonPlaceholder: 'Ví dụ: khách đổi ý, nhầm order...',
    confirm: 'Xác nhận huỷ',
    confirming: 'Đang xử lý...',
    blockedTitle: 'Không thể huỷ',
    blockedMessage: 'Có món đang được chế biến hoặc đã phục vụ.',
  },

  // Order item row
  orderItem: {
    noName: '(Không có tên)',
    noteLabel: 'Nhập ghi chú',
    notePlaceholder: 'Ví dụ: ít cay, dị ứng...',
    cancel: 'Hủy',
  },

  // Order row
  orderRow: {
    itemCount: (n: number) => `${n} món`,
  },

  // Order item status
  itemStatus: {
    pending: 'Chờ',
    preparing: 'Đang chế biến',
    ready: 'Sẵn sàng',
    served: 'Đã phục vụ',
    voided: 'Đã hủy',
  },

  itemStatusAction: {
    markServed: 'Đã phục vụ',
  },

  // Order summary
  summary: {
    subtotal: 'Tạm tính',
    tax: 'Thuế',
    serviceCharge: 'Phí dịch vụ',
    total: 'Tổng cộng',
    // plan-043 — per-rate consumption-tax labels.
    taxRateLine: (rate: number) => `Chịu thuế ${rate}%`,
    taxIncludedInline: 'Thuế đã gồm',
    taxIncludedBadge: 'Đã gồm thuế',
  },

  // Menu screen
  menu: {
    title: (tableName?: string) => tableName ? `Thực đơn · ${tableName}` : 'Thực đơn',
    tableHeader: (code: string, seats?: string) => seats ? `Bàn ${code}・${seats} ghế` : `Bàn ${code}`,
    empty: 'Không có thực đơn hôm nay',
    noProducts: 'Không tìm thấy món',
    searchPlaceholder: 'Tìm kiếm thực đơn...',
    selectMenu: 'Chọn thực đơn',
    noMenuSelected: 'Chưa chọn thực đơn',
    noMenuSelectedHint: 'Vui lòng chọn thực đơn để xem món',
    openMenuPicker: 'Chọn thực đơn',
    back: '←',
    allSections: 'Tất cả',
  },

  // Cart screen
  cart: {
    title: (tableCode: string) => `Xác nhận đơn · Bàn ${tableCode}`,
    empty: 'Giỏ hàng trống',
    addMore: 'Quay lại thực đơn',
    subtotalLabel: 'Tổng (chưa thuế)',
    submit: 'Gửi Bếp',
    submitting: 'Đang gửi...',
    submitError: 'Không thể gửi đơn. Vui lòng thử lại.',
    cancelItem: 'Hủy',
    fireError: 'Gửi bếp thất bại. Vui lòng kiểm tra máy in.',
    firePartial: 'Một số máy in không gửi được.',
  },

  // Cart bar
  cartBar: {
    viewCart: (n: number) => `Giỏ hàng (${n} món)`,
    subtotal: (amount: string) => `${amount} ›`,
  },

  // Item detail modal
  itemDetail: {
    selectSize: 'Chọn kích cỡ',
    toppingRemove: 'Bỏ bớt',
    topping: 'Topping',
    toppingHintSingle: 'Chọn 1',
    toppingHintMultiple: (min: number, max: number) => min > 0 ? `Chọn ${min}–${max}` : `Chọn tối đa ${max}`,
    toppingRequired: (min: number) => `Vui lòng chọn ít nhất ${min}`,
    quickNotes: 'Ghi chú nhanh',
    kitchenNote: 'Ghi chú bếp',
    kitchenNotePlaceholder: 'Ví dụ: ít hành, không mì chính…',
    quantity: 'Số lượng',
    subtotal: 'Tạm tính',
    addToOrder: 'Thêm vào đơn hàng',
    addAnother: '+ Thêm 1 suất nữa',
    addedSuats: (n: number) => `Đã thêm ${n} suất`,
    free: 'Miễn phí',
    quickNotesList: ['Không hành', 'Không rau mùi', 'Ít cay', 'Cay', 'Ít đá', 'Không đường', 'Mang về', 'Để riêng'],
  },

  // Pair screen
  pair: {
    title: 'Xác thực thiết bị',
    instructions: 'Quản trị viên tạo thiết bị trên web và nhập mã 6 chữ số được hiển thị (hết hạn sau 15 phút).',
    placeholder: '・',
    submit: 'Xác thực',
    submitting: 'Đang xác thực...',
    genericError: 'Xác thực thất bại. Vui lòng thử lại.',
    wrongDeviceType: 'Mã này thuộc thiết bị khác, không dùng cho ứng dụng Handy. Vui lòng pair lại bằng mã thiết bị handy.',
    wsUrlLabel: 'IP Workstation (tuỳ chọn)',
    wsUrlPlaceholder: '192.168.1.10 hoặc http://192.168.1.10:8080',
  },

  // Settings
  settings: {
    title: 'Cài đặt',
    subtitle: 'Thay đổi cài đặt ứng dụng',
    deviceSection: 'THIẾT BỊ',
    unpairButton: 'Huỷ ghép nối thiết bị này',
    unpairConfirmTitle: 'Huỷ ghép nối?',
    unpairConfirmMessage: 'Thiết bị sẽ bị huỷ ghép nối. Cần nhập lại mã 6 ký tự để sử dụng lại.',
    unpairConfirm: 'Huỷ ghép nối',
    dataSection: 'DỮ LIỆU',
    syncButton: 'Đồng bộ cài đặt cửa hàng',
    syncingButton: 'Đang đồng bộ...',
    syncSuccess: 'Đồng bộ thành công',
    syncError: 'Đồng bộ thất bại',
  },

  // Error boundary
  errorBoundary: {
    title: 'Đã xảy ra lỗi',
    retry: 'Thử lại',
  },
};
