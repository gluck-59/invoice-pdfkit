<?php
declare(strict_types=1);
// Landing + регистрация/вход в одном файле (MVP, без CSRF и e-mail).
// Таблица users создаётся сама при первом обращении.
// Язык: ?lang= → cookie pdf-lang → Accept-Language → en.
// Переводы: те же locales/*/translation.json, что и у генератора, через t().
session_start();

const LOCALES_DIR = '/var/www/locales';
const LANG_COOKIE = 'pdf-lang';
const UPLOADS_DIR = '/var/www/uploads';
const LOGO_MAX_BYTES = 2097152; // 2 MB
const LOGO_EXTS = ['jpg', 'png', 'gif', 'svg'];
// Company field set mirrors DEMO.company in server.js.
const COMPANY_FIELDS = ['company', 'addr1', 'city', 'zip', 'state', 'country', 'tel', 'web', 'email', 'account', 'bankID', 'registration', 'taxID', 'termsURL', 'terms', 'tagline'];

function envv(string $name, string $default = ''): string
{
    $v = getenv($name);
    return $v === false ? $default : $v;
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function ownCompany(mysqli $db, int $cid, int $uid): bool
{
    $st = $db->prepare('SELECT id FROM companies WHERE id = ? AND user_id = ?');
    $st->bind_param('ii', $cid, $uid);
    $st->execute();
    $st->store_result();
    $ok = $st->num_rows > 0;
    $st->close();
    return $ok;
}

function availableLangs(): array
{
    static $langs = null;
    if ($langs !== null) {
        return $langs;
    }
    $langs = [];
    $dirs = @scandir(LOCALES_DIR);
    if (is_array($dirs)) {
        foreach ($dirs as $d) {
            if ($d === '.' || $d === '..') {
                continue;
            }
            if (is_dir(LOCALES_DIR . '/' . $d)
                && is_file(LOCALES_DIR . '/' . $d . '/translation.json')
            ) {
                $langs[] = $d;
            }
        }
    }
    sort($langs);
    if (!$langs) {
        $langs = ['en'];
    }
    return $langs;
}

function currentLang(): string
{
    static $lang = null;
    if ($lang !== null) {
        return $lang;
    }
    $available = availableLangs();
    $q = strtolower(trim((string)($_GET['lang'] ?? '')));
    if ($q !== '' && in_array($q, $available, true)) {
        $lang = $q;
    } else {
        $c = strtolower(trim((string)($_COOKIE[LANG_COOKIE] ?? '')));
        if ($c !== '' && in_array($c, $available, true)) {
            $lang = $c;
        } else {
            $lang = 'en';
            $header = (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
            foreach (explode(',', $header) as $part) {
                $tag = strtolower(trim((string)preg_replace('/;.*$/', '', $part)));
                $base = explode('-', str_replace('_', '-', $tag))[0];
                if ($base !== '' && in_array($base, $available, true)) {
                    $lang = $base;
                    break;
                }
            }
        }
    }
    setcookie(LANG_COOKIE, $lang, ['path' => '/', 'samesite' => 'Lax']);
    return $lang;
}

// Same contract as src/globals.js: t(key) -> locales/{lang}/translation.json,
// missing key falls back to en, then to $fallback.
function t(string $key, string $fallback = ''): string
{
    static $data = null;
    static $enData = null;
    $lang = currentLang();
    if ($data === null) {
        $raw = @file_get_contents(LOCALES_DIR . '/' . $lang . '/translation.json');
        $data = $raw === false ? [] : (json_decode($raw, true) ?: []);
        $rawEn = @file_get_contents(LOCALES_DIR . '/en/translation.json');
        $enData = $rawEn === false ? [] : (json_decode($rawEn, true) ?: []);
    }
    if (isset($data[$key])) {
        return (string)$data[$key];
    }
    if (isset($enData[$key])) {
        return (string)$enData[$key];
    }
    return $fallback;
}

$lang = currentLang();
$dbError = '';
$errors = [];
$mode = $_GET['mode'] ?? '';
$reqPath = rtrim((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
if ($reqPath === '/preview') {
    $mode = 'preview';
}
if ($mode !== 'register' && $mode !== 'login' && $mode !== 'cabinet'
    && $mode !== 'logo' && $mode !== 'preview'
) {
    $mode = '';
}

try {
    $mysqli = @new mysqli(
        envv('MYSQL_HOST', 'mysql'),
        envv('MYSQL_USER', 'invoices'),
        envv('MYSQL_PASSWORD', 'invoices'),
        envv('MYSQL_DATABASE', 'invoices')
    );
    if ($mysqli->connect_errno) {
        throw new RuntimeException($mysqli->connect_error);
    }
    $mysqli->set_charset('utf8mb4');
    $mysqli->query(
        "CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $mysqli->query(
        "CREATE TABLE IF NOT EXISTS companies (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            data JSON NOT NULL,
            img VARCHAR(255) NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_companies_user FOREIGN KEY (user_id)
                REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $action = $_POST['action'] ?? '';
        if ($action === 'logout') {
            $_SESSION = [];
            session_destroy();
            header('Location: /');
            exit;
        }
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $needsCredentials = ($action === 'register' || $action === 'login');
        if ($needsCredentials && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = t('err_invalid_email');
        }
//        if (strlen($password) < 8) {
//            $errors[] = t('err_password_short');
//        }
        if ($action === 'register') {
            $mode = 'register';
            $confirm = (string)($_POST['confirm'] ?? '');
            if ($password !== $confirm) {
                $errors[] = t('err_password_mismatch');
            }
            if (!$errors) {
                $st = $mysqli->prepare('SELECT id FROM users WHERE email = ?');
                $st->bind_param('s', $email);
                $st->execute();
                $st->store_result();
                if ($st->num_rows > 0) {
                    $errors[] = t('err_email_exists');
                }
                $st->close();
            }
            if (!$errors) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $st = $mysqli->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)');
                $st->bind_param('ss', $email, $hash);
                $st->execute();
                $id = $st->insert_id;
                $st->close();
                $_SESSION['user_id'] = $id;
                $_SESSION['user_email'] = $email;
                header('Location: /?mode=cabinet');
                exit;
            }
        } elseif ($action === 'login') {
            $mode = 'login';
            if (!$errors) {
                $st = $mysqli->prepare('SELECT id, password_hash FROM users WHERE email = ?');
                $st->bind_param('s', $email);
                $st->execute();
                $st->bind_result($id, $hash);
                if ($st->fetch() && password_verify($password, $hash)) {
                    $_SESSION['user_id'] = $id;
                    $_SESSION['user_email'] = $email;
                    $st->close();
                    header('Location: /?mode=cabinet');
                    exit;
                }
                $st->close();
                $errors[] = t('err_invalid_credentials');
            }
        } elseif ($action === 'update_email') {
            $mode = 'cabinet';
            $uid = $_SESSION['user_id'] ?? null;
            if (!$uid) {
                header('Location: /?mode=login');
                exit;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = t('err_invalid_email');
            }
            if (!$errors) {
                $st = $mysqli->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
                $st->bind_param('si', $email, $uid);
                $st->execute();
                $st->store_result();
                if ($st->num_rows > 0) {
                    $errors[] = t('err_email_exists');
                }
                $st->close();
            }
            if (!$errors) {
                $st = $mysqli->prepare('UPDATE users SET email = ? WHERE id = ?');
                $st->bind_param('si', $email, $uid);
                $st->execute();
                $st->close();
                $_SESSION['user_email'] = $email;
                header('Location: /?mode=cabinet&saved=email');
                exit;
            }
        } elseif ($action === 'update_password') {
            $mode = 'cabinet';
            $uid = $_SESSION['user_id'] ?? null;
            if (!$uid) {
                header('Location: /?mode=login');
                exit;
            }
            $cur = (string)($_POST['current'] ?? '');
            $new = (string)($_POST['new'] ?? '');
            $confirm = (string)($_POST['confirm'] ?? '');
            $st = $mysqli->prepare('SELECT password_hash FROM users WHERE id = ?');
            $st->bind_param('i', $uid);
            $st->execute();
            $st->bind_result($hash);
            $ok = $st->fetch() && password_verify($cur, $hash);
            $st->close();
            if (!$ok) {
                $errors[] = t('err_invalid_credentials');
            } elseif ($new !== $confirm) {
                $errors[] = t('err_password_mismatch');
            } else {
                $nh = password_hash($new, PASSWORD_DEFAULT);
                $st = $mysqli->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                $st->bind_param('si', $nh, $uid);
                $st->execute();
                $st->close();
                header('Location: /?mode=cabinet&saved=password');
                exit;
            }
        } elseif ($action === 'save_company') {
            $mode = 'cabinet';
            $uid = $_SESSION['user_id'] ?? null;
            if (!$uid) {
                header('Location: /?mode=login');
                exit;
            }
            $cid = (int)($_POST['company_id'] ?? 0);
            if ($cid > 0 && !ownCompany($mysqli, $cid, $uid)) {
                $errors[] = t('err_no_company');
            } else {
                $data = [];
                foreach (COMPANY_FIELDS as $f) {
                    if ($f === 'terms') {
                        $lines = preg_split('/\r\n|\r|\n/', (string)($_POST['cf_terms'] ?? ''));
                        $data[$f] = array_values(array_filter(
                            array_map('trim', $lines ?: []),
                            fn($s) => $s !== ''
                        ));
                    } else {
                        $data[$f] = trim((string)($_POST['cf_' . $f] ?? ''));
                    }
                }
                $json = json_encode($data, JSON_UNESCAPED_UNICODE);
                if ($cid > 0) {
                    $st = $mysqli->prepare('UPDATE companies SET data = ? WHERE id = ? AND user_id = ?');
                    $st->bind_param('sii', $json, $cid, $uid);
                } else {
                    $st = $mysqli->prepare('INSERT INTO companies (user_id, data) VALUES (?, ?)');
                    $st->bind_param('is', $uid, $json);
                }
                $st->execute();
                if ($cid === 0) {
                    $cid = $st->insert_id;
                }
                $st->close();
                header('Location: /?mode=cabinet&company=' . $cid . '&saved=company');
                exit;
            }
        } elseif ($action === 'upload_logo') {
            $mode = 'cabinet';
            $uid = $_SESSION['user_id'] ?? null;
            if (!$uid) {
                header('Location: /?mode=login');
                exit;
            }
            $cid = (int)($_POST['company_id'] ?? 0);
            if ($cid <= 0 || !ownCompany($mysqli, $cid, $uid)) {
                $errors[] = t('err_no_company');
            } elseif (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
                $errors[] = t('err_upload');
            } else {
                $f = $_FILES['logo'];
                if ($f['size'] > LOGO_MAX_BYTES) {
                    $errors[] = t('err_file_size');
                } else {
                    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
                    if ($ext === 'jpeg') {
                        $ext = 'jpg';
                    }
                    $valid = false;
                    if (in_array($ext, LOGO_EXTS, true)) {
                        if ($ext === 'svg') {
                            $head = @file_get_contents($f['tmp_name'], false, null, 0, 65536);
                            $valid = $head !== false
                                && stripos($head, '<svg') !== false
                                && stripos($head, '<script') === false;
                        } else {
                            $valid = @getimagesize($f['tmp_name']) !== false;
                        }
                    }
                    if (!$valid) {
                        $errors[] = t('err_file_type');
                    } else {
                        $name = bin2hex(random_bytes(16)) . '.' . $ext;
                        @mkdir(UPLOADS_DIR, 0777, true);
                        if (!move_uploaded_file($f['tmp_name'], UPLOADS_DIR . '/' . $name)) {
                            $errors[] = t('err_upload');
                        } else {
                            $st = $mysqli->prepare('SELECT img FROM companies WHERE id = ? AND user_id = ?');
                            $st->bind_param('ii', $cid, $uid);
                            $st->execute();
                            $st->bind_result($old);
                            if ($st->fetch() && $old) {
                                @unlink(UPLOADS_DIR . '/' . basename((string)$old));
                            }
                            $st->close();
                            $st = $mysqli->prepare('UPDATE companies SET img = ? WHERE id = ? AND user_id = ?');
                            $st->bind_param('sii', $name, $cid, $uid);
                            $st->execute();
                            $st->close();
                            header('Location: /?mode=cabinet&company=' . $cid . '&saved=logo');
                            exit;
                        }
                    }
                }
            }
        }
    }
    // Logo bytes: owner-checked, served by PHP (nginx has no uploads mount).
    if ($mode === 'logo' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        $uid = $_SESSION['user_id'] ?? null;
        $lid = (int)($_GET['id'] ?? 0);
        $path = null;
        if ($uid && $lid > 0) {
            $st = $mysqli->prepare('SELECT img FROM companies WHERE id = ? AND user_id = ?');
            $st->bind_param('ii', $lid, $uid);
            $st->execute();
            $st->bind_result($img);
            if ($st->fetch() && $img) {
                $p = UPLOADS_DIR . '/' . basename((string)$img);
                if (is_file($p)) {
                    $path = $p;
                }
            }
            $st->close();
        }
        if ($path === null) {
            http_response_code(404);
            exit;
        }
        $mime = [
            'jpg' => 'image/jpeg', 'png' => 'image/png',
            'gif' => 'image/gif', 'svg' => 'image/svg+xml',
        ][strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    // PDF preview: the generator (node) has no DB access, so PHP assembles the
    // current user's company data and forwards it to the render API. header/
    // footer come from public/header.html + public/footer.html; body is pdfkit.
    // No data entered yet -> empty fields are rendered.
    if ($mode === 'preview') {
        $uid = $_SESSION['user_id'] ?? null;
        if (!$uid) {
            header('Location: /?mode=login');
            exit;
        }
        $company = [];
        foreach (COMPANY_FIELDS as $f) {
            $company[$f] = '';
        }
        $company['terms'] = [];
        $logoB64 = null;
        $cid = (int)($_GET['company'] ?? 0);
        if ($cid > 0) {
            $st = $mysqli->prepare('SELECT data, img FROM companies WHERE id = ? AND user_id = ?');
            $st->bind_param('ii', $cid, $uid);
        } else {
            $st = $mysqli->prepare('SELECT data, img FROM companies WHERE user_id = ? ORDER BY id LIMIT 1');
            $st->bind_param('i', $uid);
        }
        $st->execute();
        $st->bind_result($cData, $cImg);
        if ($st->fetch()) {
            $row = json_decode((string)$cData, true) ?: [];
            foreach (COMPANY_FIELDS as $f) {
                if (array_key_exists($f, $row)) {
                    $company[$f] = $row[$f];
                }
            }
            if ($cImg) {
                $p = UPLOADS_DIR . '/' . basename((string)$cImg);
                if (is_file($p)) {
                    $logoB64 = base64_encode((string)file_get_contents($p));
                }
            }
        }
        $st->close();

        $settings = [
            'company' => $company,
            'language' => currentLang(),
            'locale' => 'en-US',
            'currency' => 'USD',
            'page' => ['size' => 'A4'],
        ];
        if ($logoB64 !== null) {
            $settings['logoBase64'] = $logoB64;
        }
        $payload = json_encode([
            'settings' => $settings,
            'order' => [
                'id' => '', 'ref' => '', 'date' => [],
                'bill' => [], 'ship' => [], 'total' => [], 'items' => [],
            ],
        ], JSON_UNESCAPED_UNICODE);

        $headers = "Content-Type: application/json\r\n";
        $token = envv('RENDER_TOKEN');
        if ($token !== '') {
            $headers .= 'X-Render-Token: ' . $token . "\r\n";
        }
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => $headers,
            'content' => $payload,
            'timeout' => 30,
            'ignore_errors' => true,
        ]]);
        $pdf = @file_get_contents('http://node:3000/api/render/invoice', false, $ctx);
        $status = 0;
        if (isset($http_response_header[0])
            && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)
        ) {
            $status = (int)$m[1];
        }
        if ($pdf === false || $status !== 200) {
            http_response_code(502);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'PDF render failed';
            exit;
        }
        header('Content-Type: application/pdf');
        header('Content-Length: ' . strlen($pdf));
        header('Content-Disposition: inline; filename="invoice.pdf"');
        echo $pdf;
        exit;
    }

    // Cabinet data for the page below.
    $cabCompanies = [];
    $cabCompany = null;
    $cabUid = $_SESSION['user_id'] ?? null;
    if ($cabUid) {
        $st = $mysqli->prepare('SELECT id, data, img FROM companies WHERE user_id = ? ORDER BY id');
        $st->bind_param('i', $cabUid);
        $st->execute();
        $res = $st->get_result();
        while ($row = $res->fetch_assoc()) {
            $row['data'] = json_decode((string)$row['data'], true) ?: [];
            $cabCompanies[] = $row;
        }
        $st->close();
        $sel = (int)($_GET['company'] ?? $_POST['company_id'] ?? 0);
        foreach ($cabCompanies as $c) {
            if ((int)$c['id'] === $sel) {
                $cabCompany = $c;
                break;
            }
        }
    }
    $mysqli->close();
} catch (Throwable $ex) {
    $dbError = $ex->getMessage();
}

