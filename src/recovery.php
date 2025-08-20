<?php
require_once __DIR__ . '/init_web.php';
// 旧: リカバリーコード方式 → 廃止
http_response_code(410);
header('Content-Type: application/json');
echo json_encode(['success' => false, 'message' => 'このエンドポイントは廃止されました']);
exit;


