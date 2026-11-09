<?php
/**
 * 管理员密码重置工具（仅命令行运行）
 *
 * 用法：
 *   php tools/reset_admin.php
 *   php tools/reset_admin.php --user=admin --pass=NewPass123
 *
 * 安全说明：
 *   - 仅允许 CLI 运行，Web 访问会直接 403
 *   - 不读取任何 Web 参数，避免被远程利用
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden: CLI only');
}

require_once dirname(__DIR__) . '/function.php';
require_once dirname(__DIR__) . '/auth.php';

$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-zA-Z0-9_]+)=(.*)$/', $arg, $m)) {
        $args[$m[1]] = $m[2];
    }
}

$username = trim((string)($args['user'] ?? ''));
$password = (string)($args['pass'] ?? '');

if ($username === '') {
    fwrite(STDOUT, "管理员用户名 [admin]: ");
    $line = trim((string)fgets(STDIN));
    $username = $line !== '' ? $line : 'admin';
}

if ($password === '') {
    fwrite(STDOUT, "新密码（至少 8 位，含字母和数字）: ");
    // 尝试关闭回显（Windows 不支持 stty）
    @shell_exec('stty -echo');
    $password = trim((string)fgets(STDIN));
    @shell_exec('stty echo');
    fwrite(STDOUT, "\n");
}

if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
    fwrite(STDERR, "密码不符合要求：至少 8 位，且同时包含字母和数字。\n");
    exit(1);
}

try {
    $admin = db()->fetch("SELECT * FROM admin LIMIT 1");
    if ($admin) {
        db()->execute("UPDATE admin SET username = ?, password = ? WHERE id = ?",
            [$username, passwordHash($password), $admin['id']]);
    } else {
        db()->execute("INSERT INTO admin (username, password) VALUES (?, ?)", [$username, passwordHash($password)]);
    }
    db()->execute("DELETE FROM sessions");
    // 允许重新进入安装向导补充机器人（仅当从未完成安装时）
    echo "已重置管理员账号：{$username}\n";
    echo "所有登录会话已失效，请使用新密码登录 admin/login.php\n";
} catch (Throwable $e) {
    fwrite(STDERR, "重置失败：" . $e->getMessage() . "\n");
    exit(1);
}
