<?php
// Cabinet menu fragment: the two entry cards (companies / documents).
// Guarded against direct requests (nginx executes any *.php directly).
if (!defined('CABINET_PART')) { http_response_code(404); exit; }
?>
<div class="cab-cards">
  <a class="tile" href="/?mode=cabinet&view=companies"><?= e(t('my_companies')) ?></a>
  <a class="tile" href="/?mode=cabinet&view=invoices"><?= e(t('my_documents')) ?></a>
  <a class="tile" href="/?mode=cabinet&view=settings"><?= e(t('settings')) ?></a>
</div>
