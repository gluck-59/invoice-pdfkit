<?php
// Cabinet / settings fragment: account e-mail and password forms.
// Guarded against direct requests.
if (!defined('CABINET_PART')) { http_response_code(404); exit; }
?>
    <h3 class="cab-h"><?= e(t('your_data')) ?></h3>
    <form method="post" action="/?mode=cabinet" class="cab-form">
      <input type="hidden" name="action" value="update_email">
      <label><?= e(t('email')) ?><input type="email" name="email" required value="<?= e($userEmail) ?>"></label>
      <button class="btn primary" type="submit"><?= e(t('save')) ?></button>
    </form>

    <h3 class="cab-h"><?= e(t('cab_password')) ?></h3>
    <form method="post" action="/?mode=cabinet" class="cab-form">
      <input type="hidden" name="action" value="update_password">
      <label><?= e(t('cur_password')) ?><input type="password" name="current" required autocomplete="current-password"></label>
      <label><?= e(t('new_password')) ?><input type="password" name="new" required autocomplete="new-password"></label>
      <label><?= e(t('confirm_password')) ?><input type="password" name="confirm" required autocomplete="new-password"></label>
      <button class="btn primary" type="submit"><?= e(t('save')) ?></button>
    </form>