$userEmail = $_SESSION['user_email'] ?? null;
if (!$userEmail && $mode === 'cabinet') {
    $mode = 'login';
} elseif ($userEmail && ($mode === 'register' || $mode === 'login')
    && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
) {
    $mode = '';
}
$isCabinet = ($mode === 'cabinet' && (bool)$userEmail);
$saved = $_GET['saved'] ?? '';
$title = $mode === 'register' ? t('register') : ($mode === 'login' ? t('login') : ($mode === 'cabinet' ? t('cabinet') : t('app_title')));
$langCount = (string)count(availableLangs());
$favicon = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect x='2' y='2' width='60' height='60' rx='12' fill='%23C9A13B'/%3E%3Ctext x='32' y='44' font-family='Georgia, serif' font-weight='bold' font-size='34' fill='%239E252B' text-anchor='middle'%3EIn%3C/text%3E%3C/svg%3E";
?>
<!doctype html>
<html lang="<?= e($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="<?= $favicon ?>">
<title><?= e($title) ?></title>
<link rel="stylesheet" href="/css/app.css">
</head>
<body>

<?php if ($dbError): ?>
<div class="wrap" style="padding:60px 24px;text-align:center">
  <h1><?= e(t('err_db')) ?></h1>
  <p class="sub"><?= e(t('err_db_hint')) ?></p>
