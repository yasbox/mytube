<?php
/**
 * 共通関数クラス
 * 新しいストレージシステムを使用
 */
require_once __DIR__ . '/../storage/VideoStorageFactory.php';
require_once __DIR__ . '/../Bootstrap.php';

class Functions
{
    private static VideoStorageInterface $storage;
    
    /**
     * クラス初期化時にBootstrapを初期化
     */
    private static function initBootstrap(): void
    {
        Bootstrap::init();
    }
    
    /**
     * さまざまな形式の長さ表現を秒数に変換
     * 受け入れる形式:
     * - 整数（秒）
     * - 数字文字列（"96" など）
     * - 時間表記文字列（"HH:MM:SS" or "MM:SS"）
     * - 小数点を含む時間表記（"HH:MM:SS.XX" or "MM:SS.XX"）
     */
    private static function parseDurationToSeconds($value): ?int
    {
        if ($value === null) {
            return null;
        }
        
        if (is_int($value)) {
            return $value;
        }
        
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }
            
            // 時間表記（HH:MM:SS or MM:SS、小数点を含む場合も対応）
            if (preg_match('/^\d{1,2}:\d{2}(:\d{2}(\.\d+)?)?$/', $trimmed)) {
                $parts = explode(':', $trimmed);
                if (count($parts) === 3) {
                    // HH:MM:SS または HH:MM:SS.XX
                    $seconds = (float)$parts[2];
                    return (int)($parts[0] * 3600 + $parts[1] * 60 + $seconds);
                }
                if (count($parts) === 2) {
                    // MM:SS または MM:SS.XX
                    $seconds = (float)$parts[1];
                    return (int)($parts[0] * 60 + $seconds);
                }
            }
            
