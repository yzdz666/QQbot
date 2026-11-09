<?php
/**
 * 官鸡插件市场 - 插件仓库操作（审核/上下架/删除）
 */

/** 将版本文件移入正式目录并审核通过 */
function market_approve_version($versionId, $admin) {
    $pdo = MarketDB::pdo();
    $stmt = $pdo->prepare("SELECT v.*, p.slug FROM versions v JOIN plugins p ON p.id = v.plugin_id WHERE v.id = ?");
    $stmt->execute([$versionId]);
    $v = $stmt->fetch();
    if (!$v) throw new RuntimeException('版本不存在');
    if ($v['status'] === 'approved') return true;

    $safeVersion = preg_replace('/[^0-9A-Za-z\.\-_]/', '', $v['version']);
    if ($safeVersion === '') throw new RuntimeException('版本号不合法');
    $finalDir = MARKET_STORAGE_DIR . '/plugins/' . $v['slug'] . '/' . $safeVersion;
    if (!is_dir($finalDir) && !@mkdir($finalDir, 0750, true) && !is_dir($finalDir)) {
        throw new RuntimeException('创建存储目录失败，请检查 storage 权限');
    }
    $finalPath = $finalDir . '/plugin.php.txt';

    if (!@rename($v['file_path'], $finalPath)) {
        if (!@copy($v['file_path'], $finalPath)) {
            throw new RuntimeException('移动插件文件失败，请检查 storage 目录权限');
        }
        @unlink($v['file_path']);
    }
    @chmod($finalPath, 0640);
    $sha = hash_file('sha256', $finalPath);

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "UPDATE versions SET status = 'approved', file_path = ?, sha256 = ?, file_size = ?, approved_at = datetime('now','localtime') WHERE id = ?"
        )->execute([$finalPath, $sha, filesize($finalPath), $versionId]);
        $pdo->prepare(
            "UPDATE plugins SET status = 'approved', reject_reason = '', published_at = COALESCE(published_at, datetime('now','localtime')), updated_at = datetime('now','localtime') WHERE id = ?"
        )->execute([$v['plugin_id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    market_audit($admin, '审核通过', $v['slug'] . ' v' . $v['version']);
    return true;
}

/** 拒绝某个版本 */
function market_reject_version($versionId, $reason, $admin) {
    $pdo = MarketDB::pdo();
    $stmt = $pdo->prepare("SELECT v.*, p.slug FROM versions v JOIN plugins p ON p.id = v.plugin_id WHERE v.id = ?");
    $stmt->execute([$versionId]);
    $v = $stmt->fetch();
    if (!$v) throw new RuntimeException('版本不存在');

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE versions SET status = 'rejected' WHERE id = ?")->execute([$versionId]);
        // 没有任何已通过版本时，插件整体标记为拒绝
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM versions WHERE plugin_id = ? AND status = 'approved'");
        $stmt->execute([$v['plugin_id']]);
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->prepare("UPDATE plugins SET status = 'rejected', reject_reason = ?, updated_at = datetime('now','localtime') WHERE id = ?")
                ->execute([$reason, $v['plugin_id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    market_audit($admin, '审核拒绝', $v['slug'] . ' v' . $v['version'] . '：' . $reason);
    return true;
}

/** 上架/下架 */
function market_set_plugin_status($pluginId, $status, $admin) {
    if (!in_array($status, ['approved', 'offline', 'pending'], true)) {
        throw new RuntimeException('非法状态');
    }
    $stmt = MarketDB::pdo()->prepare("SELECT slug FROM plugins WHERE id = ?");
    $stmt->execute([$pluginId]);
    $slug = $stmt->fetchColumn();
    MarketDB::pdo()->prepare("UPDATE plugins SET status = ?, updated_at = datetime('now','localtime') WHERE id = ?")
        ->execute([$status, $pluginId]);
    $label = ['approved' => '上架', 'offline' => '下架', 'pending' => '转为待审核'][$status];
    market_audit($admin, $label, (string)$slug);
}

/** 删除插件及其全部版本文件 */
function market_delete_plugin($pluginId, $admin) {
    $pdo = MarketDB::pdo();
    $stmt = $pdo->prepare("SELECT * FROM plugins WHERE id = ?");
    $stmt->execute([$pluginId]);
    $plugin = $stmt->fetch();
    if (!$plugin) return false;

    $stmt = $pdo->prepare("SELECT * FROM versions WHERE plugin_id = ?");
    $stmt->execute([$pluginId]);
    $versions = $stmt->fetchAll();

    $storageRoot = realpath(MARKET_STORAGE_DIR);
    foreach ($versions as $v) {
        $path = realpath($v['file_path']);
        if ($path && $storageRoot && strpos($path, $storageRoot . DIRECTORY_SEPARATOR) === 0) {
            @unlink($path);
        }
    }
    // 删除插件目录
    $dir = MARKET_STORAGE_DIR . '/plugins/' . $plugin['slug'];
    if (is_dir($dir)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM versions WHERE plugin_id = ?")->execute([$pluginId]);
        $pdo->prepare("DELETE FROM plugins WHERE id = ?")->execute([$pluginId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    market_audit($admin, '删除插件', $plugin['slug']);
    return true;
}
