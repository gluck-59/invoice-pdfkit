<?php
// Cabinet / documents fragment: document list and editor. Mirrors the companies
// section without the logo. Guarded against direct requests.
if (!defined('CABINET_PART')) { http_response_code(404); exit; }
?>
    <?php if ($cabDocuments): ?>
    <h3 class="cab-h"><?= e(t('my_documents')) ?></h3>
    <table id="invoices" class="cab-table">
      <?php foreach ($cabDocuments as $doc): ?>
      <tr<?= $cabDocument && (int)$cabDocument['id'] === (int)$doc['id'] ? ' class="sel"' : '' ?>>
        <td><a href="/?mode=cabinet&invoice=<?= (int)$doc['id'] ?>">#<?= (int)$doc['id'] ?></a></td>
        <td class="act">
          <a class="btn ghost small" href="/?mode=preview&invoice=<?= (int)$doc['id'] ?>" target="_blank"><?= e(t('preview')) ?></a>
          <form method="post" action="/?mode=cabinet" onsubmit="return confirm('<?= e(t('confirm_delete_document')) ?>')">
            <input type="hidden" name="action" value="delete_document">
            <input type="hidden" name="document_id" value="<?= (int)$doc['id'] ?>">
            <button class="btn ghost small" type="submit"><?= e(t('delete')) ?></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif;
    ?>

    <h3 class="cab-h"><?= e($cabDocument ? t('edit') . ' #' . (int)$cabDocument['id'] : t('new_document')) ?>
      <small class="muted"><?= t('non_required')?></small>
    </h3>

    <hr>

      <form method="post" action="/?mode=cabinet<?= $cabDocument ? '&invoice=' . (int)$cabDocument['id'] : '' ?>" class="cab-form">
      <input type="hidden" name="action" value="save_document">
      <input type="hidden" name="document_id" value="<?= $cabDocument ? (int)$cabDocument['id'] : '' ?>">
      <?php foreach (DOCUMENT_FIELDS as $f): ?>
        <?php if ($f === 'terms'): ?>
          <label><?= e(t('cf_terms')) ?><textarea name="cf_terms" rows="3"><?= e(implode("\n", (array)($cabDocument['data']['terms'] ?? []))) ?></textarea></label>
        <?php else: ?>
          <label><?= e(t('cf_' . $f)) ?><input name="cf_<?= e($f) ?>" value="<?= e((string)($cabDocument['data'][$f] ?? '')) ?>"></label>
        <?php endif; ?>
      <?php endforeach; ?>
      <button class="btn primary" type="submit"><?= e(t('save')) ?></button>
    </form>
