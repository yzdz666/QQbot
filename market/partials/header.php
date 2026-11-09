<?php
/** 市场前台公共头部 */
if (!defined('MARKET_ROOT')) { require_once dirname(__DIR__) . '/lib/bootstrap.php'; }
$siteName = market_setting('site_name', '官鸡插件市场');
$siteDesc = market_setting('site_desc', '');
$activeNav = $activeNav ?? 'home';
$pageTitle = $pageTitle ?? '首页';
$stats = market_stats();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="strict-origin-when-cross-origin">
<title><?= market_h($pageTitle) ?> - <?= market_h($siteName) ?></title>
<meta name="description" content="<?= market_h($siteDesc ?: $pageTitle) ?>">
<link rel="stylesheet" href="<?= market_h(market_base_url()) ?>/assets/style.css">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='24' fill='%235b8cff'/><text y='.9em' x='50%' text-anchor='middle' font-size='58' fill='%23071020'>Q</text></svg>">
</head>
<body>
<header class="site-header">
  <div class="container inner">
    <a class="brand" href="<?= market_h(market_base_url()) ?>/index.php">
      <span class="brand-mark">Q</span>
      <span><?= market_h($siteName) ?><small>QQ BOT PLUGIN MARKET</small></span>
    </a>
    <nav class="nav">
      <a href="<?= market_h(market_base_url()) ?>/index.php" class="<?= $activeNav === 'home' ? 'active' : '' ?>">插件广场</a>
      <a href="<?= market_h(market_base_url()) ?>/index.php?sort=newest" class="<?= $activeNav === 'new' ? 'active' : '' ?>">最新上架</a>
      <a href="<?= market_h(market_base_url()) ?>/submit.php" class="<?= $activeNav === 'submit' ? 'active' : '' ?>">发布插件</a>
      <a href="<?= market_h(market_base_url()) ?>/docs.php" class="<?= $activeNav === 'docs' ? 'active' : '' ?>">接入说明</a>
    </nav>
    <div class="nav-right">
      <form class="header-search" method="get" action="<?= market_h(market_base_url()) ?>/index.php">
        <input type="text" name="q" placeholder="搜索插件 / 作者 / 标签" value="<?= market_h($_GET['q'] ?? '') ?>">
        <svg width="15" height="15" viewBox="0 0 16 16" fill="none"><circle cx="7" cy="7" r="4.5" stroke="currentColor" stroke-width="1.5"/><path d="M10.5 10.5L14 14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
      </form>
      <a class="btn btn-ghost btn-sm" href="<?= market_h(market_base_url()) ?>/admin/">管理后台</a>
    </div>
  </div>
</header>
<main>