</div>
<?php else: ?>

<header class="nav">
  <div class="wrap nav-in">
    <a class="brand" href="/"><span><img class="foot-ico" src="<?= $favicon ?>" alt=""></span> Inquistador</a>
    <nav class="nav-links">
      <?php if ($isCabinet): ?>
        <a href="/preview/" target="_blank"><?= e(t('preview')) ?></a>
        <a href="/?mode=cabinet#cabinet"><?= e(t('my_companies')) ?></a>
      <?php else: ?>
        <a href="#features"><?= e(t('nav_features')) ?></a>
        <a href="#how"><?= e(t('nav_how')) ?></a>
        <a href="/preview/"><?= e(t('nav_templates')) ?></a>
      <?php endif; ?>
    </nav>
    <div class="nav-right">
      <select class="langsel" onchange="location.href='?lang='+this.value+'<?= $mode ? '&mode=' . e($mode) : '' ?>'">
        <?php foreach (availableLangs() as $l): ?>
          <option value="<?= e($l) ?>"<?= $l === $lang ? ' selected' : '' ?>><?= e($l) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($userEmail): ?>
        <span class="nav-mail"><?= e($userEmail) ?></span>
        <?php if (!$isCabinet): ?>
          <a class="btn ghost small" href="/?mode=cabinet"><?= e(t('cabinet')) ?></a>
        <?php endif; ?>
        <form method="post" action="/" style="margin:0">
          <input type="hidden" name="action" value="logout">
          <button class="btn ghost small" type="submit"><?= e(t('logout')) ?></button>
        </form>
      <?php else: ?>
        <a class="btn ghost small" href="/?mode=login#start"><?= e(t('nav_login')) ?></a>
        <a class="btn primary small" href="#start"><?= e(t('hero_primary')) ?></a>
      <?php endif; ?>
    </div>
  </div>
