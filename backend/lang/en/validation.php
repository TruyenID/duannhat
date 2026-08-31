<?php

/**
 * PARTIAL override, on purpose (#1780).
 *
 * `Illuminate\Translation\FileLoader::loadPaths()` merges the framework's own
 * `lang/<locale>/validation.php` with this one via `array_replace_recursive`,
 * framework first. So this file only needs the keys the app adds — every stock
 * message ("The :attribute field is required.") still resolves. Do NOT paste
 * the full framework file in here: it would then be a frozen copy that stops
 * tracking Laravel upgrades.
 */
return [
    'custom' => [
        'password' => [
            // Bốn dòng này khớp 1-1 với checklist dưới ô mật khẩu ở
            // customer-web. Sửa một bên thì phải sửa bên kia — xem
            // App\Rules\StrongCustomerPassword.
            'strong' => [
                'min_length' => 'Password must be at least :min characters.',
                'uppercase' => 'Password must contain an uppercase letter.',
                'letters_and_numbers' => 'Password must contain both letters and numbers.',
                'symbol' => 'Password must contain a special character.',
            ],
        ],
    ],
];
