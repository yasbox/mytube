<?php
/**
 * ファイルベース動画ストレージ
 * 現在のシステムとの互換性を保ちながら新しいインターフェースに対応
 */
require_once __DIR__ . '/../../config/Config.php';

class FileVideoStorage implements VideoStorageInterface
{
    private string $videosPath;
    private string $thumbnailsPath;
    
    public function __construct()
    {
        Config::load();
        $this->videosPath = Config::get('storage.path', 'videos/');
        $this->thumbnailsPath = Config::get('storage.thumbnails_path', 'thumbnails/');
    }
    
    /**
     * 動画メタデータを取得
     */
    public function getVideoMetadata(string $videoId): array
    {
        $metadataFile = $this->videosPath . $videoId . '.json';
        if (file_exists($metadataFile)) {
            $data = json_decode(file_get_contents($metadataFile), true);
            $metadata = $data ?: ['title' => '', 'comment' => '', 'views' => 0, 'likes' => 0, 'upload_date' => null, 'duration' => null, 'is_public' => true];
            
            // タイトルが空の場合は「タイトルなし」を返す
            if (empty($metadata['title'])) {
                $metadata['title'] = 'タイトルなし';
            }
            
            // 公開状態が設定されていない場合は公開（true）をデフォルトとする
            if (!isset($metadata['is_public'])) {
                $metadata['is_public'] = true;
            }
            
            return $metadata;
        }
        return ['title' => 'タイトルなし', 'comment' => '', 'views' => 0, 'likes' => 0, 'upload_date' => null, 'duration' => null, 'is_public' => true];
    }
    
