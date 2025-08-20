<?php
/**
 * アプリケーション初期化クラス
 * 設定の読み込みと基本設定を行う
 */
require_once __DIR__ . '/../config/Config.php';
require_once __DIR__ . '/utils/Env.php';

class Bootstrap
{
    private static bool $initialized = false;
    
    /**
     * アプリケーションを初期化
     */
    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }
        
        // ログ設定は環境側の標準設定を使用

        // Load .env for non-Docker environments (or when env vars are not provided)
        $envPath = __DIR__ . '/../.env';
        if (file_exists($envPath)) {
            Env::load($envPath);
        }

        // 設定を読み込み
        Config::load();
        
        // タイムゾーンを設定
        $timezone = Config::get('app.timezone', 'Asia/Tokyo');
        date_default_timezone_set($timezone);
        
        // エラー出力設定
        $debug = Config::get('app.debug', false);
        if ($debug) {
            ini_set('display_errors', 1);
            ini_set('display_startup_errors', 1);
            error_reporting(E_ALL);
        } else {
            ini_set('display_errors', 0);
            ini_set('display_startup_errors', 0);
            error_reporting(0);
        }
        
        // セッション設定は各ページで行う
        
        self::$initialized = true;
    }
    
    /**
     * 初期化済みかチェック
     */
    public static function isInitialized(): bool
    {
        return self::$initialized;
    }
}
