<?php
/**
 * 既定設定ファイル（コード側）
 * アプリケーションの基本設定を定義
 */
return [
    'app' => [
        'name' => 'MyTube',
        'version' => '1.0.0',
        'debug' => false,
        'timezone' => 'Asia/Tokyo'
    ],
    'brand' => [
        'name' => 'MyTube',
        'logo' => 'images/logo.png',
        'favicon' => 'favicon.svg',
        'primary_color' => '#3B82F6',
        'secondary_color' => '#1E40AF'
    ],
    'features' => [
        'upload' => [
            'allowed_formats' => ['mp4', 'webm', 'ogg', 'avi', 'mov', 'mkv', 'flv'],
            'max_size_mb' => 200,
            'auto_convert' => true,
            'thumbnail_generation' => true
        ],
        'views' => [
            'unique_countup' => false
        ],
        'likes' => [
            'unique_countup' => false
        ]
    ],
    'ui' => [
        'layout' => 'default',
        'show_stats' => true,
        'infinite_scroll' => true,
        'video_grid_cols' => [
            'mobile' => 1,
            'tablet' => 2,
            'desktop' => 4
        ],
        'default_thumbnail' => 'images/default-thumbnail.svg',
        'default_thumbnail_small' => 'images/default-thumbnail-small.svg'
    ],
    'storage' => [
        'type' => 'file',
        'path' => 'videos/',
        'thumbnails_path' => 'thumbnails/',
        'ffmpeg_path' => '/usr/bin/ffmpeg'
    ],
    'security' => [
        'admin_password' => 'admin123',
        'admin_session_lifetime' => 86400,
        'remember_me_lifetime' => 30 * 24 * 3600,
        'remember_me_cookie_name' => 'MyTube_remember',
        // 動画・サムネイルの専用 URL が切り替わる間隔（秒）。発行した URL はこの1〜2倍の時間有効
        'media_url_ttl' => 6 * 3600
    ],
    'video' => [
        'mime_types' => [
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'ogg' => 'video/ogg',
            'avi' => 'video/x-msvideo',
            'mov' => 'video/quicktime',
            'mkv' => 'video/x-matroska',
            'flv' => 'video/x-flv'
        ],
        'conversion' => [
            'enabled' => true,
            'codec' => 'libx264',
            'preset' => 'medium',
            'crf' => '23',
            'audio_codec' => 'aac',
            'audio_bitrate' => '128k',
            'web_optimize' => true,
            'convertible_formats' => ['webm', 'ogg', 'avi', 'mov', 'mkv', 'flv', 'wmv', '3gp', 'm4v', 'mp4']
        ]
    ],
    // 既存の定義に追加: サムネイル抽出時間
    'video_thumbnail_time' => '00:00:01.000'
];


