<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

require_admin();

if (is_post()) {
    $action = post('action');
    $id = (int) post('id');
    $name = post('name');
    $description = post('description');

    if ($action === 'create') {
        try {
            project_create($name, $description);
            flash('success', 'Projeto criado.');
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        }
        redirect('projects.php');
    }

    if ($action === 'update') {
        if ($name === '' || mb_strlen($name) > 120) {
            flash('error', 'Informe o nome do projeto (até 120 caracteres).');
            redirect('projects.php?edit=' . $id);
        }
        if (mb_strlen($description) > 2000) {
            $description = mb_substr($description, 0, 2000);
        }
        $dup = db_val('SELECT id FROM projects WHERE name = ? AND id <> ?', [$name, $id]);
        if ($dup) {
            flash('error', 'Já existe um projeto com esse nome.');
            redirect('projects.php?edit=' . $id);
        }
        db_run('UPDATE projects SET name = ?, description = ? WHERE id = ?', [$name, $description !== '' ? $description : null, $id]);
        flash('success', 'Projeto atualizado.');
    } elseif ($action === 'delete') {
        $tokens = (int) db_val('SELECT COUNT(*) FROM api_tokens WHERE project_id = ?', [$id]);
        if ($tokens > 0) {
            flash('error', "Este projeto tem {$tokens} token(s). Revogue e exclua os tokens antes de excluir o projeto.");
        } else {
            db_run('DELETE FROM projects WHERE id = ?', [$id]);
            flash('success', 'Projeto excluído. Os logs antigos foram mantidos.');
        }
    }
    redirect('projects.php');
}

$editing = null;
if ($editId = query_int('edit')) {
    $editing = db_one('SELECT * FROM projects WHERE id = ?', [$editId]);
}

$projects = projects_list();

render_header('Projetos', 'projects');
page_head('Projetos', 'Agrupe os tokens por aplicação: ERP, site, app mobile.');
?>
<section class="grid-2">
  <div class="card">
    <?php if (!$projects): ?>
      <div class="empty"><h3>Nenhum projeto</h3><p>Crie o primeiro projeto no formulário ao lado.</p></div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Projeto</th><th>Tokens ativos</th><th>Enviados</th><th>Última atividade</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($projects as $p): ?>
          <tr>
            <td><strong><?= e($p['name']) ?></strong><?php if ($p['description']): ?><span class="cell-sub truncate"><?= e($p['description']) ?></span><?php endif; ?></td>
            <td><?= fmt_num($p['active_tokens']) ?></td>
            <td><a href="logs.php?project_id=<?= (int) $p['id'] ?>&amp;status=sent"><?= fmt_num($p['sent_total']) ?></a></td>
            <td class="nowrap muted"><?= e(fmt_dt($p['last_activity'])) ?></td>
            <td>
              <div class="actions">
                <a class="btn btn-secondary btn-sm" href="projects.php?edit=<?= (int) $p['id'] ?>">Editar</a>
                <form method="post" data-confirm="Excluir o projeto &quot;<?= e($p['name']) ?>&quot;?">
                  <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                  <button class="btn btn-danger btn-sm" type="submit">Excluir</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-head"><h2><?= $editing ? 'Editar projeto' : 'Novo projeto' ?></h2></div>
    <div class="card-body">
      <form method="post" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
        <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
        <div class="field">
          <label for="name">Nome</label>
          <input class="input" id="name" name="name" maxlength="120" required value="<?= e($editing['name'] ?? '') ?>" placeholder="Sistema ERP">
        </div>
        <div class="field">
          <label for="description">Descrição</label>
          <textarea class="textarea" id="description" name="description" maxlength="2000" placeholder="Para que serve, quem mantém"><?= e($editing['description'] ?? '') ?></textarea>
        </div>
        <div class="form-actions">
          <button class="btn btn-primary" type="submit"><?= $editing ? 'Salvar' : 'Criar projeto' ?></button>
          <?php if ($editing): ?><a class="btn btn-secondary" href="projects.php">Cancelar</a><?php endif; ?>
        </div>
      </form>
    </div>
  </div>
</section>
<?php
render_footer();
