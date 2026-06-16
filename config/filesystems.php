<?php

// Which driver backs the private KYC document disk. Local for dev, s3 (or any
// S3-compatible store like Spaces/R2) in production — chosen here so the disk
// name stays 'deal_documents' everywhere in the app and only the env changes.
$dealDocumentsDriver = env('DEAL_DOCUMENTS_DISK_DRIVER', 'local');

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

        // Buyer KYC documents (driver's licence, proof of income, void cheque,
        // proof of insurance). Always private — never the public disk, never a
        // public URL. Files are streamed through a guarded download route when
        // dealer retrieval lands; nothing here is web-reachable on its own.
        'deal_documents' => $dealDocumentsDriver === 's3'
            ? [
                'driver' => 's3',
                'key' => env('DEAL_DOCUMENTS_AWS_ACCESS_KEY_ID', env('AWS_ACCESS_KEY_ID')),
                'secret' => env('DEAL_DOCUMENTS_AWS_SECRET_ACCESS_KEY', env('AWS_SECRET_ACCESS_KEY')),
                'region' => env('DEAL_DOCUMENTS_AWS_DEFAULT_REGION', env('AWS_DEFAULT_REGION')),
                'bucket' => env('DEAL_DOCUMENTS_AWS_BUCKET'),
                'endpoint' => env('DEAL_DOCUMENTS_AWS_ENDPOINT', env('AWS_ENDPOINT')),
                'use_path_style_endpoint' => env('DEAL_DOCUMENTS_AWS_USE_PATH_STYLE_ENDPOINT', false),
                'visibility' => 'private',
                'throw' => false,
                'report' => false,
            ]
            : [
                'driver' => 'local',
                'root' => storage_path('app/private/deal-documents'),
                'serve' => false,
                'visibility' => 'private',
                'throw' => false,
                'report' => false,
            ],

    ],

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
