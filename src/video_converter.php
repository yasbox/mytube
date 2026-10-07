<?php
/**
 * 動画変換関連の関数群
 * FFmpegを使用した動画変換と進捗監視機能
 */

// PHPのログ出力先設定はphp.iniに委譲

// アプリケーション初期化
require_once 'core/Bootstrap.php';
Bootstrap::init();

// 共通関数をインクルード
require_once 'functions.php';
require_once 'admin_functions.php';

/**
 * FFmpeg のコマンド行を組み立てる（実行ファイルのパスも引数もすべてシェル用にエスケープする）
 *
 * @param array $args FFmpeg に渡す引数（例: ['-i', $path]）
 * @return string コマンド行
 */
function ffmpegCommand(array $args) {
    $parts = [(string)Config::get('storage.ffmpeg_path', '/usr/bin/ffmpeg')];
    foreach ($args as $arg) {
        $parts[] = (string)$arg;
    }
    return implode(' ', array_map('escapeshellarg', $parts));
}

/**
 * FFmpeg を実行して終わるまで待ち、出力（エラー出力を含む）を返す
 *
 * @param array $args FFmpeg に渡す引数
 * @return string FFmpeg の出力
 */
function runFfmpeg(array $args) {
    return (string)shell_exec(ffmpegCommand($args) . ' 2>&1');
}

/**
 * 動画の長さ（秒）を調べる。分からなければ 0
 *
 * @param string $videoPath 動画ファイルのパス
 * @return float 秒数
 */
function getVideoDurationSeconds($videoPath) {
    if (preg_match('/Duration: (\d+):(\d{2}):(\d{2}(?:\.\d+)?)/', runFfmpeg(['-i', $videoPath]), $matches)) {
        return (int)$matches[1] * 3600 + (int)$matches[2] * 60 + (float)$matches[3];
    }
    return 0;
}

/**
 * 動画からサムネイル（JPEG。長辺1000pxまで縮小・比率維持・拡大はしない）を作る
 * アップロード時・変換後の両方で使う。1秒の位置の画面を使い、短い動画などで取れなければもっと前の位置で試す
 *
 * @param string $videoPath 動画ファイルのパス
 * @param string $thumbnailPath 作成するサムネイルのパス
 * @return bool 作成できたか
 */
function generateThumbnail($videoPath, $thumbnailPath) {
    $thumbnailDir = dirname($thumbnailPath);
    if (!is_dir($thumbnailDir)) {
        @mkdir($thumbnailDir, 0755, true);
    }
    $vf = "scale='if(gte(iw,ih),min(iw,1000),-2)':'if(gte(iw,ih),-2,min(ih,1000))'";
    foreach (['00:00:01', '00:00:00.500', '00:00:00'] as $position) {
        runFfmpeg(['-y', '-ss', $position, '-i', $videoPath, '-frames:v', '1', '-vf', $vf, $thumbnailPath]);
        clearstatcache(true, $thumbnailPath);
        if (is_file($thumbnailPath) && filesize($thumbnailPath) > 0) {
            @chmod($thumbnailPath, 0644);
            return true;
        }
    }
    // 取り出せなかったときに残る空のファイルは消す（一覧で壊れた画像として表示されないように）
    if (is_file($thumbnailPath) && filesize($thumbnailPath) === 0) {
        @unlink($thumbnailPath);
    }
    return false;
}

/**
 * コマンドを裏で動かし、終わるのを待たずにプロセス ID を返す（始められなければ null）
 * 動画の変換に使う（sh のある Linux などの環境が前提）
 *
 * @param string $command コマンド行（引数はエスケープ済みのもの）
 * @return int|null プロセス ID
 */
function startBackgroundProcess($command) {
    if (PHP_OS_FAMILY === 'Windows') {
        return null;
    }
    $pid = trim((string)shell_exec($command . ' > /dev/null 2>&1 & echo $!'));
    return ctype_digit($pid) ? (int)$pid : null;
}

/**
 * 動画をMP4に変換する関数（進捗追跡付き）
 * 
 * @param string $inputPath 入力ファイルのパス
 * @param string $outputPath 出力ファイルのパス
 * @param string $basename ファイルのベース名
 * @return array 変換結果
 */
