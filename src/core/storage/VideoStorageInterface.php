<?php
/**
 * 動画ストレージインターフェース
 * ファイルベースとデータベースベースの両方に対応
 */
interface VideoStorageInterface
{
    /**
     * 動画メタデータを取得
     */
    public function getVideoMetadata(string $videoId): array;
    
    /**
     * 動画メタデータを保存
     */
    public function saveVideoMetadata(string $videoId, array $metadata): bool;
    
    /**
     * 再生回数をインクリメント
     */
    public function incrementViews(string $videoId): int;
    
    /**
     * いいねをトグル
     */
    public function toggleLike(string $videoId, string $userId = null): array;
    
    /**
     * 動画一覧を取得
     */
    public function getVideoList(array $filters = []): array;
    
    /**
     * 動画ファイルが存在するかチェック
     */
    public function videoExists(string $videoId): bool;
    
    /**
     * 動画を削除
     */
    public function deleteVideo(string $videoId): bool;
    
    /**
     * 統計情報を取得
     */
    public function getStats(): array;
    
    /**
     * 動画をソートして取得
     */
    public function getSortedVideos(string $sort = 'new'): array;
}
