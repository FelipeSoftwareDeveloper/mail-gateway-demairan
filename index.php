<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$admin = require_admin();

$stats = db_one(
    "SELECT
        SUM(status = 'sent'         AND created_at >= CURDATE())               AS sent_today,
        SUM(status = 'failed'       AND created_at >= CURDATE())               AS failed_today,
        SUM(status = 'unauthorized' AND created_at >= NOW() - INTERVAL 1 DAY)  AS unauth_24h,
        SUM(status = 'sent'         AND created_at >= CURDATE() - INTERVAL 29 DAY) AS sent_30d
     FROM email_logs
     WHERE created_at >= CURDATE() - INTERVAL 29 DAY"
) ?? [];
$counts = db_one(
    "SELECT (SELECT COUNT(*) FROM projects) AS projects,
            (SELECT COUNT(*) FROM smtp_accounts) AS smtp,
            (SELECT COUNT(*) FROM api_tokens WHERE status = 'active') AS active_tokens"
) ?? [];

// Série dos últimos 7 dias
$days = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $days[$d] = ['sent' => 0, 'failed' => 0, 'unauthorized' => 0];
}
foreach (db_all(
    "SELECT DATE(created_at) AS d, status, COUNT(*) AS c
       FROM email_logs WHERE created_at >= CURDATE() - INTERVAL 6 DAY
      GROUP BY DATE(created_at), status"
) as $r) {
    if (isset($days[$r['d']])) {
        $days[$r['d']][$r['status']] = (int) $r['c'];
    }
}
$max = 1;
foreach ($days as $v) {
    $max = max($max, $v['sent'], $v['failed'], $v['unauthorized']);
}

$recent = db_all(
    'SELECT l.id, l.status, l.recipient_email, l.subject, l.sender_email, l.created_at, p.name AS project
       FROM email_logs l LEFT JOIN projects p ON p.id = l.project_id
      ORDER BY l.id DESC LIMIT 8'
);

$steps = [
    ['Cadastre uma conta SMTP do Titan', (int) $counts['smtp'] > 0, 'smtp.php'],
    ['Crie um projeto', (int) $counts['projects'] > 0, 'projects.php'],
    ['Gere um token de API', (int) $counts['active_tokens'] > 0, 'tokens.php'],
    ['Faça o primeiro envio pela API', (int) ($stats['sent_30d'] ?? 0) > 0, 'tokens.php'],
];
$setupDone = !in_array(false, array_column($steps, 1), true);

render_header('Visão geral', 'dashboard');
page_head('Olá, ' . explode(' ', $admin['name'])[0], 'Resumo dos envios do gateway.', '<a class="btn btn-primary" href="tokens.php">Novo token</a>');

if (phpmailer_path() === null) {
    echo '<div class="alert alert-warning">PHPMailer não encontrado. Os envios vão falhar até você enviar a pasta <code>src/</code> para <code>lib/PHPMailer/src/</code>.</div>';
}
if (is_file(__DIR__ . '/install.php')) {
    echo '<div class="alert alert-warning">O arquivo <code>install.php</code> ainda está no servidor. Apague-o pelo Gerenciador de Arquivos.</div>';
}
?>
<section class="stats">
  <div class="card stat"><div class="stat-label"><span class="dot dot-success"></span>Enviados hoje</div><div class="stat-value"><?= fmt_num($stats['sent_today'] ?? 0) ?></div></div>
  <div class="card stat"><div class="stat-label"><span class="dot dot-danger"></span>Falhas hoje</div><div class="stat-value"><?= fmt_num($stats['failed_today'] ?? 0) ?></div></div>
  <div class="card stat"><div class="stat-label"><span class="dot dot-warning"></span>Bloqueios em 24h</div><div class="stat-value"><?= fmt_num($stats['unauth_24h'] ?? 0) ?></div></div>
  <div class="card stat"><div class="stat-label"><span class="dot dot-neutral"></span>Tokens ativos</div><div class="stat-value"><?= fmt_num($counts['active_tokens'] ?? 0) ?></div></div>
