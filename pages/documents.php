<?php
declare(strict_types=1);

$vs = vehicles(true);
$ids = array_map('intval', array_column($vs, 'id'));
$kind = array_key_exists($_GET['kind'] ?? '', DOC_KINDS) ? $_GET['kind'] : '';
$docs = $ids ? rows('SELECT * FROM documents WHERE vehicle_id IN (' . implode(',', $ids) . ')' . ($kind ? ' AND kind = ?' : '') . ' ORDER BY created_at DESC', $kind ? [$kind] : []) : [];

page_header('Dokumente', ['nav' => 'documents', 'back' => url('more')]);
?>
<div class="chips mb">
  <a class="chip<?= !$kind ? ' on' : '' ?>" href="<?= e(url('documents')) ?>">Alle</a>
  <?php foreach (DOC_KINDS as $k => $l): ?><a class="chip<?= $kind === $k ? ' on' : '' ?>" href="<?= e(url('documents', ['kind' => $k])) ?>"><?= e($l) ?></a><?php endforeach; ?>
</div>
<?= documents_list($docs, $vs, true) ?>
<?php if ($vs): ?><p class="muted small mt">Neue Dokumente lädst du über das Plus oder direkt beim Fahrzeug hoch.</p><?php endif;
page_footer();
