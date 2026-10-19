<?php
// Cabinet / invoices fragment: placeholder section (empty for now).
// Guarded against direct requests.
if (!defined('CABINET_PART')) { http_response_code(404); exit; }
?>
    <h3 class="cab-h"><?= e(t('my_invoices')) ?></h3>
