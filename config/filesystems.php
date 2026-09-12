<?php

$awsDisk = static function (string $rootEnv, string $defaultRoot, string $bucketEnv): array {
    return [
        'driver' => 's3',
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION'),
        'bucket' => env($bucketEnv, env('AWS_BUCKET')),
        'url' => env('AWS_URL'),
        'endpoint' => env('AWS_ENDPOINT'),
        'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
        'root' => env($rootEnv, $defaultRoot),
        'visibility' => 'private',
        'throw' => true,
        'report' => false,
    ];
};

return [

    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'visibility' => 'private',
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        /*
         | Private evidence (verification, payment evidence, dispute attachments).
         | Staging: SENSITIVE_DISK_DRIVER=s3 with a private bucket (no public ACL).
         */
        'sensitive' => env('SENSITIVE_DISK_DRIVER', 'local') === 's3'
            ? $awsDisk('SENSITIVE_S3_ROOT', 'sensitive', 'SENSITIVE_AWS_BUCKET')
            : [
                'driver' => 'local',
                'root' => storage_path('app/private/sensitive'),
                'visibility' => 'private',
                'serve' => false,
                'throw' => true,
                'report' => false,
            ],

        /*
         | Campaign marketing resources + Campaign Cover.
         | Staging: CAMPAIGN_MEDIA_DISK_DRIVER=s3 with a private bucket (no public ACL).
         */
        'campaign_media' => env('CAMPAIGN_MEDIA_DISK_DRIVER', 'local') === 's3'
            ? $awsDisk('CAMPAIGN_MEDIA_S3_ROOT', 'campaign-media', 'CAMPAIGN_MEDIA_AWS_BUCKET')
            : [
                'driver' => 'local',
                'root' => storage_path('app/private/campaign-media'),
                'visibility' => 'private',
                'serve' => false,
                'throw' => true,
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
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ],

    ],

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
