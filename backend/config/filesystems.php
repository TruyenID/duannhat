<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | User Upload Disk
    |--------------------------------------------------------------------------
    |
    | Where `POST /api/v1/files/upload` puts what users upload. Deliberately
    | NOT `filesystems.default`: Laravel's default disk is `local`, whose root
    | is `storage/app/private` and whose visibility is private BY DESIGN, while
    | everything uploaded here (product images, brand logos, tier backgrounds)
    | must be readable by customer-web, kiosk and the workstation replica.
    |
    | Binding the two together is what broke production (#2101): uploads landed
    | on the private disk, `Storage::url()` handed out an UNSIGNED `/storage/…`,
    | and `ServeFile` refused it — every image 404'd while the upload itself
    | returned 201 and wrote no log line at all. Point this at `s3` (or any
    | disk publishing an absolute URL) when moving off local storage; keep it
    | off `local`, which `UploadDiskIsPubliclyServableTest` enforces.
    |
    */

    'uploads' => env('UPLOADS_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Print Image Originals
    |--------------------------------------------------------------------------
    |
    | Nơi `PrintImageStore` giữ byte GỐC của ảnh in. Cố ý KHÔNG phải
    | `filesystems.default`, và cũng cố ý KHÔNG công khai.
    |
    | Khác với ảnh người dùng tải lên, byte gốc ở đây không bao giờ đến tay
    | client: thứ máy trạm kéo về là bitmap đã raster, nằm trong cột
    | `print_image_rasters.data`, và không chỗ nào dựng URL từ `original_path`.
    | Byte gốc chỉ được đọc lại phía server khi cần raster một bề rộng chưa dựng
    | sẵn. Nên `local` (private) là ĐÚNG ở đây — đừng "sửa" nó thành `public`
    | cho giống đích upload.
    |
    | Điều cần tránh là để nó bám disk mặc định (#2136): `print_image_assets`
    | không có cột `disk`, nên khi đó nơi byte thật sự nằm chỉ là giá trị biến
    | môi trường lúc ghi. Một lần đổi `FILESYSTEM_DISK` làm mồ côi ảnh đã ghi, và
    | `rasterFor()` nuốt im lặng theo TR-05 ⇒ những bề rộng chưa dựng sẵn lặng lẽ
    | biến mất khỏi feed. Nêu tên disk ở đây cắt đứt sự phụ thuộc đó.
    |
    */

    'print_images' => env('PRINT_IMAGES_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
