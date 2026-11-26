<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

require_admin();

$f = [
    'status'     => query('status'),
    'project_id' => query_int('project_id'),
    'token_id'   => query_int('token_id'),
    'q'          => query('q'),
    'from'       => query('from'),
    'to'         => query('to'),
];

$where = [];
$params = [];
if (in_array($f['status'], ['sent', 'failed', 'unauthorized'], true)) {
    $where[] = 'l.status = ?';
    $params[] = $f['status'];
}
if ($f['project_id']) {
    $where[] = 'l.project_id = ?';
    $params[] = $f['project_id'];
}
if ($f['token_id']) {
    $where[] = 'l.token_id = ?';
    $params[] = $f['token_id'];
}
if ($f['q'] !== '') {
    $like = '%' . addcslashes(mb_substr($f['q'], 0, 190), '%_\\') . '%';
    $where[] = '(l.recipient_email LIKE ? OR l.subject LIKE ? OR l.sender_email LIKE ? OR l.origin_ip LIKE ?)';
    array_push($params, $like, $like, $like, $like);
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['from'])) {
    $where[] = 'l.created_at >= ?';
    $params[] = $f['from'] . ' 00:00:00';
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['to'])) {
    $where[] = 'l.created_at <= ?';
    $params[] = $f['to'] . ' 23:59:59';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$perPage = 50;
$total = (int) db_val("SELECT COUNT(*) FROM email_logs l {$whereSql}", $params);
$pages = max(1, (int) ceil($total / $perPage));
$page = min(max(1, query_int('page', 1)), $pages);
$offset = ($page - 1) * $perPage;

$logs = db_all(
    "SELECT l.id, l.status, l.sender_email, l.recipient_email, l.subject, l.origin_ip, l.error_message, l.created_at,
            p.name AS project, t.name AS token_name, t.token_prefix
       FROM email_logs l
       LEFT JOIN projects p ON p.id = l.project_id
       LEFT JOIN api_tokens t ON t.id = l.token_id
       {$whereSql}
      ORDER BY l.id DESC
      LIMIT {$perPage} OFFSET {$offset}",
    $params
);

$projects = db_all('SELECT id, name FROM projects ORDER BY name');
$tokens = db_all('SELECT id, name, token_prefix FROM api_tokens ORDER BY name');

render_header('Logs', 'logs');
page_head('Logs de auditoria', fmt_num($total) . ' registro(s) encontrados. Envios, falhas e tentativas bloqueadas.');
?>
<section class="card">
  <form method="get" class="filters">
    <div class="field span-2"><label for="q">Buscar</label><input class="input" id="q" name="q" value="<?= e($f['q']) ?>" placeholder="E-mail, assunto ou IP"></div>
    <div class="field"><label for="status">Status</label>
      <select class="select" id="status" name="status">
        <option value="">Todos</option>
        <?php foreach (['sent', 'failed', 'unauthorized'] as $s): ?><option value="<?= $s ?>" <?= $f['status'] === $s ? 'selected' : '' ?>><?= e(status_label($s)) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label for="project_id">Projeto</label>
      <select class="select" id="project_id" name="project_id">
        <option value="">Todos</option>
        <?php foreach ($projects as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $f['project_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label for="from">De</label><input class="input" type="date" id="from" name="from" value="<?= e($f['from']) ?>"></div>
    <div class="field"><label for="to">Até</label><input class="input" type="date" id="to" name="to" value="<?= e($f['to']) ?>"></div>
    <div class="field span-2"><label for="token_id">Token</label>
      <select class="select" id="token_id" name="token_id">
        <option value="">Todos</option>
        <?php foreach ($tokens as $t): ?><option value="<?= (int) $t['id'] ?>" <?= $f['token_id'] === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['name'] . ' (' . $t['token_prefix'] . '…)') ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Filtrar</button><a class="btn btn-secondary" href="logs.php">Limpar</a></div>
  </form>

  <?php if (!$logs): ?>
    <div class="empty"><h3>Nada por aqui</h3><p>Nenhum registro corresponde aos filtros.</p></div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Status</th><th>Quando</th><th>De → Para</th><th>Assunto / erro</th><th>Projeto · Token</th><th>IP</th></tr></thead>
      <tbody>
      <?php foreach ($logs as $l): ?>
        <tr>
          <td><a href="log.php?id=<?= (int) $l['id'] ?>"><?= status_badge($l['status']) ?></a></td>
          <td class="nowrap muted"><a href="log.php?id=<?= (int) $l['id'] ?>"><?= e(fmt_dt($l['created_at'], 'd/m/Y H:i:s')) ?></a></td>
          <td><span class="truncate"><?= e($l['sender_email'] ?: '—') ?></span><span class="cell-sub truncate"><?= e($l['recipient_email'] ?: '—') ?></span></td>
          <td><span class="truncate"><?= e($l['subject'] ?: '—') ?></span><?php if ($l['error_message']): ?><span class="cell-sub truncate"><?= e($l['error_message']) ?></span><?php endif; ?></td>
          <td><?= e($l['project'] ?? '—') ?><span class="cell-sub"><?= e($l['token_name'] ?? 'sem token') ?></span></td>
          <td class="mono nowrap"><?= e($l['origin_ip']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php render_pagination($page, $pages, array_filter($f, fn($v) => $v !== '' && $v !== 0)); ?>
  <?php endif; ?>
</section>
<?php
render_footer();
