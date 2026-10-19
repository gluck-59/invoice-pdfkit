<?php
// Cabinet / companies fragment: company list, logo upload, company editor and
// account forms. Moved verbatim from index.php. Guarded against direct requests.
if (!defined('CABINET_PART')) { http_response_code(404); exit; }
?>
    <?php if ($cabCompanies): ?>
    <h3 class="cab-h"><?= e(t('my_companies')) ?></h3>
    <table id="companies" class="cab-table">
      <?php foreach ($cabCompanies as $c): ?>
      <tr<?= $cabCompany && (int)$cabCompany['id'] === (int)$c['id'] ? ' class="sel"' : '' ?>>
        <td><a href="/?mode=cabinet&company=<?= (int)$c['id'] ?>"><?= e((string)($c['data']['company'] ?? ('#' . $c['id']))) ?></a></td>
        <td class="thumb">
          <?php if (!empty($c['img'])): ?>
            <img src="/?mode=logo&id=<?= (int)$c['id'] ?>" alt="logo">
          <?php else: ?>
            <div class="noimg">NO IMAGE</div>
          <?php endif; ?>
        </td>
        <td class="act">
          <form method="post" action="/?mode=cabinet" onsubmit="return confirm('<?= e(t('confirm_delete')) ?>')">
            <input type="hidden" name="action" value="delete_company">
            <input type="hidden" name="company_id" value="<?= (int)$c['id'] ?>">
            <button class="btn ghost small" type="submit"><?= e(t('delete')) ?></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif;
    ?>

    <h3 class="cab-h"><?= e($cabCompany ? t('edit') . ' ' . $cabCompany['data']['company'] : t('new_company')) ?>
      <small class="muted"><?= t('non_required')?></small>
    </h3>

    <hr>

      <?php if ($cabCompany): ?>
          <form id="logo" class="cab-form" method="post" action="/?mode=cabinet&company=<?= (int)$cabCompany['id'] ?>" enctype="multipart/form-data">
              <input type="hidden" name="action" value="upload_logo">
              <input type="hidden" name="company_id" value="<?= (int)$cabCompany['id'] ?>">
              <div class="drop" id="drop">
                  <span><?= e(t('logo_drop')) ?></span>
                  <small><?= e(t('logo_hint')) ?></small>
                  <input type="file" name="logo" id="logo-file" accept=".jpg,.jpeg,.png,.gif,.svg" hidden>
              </div>
              <button class="btn primary" type="submit" style="margin-top:12px"><?= e(t('save')) ?></button>
          </form>
          <script src="/js/app.js" defer></script>
          <hr>
      <?php endif; ?>


      <form method="post" action="/?mode=cabinet<?= $cabCompany ? '&company=' . (int)$cabCompany['id'] : '' ?>" class="cab-form">
      <input type="hidden" name="action" value="save_company">
      <input type="hidden" name="company_id" value="<?= $cabCompany ? (int)$cabCompany['id'] : '' ?>">
      <?php foreach (COMPANY_FIELDS as $f): ?>
        <?php if ($f === 'terms'): ?>
          <label><?= e(t('cf_terms')) ?><textarea name="cf_terms" rows="3"><?= e(implode("\n", (array)($cabCompany['data']['terms'] ?? []))) ?></textarea></label>
        <?php else: ?>
          <label><?= e(t('cf_' . $f)) ?><input name="cf_<?= e($f) ?>" value="<?= e((string)($cabCompany['data'][$f] ?? '')) ?>"></label>
        <?php endif; ?>
      <?php endforeach; ?>
      <button class="btn primary" type="submit"><?= e(t('save')) ?></button>
    </form>