</header>

<?php if ($isCabinet): ?>
<section class="block" id="cabinet">
  <div class="wrap">
    <h2><?= e(t('cabinet')) ?></h2>
<!--    <p class="center-sub">--><?php //= e($userEmail) ?><!--</p>-->
    <?php if ($saved === 'email'): ?>
      <div class="ok"><?= e(t('saved_email')) ?></div>
    <?php elseif ($saved === 'password'): ?>
      <div class="ok"><?= e(t('saved_password')) ?></div>
    <?php elseif ($saved === 'company'): ?>
      <div class="ok"><?= e(t('saved_company')) ?></div>
    <?php elseif ($saved === 'logo'): ?>
      <div class="ok"><?= e(t('saved_logo')) ?></div>
    <?php endif; ?>
    <?php foreach ($errors as $err): ?>
      <div class="err" style="max-width:720px;margin:0 auto 12px"><?= e($err) ?></div>
    <?php endforeach; ?>

    <?php if ($cabCompanies): ?>
    <h3 class="cab-h"><?= e(t('my_companies')) ?></h3>
    <table class="cab-table">
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
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif;
    ?>

    <h3 class="cab-h"><?= e($cabCompany ? t('edit') . ' ' . $cabCompany['data']['company'] : t('new_company')) ?>
      <small class="muted"><?= t('non_required')?></small>
    </h3>
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

    <?php if ($cabCompany): ?>
    <h3 class="cab-h"><?= e(t('cab_logo')) ?></h3>
    <form method="post" action="/?mode=cabinet&company=<?= (int)$cabCompany['id'] ?>" enctype="multipart/form-data">
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
    <?php endif; ?>

    <hr>

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
  </div>