function convertVideoToMp4($inputPath, $outputPath, $basename) {
    $ffmpeg = (string)Config::get('storage.ffmpeg_path', '/usr/bin/ffmpeg');
    if (!file_exists($ffmpeg)) {
        return ['success' => false, 'message' => 'FFmpegが見つかりません'];
    }

    // 動画の総時間を事前に取得（進捗の計算に使う）
    $totalDuration = getVideoDurationSeconds($inputPath);

    // 変換開始時刻を記録
    $startTime = time();

    // 進捗ファイルのパス
    $progressFile = "videos/{$basename}_progress.txt";

    // 変換コマンドの構築（コーデック等の設定値もエスケープして渡す）
    $args = [
        '-i', $inputPath,
        '-c:v', (string)Config::get('video.conversion.codec', 'libx264'),
        '-preset', (string)Config::get('video.conversion.preset', 'medium'),
        '-crf', (string)Config::get('video.conversion.crf', '23'),
        '-c:a', (string)Config::get('video.conversion.audio_codec', 'aac'),
        '-b:a', (string)Config::get('video.conversion.audio_bitrate', '128k'),
    ];
    // Web最適化
    if ((bool)Config::get('video.conversion.web_optimize', true)) {
        array_push($args, '-movflags', '+faststart');
    }
    // 進捗出力（0.5秒ごと）
    array_push($args, '-progress', $progressFile, '-stats_period', '0.5');
    // 出力ファイル（変換中は .part の名前で書き出すため、形式を明示する）
    array_push($args, '-f', 'mp4', '-y', $outputPath);

    // バックグラウンドで変換を開始
    $pid = startBackgroundProcess(ffmpegCommand($args));
    if ($pid === null) {
        return ['success' => false, 'message' => '変換プロセスの開始に失敗しました'];
    }

    return [
        'success' => true,
        'message' => '変換を開始しました',
        'pid' => $pid,
        'progress_file' => $progressFile,
        'start_time' => $startTime,
        'total_duration' => $totalDuration
    ];
}

/**
 * FFmpegの進捗を取得する関数
 * 
 * @param string $progressFile 進捗ファイルのパス
 * @param int $pid プロセスID
 * @param float $totalDuration 動画の総時間
 * @return array 進捗情報
 */
function getFfmpegProgress($progressFile, $pid, $totalDuration = 0) {
    
    
    if (!file_exists($progressFile)) {
        
        return ['progress' => 0, 'status' => 'waiting'];
    }
    
    // プロセスが実行中かチェック
    if (!isProcessRunning($pid)) {
        
        return ['progress' => 100, 'status' => 'completed'];
    }
    
    // 進捗ファイルを読み取り
    $progress = 0;
    $currentTime = 0;
    $totalTime = $totalDuration; // セッションから取得した総時間を使用
    $frame = 0;
    $fps = 0;
    
    $lines = file($progressFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    
    // 進捗ファイルの内容をログ出力（デバッグ用）
    if ($lines) {
        
    }
    
    if ($lines) {
        // 最新の進捗情報を取得するため、ファイルの最後から逆順に読み取り
        $lines = array_reverse($lines);
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // 最新の値を取得するため、最初に見つかった値を採用
            if (strpos($line, 'out_time_ms=') === 0 && $currentTime == 0) {
                $currentTime = intval(substr($line, 12)) / 1000000; // マイクロ秒を秒に変換
            } elseif (strpos($line, 'out_time=') === 0 && $currentTime == 0) {
                // 時間形式（HH:MM:SS.ms）の場合
                $timeStr = substr($line, 9);
                $currentTime = parseDuration($timeStr);
            } elseif (strpos($line, 'frame=') === 0 && $frame == 0) {
                $frame = intval(substr($line, 6));
            } elseif (strpos($line, 'fps=') === 0 && $fps == 0) {
                $fps = floatval(substr($line, 4));
            }
            
            // 必要な情報が全て取得できたら終了
            if ($currentTime > 0 && $frame > 0 && $fps > 0) {
                break;
            }
        }
    }
    
    // 進捗を計算（時間ベースとフレームベースの両方を使用）
    if ($totalTime > 0 && $currentTime > 0) {
        $timeProgress = min(100, round(($currentTime / $totalTime) * 100));
        $progress = $timeProgress;
    } elseif ($frame > 0 && $fps > 0) {
        // フレームベースの進捗計算（時間情報がない場合）
        $estimatedTotalFrames = $totalTime > 0 ? ($totalTime * $fps) : ($frame * 2); // 推定
        $frameProgress = min(100, round(($frame / $estimatedTotalFrames) * 100));
        $progress = $frameProgress;
    } else {
        
    }
    
    // 進捗の上限を設定（100%を超えないように）
    $progress = min(100, max(0, $progress));
    
    // 詳細情報を含む進捗データ
    $progressData = [
        'progress' => $progress,
        'status' => 'converting',
        'current_time' => $currentTime,
        'total_time' => $totalTime,
        'frame' => $frame,
        'fps' => $fps
    ];
    
    // 残り時間の計算
    if ($fps > 0 && $totalTime > 0) {
        $remainingFrames = ($totalTime * $fps) - $frame;
        $estimatedRemainingTime = $remainingFrames / $fps;
        $progressData['estimated_remaining_time'] = max(0, $estimatedRemainingTime);
    }
    
    
    return $progressData;
}

/**
 * 時間文字列を秒に変換する関数
 * 
 * @param string $durationStr 時間文字列（HH:MM:SS.ms形式）
 * @return float 秒数
 */
function parseDuration($durationStr) {
    // HH:MM:SS.ms 形式を秒に変換
    $parts = explode(':', $durationStr);
    if (count($parts) === 3) {
        $hours = intval($parts[0]);
        $minutes = intval($parts[1]);
        $seconds = floatval($parts[2]);
        return $hours * 3600 + $minutes * 60 + $seconds;
    }
    return 0;
}

