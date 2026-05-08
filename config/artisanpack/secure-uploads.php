<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Secure Uploads — File Upload Security
|--------------------------------------------------------------------------
|
| Configure secure file upload handling with comprehensive validation,
| threat protection, malware scanning, rate limiting, and secure storage.
|
| Accessed throughout the package as `config('artisanpack.secure-uploads.X')`.
|
*/

return [
    'enabled' => env('SECURE_UPLOADS_ENABLED', true),

    /*
     * File type validation — allowlists
     */
    'allowedMimeTypes' => [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
        'text/plain',
        'text/csv',
    ],

    'allowedExtensions' => [
        'jpg', 'jpeg', 'png', 'gif', 'webp',
        'pdf', 'txt', 'csv',
    ],

    /*
     * Blocked patterns — always rejected regardless of allowlists
     */
    'blockedExtensions' => [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps',
        'exe', 'com', 'bat', 'cmd', 'sh', 'bash',
        'js', 'jsx', 'ts', 'tsx',
        'asp', 'aspx', 'jsp', 'cgi', 'pl', 'py', 'rb',
        'htaccess', 'htpasswd',
        'svg', // Can contain embedded scripts
    ],

    'blockedMimeTypes' => [
        'application/x-httpd-php',
        'application/x-php',
        'text/x-php',
        'application/x-executable',
        'application/x-msdownload',
        'application/javascript',
        'text/javascript',
        'image/svg+xml', // Can contain embedded scripts
    ],

    /*
     * Size restrictions
     */
    'maxFileSize' => 10 * 1024 * 1024, // 10 MB default (in bytes)
    'maxFileSizePerType' => [
        'image/*' => 5 * 1024 * 1024,         // 5 MB for images
        'application/pdf' => 20 * 1024 * 1024, // 20 MB for PDFs
    ],

    /*
     * Content validation
     */
    'validateMimeByContent' => true,   // Inspect actual file content, not just extension
    'checkForDoubleExtensions' => true, // Detect file.php.jpg tricks
    'checkForNullBytes' => true,       // Detect file.php%00.jpg tricks
    'stripExifData' => true,           // Remove EXIF metadata from images

    /*
     * Malware scanning integration
     */
    'malwareScanning' => [
        'enabled' => env('SECURE_UPLOADS_MALWARE_SCANNING_ENABLED', false),
        'driver' => env('SECURE_UPLOADS_MALWARE_DRIVER', 'null'), // null, clamav, virustotal
        'failOnScanError' => true,      // Reject upload if scanner is unavailable
        'async' => false,               // Scan asynchronously (quarantine until scanned)
        'quarantinePath' => storage_path('app/quarantine'),

        // ClamAV configuration
        'clamav' => [
            'socketPath' => env('CLAMAV_SOCKET_PATH', '/var/run/clamav/clamd.sock'),
            'binaryPath' => env('CLAMAV_BINARY_PATH', '/usr/bin/clamscan'),
            'timeout' => 30,
        ],

        // VirusTotal configuration
        'virustotal' => [
            'apiKey' => env('VIRUSTOTAL_API_KEY'),
            'timeout' => 60,
        ],
    ],

    /*
     * Rate limiting for uploads
     */
    'rateLimiting' => [
        'enabled' => env('SECURE_UPLOADS_RATE_LIMITING_ENABLED', true),
        'maxUploadsPerMinute' => 10,
        'maxUploadsPerHour' => 100,
        'maxTotalSizePerHour' => 100 * 1024 * 1024, // 100 MB per hour
    ],

    /*
     * Secure storage settings
     */
    'storage' => [
        'disk' => env('SECURE_UPLOADS_DISK', 'local'),
        'path' => 'secure-uploads',
        'hashFilenames' => true,        // Store with hashed names
        'preserveOriginalName' => true, // Store original name in metadata
        'organizeByDate' => true,       // Store in YYYY/MM/DD subdirectories
    ],

    /*
     * Secure file serving
     */
    'serving' => [
        'useSignedUrls' => true,
        'signedUrlExpiration' => 60,    // minutes
        'forceDownload' => false,       // Force Content-Disposition: attachment
        'allowedReferrers' => [],       // Empty = allow all, or list of allowed domains
    ],

    /*
     * Event logging
     */
    'logging' => [
        'uploads' => true,
        'rejections' => true,
        'malwareDetections' => true,
        'downloads' => true,
    ],
];