    /**
     * 動画メタデータを保存
     */
    public function saveVideoMetadata(string $videoId, array $metadata): bool
    {
        $metadataFile = $this->videosPath . $videoId . '.json';
        $currentData = [];
        
        if (file_exists($metadataFile)) {
            $currentData = json_decode(file_get_contents($metadataFile), true) ?? [];
        }
        
        // duration を保存する際は整数秒に統一（後方互換のためこの層でも最終防衛）
        if (array_key_exists('duration', $metadata)) {
            $duration = $metadata['duration'];
            if (is_string($duration)) {
                // 簡易正規化: HH:MM:SS(.xx) / MM:SS(.xx) を秒へ
                if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2})(?:\.\d+)?)?$/', trim($duration), $m)) {
                    $h = isset($m[3]) ? (int)$m[1] : 0;
                    $mi = isset($m[3]) ? (int)$m[2] : (int)$m[1];
                    $s = isset($m[3]) ? (int)$m[3] : (int)$m[2];
                    $metadata['duration'] = ($h * 3600) + ($mi * 60) + $s;
                } elseif (ctype_digit(trim($duration))) {
                    $metadata['duration'] = (int)trim($duration);
                }
            }
            if (is_float($metadata['duration'])) {
                $metadata['duration'] = (int)round($metadata['duration']);
            }
        }
        $currentData = array_merge($currentData, $metadata);
        return file_put_contents($metadataFile, json_encode($currentData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
    }
    
    /**
     * 再生回数をインクリメント
     */
    public function incrementViews(string $videoId): int
    {
        $metadata = $this->getVideoMetadata($videoId);
        $newViews = $metadata['views'] + 1;
        
        $this->saveVideoMetadata($videoId, [
            'views' => $newViews,
            'title' => $metadata['title'],
            'comment' => $metadata['comment'] ?? '',
            'likes' => $metadata['likes'] ?? 0,
            'upload_date' => $metadata['upload_date'] ?? date('Y-m-d H:i:s')
        ]);
        
        return $newViews;
    }
    
    /**
     * いいねをトグル
     */
    public function toggleLike(string $videoId, string $userId = null): array
    {
        $metadata = $this->getVideoMetadata($videoId);
        $newLikes = $metadata['likes'] + 1;
        
        $this->saveVideoMetadata($videoId, [
            'likes' => $newLikes,
            'title' => $metadata['title'],
            'comment' => $metadata['comment'] ?? '',
            'views' => $metadata['views'] ?? 0,
            'upload_date' => $metadata['upload_date'] ?? date('Y-m-d H:i:s')
        ]);
        
        return [
            'likes' => $newLikes,
            'liked' => true
        ];
    }
    
    /**
     * 動画一覧を取得
     */
    public function getVideoList(array $filters = []): array
    {
        $videos = [];
        $videoDir = $this->videosPath;
        
        if (!is_dir($videoDir)) {
            return $videos;
        }
        
        $files = scandir($videoDir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $allowedFormats = Config::get('features.upload.allowed_formats', ['mp4', 'webm', 'ogg', 'avi', 'mov', 'mkv', 'flv']);
            
            if (in_array($ext, $allowedFormats)) {
                $videos[] = $file;
            }
        }
        
        return $videos;
    }
    
    /**
     * 動画ファイルが存在するかチェック
     */
    public function videoExists(string $videoId): bool
    {
        $videoFiles = $this->getVideoList();
        return in_array($videoId, $videoFiles);
    }
    
    /**
     * 動画を削除
     */
    public function deleteVideo(string $videoId): bool
    {
        $videoFiles = $this->getVideoList();
        
        if (!in_array($videoId, $videoFiles)) {
            return false;
        }
        
        $videoPath = $this->videosPath . $videoId;
        $metadataPath = $this->videosPath . pathinfo($videoId, PATHINFO_FILENAME) . '.json';
        $thumbnailPath = $this->thumbnailsPath . pathinfo($videoId, PATHINFO_FILENAME) . '.jpg';
        
        $errors = [];
        
        // 動画ファイルの削除
        if (file_exists($videoPath) && !unlink($videoPath)) {
            $errors[] = '動画ファイルの削除に失敗しました';
        }
        
        // メタデータファイルの削除
        if (file_exists($metadataPath) && !unlink($metadataPath)) {
            $errors[] = 'メタデータファイルの削除に失敗しました';
        }
        
        // サムネイルファイルの削除
        if (file_exists($thumbnailPath) && !unlink($thumbnailPath)) {
            $errors[] = 'サムネイルファイルの削除に失敗しました';
        }
        
        return empty($errors);
    }
    
    /**
     * 統計情報を取得
     */
    public function getStats(): array
    {
        $videos = $this->getVideoList();
        $totalVideos = count($videos);
        $totalViews = 0;
        $totalLikes = 0;
        $totalSize = 0;
        
        foreach ($videos as $video) {
            $basename = pathinfo($video, PATHINFO_FILENAME);
            $metadata = $this->getVideoMetadata($basename);
            $totalViews += $metadata['views'] ?? 0;
            $totalLikes += $metadata['likes'] ?? 0;
            
            $videoPath = $this->videosPath . $video;
            if (file_exists($videoPath)) {
                $totalSize += filesize($videoPath);
            }
        }
        
        return [
            'total_videos' => $totalVideos,
            'total_views' => $totalViews,
            'total_likes' => $totalLikes,
            'total_size' => $totalSize,
            'average_views' => $totalVideos > 0 ? round($totalViews / $totalVideos, 1) : 0,
            'average_likes' => $totalVideos > 0 ? round($totalLikes / $totalVideos, 1) : 0
        ];
    }
    
    /**
     * 動画をソートして取得
     */
    public function getSortedVideos(string $sort = 'new'): array
    {
        $videos = $this->getVideoList();
        
        if (empty($videos)) {
            return [];
        }
        
        // 非公開動画をフィルタリング
        $publicVideos = [];
        foreach ($videos as $video) {
            $basename = pathinfo($video, PATHINFO_FILENAME);
            $metadata = $this->getVideoMetadata($basename);
            
            // is_publicがtrueまたは未設定の場合は公開動画として扱う
            if (($metadata['is_public'] ?? true) !== false) {
                $publicVideos[] = $video;
            }
        }
        
        if (empty($publicVideos)) {
            return [];
        }
        
        switch ($sort) {
            case 'new':
                // 新着順（ファイルの更新日時）
                usort($publicVideos, function($a, $b) {
                    return filemtime($this->videosPath . $b) - filemtime($this->videosPath . $a);
                });
                break;
                
            case 'popular':
                // 人気順（再生数といいね数を組み合わせた評価値）
                usort($publicVideos, function($a, $b) {
                    $aBasename = pathinfo($a, PATHINFO_FILENAME);
                    $bBasename = pathinfo($b, PATHINFO_FILENAME);
                    $aMetadata = $this->getVideoMetadata($aBasename);
                    $bMetadata = $this->getVideoMetadata($bBasename);
                    
                    $aScore = ($aMetadata['views'] ?? 0) + (($aMetadata['likes'] ?? 0) * 2);
                    $bScore = ($bMetadata['views'] ?? 0) + (($bMetadata['likes'] ?? 0) * 2);
                    
                    return $bScore - $aScore;
                });
                break;
                
            case 'views':
                // 再生数順
                usort($publicVideos, function($a, $b) {
                    $aBasename = pathinfo($a, PATHINFO_FILENAME);
                    $bBasename = pathinfo($b, PATHINFO_FILENAME);
                    $aMetadata = $this->getVideoMetadata($aBasename);
                    $bMetadata = $this->getVideoMetadata($bBasename);
                    
                    return ($bMetadata['views'] ?? 0) - ($aMetadata['views'] ?? 0);
                });
                break;
                
            case 'likes':
                // いいね数順
                usort($publicVideos, function($a, $b) {
                    $aBasename = pathinfo($a, PATHINFO_FILENAME);
                    $bBasename = pathinfo($b, PATHINFO_FILENAME);
                    $aMetadata = $this->getVideoMetadata($aBasename);
                    $bMetadata = $this->getVideoMetadata($bBasename);
                    
                    return ($bMetadata['likes'] ?? 0) - ($aMetadata['likes'] ?? 0);
                });
                break;
        }
        
        return $publicVideos;
    }
}