</section>
<?php endif; ?>

<?php if (!$isCabinet): ?>
<div class="hero">
  <div class="wrap hero-grid">
    <div>
      <span class="badge"><?= e(t('hero_badge')) ?></span>
      <h1><?= e(t('hero_title')) ?></h1>
      <p class="sub"><?= e(t('hero_sub')) ?></p>
      <div class="cta-row">
        <?php if ($userEmail): ?>
          <a class="btn primary" href="/preview/"><?= e(t('nav_templates')) ?></a>
        <?php else: ?>
          <a class="btn primary" href="#start"><?= e(t('hero_primary')) ?></a>
          <a class="btn ghost" href="/?mode=login#start"><?= e(t('hero_secondary')) ?></a>
        <?php endif; ?>
      </div>
      <div class="stats">
        <div class="stat"><b><?= e(t('stat1v')) ?></b><span><?= e(t('stat1l')) ?></span></div>
        <div class="stat"><b><?= e($langCount) ?></b><span><?= e(t('stat2l')) ?></span></div>
        <div class="stat"><b><?= e(t('stat3v')) ?></b><span><?= e(t('stat3l')) ?></span></div>
      </div>
    </div>
    <div class="mock">
      <h3><?= e(t('invoice')) ?></h3>
      <div class="row"><span><?= e(t('bill_to')) ?></span><span>ACME Ltd.</span></div>
      <div class="row"><span><?= e(t('order_id')) ?></span><span>2026-001</span></div>
      <div class="row"><span><?= e(t('order_due')) ?></span><span>2026-11-15</span></div>
      <div class="row"><span><?= e(t('item_qty')) ?></span><span>3 × Widget — 120.00</span></div>
      <div class="total"><span><?= e(t('total')) ?></span><span>360.00</span></div>
    </div>
  </div>
