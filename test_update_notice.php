<?php
/**
 * 后台更新提醒测试
 *
 * 覆盖：版本号规范化、Release 说明摘要、检查缓存命中与版本比较
 * 运行：php test_update_notice.php
 */

date_default_timezone_set('Asia/Shanghai');

$passCount = 0;
$failCount = 0;
$failures  = [];

function ok($name, $cond, $extra = '') {
    global $passCount, $failCount, $failures;
    if ($cond) {
        $passCount++;
        echo "  [PASS] {$name}\n";
    } else {
        $failCount++;
        $failures[] = $name . ($extra !== '' ? " ({$extra})" : '');
        echo "  [FAIL] {$name}" . ($extra !== '' ? " - {$extra}" : '') . "\n";
    }
}

// ==================== 临时站点目录 ====================
$tmp = rtrim(sys_get_temp_dir(), '/') . '/qqbot_update_test_' . getmypid() . '/';
@mkdir($tmp . 'admin', 0777, true);
@mkdir($tmp . 'data', 0777, true);
file_put_contents($tmp . 'admin/version.php', "<?php return ['version' => '1.8.1'];");
define('APP_ROOT', $tmp);

// ==================== mock 数据库（settings 为空） ====================
class UpdateTestDB {
    public function execute($sql, $params = []) { return null; }
    public function fetchAll($sql, $params = []) { return []; }
    public function fetch($sql, $params = []) { return null; }
    public function fetchColumn($sql, $params = []) { return false; }
}
$GLOBALS['__upddb'] = new UpdateTestDB();
function db() { return $GLOBALS['__upddb']; }

require __DIR__ . '/admin/lib_update.php';

// ==================== 版本号 ====================
echo "\n[版本号]\n";
ok('v 前缀被去掉', 更新规范版本('v1.8.2') === '1.8.2');
ok('纯数字版本保持', 更新规范版本('1.10.0') === '1.10.0');
ok('带后缀的 tag 取数字段', 更新规范版本('release-1.9.3-beta') === '1.9.3');
ok('双位数版本比较正确', version_compare('1.8.10', '1.8.9', '>') === true);
ok('当前版本读取自 version.php', 更新当前版本() === '1.8.1');

// ==================== 更新摘要 ====================
echo "\n[更新摘要]\n";
$body = "## 1.8.2\n\n- 手机端取值表输入框不再被压成一条缝\n- 后台打开时提醒新版本\n";
ok('跳过标题，取第一条正文', 更新摘要($body) === '手机端取值表输入框不再被压成一条缝');

$md = "**重要**\n\n1. 修复 `@body` 取值\n2. 见 [Release](https://example.com)\n";
ok('去掉粗体与反引号', 更新摘要($md) === '重要');
ok('链接保留文字', 更新摘要("- 见 [Release](https://example.com)\n") === '见 Release');
ok('只有标题时回退用标题', 更新摘要("## 修复内容\n\n---\n") === '修复内容');
ok('空内容返回空串', 更新摘要('   ') === '');

$long = str_repeat('长', 200);
$cut = 更新摘要($long, 20);
ok('超长内容按字数截断', mb_strlen($cut, 'UTF-8') === 21 && mb_substr($cut, -1, 1, 'UTF-8') === '…');

// ==================== 检查缓存 ====================
echo "\n[检查缓存]\n";
$cache = [
    'ok' => true,
    'version' => '9.9.9',
    'tag' => 'v9.9.9',
    'name' => 'v9.9.9',
    'body' => "- 测试更新\n",
    'html_url' => 'https://github.com/yzdz666/QQbot/releases/tag/v9.9.9',
    'checked_at' => time(),
];
file_put_contents(更新检查缓存文件, json_encode($cache, JSON_UNESCAPED_UNICODE));

$r = 更新检查缓存();
ok('新缓存直接命中，不请求网络', !empty($r['cached']));
ok('命中缓存时重新比较版本', !empty($r['has_update']));
ok('命中缓存时回填当前版本', ($r['current'] ?? '') === '1.8.1');
ok('缓存内容未被改动', ($r['version'] ?? '') === '9.9.9');

$cache['version'] = '1.8.1';
$cache['tag'] = 'v1.8.1';
$cache['checked_at'] = time();
file_put_contents(更新检查缓存文件, json_encode($cache, JSON_UNESCAPED_UNICODE));
$r = 更新检查缓存();
ok('同版本不提示更新', empty($r['has_update']));

$cache['version'] = '1.8.0';
$cache['checked_at'] = time();
file_put_contents(更新检查缓存文件, json_encode($cache, JSON_UNESCAPED_UNICODE));
$r = 更新检查缓存();
ok('线上版本更旧不提示更新', empty($r['has_update']));

// 缓存过期但拿不到网络时，不应阻塞太久（仅校验返回结构，超时 1 秒）
$cache['checked_at'] = time() - 99999;
$cache['version'] = '9.9.9';
file_put_contents(更新检查缓存文件, json_encode($cache, JSON_UNESCAPED_UNICODE));
$start = microtime(true);
$r = 更新检查缓存(false, 3);
$elapsed = microtime(true) - $start;
ok('过期缓存会重新检查', empty($r['cached']));
ok('检查耗时受 timeout 限制', $elapsed < 12, '实际 ' . round($elapsed, 1) . 's');

// ==================== 清理 ====================
foreach (glob($tmp . 'data/*') ?: [] as $f) { @unlink($f); }
@rmdir($tmp . 'data');
@unlink($tmp . 'admin/version.php');
@rmdir($tmp . 'admin');
@rmdir($tmp);

echo "\n========================================\n";
echo "通过 {$passCount} 项，失败 {$failCount} 项\n";
if ($failCount) {
    echo "失败项：\n - " . implode("\n - ", $failures) . "\n";
    exit(1);
}
echo "全部通过 ✓\n";
