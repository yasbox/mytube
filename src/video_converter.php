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
 * 動画をMP4に変換する関数（進捗追跡付き）
 * 
 * @param string $inputPath 入力ファイルのパス
 * @param string $outputPath 出力ファイルのパス
 * @param string $basename ファイルのベース名
 * @return array 変換結果
 */
function convertVideoToMp4($inputPath, $outputPath, $basename) {
    // ローカルログファイル出力は無効化
    
    $ffmpeg = (string)Config::get('storage.ffmpeg_path', '/usr/bin/ffmpeg');
    if (!file_exists($ffmpeg)) {
        // ログ出力は行わない
        return ['success' => false, 'message' => 'FFmpegが見つかりません'];
    }
    
    // 動画の総時間を事前に取得
    $durationCmd = $ffmpeg . " -i " . escapeshellarg($inputPath) . " 2>&1 | grep 'Duration' | cut -d ' ' -f 4 | sed s/,//";
    $durationOutput = shell_exec($durationCmd);
    $totalDuration = 0;
    
    if ($durationOutput) {
        $durationStr = trim($durationOutput);
        $totalDuration = parseDuration($durationStr);
    } else {
        
    }
    
    // 変換開始時刻を記録
    $startTime = time();
    
    // 進捗ファイルのパス
    $progressFile = "videos/{$basename}_progress.txt";
    
    // 変換コマンドの構築（進捗出力付き）
    $cmd = $ffmpeg . " -i " . escapeshellarg($inputPath);
    
    // 動画コーデック設定
    $videoCodec = (string)Config::get('video.conversion.codec', 'libx264');
    $videoPreset = (string)Config::get('video.conversion.preset', 'medium');
    $videoCrf = (string)Config::get('video.conversion.crf', '23');
    $cmd .= " -c:v " . $videoCodec;
    $cmd .= " -preset " . $videoPreset;
    $cmd .= " -crf " . $videoCrf;
    
    // 音声コーデック設定
    $audioCodec = (string)Config::get('video.conversion.audio_codec', 'aac');
    $audioBitrate = (string)Config::get('video.conversion.audio_bitrate', '128k');
    $cmd .= " -c:a " . $audioCodec;
    $cmd .= " -b:a " . $audioBitrate;
    
    // Web最適化
    $webOptimize = (bool)Config::get('video.conversion.web_optimize', true);
    if ($webOptimize) {
        $cmd .= " -movflags +faststart";
    }
    
    // 進捗出力を追加（より詳細な情報を取得）
    $cmd .= " -progress " . escapeshellarg($progressFile);
    $cmd .= " -stats_period 0.5"; // 0.5秒ごとに統計情報を出力
    
    // 出力ファイル
    $cmd .= " -y " . escapeshellarg($outputPath);
    
    // バックグラウンドで変換を開始
    $cmd .= " > /dev/null 2>&1 & echo $!";
    
    // デバッグ用にコマンドをログ出力
    
    
    // 変換プロセスを開始
    $pid = shell_exec($cmd);
    $pid = trim($pid);
    
    
    
    if (empty($pid) || !is_numeric($pid)) {
        
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
    $isRunning = false;
    if (is_numeric($pid)) {
        $checkCmd = "ps -p {$pid} > /dev/null 2>&1; echo $?";
        $result = shell_exec($checkCmd);
        $isRunning = (trim($result) === '0');
    }
    
    if (!$isRunning) {
        
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
 * 変換プロセスを停止する関数
 * 
 * @param int $pid プロセスID
 */
function stopFfmpegProcess($pid) {
    if (is_numeric($pid)) {
        shell_exec("kill {$pid} 2>/dev/null");
    }
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
    $ffmpeg = (string)Config::get('storage.ffmpeg_path', '/usr/bin/ffmpeg');
    $cmd = $ffmpeg . " -i " . escapeshellarg($videoPath) . " 2>&1";
    $output = shell_exec($cmd);
    
    
    
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

/**
 * 変換完了時の処理を行う関数
 * 
 * @param string $outputPath 変換後の動画ファイルパス
 * @param string $basename ファイルのベース名
 * @return bool 処理成功かどうか
 */
function handleConversionComplete($outputPath, $basename) {
    
    
    // メタデータを生成
    $metadata = generateVideoMetadata($outputPath, $basename);
    
    if ($metadata) {
        // サムネイル生成（既存の機能があれば）
        // generateThumbnail($outputPath, $basename);
        
        
        return true;
    } else {
        
        return false;
    }
}

 