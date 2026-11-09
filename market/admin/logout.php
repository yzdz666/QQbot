<?php
/** 市场后台 - 退出 */
require_once dirname(__DIR__) . '/lib/bootstrap.php';
market_security_headers(true);
market_session_start();

$token = (string)($_GET['token'] ?? '');
$expected = $_SESSION['market_csrf'] ?? '';
if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
    http_response_code(403);
    exit('非法退出请求');
}
MarketAuth::logout();
header('Location: login.php');
exit;
