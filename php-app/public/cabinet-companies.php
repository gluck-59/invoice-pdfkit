<?php
// Cabinet / companies fragment: company list, logo upload, company editor and
// account forms. Guarded against direct requests.
if (!defined('CABINET_PART')) { http_response_code(404); exit; }
?>

<?php if ($cabCompanies): ?>
<h3 class="cab-h"><?= e(t('my_companies')) ?></h3>
<div id="companies" class="cab-list">
  <?php foreach ($cabCompanies as $c): ?>
  <div class="cab-item<?= $cabCompany && (int)$cabCompany['id'] === (int)$c['id'] ? ' sel' : '' ?>" data-id="<?= (int)$c['id'] ?>">
    <div class="cab-main">
      <div class="thumb">
        <?php if (!empty($c['img'])): ?>
          <img src="/?mode=logo&id=<?= (int)$c['id'] ?>" alt="logo">
        <?php else: ?>
          <div class="noimg">NO IMAGE</div>
        <?php endif; ?>
      </div>
      <a href="/?mode=cabinet&company=<?= (int)$c['id'] ?>"><?= e((string)($c['data']['company'] ?? '') ?: ('#' . $c['id'])) ?></a>
    </div>
    <form method="post" action="/?mode=cabinet" onsubmit="return confirm('<?= e(t('confirm_delete')) ?>')">
      <input type="hidden" name="action" value="delete_company">
      <input type="hidden" name="company_id" value="<?= (int)$c['id'] ?>">
      <button class="btn danger small" type="submit"><?= e(t('delete')) ?></button>
    </form>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<h3 class="cab-h"><?= e($cabCompany ? t('edit') . ' ' . $cabCompany['data']['company'] : t('new_company')) ?>
  <small class="muted"><?= t('non_required')?></small>
</h3>

<hr>

<form id="company" method="post" action="/?mode=cabinet<?= $cabCompany ? '&company=' . (int)$cabCompany['id'] : '' ?>" class="cab-form" enctype="multipart/form-data">
  <input type="hidden" name="action" value="save_company">
  <input type="hidden" name="company_id" value="<?= $cabCompany ? (int)$cabCompany['id'] : '' ?>">
  <div class="logo-row">
    <div class="drop" id="drop">
      <span><?= e(t('logo_drop')) ?></span>
      <small><?= e(t('logo_hint')) ?></small>
      <input type="file" name="logo" id="logo-file" accept=".jpg,.jpeg,.png,.gif,.svg" hidden data-upload-error="<?= e(t('err_upload')) ?>">
    </div>
    <div class="logo-preview">
      <?php if ($cabCompany && !empty($cabCompany['img'])): ?>
        <img src="/?mode=logo&id=<?= (int)$cabCompany['id'] ?>" alt="logo">
      <?php else: ?>
        <div class="noimg">NO IMAGE</div>
      <?php endif; ?>
    </div>
  </div>
  <?php foreach (COMPANY_FIELDS as $f): ?>
    <?php if ($f === 'terms'): ?>
      <label><?= e(t('cf_terms')) ?><textarea name="cf_terms" rows="3"><?= e(implode("\n", (array)($cabCompany['data']['terms'] ?? []))) ?></textarea></label>
    <?php else: ?>
      <label><?= e(t('cf_' . $f)) ?><input name="cf_<?= e($f) ?>" value="<?= e((string)($cabCompany['data'][$f] ?? '')) ?>"></label>
    <?php endif; ?>
  <?php endforeach; ?>
  <button class="btn primary" type="submit"><?= e(t('save')) ?></button>
</form>
<script src="/js/app.js?v=<?= (int)filemtime(__DIR__ . '/js/app.js') ?>" defer></script>
<hr>
