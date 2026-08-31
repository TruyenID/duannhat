<?php

/*
 * Per-country tender vocabulary (org-level rows seeded by TillTenderTypeSeeder).
 *
 * #1153 gave every organization an immutable `operating_country` (Platform
 * mirror, default JP); #1156 made the tender vocabulary the source of the
 * 精算 manifest and of `metadata.accepts` validation on payment terminals.
 * This file parametrizes that vocabulary per country — same compliance-profile
 * philosophy: ONE model, country only changes the DATA, never the machinery.
 *
 * Row shape: key, labels (ja/en/vi), category (till_tender_categories key),
 * method (payment_method_code anchor, null = brand row), anchor
 * (is_expected_anchor — row carries the per-method expected at close),
 * terminal (requires_terminal_total — needs a batch figure keyed from a
 * device slip at 精算).
 *
 * Unknown/missing country falls back to JP (matches the column default).
 */

return [

    'JP' => [
        'currency' => 'JPY',
        'tenders' => [
            ['key' => 'cash', 'ja' => '現金', 'en' => 'Cash', 'vi' => 'Tiền mặt', 'category' => 'cash', 'method' => 'cash', 'anchor' => true, 'terminal' => false],
            // Credit anchor (Stera-style slips lump card brands into one section).
            ['key' => 'credit', 'ja' => 'クレジット', 'en' => 'Credit', 'vi' => 'Tín dụng', 'category' => 'card', 'method' => 'card', 'anchor' => true, 'terminal' => true],
            // QR wallet brands.
            ['key' => 'rakuten_pay', 'ja' => '楽天ペイ', 'en' => 'Rakuten Pay', 'vi' => 'Rakuten Pay', 'category' => 'qr', 'method' => null, 'anchor' => false, 'terminal' => true],
            ['key' => 'paypay', 'ja' => 'PayPay', 'en' => 'PayPay', 'vi' => 'PayPay', 'category' => 'qr', 'method' => null, 'anchor' => false, 'terminal' => true],
            ['key' => 'd_barai', 'ja' => 'd払い', 'en' => 'd Barai', 'vi' => 'd Barai', 'category' => 'qr', 'method' => null, 'anchor' => false, 'terminal' => true],
            ['key' => 'au_pay', 'ja' => 'au PAY', 'en' => 'au PAY', 'vi' => 'au PAY', 'category' => 'qr', 'method' => null, 'anchor' => false, 'terminal' => true],
            ['key' => 'merpay', 'ja' => 'メルペイ', 'en' => 'Merpay', 'vi' => 'Merpay', 'category' => 'qr', 'method' => null, 'anchor' => false, 'terminal' => true],
            ['key' => 'ginko_pay', 'ja' => '銀行Pay', 'en' => 'Bank Pay', 'vi' => 'Bank Pay', 'category' => 'qr', 'method' => null, 'anchor' => false, 'terminal' => true],
            ['key' => 'wechat_pay', 'ja' => 'WeChat Pay', 'en' => 'WeChat Pay', 'vi' => 'WeChat Pay', 'category' => 'qr', 'method' => null, 'anchor' => false, 'terminal' => true],
            ['key' => 'alipay', 'ja' => 'Alipay', 'en' => 'Alipay', 'vi' => 'Alipay', 'category' => 'qr', 'method' => null, 'anchor' => false, 'terminal' => true],
            ['key' => 'unionpay', 'ja' => '銀聯', 'en' => 'UnionPay', 'vi' => 'UnionPay', 'category' => 'qr', 'method' => null, 'anchor' => false, 'terminal' => true],
            // Contactless e-money brands.
            ['key' => 'id', 'ja' => 'iD', 'en' => 'iD', 'vi' => 'iD', 'category' => 'emoney', 'method' => null, 'anchor' => false, 'terminal' => true],
            ['key' => 'ic', 'ja' => '交通系IC', 'en' => 'Transit IC', 'vi' => 'IC giao thông', 'category' => 'emoney', 'method' => null, 'anchor' => false, 'terminal' => true],
            ['key' => 'edy', 'ja' => '楽天Edy', 'en' => 'Rakuten Edy', 'vi' => 'Rakuten Edy', 'category' => 'emoney', 'method' => null, 'anchor' => false, 'terminal' => true],
            ['key' => 'waon', 'ja' => 'WAON', 'en' => 'WAON', 'vi' => 'WAON', 'category' => 'emoney', 'method' => null, 'anchor' => false, 'terminal' => true],
            ['key' => 'nanaco', 'ja' => 'nanaco', 'en' => 'nanaco', 'vi' => 'nanaco', 'category' => 'emoney', 'method' => null, 'anchor' => false, 'terminal' => true],
            ['key' => 'quicpay', 'ja' => 'QUICPay', 'en' => 'QUICPay', 'vi' => 'QUICPay', 'category' => 'emoney', 'method' => null, 'anchor' => false, 'terminal' => true],
        ],
    ],

    'VN' => [
        'currency' => 'VND',
        'tenders' => [
            ['key' => 'cash', 'ja' => '現金', 'en' => 'Cash', 'vi' => 'Tiền mặt', 'category' => 'cash', 'method' => 'cash', 'anchor' => true, 'terminal' => false],
            ['key' => 'credit', 'ja' => 'クレジット', 'en' => 'Credit', 'vi' => 'Tín dụng', 'category' => 'card', 'method' => 'card', 'anchor' => true, 'terminal' => true],
            // VietQR bank transfer (chuyển khoản 24/7) — anchors the `transfer`
            // payment method: the expected at close comes from transfer payments;
            // confirmation is the bank app, no device slip.
            ['key' => 'vietqr', 'ja' => 'VietQR', 'en' => 'VietQR', 'vi' => 'VietQR (chuyển khoản)', 'category' => 'qr', 'method' => 'transfer', 'anchor' => true, 'terminal' => false],
            // Wallet brands.
            ['key' => 'momo', 'ja' => 'MoMo', 'en' => 'MoMo', 'vi' => 'MoMo', 'category' => 'qr', 'method' => null, 'anchor' => false, 'terminal' => true],
            ['key' => 'zalopay', 'ja' => 'ZaloPay', 'en' => 'ZaloPay', 'vi' => 'ZaloPay', 'category' => 'qr', 'method' => null, 'anchor' => false, 'terminal' => true],
            ['key' => 'vnpay', 'ja' => 'VNPAY', 'en' => 'VNPAY', 'vi' => 'VNPAY', 'category' => 'qr', 'method' => null, 'anchor' => false, 'terminal' => true],
            ['key' => 'shopeepay', 'ja' => 'ShopeePay', 'en' => 'ShopeePay', 'vi' => 'ShopeePay', 'category' => 'qr', 'method' => null, 'anchor' => false, 'terminal' => true],
        ],
    ],

];
