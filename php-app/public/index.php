<?php
declare(strict_types=1);
// Landing + регистрация/вход в одном файле (MVP, без CSRF и e-mail).
// Таблица users создаётся сама при первом обращении.
// Язык: ?lang= → cookie pdf-lang → Accept-Language → en.
// Переводы: те же locales/*/translation.json, что и у генератора, через t().
session_start();

const LOCALES_DIR = '/var/www/locales';
const LANG_COOKIE = 'pdf-lang';

function envv(string $name, string $default = ''): string
{
    $v = getenv($name);
    return $v === false ? $default : $v;
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
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
if ($mode !== 'register' && $mode !== 'login') {
    $mode = '';
}

try {
    $mysqli = new mysqli(
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
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = t('err_invalid_email');
        }
        if (strlen($password) < 8) {
            $errors[] = t('err_password_short');
        }
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
                header('Location: /');
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
                    header('Location: /');
                    exit;
                }
                $st->close();
                $errors[] = t('err_invalid_credentials');
            }
        }
    }
    $mysqli->close();
} catch (Throwable $ex) {
    $dbError = $ex->getMessage();
}

$userEmail = $_SESSION['user_email'] ?? null;
// Вошедшему пользователю формы не нужны — только лендинг.
if ($userEmail && $mode !== '' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $mode = '';
}
$title = $mode === 'register' ? t('register') : ($mode === 'login' ? t('login') : t('app_title'));
$langCount = (string)count(availableLangs());
?>
<!doctype html>
<html lang="<?= e($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<style>
  * { box-sizing: border-box; }
  :root {
    --bg: #0b1220;
    --bg2: #0f1b2d;
    --card: #111d33;
    --line: rgba(148,163,184,.16);
    --txt: #e8eef7;
    --mut: #93a3bb;
    --acc: #2dd4bf;
    --acc-d: #0d9488;
  }
  body {
    margin: 0;
    font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
    background: var(--bg);
    color: var(--txt);
  }
  a { color: inherit; }
  .wrap { max-width: 1120px; margin: 0 auto; padding: 0 24px; }
  /* nav */
  .nav {
    position: sticky; top: 0; z-index: 10;
    background: rgba(11,18,32,.85);
    backdrop-filter: blur(10px);
    border-bottom: 1px solid var(--line);
  }
  .nav-in { display: flex; align-items: center; gap: 28px; height: 64px; }
  .brand { font-weight: 800; font-size: 19px; text-decoration: none; }
  .brand span { color: var(--acc); }
  .nav-links { display: flex; gap: 22px; margin-left: 12px; }
  .nav-links a { color: var(--mut); text-decoration: none; font-size: 15px; }
  .nav-links a:hover { color: var(--txt); }
  .nav-right { margin-left: auto; display: flex; align-items: center; gap: 12px; }
  .nav-mail { color: var(--mut); font-size: 14px; max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .btn {
    display: inline-block;
    padding: 11px 22px;
    border-radius: 10px;
    font-size: 15px;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    border: 0;
  }
  .btn.primary { background: var(--acc); color: #04211d; }
  .btn.primary:hover { background: #5eead4; }
  .btn.ghost { background: transparent; color: var(--txt); border: 1px solid var(--line); }
  .btn.ghost:hover { border-color: var(--acc); }
  .btn.small { padding: 8px 16px; font-size: 14px; }
  .langsel {
    background: transparent;
    color: var(--txt);
    border: 1px solid var(--line);
    border-radius: 10px;
    padding: 8px 10px;
    font-size: 14px;
    cursor: pointer;
  }
  .langsel option { color: #0f172a; }
  /* hero */
  .hero {
    position: relative;
    overflow: hidden;
    padding: 88px 0 64px;
    background:
      radial-gradient(700px 340px at 15% 0%, rgba(45,212,191,.16), transparent 60%),
      radial-gradient(600px 300px at 90% 20%, rgba(59,130,246,.14), transparent 60%);
  }
  .hero-grid { display: grid; grid-template-columns: 1.05fr .95fr; gap: 48px; align-items: center; }
  .badge {
    display: inline-block;
    font-size: 13px;
    color: var(--acc);
    border: 1px solid rgba(45,212,191,.4);
    border-radius: 999px;
    padding: 6px 14px;
    margin-bottom: 20px;
  }
  h1 {
    margin: 0 0 18px;
    font-size: 52px;
    line-height: 1.05;
    letter-spacing: -1px;
  }
  .sub { color: var(--mut); font-size: 18px; line-height: 1.6; margin: 0 0 30px; }
  .cta-row { display: flex; gap: 12px; flex-wrap: wrap; }
  .stats { display: flex; gap: 36px; margin-top: 40px; }
  .stat b { display: block; font-size: 26px; }
  .stat span { color: var(--mut); font-size: 14px; }
  /* product mock */
  .mock {
    background: #fff;
    color: #0f172a;
    border-radius: 14px;
    padding: 26px 28px;
    box-shadow: 0 30px 80px rgba(0,0,0,.5);
    transform: rotate(1.5deg);
  }
  .mock h3 { margin: 0 0 4px; font-size: 26px; font-weight: 400; }
  .mock .row { display: flex; justify-content: space-between; font-size: 13px; padding: 5px 0; border-bottom: 1px solid #eef2f7; }
  .mock .row span:first-child { color: #64748b; }
  .mock .total { display: flex; justify-content: space-between; font-weight: 800; font-size: 15px; padding-top: 10px; }
  /* sections */
  section.block { padding: 72px 0; }
  h2 { font-size: 34px; letter-spacing: -.5px; margin: 0 0 10px; text-align: center; }
  p.center-sub { color: var(--mut); text-align: center; margin: 0 0 40px; font-size: 17px; }
  .bento { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
  .tile {
    background: var(--card);
    border: 1px solid var(--line);
    border-radius: 14px;
    padding: 28px;
  }
  .tile.wide { grid-column: 1 / -1; }
  .tile h3 { margin: 0 0 10px; font-size: 19px; }
  .tile p { margin: 0; color: var(--mut); font-size: 15px; line-height: 1.6; }
  .tile .ico { font-size: 26px; }
  .steps { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
  .step {
    background: var(--card);
    border: 1px solid var(--line);
    border-radius: 14px;
    padding: 28px;
  }
  .step .n {
    display: inline-flex; align-items: center; justify-content: center;
    width: 36px; height: 36px; border-radius: 50%;
    background: var(--acc); color: #04211d; font-weight: 800; margin-bottom: 14px;
  }
  .step h3 { margin: 0 0 8px; font-size: 18px; }
  .step p { margin: 0; color: var(--mut); font-size: 15px; line-height: 1.6; }
  /* auth */
  .auth-card {
    max-width: 440px; margin: 0 auto;
    background: var(--card);
    border: 1px solid var(--line);
    border-radius: 16px;
    padding: 36px 38px;
    text-align: center;
  }
  .auth-card h2 { margin-bottom: 6px; }
  .tabs { display: flex; gap: 8px; margin: 20px 0; }
  .tabs a {
    flex: 1; padding: 10px; border-radius: 10px; text-decoration: none;
    background: var(--bg2); color: var(--mut); font-weight: 600; font-size: 15px;
  }
  .tabs a.on { background: var(--acc); color: #04211d; }
  input {
    display: block; width: 100%;
    padding: 12px; margin: 8px 0;
    border: 1px solid var(--line); border-radius: 10px;
    font-size: 15px; background: var(--bg2); color: var(--txt);
  }
  input::placeholder { color: var(--mut); }
  .auth-card .btn { width: 100%; margin-top: 8px; }
  .err {
    background: rgba(248,113,113,.12);
    color: #fca5a5;
    border: 1px solid rgba(248,113,113,.35);
    border-radius: 10px;
    padding: 10px 12px;
    margin: 0 0 12px;
    font-size: 14px;
    text-align: left;
  }
  .back { display: inline-block; margin-top: 16px; color: var(--mut); font-size: 14px; }
  /* cta + footer */
  .cta-band {
    background: linear-gradient(135deg, #0d9488, #155e75);
    border-radius: 18px;
    padding: 56px 40px;
    text-align: center;
  }
  .cta-band h2 { color: #fff; }
  .cta-band p { color: rgba(255,255,255,.8); margin: 0 0 26px; font-size: 17px; }
  .cta-band .btn.primary { background: #fff; color: #0b3b36; }
  footer { border-top: 1px solid var(--line); padding: 26px 0 40px; color: var(--mut); font-size: 14px; }
  footer .wrap { display: flex; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
  footer a { color: var(--mut); }
  @media (max-width: 860px) {
    .hero-grid { grid-template-columns: 1fr; }
    h1 { font-size: 38px; }
    .bento, .steps { grid-template-columns: 1fr; }
    .nav-links { display: none; }
    .mock { transform: none; }
  }
</style>
</head>
<body>

<?php if ($dbError): ?>
<div class="wrap" style="padding:60px 24px;text-align:center">
  <h1><?= e(t('err_db')) ?></h1>
  <p class="sub"><?= e($dbError) ?></p>
</div>
<?php else: ?>

<header class="nav">
  <div class="wrap nav-in">
    <a class="brand" href="/"><span>▦</span> Invoice</a>
    <nav class="nav-links">
      <a href="#features"><?= e(t('nav_features')) ?></a>
      <a href="#how"><?= e(t('nav_how')) ?></a>
      <a href="/preview/"><?= e(t('nav_templates')) ?></a>
    </nav>
    <div class="nav-right">
      <select class="langsel" onchange="location.href='?lang='+this.value+'<?= $mode ? '&mode=' . e($mode) : '' ?>'">
        <?php foreach (availableLangs() as $l): ?>
          <option value="<?= e($l) ?>"<?= $l === $lang ? ' selected' : '' ?>><?= e($l) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($userEmail): ?>
        <span class="nav-mail"><?= e($userEmail) ?></span>
        <form method="post" action="/" style="margin:0">
          <input type="hidden" name="action" value="logout">
          <button class="btn ghost small" type="submit"><?= e(t('logout')) ?></button>
        </form>
      <?php else: ?>
        <a class="btn ghost small" href="#start"><?= e(t('nav_login')) ?></a>
        <a class="btn primary small" href="#start"><?= e(t('hero_primary')) ?></a>
      <?php endif; ?>
    </div>
  </div>
</header>

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
          <a class="btn ghost" href="#start-login"><?= e(t('hero_secondary')) ?></a>
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

<section class="block" id="start" style="padding-top:0">
  <div class="wrap">
    <div class="auth-card" id="start-login">
      <?php if ($userEmail): ?>
        <h2><?= e(t('welcome')) ?></h2>
        <p class="center-sub"><?= e($userEmail) ?></p>
        <?php foreach ($errors as $err): ?>
          <div class="err"><?= e($err) ?></div>
        <?php endforeach; ?>
        <form method="post" action="/">
          <input type="hidden" name="action" value="logout">
          <button class="btn ghost" type="submit"><?= e(t('logout')) ?></button>
        </form>
      <?php else: ?>
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
      <?php endif; ?>
    </div>
  </div>
</section>

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

<footer>
  <div class="wrap">
    <span>▦ Invoice — <?= e(t('foot_note')) ?></span>
    <a href="/preview/"><?= e(t('pdf_templates')) ?></a>
  </div>
</footer>

<?php endif; ?>
</body>
</html>
