<?php
// Cabinet / documents fragment: document list and editor. Guarded against direct requests.
if (!defined('CABINET_PART')) { http_response_code(404); exit; }
?>

<?php if ($cabDocuments): ?>
<h3 class="cab-h"><?= e(t('my_documents')) ?></h3>
<div id="documents" class="cab-list">
  <?php foreach ($cabDocuments as $doc): ?>
  <div class="cab-item<?= $cabDocument && (int)$cabDocument['id'] === (int)$doc['id'] ? ' sel' : '' ?>">
    <div class="cab-main">
      <a href="/?mode=cabinet&document=<?= (int)$doc['id'] ?>">
        <?= e((string)($doc['data']['document_number'] ?? '') ?: ('#' . $doc['id'])) ?>
        <span class="cab-meta">— <?= e((string)($doc['data']['company'] ?? '')) ?></span>
      </a>
      <span class="cab-meta"><?= e((new DateTime($doc['updated_at']))->format('d.m.Y G:i')) ?></span>
    </div>
    <div class="cab-actions">
      <a class="btn primary small" href="/?mode=preview&document=<?= (int)$doc['id'] ?>" target="_blank"><?= e(t('preview')) ?></a>
      <a class="btn danger small" href="/?action=delete_document&document_id=<?= (int)$doc['id'] ?>"
         onclick="return confirm('<?= e(t('confirm_delete_document')) ?>')"><?= e(t('delete')) ?></a>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<h3 class="cab-h"><?= e($cabDocument ? t('edit') . ' ' . $cabDocument['data']['company'] : t('new_document')) ?>
  <small class="muted"><?= t('non_required')?></small>
</h3>

<hr>

<form id="document" method="post" action="/?mode=cabinet<?= $cabDocument ? '&document=' . (int)$cabDocument['id'] : '' ?>" class="cab-form">
  <input type="hidden" name="action" value="save_document">
  <input type="hidden" name="document_id" value="<?= $cabDocument ? (int)$cabDocument['id'] : '' ?>">
  <?php if (!$cabCompanies): ?>
    <p class="muted"><?= e(t('err_no_company')) ?> — <?= e(t('cf_issuer')) ?></p>
  <?php else: ?>
    <label><?= e(t('cf_issuer')) ?>
      <select name="issuer_id" id="issuer_id" required>
        <?php foreach ($cabCompanies as $c): ?>
        <option value="<?= (int)$c['id'] ?>"<?= $cabDocument && (int)($cabDocument['issuer_id'] ?? 0) === (int)$c['id'] ? ' selected' : '' ?>><?= e((string)($c['data']['company'] ?? '') ?: '#' . (int)$c['id']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  <?php endif; ?>

  <div class="body-grid" id="newDocument">
    <div class="body-grid-header">
      <div><?= e(t('item_id')) ?></div>
      <div><?= e(t('item_desc')) ?></div>
      <div><?= e(t('item_qty')) ?></div>
      <div><?= e(t('item_price')) ?></div>
      <div><?= e(t('item_discount')) ?> %</div>
      <div><?= e(t('item_tax')) ?></div>
    </div>
    <div class="body-grid-body">
      <?php
      $bodyFields = ['id', 'desc', 'qty', 'price', 'discount', 'tax'];
      $bodyRows = array_values(array_filter((array)($cabDocument['data']['body'] ?? []), function ($r) {
          return is_array($r) && array_filter($r, fn($v) => trim((string)$v) !== '');
      }));
      if ($bodyRows) {
          $bodyRows[] = [];
      } else {
          $bodyRows = [[]];
      }
      foreach ($bodyRows as $bRow): ?>
      <div class="body-grid-row">
        <?php foreach ($bodyFields as $bf): ?>
        <div class="body-grid-cell" data-label="<?= e(t('item_' . $bf)) ?>"><input type="text" name="body[<?= e($bf) ?>][]" value="<?= e((string)($bRow[$bf] ?? '')) ?>"></div>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <script>
  (function () {
    var grid = document.getElementById('newDocument');
    if (!grid) return;
    var body = grid.querySelector('.body-grid-body');
    if (!body) return;
    var template = body.querySelector('.body-grid-row');
    if (!template) return;
    template = template.cloneNode(true);
    Array.prototype.forEach.call(template.querySelectorAll('input'), function (i) { i.value = ''; });
    function hasValue(row) {
      return Array.prototype.some.call(row.querySelectorAll('input'), function (i) {
        return i.value.trim() !== '';
      });
    }
    function addRow() {
      var row = template.cloneNode(true);
      body.appendChild(row);
    }
    grid.addEventListener('input', function () {
      var rows = body.querySelectorAll('.body-grid-row');
      var last = rows[rows.length - 1];
      if (last && hasValue(last)) addRow();
    });
    grid.addEventListener('focusout', function (e) {
      if (e.relatedTarget && grid.contains(e.relatedTarget)) {
        return;
      }
      var rows = body.querySelectorAll('.body-grid-row');
      for (var i = rows.length - 1; i > 0; i--) {
        if (!hasValue(rows[i])) {
          body.removeChild(rows[i]);
        }
      }
    });
  })();
  </script>

  <hr>
  <h3><?= e(t('bill_to')) ?></h3>
  <?php foreach (DOCUMENT_FIELDS as $f): ?>
    <?php if ($f === 'document_number'): ?>
      <div class="form-row">
        <label><?= e(t('document_number')) ?><input name="cf_document_number" value="<?= e((string)($cabDocument['data']['document_number'] ?? '')) ?>"></label>
    <?php elseif ($f === 'document_date'): ?>
        <label><?= e(t('document_date')) ?><input type="date" name="cf_document_date" value="<?= e((string)($cabDocument['data']['document_date'] ?? '')) ?>"></label>
      </div>
    <?php elseif ($f === 'terms'): ?>
      <label><?= e(t('cf_terms')) ?><textarea name="cf_terms" rows="3"><?= e(implode("\n", (array)($cabDocument['data']['terms'] ?? []))) ?></textarea></label>
    <?php else: ?>
      <label><?= e(t('cf_' . $f)) ?><input name="cf_<?= e($f) ?>" value="<?= e((string)($cabDocument['data'][$f] ?? '')) ?>"></label>
    <?php endif; ?>
  <?php endforeach; ?>
  <button class="btn primary" type="submit"><?= e(t('save')) ?></button>
</form>