            // 数字のみ
            if (ctype_digit($trimmed)) {
                return (int)$trimmed;
            }
        }
        
        return null;
    }
    
    /**
     * ストレージを初期化
     */
    private static function initStorage(): void
    {
        // Bootstrapを初期化
        self::initBootstrap();
        
        if (!isset(self::$storage)) {
            self::$storage = VideoStorageFactory::create();
        }
    }
    
    /**
     * 動画のメタデータを取得
     */
    public static function getVideoMetadata(string $basename): array
    {
        self::initStorage();
        return self::$storage->getVideoMetadata($basename);
    }
    
    /**
     * 動画のメタデータを保存
     */
    public static function saveVideoMetadata(string $basename, array $data): bool
    {
        self::initStorage();
        // duration が含まれている場合は整数秒に正規化して保存
        if (array_key_exists('duration', $data)) {
            $normalized = self::parseDurationToSeconds($data['duration']);
            $data['duration'] = $normalized; // パース不可は null のまま
        }
        return self::$storage->saveVideoMetadata($basename, $data);
    }
    
    /**
     * 動画ファイルの一覧を取得
     */
    public static function getVideoFiles(): array
    {
        self::initStorage();
        return self::$storage->getVideoList();
    }
    
    /**
     * ソートされた動画一覧を取得
     * 戻り値はフロント用に整形された配列
     * [
     *   [
     *     'id' => string,           // 動画ID（ベース名）
     *     'filename' => string,     // 実ファイル名
     *     'title' => string,
     *     'thumbnail' => string,    // サムネイルパス
     *     'duration' => ?int,
     *     'views' => int,
     *     'likes' => int,
     *     'upload_date' => string   // 'Y-m-d H:i:s'
     *   ],
     *   ...
     * ]
     */
    public static function getSortedVideos(string $sort = 'new'): array
    {
        self::initStorage();
        $files = self::$storage->getSortedVideos($sort);
        $videos = [];

        $videosPath = Config::get('storage.path', 'videos/');
        $thumbnailsPath = Config::get('storage.thumbnails_path', 'thumbnails/');

        foreach ($files as $file) {
            // 既に整形済みの要素が来た場合はそのまま取り込む
            if (is_array($file)) {
                $videos[] = $file;
                continue;
            }

            if (!is_string($file)) {
                continue;
            }

            $basename = pathinfo($file, PATHINFO_FILENAME);
            $metadata = self::$storage->getVideoMetadata($basename);

            $videoFilePath = $videosPath . $file;
            $thumbnailFilePath = $thumbnailsPath . $basename . '.jpg';
            $thumbnail = file_exists($thumbnailFilePath)
                ? ($thumbnailFilePath . '?v=' . filemtime($thumbnailFilePath))
                : 'images/default-thumbnail-small.svg';

            $uploadDate = $metadata['upload_date'] ?? null;
            if ($uploadDate) {
                $uploadDate = date('Y-m-d H:i:s', strtotime($uploadDate));
            } else {
                $uploadDate = file_exists($videoFilePath)
                    ? date('Y-m-d H:i:s', filemtime($videoFilePath))
                    : date('Y-m-d H:i:s');
            }

            $videos[] = [
                'id' => $basename,
                'filename' => $file,
                'title' => $metadata['title'] ?? 'タイトルなし',
                'thumbnail' => $thumbnail,
                'duration' => self::parseDurationToSeconds($metadata['duration'] ?? null),
                'views' => $metadata['views'] ?? 0,
                'likes' => $metadata['likes'] ?? 0,
                'upload_date' => $uploadDate,
            ];
        }

        return $videos;
    }
    
    /**
     * 動画統計情報を取得
     */
    public static function getVideoStats(): array
    {
        self::initStorage();
        return self::$storage->getStats();
    }
    
    /**
     * 動画を削除
     */
    public static function deleteVideo(string $videoFile): array
    {
        self::initStorage();
        
        if (!self::$storage->videoExists($videoFile)) {
            return ['success' => false, 'message' => '動画ファイルが見つかりません'];
        }
        
        $success = self::$storage->deleteVideo($videoFile);
        
        if ($success) {
            return ['success' => true, 'message' => '動画を削除しました'];
        } else {
            return ['success' => false, 'message' => '動画の削除に失敗しました'];
        }
    }
    
    /**
     * 動画メタデータを更新
     */
    public static function updateVideoMetadata(string $videoFile, string $title, string $comment): array
    {
        self::initStorage();
        
        if (!self::$storage->videoExists($videoFile)) {
            return ['success' => false, 'message' => '動画ファイルが見つかりません'];
        }
        
        $basename = pathinfo($videoFile, PATHINFO_FILENAME);
        $currentMetadata = self::$storage->getVideoMetadata($basename);
        
        $newMetadata = [
            'title' => trim($title),
            'comment' => trim($comment),
            'views' => $currentMetadata['views'] ?? 0,
            'likes' => $currentMetadata['likes'] ?? 0,
            'upload_date' => $currentMetadata['upload_date'] ?? date('Y-m-d H:i:s'),
            'duration' => $currentMetadata['duration'] ?? null
        ];
        
        $success = self::$storage->saveVideoMetadata($basename, $newMetadata);
        
        if ($success) {
            return ['success' => true, 'message' => 'メタデータを更新しました'];
        } else {
            return ['success' => false, 'message' => 'メタデータの更新に失敗しました'];
        }
    }
    
    /**
     * ファイルサイズを人間が読みやすい形式に変換
     */
    public static function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }
    
    /**
     * 動画の長さをフォーマット
     */
    public static function formatDuration($duration): string
    {
        // null/空は明示的に0:00
        if ($duration === null || $duration === '') {
            return '0:00';
        }

        // あらゆる受け入れ形式を整数秒に正規化（小数点付きHH:MM:SS.XX / MM:SS.XXも対応）
        $totalSeconds = self::parseDurationToSeconds($duration);

        // パースできない場合は保守的に0:00を返す（生表示は避ける）
        if ($totalSeconds === null) {
            return '0:00';
        }

        if ($totalSeconds <= 0) {
            return '0:00';
        }

        $hours = (int)floor($totalSeconds / 3600);
        $minutes = (int)floor(($totalSeconds % 3600) / 60);
        $secs = (int)($totalSeconds % 60);

        // 表示ポリシー: 1時間以上は H:MM:SS、未満は M:SS
        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
        }
        return sprintf('%d:%02d', $minutes, $secs);
    }
    
    /**
     * 日付をフォーマット
     */
    public static function formatDate(string $dateString): string
    {
        $date = new DateTime($dateString);
        $now = new DateTime();
        $diff = $now->diff($date);
        
        if ($diff->days == 0) {
            if ($diff->h == 0) {
                if ($diff->i == 0) {
                    return '今';
                } else {
                    return $diff->i . '分前';
                }
            } else {
                return $diff->h . '時間前';
            }
        } elseif ($diff->days == 1) {
            return '昨日';
        } elseif ($diff->days < 7) {
            return $diff->days . '日前';
        } else {
            return $date->format('Y/m/d');
        }
    }
    
    /**
     * 管理用動画一覧を取得
     */
    public static function getAdminVideoList(): array
    {
        self::initStorage();
        $videos = self::$storage->getVideoList();
        $result = [];
        
        foreach ($videos as $video) {
            $basename = pathinfo($video, PATHINFO_FILENAME);
            $metadata = self::$storage->getVideoMetadata($basename);
            $videoPath = Config::get('storage.path', 'videos/') . $video;
            $thumbnailPath = Config::get('storage.thumbnails_path', 'thumbnails/') . $basename . '.jpg';
            $thumbUrl = file_exists($thumbnailPath)
                ? ($thumbnailPath . '?v=' . filemtime($thumbnailPath))
                : 'images/default-thumbnail-small.svg';
            
            $result[] = [
                'filename' => $video,
                'basename' => $basename,
                'title' => $metadata['title'] ?? 'タイトルなし',
                'comment' => $metadata['comment'] ?? '',
                'views' => $metadata['views'] ?? 0,
                'likes' => $metadata['likes'] ?? 0,
                'upload_date' => $metadata['upload_date'] ? date('Y-m-d H:i:s', strtotime($metadata['upload_date'])) : date('Y-m-d H:i:s', filemtime($videoPath)),
                'file_size' => file_exists($videoPath) ? self::formatFileSize(filesize($videoPath)) : '0 B',
                'file_size_bytes' => file_exists($videoPath) ? filesize($videoPath) : 0,
                'has_thumbnail' => file_exists($thumbnailPath),
                'thumb_url' => $thumbUrl,
                'duration' => $metadata['duration'] ?? null,
                'is_public' => $metadata['is_public'] ?? true
            ];
        }
        
        // アップロード日時順でソート（新しい順）
        usort($result, function($a, $b) {
            return strtotime($b['upload_date']) - strtotime($a['upload_date']);
        });
        
        return $result;
    }
    
    /**
     * ソート機能付き管理用動画一覧を取得
     */
    public static function getAdminVideoListSorted(string $sort = 'new'): array
    {
        $videos = self::getAdminVideoList();
        
        // ソート条件に応じてソート
        if ($sort === 'popular') {
            // 人気順（再生数といいね数を組み合わせた評価値）
            usort($videos, function($a, $b) {
                // より直感的な人気度計算
                // 1. 基本スコア（再生数 + いいね数）
                $aBaseScore = $a['views'] + $a['likes'];
                $bBaseScore = $b['views'] + $b['likes'];
                
                // 2. いいね率ボーナス（再生数が1以上の場合）
                $aLikeRateBonus = 0;
                $bLikeRateBonus = 0;
                
                if ($a['views'] > 0) {
                    $aLikeRate = ($a['likes'] / $a['views']) * 100;
                    $aLikeRateBonus = $aLikeRate * 0.1; // いいね率の10%をボーナス
                }
                if ($b['views'] > 0) {
                    $bLikeRate = ($b['likes'] / $b['views']) * 100;
                    $bLikeRateBonus = $bLikeRate * 0.1; // いいね率の10%をボーナス
                }
                
                // 3. 総合スコア
                $aTotalScore = $aBaseScore + $aLikeRateBonus;
                $bTotalScore = $bBaseScore + $bLikeRateBonus;
                
                return $bTotalScore - $aTotalScore;
            });
        } elseif ($sort === 'views') {
            // 再生数順
            usort($videos, function($a, $b) {
                return $b['views'] - $a['views'];
            });
        } elseif ($sort === 'likes') {
            // いいね数順
            usort($videos, function($a, $b) {
                return $b['likes'] - $a['likes'];
            });
        } else {
            // 新しい順（デフォルト）
            usort($videos, function($a, $b) {
                return strtotime($b['upload_date']) - strtotime($a['upload_date']);
            });
        }
        
        return $videos;
    }
    
    /**
     * ページネーション機能付き管理用動画一覧を取得
     */
    public static function getAdminVideoListPaginated(string $sort = 'new', int $page = 1, int $perPage = 10): array
    {
        $videos = self::getAdminVideoListSorted($sort);
        
        // 総件数を取得
        $totalVideos = count($videos);
        
        // ページネーション処理
        $totalPages = ceil($totalVideos / $perPage);
        $offset = ($page - 1) * $perPage;
        
        // 指定ページのデータを取得
        $paginatedVideos = array_slice($videos, $offset, $perPage);
        
        return [
            'success' => true,
            'videos' => $paginatedVideos,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_videos' => $totalVideos,
                'total_pages' => $totalPages,
                'has_next_page' => $page < $totalPages,
                'has_prev_page' => $page > 1
            ],
            'sort' => $sort
        ];
    }
}