</section>

<section class="grid-2">
  <div class="card">
    <div class="card-head"><h2>Últimos 7 dias</h2>
      <div class="legend"><span><i class="dot dot-success"></i>Enviados</span><span><i class="dot dot-danger"></i>Falhas</span><span><i class="dot dot-warning"></i>Bloqueios</span></div>
    </div>
    <div class="card-body">
      <?php
      $w = 700; $h = 220; $top = 10; $bottom = 28; $plotH = $h - $top - $bottom;
      $groupW = $w / 7; $barW = 18; $gap = 4;
      ?>
      <svg class="chart" viewBox="0 0 <?= $w ?> <?= $h ?>" role="img" aria-label="Envios por dia nos últimos 7 dias">
        <?php for ($g = 0; $g <= 4; $g++): $y = $top + $plotH * $g / 4; ?>
          <line class="grid" x1="0" x2="<?= $w ?>" y1="<?= $y ?>" y2="<?= $y ?>"/>
        <?php endfor; ?>
        <?php $i = 0; foreach ($days as $date => $v):
            $cx = $groupW * $i + $groupW / 2;
            $series = [['sent', 'bar-sent'], ['failed', 'bar-failed'], ['unauthorized', 'bar-unauth']];
            foreach ($series as $k => [$key, $cls]):
                $bh = $v[$key] > 0 ? max(2, $plotH * $v[$key] / $max) : 0;
                $x = $cx - (3 * $barW + 2 * $gap) / 2 + $k * ($barW + $gap);
                $y = $top + $plotH - $bh; ?>
            <rect class="<?= $cls ?>" x="<?= round($x, 1) ?>" y="<?= round($y, 1) ?>" width="<?= $barW ?>" height="<?= round($bh, 1) ?>" rx="3"><title><?= e(date('d/m', strtotime($date)) . ' · ' . status_label($key) . ': ' . $v[$key]) ?></title></rect>
            <?php endforeach; ?>
            <text class="axis" x="<?= round($cx, 1) ?>" y="<?= $h - 8 ?>" text-anchor="middle"><?= e(date('d/m', strtotime($date))) ?></text>
        <?php $i++; endforeach; ?>
      </svg>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h2><?= $setupDone ? 'Tudo pronto' : 'Primeiros passos' ?></h2></div>
    <div class="card-body">
      <ol class="steps">
        <?php foreach ($steps as $n => [$label, $done, $href]): ?>
          <li class="<?= $done ? 'done' : '' ?>"><span class="step-n"><?= $n + 1 ?></span>
            <span class="step-t"><?= $done ? e($label) : '<a href="' . e($href) . '">' . e($label) . '</a>' ?></span></li>
        <?php endforeach; ?>
      </ol>
      <p class="muted small steps-foot"><?= fmt_num($stats['sent_30d'] ?? 0) ?> e-mails enviados nos últimos 30 dias.</p>
    </div>
  </div>
</section>

<section class="card">
  <div class="card-head"><h2>Atividade recente</h2><a class="btn btn-secondary btn-sm" href="logs.php">Ver todos os logs</a></div>
  <?php if (!$recent): ?>
    <div class="empty"><h3>Nenhum envio ainda</h3><p>Os disparos feitos pela API aparecem aqui.</p></div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Status</th><th>Destinatário</th><th>Assunto</th><th>Projeto</th><th>Quando</th></tr></thead>
      <tbody>
      <?php foreach ($recent as $r): ?>
        <tr>
          <td><?= status_badge($r['status']) ?></td>
          <td><a href="log.php?id=<?= (int) $r['id'] ?>" class="truncate"><?= e($r['recipient_email'] ?: '—') ?></a></td>
          <td><span class="truncate"><?= e($r['subject'] ?: '—') ?></span></td>
          <td><?= e($r['project'] ?? '—') ?></td>
          <td class="nowrap muted"><?= e(fmt_dt($r['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php
render_footer();
