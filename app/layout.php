<?php
declare(strict_types=1);

/** Símbolo Demairan: arco mimosa (ciclo), núcleo (relógio interno), ponto dawn (o aviso). */
function brand_symbol(string $class = 'brand-symbol'): string
{
    return '<svg class="' . e($class) . '" viewBox="48 72 144 144" aria-hidden="true" focusable="false">'
        . '<path d="M120 80 A64 64 0 1 1 56 144" fill="none" stroke="#22C39A" stroke-width="16" stroke-linecap="round"/>'
        . '<circle class="sym-core" cx="120" cy="144" r="24"/>'
        . '<circle cx="72" cy="96" r="12" fill="#F5A524"/></svg>';
}

function icon(string $name): string
{
    $paths = [
        'home'    => '<path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
        'folder'  => '<path d="M3 6a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'mail'    => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'key'     => '<circle cx="8" cy="15" r="4"/><path d="m10.8 12.2 9.2-9.2M17 6l3 3M14 9l2 2"/>',
        'list'    => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'users'   => '<circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0M16 3.5a4 4 0 0 1 0 9M22 21a7 7 0 0 0-4-6.3"/>',
        'logout'  => '<path d="M15 4h4a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-4M10 17l5-5-5-5M15 12H3"/>',
        'moon'    => '<path d="M21 13A9 9 0 1 1 11 3a7 7 0 0 0 10 10z"/>',
        'copy'    => '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1"/>',
        'menu'    => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'plug'    => '<path d="M9 2v6M15 2v6M6 8h12v4a6 6 0 0 1-12 0zM12 18v4"/>',
    ];
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" '
        . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

function render_head(string $title): void
{
    $app = (string) config('app_name', 'Mail Gateway');
    ?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> · <?= e($app) ?></title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="assets/app.css?v=2">
<script src="assets/theme.js?v=1"></script>
<script src="assets/app.js?v=2" defer></script>
</head>
<?php
}

function render_flashes(): void
{
    foreach (pull_flashes() as $f) {
        echo '<div class="alert alert-' . e($f['type']) . '" role="status">' . e($f['message']) . '</div>';
    }
}

function render_header(string $title, string $active = ''): void
{
    $admin = current_admin();
    $nav = [
        'dashboard' => ['index.php', 'Visão geral', 'home'],
        'projects'  => ['projects.php', 'Projetos', 'folder'],
        'smtp'      => ['smtp.php', 'Contas de envio', 'mail'],
        'tokens'    => ['tokens.php', 'Tokens de API', 'key'],
        'logs'      => ['logs.php', 'Logs', 'list'],
        'mcp'       => ['mcp_keys.php', 'Acesso MCP', 'plug'],
        'admins'    => ['admins.php', 'Administradores', 'users'],
    ];
    render_head($title);
    ?>
<body>
<div class="app">
  <aside class="sidebar" id="sidebar">
    <a class="brand" href="index.php">
      <?= brand_symbol('brand-symbol on-dark') ?>
      <span class="brand-text"><span class="brand-name">DEMAIRAN</span><span class="brand-sub">Mail Gateway</span></span>
    </a>
    <button class="nav-toggle" type="button" data-toggle-nav aria-controls="sidebar-nav" aria-expanded="false">
      <?= icon('menu') ?><span class="sr-only">Menu</span>
    </button>
    <nav class="nav" id="sidebar-nav">
      <?php foreach ($nav as $key => [$href, $label, $ic]): ?>
        <a href="<?= e($href) ?>" class="<?= $key === $active ? 'is-active' : '' ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>>
          <?= icon($ic) ?><span><?= e($label) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot">
      <div class="who">
        <span class="avatar"><?= e(strtoupper(substr((string) ($admin['name'] ?? '?'), 0, 1))) ?></span>
        <span class="who-text"><span class="who-name"><?= e($admin['name'] ?? '') ?></span><span class="who-mail"><?= e($admin['email'] ?? '') ?></span></span>
      </div>
      <div class="sidebar-actions">
        <button type="button" class="icon-btn" data-theme-toggle title="Alternar tema claro/escuro"><?= icon('moon') ?><span class="sr-only">Alternar tema</span></button>
        <form method="post" action="logout.php">
          <?= csrf_field() ?>
          <button type="submit" class="icon-btn" title="Sair"><?= icon('logout') ?><span class="sr-only">Sair</span></button>
        </form>
      </div>
    </div>
  </aside>
  <main class="main">
    <?php render_flashes(); ?>
<?php
}

function render_footer(): void
{
    ?>
  </main>
</div>
</body>
</html>
<?php
}

function render_guest_header(string $title): void
{
    render_head($title);
    ?>
<body class="guest">
<div class="auth">
  <div class="auth-card">
    <div class="auth-brand"><?= brand_symbol('brand-symbol') ?><span class="brand-name">DEMAIRAN</span></div>
    <?php render_flashes(); ?>
<?php
}

function render_guest_footer(): void
{
    ?>
  </div>
  <p class="auth-foot">No ritmo certo do seu negócio.</p>
</div>
</body>
</html>
<?php
}

function page_head(string $title, string $subtitle = '', string $actionsHtml = ''): void
{
    echo '<header class="page-head"><div><h1>' . e($title) . '</h1>';
    if ($subtitle !== '') {
        echo '<p class="muted">' . e($subtitle) . '</p>';
    }
    echo '</div>' . ($actionsHtml !== '' ? '<div class="page-actions">' . $actionsHtml . '</div>' : '') . '</header>';
}

/** Paginação simples preservando os filtros da URL. */
function render_pagination(int $page, int $pages, array $params): void
{
    if ($pages <= 1) {
        return;
    }
    $link = function (int $p) use ($params): string {
        return '?' . http_build_query(array_merge($params, ['page' => $p]));
    };
    echo '<nav class="pagination" aria-label="Paginação">';
    echo $page > 1 ? '<a class="btn btn-secondary btn-sm" href="' . e($link($page - 1)) . '">Anterior</a>' : '<span class="btn btn-secondary btn-sm is-disabled">Anterior</span>';
    echo '<span class="muted">Página ' . $page . ' de ' . $pages . '</span>';
    echo $page < $pages ? '<a class="btn btn-secondary btn-sm" href="' . e($link($page + 1)) . '">Próxima</a>' : '<span class="btn btn-secondary btn-sm is-disabled">Próxima</span>';
    echo '</nav>';
}
