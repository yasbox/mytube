<?php
/**
 * 設定管理システム
 * ベース設定、運用設定(JSON)、環境変数を統合管理
 */
class Config
{
    private static array $config = [];
    private static bool $loaded = false;
    
    /**
     * 設定を読み込み
     */
    public static function load(string $environment = 'production'): void
    {
        if (self::$loaded) {
            return;
        }
        
        // ベース設定を読み込み
        self::$config = self::loadBaseConfig();
        
        // JSON設定をマージ（コード外のデータ領域に保存される運用設定）
        self::loadJsonSettings();

        // 環境変数での上書き
        self::loadEnvironmentOverrides();
        

        
        self::$loaded = true;
    }
    
    /**
     * ベース設定を読み込み
     */
    private static function loadBaseConfig(): array
    {
        $baseConfigPath = __DIR__ . '/default.php';
        if (file_exists($baseConfigPath)) {
            $baseConfig = require $baseConfigPath;
            
            // default.phpにfeatures.viewsとfeatures.likesが含まれていない場合は追加
            if (!isset($baseConfig['features']['views'])) {
                $baseConfig['features']['views'] = ['unique_countup' => false];
            }
            if (!isset($baseConfig['features']['likes'])) {
                $baseConfig['features']['likes'] = ['unique_countup' => false];
            }
            // 自動再生設定がない場合は追加
            if (!isset($baseConfig['features']['autoplay'])) {
                $baseConfig['features']['autoplay'] = true;
            }
            
            return $baseConfig;
        }
        
        // デフォルト設定
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
                    'max_size_mb' => 500,
                    'auto_convert' => true,
                    'thumbnail_generation' => true
                ],
                // 動画自動再生の既定値
                'autoplay' => true,
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
                ]
            ],
            'storage' => [
                'type' => 'file', // file, mysql, sqlite
                'path' => 'videos/',
                'thumbnails_path' => 'thumbnails/'
            ],
            'security' => [
                'admin_session_lifetime' => 30 * 24 * 3600,
                'remember_me_lifetime' => 30 * 24 * 3600
            ]
        ];
    }
    
    // customer.php は廃止（案2の最小構成）
    
    /**
     * JSON設定（data/settings.json）を読み込み・マージ
     */
    private static function loadJsonSettings(): void
    {
        $settingsPath = self::getSettingsJsonPath();
        if (!is_file($settingsPath)) {
            // 初回起動: デフォルト設定を生成して永続化（原子的に）
            $dir = dirname($settingsPath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $defaultData = [
                'security' => [
                    'password_protection' => false, // 既定は保護OFF(公開)
                    'user_password' => self::generateRandomPassword(8)
                ],
                'features' => [
                    'autoplay' => true,
                    'views' => [
                        'unique_countup' => false
                    ],
                    'likes' => [
                        'unique_countup' => false
                    ]
                ]
            ];
            $json = json_encode($defaultData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            if ($json !== false) {
                $tmp = $settingsPath . '.tmp';
                $written = @file_put_contents($tmp, $json, LOCK_EX);
                if ($written !== false) {
                    if (!@rename($tmp, $settingsPath)) {
                        // フォールバック
                        @unlink($settingsPath);
                        @copy($tmp, $settingsPath);
                        @unlink($tmp);
                    }
                }
            }
            // ランタイムへも反映
            self::$config = self::arrayMergeRecursive(self::$config, $defaultData);
            return;
        }
        if (!is_readable($settingsPath)) {
            return;
        }
        $json = file_get_contents($settingsPath);
        if ($json === false) {
            return;
        }
        
        $data = json_decode($json, true);
        if (is_array($data)) {

                // 正規化
            $needsWriteBack = false;
            if (!isset($data['security'])) {
                $data['security'] = [];
            }
            if (isset($data['security']['password_protection'])) {
                $data['security']['password_protection'] = filter_var($data['security']['password_protection'], FILTER_VALIDATE_BOOLEAN);
            }
            // user_password が未設定/空の場合は自動生成し、ファイルにも反映
            if (!isset($data['security']['user_password']) || $data['security']['user_password'] === '') {
                $data['security']['user_password'] = self::generateRandomPassword(8);
                $needsWriteBack = true;
            }
            
            // featuresセクションの正規化
            if (!isset($data['features'])) {
                $data['features'] = [];
            }
            if (!isset($data['features']['autoplay'])) {
                // 省略時は既定true
                $data['features']['autoplay'] = true;
            }
            if (!isset($data['features']['views'])) {
                $data['features']['views'] = [];
            }
            if (!isset($data['features']['likes'])) {
                $data['features']['likes'] = [];
            }
            if (isset($data['features']['autoplay'])) {
                $data['features']['autoplay'] = filter_var($data['features']['autoplay'], FILTER_VALIDATE_BOOLEAN);
            }
            if (isset($data['features']['views']['unique_countup'])) {
                $data['features']['views']['unique_countup'] = filter_var($data['features']['views']['unique_countup'], FILTER_VALIDATE_BOOLEAN);
            }
            if (isset($data['features']['likes']['unique_countup'])) {
                $data['features']['likes']['unique_countup'] = filter_var($data['features']['likes']['unique_countup'], FILTER_VALIDATE_BOOLEAN);
            }

            // UI セクションの正規化（デフォルトテーマ）
            if (!isset($data['ui'])) {
                $data['ui'] = [];
            }
            if (!isset($data['ui']['theme'])) {
                $data['ui']['theme'] = 'light';
            } else {
                $themeVal = strtolower((string)$data['ui']['theme']);
                $data['ui']['theme'] = in_array($themeVal, ['light', 'dark'], true) ? $themeVal : 'light';
            }
            
            if ($needsWriteBack) {
                $jsonOut = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                if ($jsonOut !== false) {
                    $tmp = $settingsPath . '.tmp';
                    $bytes = @file_put_contents($tmp, $jsonOut, LOCK_EX);
                    if ($bytes !== false) {
                        if (!@rename($tmp, $settingsPath)) {
                            @unlink($settingsPath);
                            @copy($tmp, $settingsPath);
                            @unlink($tmp);
                        }
                    }
                }
            }
                
            
            // 設定をマージ（既存の値を確実に上書き）
            self::$config = self::arrayMergeRecursive(self::$config, $data);
            }

        // 追加: 管理者パスワードのセキュア保存ファイルを読み込み（環境変数より低優先）
        $securePwPath = self::getSecureAdminPasswordPath();
        if (is_readable($securePwPath)) {
            $raw = @file_get_contents($securePwPath);
            if ($raw !== false) {
                $pwData = json_decode($raw, true);
                if (is_array($pwData) && isset($pwData['password']) && is_string($pwData['password'])) {
                    if (!isset(self::$config['security']) || !is_array(self::$config['security'])) {
                        self::$config['security'] = [];
                    }
                    // ここで設定した値は後続の環境変数上書きでさらに上書きされうる
                    self::$config['security']['admin_password'] = (string)$pwData['password'];
                }
            }
        }
    }

    /**
     * 初期パスワード生成（視認性の悪い文字は除外）
     */
    private static function generateRandomPassword(int $length = 8): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $maxIndex = strlen($alphabet) - 1;
        $result = '';
        for ($i = 0; $i < $length; $i++) {
            $idx = random_int(0, $maxIndex);
            $result .= $alphabet[$idx];
        }
        return $result;
    }
    
    /**
     * 環境変数での設定上書き
     */
	private static function loadEnvironmentOverrides(): void
	{
		// 環境変数から設定を取得（.env含む）: 常に最優先で上書き
		$envOverrides = [
			'app.name' => $_ENV['APP_NAME'] ?? $_SERVER['APP_NAME'] ?? getenv('APP_NAME') ?: null,
			'app.debug' => $_ENV['DEBUG_MODE'] ?? $_SERVER['DEBUG_MODE'] ?? getenv('DEBUG_MODE') ?: null,
			'security.admin_password' => $_ENV['ADMIN_PASSWORD'] ?? $_SERVER['ADMIN_PASSWORD'] ?? getenv('ADMIN_PASSWORD') ?: null,
			'security.admin_session_lifetime' => $_ENV['ADMIN_SESSION_LIFETIME'] ?? $_SERVER['ADMIN_SESSION_LIFETIME'] ?? getenv('ADMIN_SESSION_LIFETIME') ?: null,
			// 追加: ログイン保持期間（秒）
			'security.remember_me_lifetime' => $_ENV['REMEMBER_ME_LIFETIME'] ?? $_SERVER['REMEMBER_ME_LIFETIME'] ?? getenv('REMEMBER_ME_LIFETIME') ?: null,
			// 追加: リメンバーミークッキー名（任意）
			'security.remember_me_cookie_name' => $_ENV['REMEMBER_ME_COOKIE_NAME'] ?? $_SERVER['REMEMBER_ME_COOKIE_NAME'] ?? getenv('REMEMBER_ME_COOKIE_NAME') ?: null,
			'storage.ffmpeg_path' => $_ENV['FFMPEG_PATH'] ?? $_SERVER['FFMPEG_PATH'] ?? getenv('FFMPEG_PATH') ?: null,
			'video.conversion.enabled' => $_ENV['CONVERT_TO_MP4'] ?? $_SERVER['CONVERT_TO_MP4'] ?? getenv('CONVERT_TO_MP4') ?: null,
			'video.conversion.codec' => $_ENV['VIDEO_CODEC'] ?? $_SERVER['VIDEO_CODEC'] ?? getenv('VIDEO_CODEC') ?: null,
			'video.conversion.preset' => $_ENV['VIDEO_PRESET'] ?? $_SERVER['VIDEO_PRESET'] ?? getenv('VIDEO_PRESET') ?: null,
			'video.conversion.crf' => $_ENV['VIDEO_CRF'] ?? $_SERVER['VIDEO_CRF'] ?? getenv('VIDEO_CRF') ?: null,
			'video.conversion.audio_codec' => $_ENV['AUDIO_CODEC'] ?? $_SERVER['AUDIO_CODEC'] ?? getenv('AUDIO_CODEC') ?: null,
			'video.conversion.audio_bitrate' => $_ENV['AUDIO_BITRATE'] ?? $_SERVER['AUDIO_BITRATE'] ?? getenv('AUDIO_BITRATE') ?: null,
			'video.conversion.web_optimize' => $_ENV['WEB_OPTIMIZE'] ?? $_SERVER['WEB_OPTIMIZE'] ?? getenv('WEB_OPTIMIZE') ?: null
		];

		foreach ($envOverrides as $key => $value) {
			if ($value === null) {
				continue;
			}

			// 型の正規化
			switch ($key) {
				// settings.json の app.name が存在する場合のみ環境変数で上書きしない。
				// 初回（settings.json 未設定）や削除時は .env（APP_NAME）を既定より優先する。
				case 'app.name':
					$hasJsonAppName = false;
					$settingsPath = self::getSettingsJsonPath();
					if (is_readable($settingsPath)) {
						$jsonStr = @file_get_contents($settingsPath);
						if ($jsonStr !== false) {
							$data = json_decode($jsonStr, true);
							if (is_array($data) && isset($data['app']) && array_key_exists('name', $data['app']) && $data['app']['name'] !== null && $data['app']['name'] !== '') {
								$hasJsonAppName = true;
							}
						}
					}
					if ($hasJsonAppName) {
						break;
					}
					self::set($key, (string)$value);
					break;
				case 'app.debug':
				case 'video.conversion.enabled':
				case 'video.conversion.web_optimize':
					$boolVal = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
					if ($boolVal !== null) {
						self::set($key, $boolVal);
					} else {
						self::set($key, (bool)$value);
					}
					break;

				case 'security.admin_session_lifetime':
				case 'security.remember_me_lifetime':
					$intVal = is_numeric($value) ? (int)$value : null;
					if ($intVal !== null) {
						self::set($key, $intVal);
					}
					break;

				default:
					self::set($key, $value);
					break;
			}
		}

		// アップロード最大サイズ（バイト）: UPLOAD_MAX_SIZE → bytes/mb 両方格納
		$uploadMaxBytes = $_ENV['UPLOAD_MAX_SIZE'] ?? $_SERVER['UPLOAD_MAX_SIZE'] ?? getenv('UPLOAD_MAX_SIZE') ?: null;
		if ($uploadMaxBytes !== null && is_numeric((string)$uploadMaxBytes)) {
			$bytes = (int)$uploadMaxBytes;
			if ($bytes > 0) {
				self::set('features.upload.max_size_bytes', $bytes);
				$mb = (int)ceil($bytes / 1048576);
				self::set('features.upload.max_size_mb', max(1, $mb));
			}
		}

		// チャンクサイズ（バイト）: CHUNK_SIZE
		$chunkSizeBytesEnv = $_ENV['CHUNK_SIZE'] ?? $_SERVER['CHUNK_SIZE'] ?? getenv('CHUNK_SIZE') ?: null;
		if ($chunkSizeBytesEnv !== null && is_numeric((string)$chunkSizeBytesEnv)) {
			$chunkBytes = (int)$chunkSizeBytesEnv;
			if ($chunkBytes > 0) {
				self::set('features.upload.chunk_size_bytes', $chunkBytes);
			}
		}
	}

    /**
     * データディレクトリ（Web公開領域外）
     */
    public static function getSettingsJsonPath(): string
    {
        // src ディレクトリ配下の data/ に配置（直アクセスは .htaccess で遮断）
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'settings.json';
    }

    /**
     * 管理者パスワード保存先（Web直配信外）
     */
    public static function getSecureAdminPasswordPath(): string
    {
        $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'secure';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . DIRECTORY_SEPARATOR . 'admin_password.json';
    }

    /**
     * 管理者リカバリーコード保存先
     */
    public static function getAdminRecoveryCodesPath(): string
    {
        $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'secure';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . DIRECTORY_SEPARATOR . 'admin_recovery.json';
    }

    /**
     * 復旧端末（デバイス）登録リストの保存先
     */
    public static function getRecoveryDevicesPath(): string
    {
        $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'secure';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . DIRECTORY_SEPARATOR . 'recovery_devices.json';
    }

    /**
     * 現在の構成に指定のドット区切りキーが存在するか
     */
    private static function configKeyExists(string $dotKey): bool
    {
        $keys = explode('.', $dotKey);
        $value = self::$config;
        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return false;
            }
            $value = $value[$k];
        }
        return true;
    }
    
    /**
     * 設定値を取得
     */
    public static function get(string $key, $default = null)
    {
        if (!self::$loaded) {
            self::load();
        }
        
        $keys = explode('.', $key);
        $value = self::$config;
        
                    foreach ($keys as $k) {
                if (!isset($value[$k])) {
                    return $default;
                }
                $value = $value[$k];
            }
            
            return $value;
    }
    
    /**
     * 設定値を設定
     */
    public static function set(string $key, $value): void
    {
        // 初期化されていない場合は、必要な部分のみを初期化（完全な再読み込みは行わない）
        if (!self::$loaded) {
            // 最小限の初期化のみ行う
            if (empty(self::$config)) {
                self::$config = self::loadBaseConfig();
            }
            self::$loaded = true;
        }
        
        $keys = explode('.', $key);
        $config = &self::$config;
        
        foreach ($keys as $k) {
            if (!isset($config[$k])) {
                $config[$k] = [];
            }
            $config = &$config[$k];
        }
        
        $config = $value;
    }
    
    /**
     * 設定が存在するかチェック
     */
    public static function has(string $key): bool
    {
        if (!self::$loaded) {
            self::load();
        }
        
        $keys = explode('.', $key);
        $value = self::$config;
        
        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return false;
            }
            $value = $value[$k];
        }
        
        return true;
    }
    
    /**
     * 全設定を取得
     */
    public static function all(): array
    {
        if (!self::$loaded) {
            self::load();
        }
        
        return self::$config;
    }
    
    /**
     * 配列の再帰的マージ
     */
    private static function arrayMergeRecursive(array $array1, array $array2): array
    {
        foreach ($array2 as $key => $value) {
            if (is_array($value) && isset($array1[$key]) && is_array($array1[$key])) {
                $array1[$key] = self::arrayMergeRecursive($array1[$key], $value);
            } else {
                $array1[$key] = $value;
            }
        }
        
        return $array1;
    }
    
    /**
     * 設定をリセット（テスト用）
     */
    public static function reset(): void
    {
        self::$config = [];
        self::$loaded = false;
    }
    
    /**
     * デバッグ用：設定の読み込み状況を確認
     */
    public static function debugConfig(): array
    {
        if (!self::$loaded) {
            self::load();
        }
        
        return [
            'loaded' => self::$loaded,
            'base_config' => self::loadBaseConfig(),
            'json_settings_path' => self::getSettingsJsonPath(),
            'json_settings_exists' => file_exists(self::getSettingsJsonPath()),
            'json_settings_readable' => is_readable(self::getSettingsJsonPath()),
            'current_config' => self::$config,
            'features_section' => self::$config['features'] ?? 'NOT_SET'
        ];
    }
}
