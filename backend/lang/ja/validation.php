<?php

/** Partial override — 説明は `lang/en/validation.php` を参照（#1780）。 */
return [
    'custom' => [
        'password' => [
            'strong' => [
                'min_length' => 'パスワードは :min 文字以上で入力してください。',
                'uppercase' => 'パスワードには大文字を含めてください。',
                'letters_and_numbers' => 'パスワードには英字と数字の両方を含めてください。',
                'symbol' => 'パスワードには記号を含めてください。',
            ],
        ],
    ],
];
