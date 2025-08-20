<?php
/**
 * 動画ストレージファクトリー
 * 設定に基づいて適切なストレージクラスを返す
 */
require_once __DIR__ . '/../../config/Config.php';
require_once __DIR__ . '/VideoStorageInterface.php';
require_once __DIR__ . '/FileVideoStorage.php';

class VideoStorageFactory
{
    /**
     * 設定に基づいてストレージインスタンスを作成
     */
    public static function create(): VideoStorageInterface
    {
        Config::load();
        $storageType = Config::get('storage.type', 'file');
        
        switch($storageType) {
            case 'file':
                return new FileVideoStorage();
            case 'mysql':
                return self::createMySQLStorage();
            case 'sqlite':
                return self::createSQLiteStorage();
            default:
                return new FileVideoStorage();
        }
    }
    
    /**
     * MySQLストレージを作成（将来の実装）
     */
    private static function createMySQLStorage(): VideoStorageInterface
    {
        // TODO: MySQLVideoStorageクラスを実装
        throw new Exception('MySQL storage is not implemented yet');
    }
    
    /**
     * SQLiteストレージを作成（将来の実装）
     */
    private static function createSQLiteStorage(): VideoStorageInterface
    {
        // TODO: SQLiteVideoStorageクラスを実装
        throw new Exception('SQLite storage is not implemented yet');
    }
}