</div>

<section class="block" id="features">
  <div class="wrap">
    <h2><?= e(t('feat_title')) ?></h2>
    <p class="center-sub"><?= e(t('feat_sub')) ?></p>
    <div class="bento">
      <div class="tile"><div class="ico">📄</div><h3><?= e(t('f1t')) ?></h3><p><?= e(t('f1d')) ?></p></div>
      <div class="tile"><div class="ico">🌍</div><h3><?= e(t('f2t')) ?></h3><p><?= e(t('f2d')) ?></p></div>
      <div class="tile"><div class="ico">👁</div><h3><?= e(t('f3t')) ?></h3><p><?= e(t('f3d')) ?></p></div>
      <div class="tile"><div class="ico">🐳</div><h3><?= e(t('f4t')) ?></h3><p><?= e(t('f4d')) ?></p></div>
    </div>
  </div>
</section>

<section class="block" id="how" style="padding-top:0">
  <div class="wrap">
    <h2><?= e(t('how_title')) ?></h2>
    <p class="center-sub">&nbsp;</p>
    <div class="steps">
      <div class="step"><span class="n">1</span><h3><?= e(t('s1t')) ?></h3><p><?= e(t('s1d')) ?></p></div>
      <div class="step"><span class="n">2</span><h3><?= e(t('s2t')) ?></h3><p><?= e(t('s2d')) ?></p></div>
      <div class="step"><span class="n">3</span><h3><?= e(t('s3t')) ?></h3><p><?= e(t('s3d')) ?></p></div>
    </div>
  </div>
