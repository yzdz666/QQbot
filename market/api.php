<?php
/**
 * 官鸡插件市场 - 公开 API
 * GET  action=list|detail|source|check
 */

require_once __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
market_security_headers(false);

if (!market_rate_limit('api:' . market_client_ip(), 180, 60)) {
    market_json_out(['success' => false, 'message' => '请求过于频繁，请稍后再试'], 429);
}

$action = $_GET['action'] ?? 'list';
$pdo = MarketDB::pdo();

switch ($action) {
    case 'list':
        $q = trim((string)($_GET['q'] ?? ''));
        $category = trim((string)($_GET['category'] ?? ''));
        $sort = (string)($_GET['sort'] ?? 'featured');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(48, max(1, (int)($_GET['limit'] ?? 12)));
        $offset = ($page - 1) * $limit;

        $where = [MarketDB::public_where()];
        $params = [];
        if ($q !== '') {
            $where[] = "(p.name LIKE ? OR p.summary LIKE ? OR p.author LIKE ? OR p.tags LIKE ?)";
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            array_push($params, $like, $like, $like, $like);
        }
        if ($category !== '' && $category !== '全部') {
            $where[] = "p.category = ?";
            $params[] = $category;
        }
        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $orderMap = [
            'featured' => 'p.featured DESC, p.downloads DESC, p.published_at DESC',
            'newest'   => 'p.published_at DESC, p.id DESC',
            'downloads'=> 'p.downloads DESC, p.id DESC',
            'name'     => 'p.name ASC',
        ];
        $order = $orderMap[$sort] ?? $orderMap['featured'];

        $total = (int)(function () use ($pdo, $whereSql, $params) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM plugins p" . $whereSql);
            $stmt->execute($params);
            return $stmt->fetchColumn();
        })();

        $stmt = $pdo->prepare(
            "SELECT p.*,
                    (SELECT v.version FROM versions v WHERE v.plugin_id = p.id AND v.status = 'approved' ORDER BY v.id DESC LIMIT 1) AS version,
                    (SELECT v.sha256 FROM versions v WHERE v.plugin_id = p.id AND v.status = 'approved' ORDER BY v.id DESC LIMIT 1) AS sha256,
                    (SELECT v.file_size FROM versions v WHERE v.plugin_id = p.id AND v.status = 'approved' ORDER BY v.id DESC LIMIT 1) AS file_size,
                    (SELECT v.scan_status FROM versions v WHERE v.plugin_id = p.id AND v.status = 'approved' ORDER BY v.id DESC LIMIT 1) AS scan_status,
                    (SELECT COUNT(*) FROM versions v WHERE v.plugin_id = p.id AND v.status = 'approved') AS versions_count
             FROM plugins p" . $whereSql . "
             ORDER BY " . $order . " LIMIT ? OFFSET ?"
        );
        $stmt->execute(array_merge($params, [$limit, $offset]));
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = market_public_item($row);
        }

        market_json_out([
            'success' => true,
            'data' => [
                'items' => $items,
                'total' => $total,
                'page' => $page,
                'pages' => max(1, (int)ceil($total / $limit)),
                'categories' => market_categories(),
                'stats' => market_stats(),
            ],
        ]);
        break;

    case 'detail':
        $id = $_GET['id'] ?? ($_GET['slug'] ?? '');
        $plugin = MarketDB::plugin_with_latest($id);
        if (!$plugin || $plugin['status'] !== 'approved' || empty($plugin['latest'])) {
            market_json_out(['success' => false, 'message' => '插件不存在或尚未通过审核'], 404);
        }
        $stmt = $pdo->prepare("SELECT * FROM versions WHERE plugin_id = ? AND status = 'approved' ORDER BY id DESC");
        $stmt->execute([$plugin['id']]);
        $versions = [];
        foreach ($stmt->fetchAll() as $v) {
            $versions[] = [
                'version' => $v['version'],
                'changelog' => $v['changelog'],
                'size' => (int)$v['file_size'],
                'sha256' => $v['sha256'],
                'downloads' => (int)$v['downloads'],
                'created_at' => $v['created_at'],
            ];
        }
        $item = market_public_item($plugin);
        $item['description'] = $plugin['description'];
        $item['homepage'] = $plugin['homepage'];
        $item['license'] = $plugin['license'];
        $item['author_link'] = $plugin['author_link'];
        $item['versions'] = $versions;
        $item['latest'] = [
            'version' => $plugin['latest']['version'],
            'sha256' => $plugin['latest']['sha256'],
            'size' => (int)$plugin['latest']['file_size'],
            'changelog' => $plugin['latest']['changelog'],
            'scan_status' => $plugin['latest']['scan_status'],
        ];
        market_json_out(['success' => true, 'data' => $item]);
        break;

    case 'source':
        $id = $_GET['id'] ?? ($_GET['slug'] ?? '');
        $version = trim((string)($_GET['version'] ?? ''));
        $plugin = MarketDB::plugin_with_latest($id);
        if (!$plugin || $plugin['status'] !== 'approved') {
            market_json_out(['success' => false, 'message' => '插件不存在或未通过审核'], 404);
        }
        if ($version !== '') {
            $stmt = $pdo->prepare("SELECT * FROM versions WHERE plugin_id = ? AND version = ? AND status = 'approved'");
            $stmt->execute([$plugin['id'], $version]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM versions WHERE plugin_id = ? AND status = 'approved' ORDER BY id DESC LIMIT 1");
            $stmt->execute([$plugin['id']]);
        }
        $v = $stmt->fetch();
        if (!$v) {
            market_json_out(['success' => false, 'message' => '该版本不存在或未通过审核'], 404);
        }
        $path = realpath($v['file_path']);
        $storage = realpath(MARKET_STORAGE_DIR);
        if ($path === false || $storage === false || strpos($path, $storage . DIRECTORY_SEPARATOR) !== 0) {
            market_json_out(['success' => false, 'message' => '插件文件不可用'], 500);
        }
        if (filesize($path) > 1048576) {
            market_json_out(['success' => false, 'message' => '源码文件过大，请下载后查看'], 413);
        }
        $code = file_get_contents($path);
        market_json_out([
            'success' => true,
            'data' => [
                'plugin_id' => (int)$plugin['id'],
                'name' => $plugin['name'],
                'version' => $v['version'],
                'sha256' => $v['sha256'],
                'scan_status' => $v['scan_status'],
                'source' => $code,
            ],
        ]);
        break;

    case 'check':
        $slug = trim((string)($_GET['slug'] ?? ''));
        $current = trim((string)($_GET['version'] ?? ''));
        $plugin = MarketDB::plugin_with_latest($slug);
        if (!$plugin || $plugin['status'] !== 'approved' || empty($plugin['latest'])) {
            market_json_out(['success' => false, 'message' => '插件不存在'], 404);
        }
        $latest = $plugin['latest']['version'];
        market_json_out([
            'success' => true,
            'data' => [
                'slug' => $plugin['slug'],
                'name' => $plugin['name'],
                'latest' => $latest,
                'sha256' => $plugin['latest']['sha256'],
                'has_update' => $current !== '' && market_version_cmp($current, $latest) < 0,
            ],
        ]);
        break;

    default:
        market_json_out(['success' => false, 'message' => '未知操作'], 400);
}

/** 统一公开字段 */
function market_public_item($row) {
    $tags = array_values(array_filter(array_map('trim', explode(',', (string)($row['tags'] ?? '')))));
    return [
        'id' => (int)$row['id'],
        'slug' => $row['slug'],
        'name' => $row['name'],
        'summary' => $row['summary'],
        'category' => $row['category'],
        'author' => $row['author'],
        'icon' => $row['icon'] ?: '🧩',
        'tags' => $tags,
        'featured' => (int)$row['featured'],
        'downloads' => (int)$row['downloads'],
        'status' => $row['status'],
        'version' => $row['version'] ?? ($row['latest']['version'] ?? ''),
        'sha256' => $row['sha256'] ?? ($row['latest']['sha256'] ?? ''),
        'file_size' => (int)($row['file_size'] ?? ($row['latest']['file_size'] ?? 0)),
        'scan_status' => $row['scan_status'] ?? ($row['latest']['scan_status'] ?? ''),
        'versions_count' => (int)($row['versions_count'] ?? 1),
        'published_at' => $row['published_at'],
        'updated_at' => $row['updated_at'],
    ];
}
