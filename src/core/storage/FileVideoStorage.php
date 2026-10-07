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
     * 動画メタデータを保存（保存済みの内容に $metadata を上書きでマージする。排他して行う）
     */
    public function saveVideoMetadata(string $videoId, array $metadata): bool
    {
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
        return $this->withMetadataLock(function () use ($videoId, $metadata) {
            return $this->writeMetadataFile($videoId, array_merge($this->readMetadataFile($videoId), $metadata));
        });
    }

    /**
     * 再生回数をインクリメント（同時に再生されても取りこぼさないよう、排他して1だけ加算する）
     */
    public function incrementViews(string $videoId): int
    {
        return $this->incrementCounter($videoId, 'views');
    }

    /**
     * いいねを加算（取り消しは無い）
     */
    public function toggleLike(string $videoId, string $userId = null): array
    {
        return [
            'likes' => $this->incrementCounter($videoId, 'likes'),
            'liked' => true
        ];
    }

    /**
     * メタデータの項目を排他して1だけ加算し、加算後の値を返す（他の項目は書き換えない）
     */
    private function incrementCounter(string $videoId, string $field): int
    {
        return $this->withMetadataLock(function () use ($videoId, $field) {
            $data = $this->readMetadataFile($videoId);
            $data[$field] = (int)($data[$field] ?? 0) + 1;
            $this->writeMetadataFile($videoId, $data);
            return $data[$field];
        });
    }

    /**
     * メタデータの書き換え（読み込み → 変更 → 書き込み）を排他して行う
     * 書き換えは短時間で終わるため、全動画で1つのロックを使う
     */
    private function withMetadataLock(callable $fn)
    {
        $lock = @fopen(dirname(Config::getSettingsJsonPath()) . DIRECTORY_SEPARATOR . 'metadata.lock', 'c');
        if ($lock) {
            flock($lock, LOCK_EX);
        }
        try {
            return $fn();
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /**
     * 保存されているメタデータをそのまま読む（表示用の補完はしない）
     */
    private function readMetadataFile(string $videoId): array
    {
        $metadataFile = $this->videosPath . $videoId . '.json';
        if (!is_file($metadataFile)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($metadataFile), true);
        return is_array($data) ? $data : [];
    }

    /**
     * メタデータを一時ファイルに書いてから置き換える（読み込み中に書きかけを読まないように）
     */
    private function writeMetadataFile(string $videoId, array $data): bool
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }
        $metadataFile = $this->videosPath . $videoId . '.json';
        $tmp = $metadataFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $json) === false) {
            return false;
        }
        if (!@rename($tmp, $metadataFile)) {
            @unlink($tmp);
            return false;
        }
        return true;
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
}