</section>

<?php if (!$userEmail): ?>
<section class="block" id="start" style="padding-top:0">
  <div class="wrap">
    <div class="auth-card" id="start-login">
      <?php $isReg = $mode !== 'login'; ?>
        <h2><?= e($isReg ? t('register') : t('login')) ?></h2>
        <div class="tabs">
          <a href="/?mode=register#start" class="<?= $isReg ? 'on' : '' ?>"><?= e(t('register')) ?></a>
          <a href="/?mode=login#start" class="<?= !$isReg ? 'on' : '' ?>"><?= e(t('login')) ?></a>
        </div>
        <?php foreach ($errors as $err): ?>
          <div class="err"><?= e($err) ?></div>
        <?php endforeach; ?>
        <form method="post" action="/<?= $isReg ? '?mode=register' : '?mode=login' ?>#start">
          <input type="hidden" name="action" value="<?= $isReg ? 'register' : 'login' ?>">
          <input type="email" name="email" placeholder="<?= e(t('email')) ?>" required
            value="<?= e(trim((string)($_POST['email'] ?? ''))) ?>">
          <input type="password" name="password" placeholder="<?= e(t('password')) ?>" required>
          <?php if ($isReg): ?>
            <input type="password" name="confirm" placeholder="<?= e(t('confirm_password')) ?>" required>
          <?php endif; ?>
          <button class="btn primary" type="submit"><?= e($isReg ? t('submit_register') : t('submit_login')) ?></button>
        </form>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="block" style="padding-top:0">
  <div class="wrap">
    <div class="cta-band">
      <h2><?= e(t('cta_title')) ?></h2>
      <p><?= e(t('cta_sub')) ?></p>
      <?php if ($userEmail): ?>
        <a class="btn primary" href="/preview/"><?= e(t('nav_templates')) ?></a>
      <?php else: ?>
        <a class="btn primary" href="#start"><?= e(t('hero_primary')) ?></a>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<footer>
  <div class="wrap">
    <span>©2026 &nbsp;<img class="foot-ico" src="<?= $favicon ?>" alt="">&nbsp;Inquistador — <?= e(t('foot_note')) ?></span>
    <a href="/preview/"><?= e(t('pdf_templates')) ?></a>
  </div>
</footer>

<?php endif; ?>
</body>
</html>