/**
 * プロセスが実行中か
 * PHP の posix 拡張があればシェルを使わずに確かめる（シグナル 0 は「送らずに存在だけ確かめる」の意味）。
 * 無い環境では ps コマンドで確かめる
 */
function isProcessRunning($pid): bool {
    if (!is_numeric($pid) || (int)$pid <= 0) {
        return false;
    }
    if (function_exists('posix_kill')) {
        return posix_kill((int)$pid, 0);
    }
    $result = shell_exec('ps -p ' . (int)$pid . ' > /dev/null 2>&1; echo $?');
    return trim((string)$result) === '0';
}

/**
 * FFmpeg が最後まで変換を終えたか（-progress の出力が progress=end で終わっているか）
 * 途中で失敗・中断した場合は progress=continue のまま終わる
 */
function conversionFinishedSuccessfully($progressFile): bool {
    if (!is_string($progressFile) || !is_file($progressFile)) {
        return false;
    }
    $last = '';
    foreach (file($progressFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (strpos($line, 'progress=') === 0) {
            $last = trim($line);
        }
    }
    return $last === 'progress=end';
}

/**
 * 変換プロセスを停止する関数
 *
 * @param int $pid プロセスID
 */
function stopFfmpegProcess($pid) {
    if (!is_numeric($pid) || (int)$pid <= 0) {
        return;
    }
    if (function_exists('posix_kill')) {
        posix_kill((int)$pid, 15); // 15 = SIGTERM（終了を依頼する）
        return;
    }
    shell_exec('kill ' . (int)$pid . ' 2>/dev/null');
}

/**
 * 変換可能な形式かチェックする関数
 * 
 * @param string $extension ファイル拡張子
 * @return bool 変換可能かどうか
 */
function isConvertibleFormat($extension) {
    $formats = Config::get('video.conversion.convertible_formats', ['webm','ogg','avi','mov','mkv','flv','wmv','3gp','m4v','mp4']);
    return in_array(strtolower($extension), $formats, true);
} 

/**
 * 動画のメタデータを生成・更新する関数
 * 
 * @param string $videoPath 動画ファイルのパス
 * @param string $basename ファイルのベース名
 * @return array 生成されたメタデータ
 */
function generateVideoMetadata($videoPath, $basename) {
    
    
    if (!file_exists($videoPath)) {
        
        return false;
    }
    
    // FFmpegを使用して動画情報を取得
    $output = runFfmpeg(['-i', $videoPath]);
    
    
    
    // 動画の基本情報を解析
    $metadata = [
        'title' => '',
        'comment' => '',
        'views' => 0,
        'likes' => 0,
        'upload_date' => date('Y-m-d H:i:s'),
        'duration' => null,
        'width' => null,
        'height' => null,
        'fps' => null,
        'bitrate' => null,
        'size' => filesize($videoPath)
    ];
    
    // 既存のメタデータがあれば読み込み
    $existingMetadataFile = "videos/{$basename}.json";
    if (file_exists($existingMetadataFile)) {
        $existingData = json_decode(file_get_contents($existingMetadataFile), true);
        if ($existingData) {
            // 既存のデータを保持（views, likes, title, comment）
            $metadata['title'] = $existingData['title'] ?? '';
            $metadata['comment'] = $existingData['comment'] ?? '';
            $metadata['views'] = $existingData['views'] ?? 0;
            $metadata['likes'] = $existingData['likes'] ?? 0;
            $metadata['upload_date'] = $existingData['upload_date'] ?? date('Y-m-d H:i:s');
        }
    }
    
    // FFmpeg出力から動画情報を解析
    if ($output) {
        // Duration
        if (preg_match('/Duration: (\d{2}):(\d{2}):(\d{2}\.\d{2})/', $output, $matches)) {
            $hours = intval($matches[1]);
            $minutes = intval($matches[2]);
            $seconds = floatval($matches[3]);
            $totalSeconds = $hours * 3600 + $minutes * 60 + $seconds;
            // ストレージには整数秒で保存し、表示は共通フォーマッタで統一
            $metadata['duration'] = (int)round($totalSeconds);
        }
        
        // Video stream info
        if (preg_match('/(\d{3,4})x(\d{3,4})/', $output, $matches)) {
            $metadata['width'] = intval($matches[1]);
            $metadata['height'] = intval($matches[2]);
        }
        
        // FPS
        if (preg_match('/(\d+(?:\.\d+)?) fps/', $output, $matches)) {
            $metadata['fps'] = floatval($matches[1]);
        }
        
        // Bitrate
        if (preg_match('/(\d+) kb\/s/', $output, $matches)) {
            $metadata['bitrate'] = intval($matches[1]);
        }
    }
    
    // メタデータを保存（他のメタデータ更新と同じく排他して書き込む）
    if (saveVideoMetadata($basename, $metadata)) {
        return $metadata;
    } else {
        return false;
    }
}

 