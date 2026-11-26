<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

require_admin();

$log = log_find(query_int('id'));
if (!$log) {
    http_response_code(404);
    flash('error', 'Registro não encontrado.');
    redirect('logs.php');
}

render_header('Log #' . $log['id'], 'logs');
page_head('Registro #' . $log['id'], fmt_dt($log['created_at'], 'd/m/Y \à\s H:i:s'), '<a class="btn btn-secondary" href="logs.php">Voltar aos logs</a>');
?>
<section class="card">
  <div class="card-body">
    <dl class="dl">
      <dt>Status</dt><dd><?= status_badge($log['status']) ?></dd>
      <?php if ($log['error_message']): ?><dt>Erro</dt><dd><?= e($log['error_message']) ?></dd><?php endif; ?>
      <dt>Remetente</dt><dd><?= e($log['sender_email'] ?: '—') ?></dd>
      <dt>Destinatário(s)</dt><dd><?= e($log['recipient_email'] ?: '—') ?></dd>
      <dt>Assunto</dt><dd><?= e($log['subject'] ?: '—') ?></dd>
      <dt>Projeto</dt><dd><?= e($log['project'] ?? '—') ?></dd>
      <dt>Token</dt><dd><?= $log['token_name'] ? e($log['token_name']) . ' <span class="mono muted">' . e($log['token_prefix']) . '…</span> ' . status_badge($log['token_status']) : '—' ?></dd>
      <dt>Message-ID</dt><dd class="mono"><?= e($log['message_id'] ?: '—') ?></dd>
      <dt>IP de origem</dt><dd class="mono"><?= e($log['origin_ip']) ?></dd>
      <dt>User-Agent</dt><dd class="mono"><?= e($log['user_agent'] ?: '—') ?></dd>
      <dt>Origin / Referer</dt><dd class="mono"><?= e($log['origin_header'] ?: '—') ?></dd>
    </dl>
  </div>
</section>

<?php if ($log['body_preview'] !== null && $log['body_preview'] !== ''): ?>
<section class="card">
  <div class="card-head"><h2>Conteúdo registrado</h2><span class="muted small">Exibido como texto, nunca renderizado</span></div>
  <div class="card-body"><pre class="prebox"><?= e($log['body_preview']) ?></pre></div>
</section>
<?php endif; ?>
<?php
render_footer();
