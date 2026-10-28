<?php
declare(strict_types=1);
session_start();

const I18N_DIR = '/var/www/i18n';
const LANG_COOKIE = 'pdf-lang';
const UPLOADS_DIR = '/var/www/uploads';
const LOGO_MAX_BYTES = 2097152; // 2 MB
const LOGO_EXTS = ['jpg', 'png', 'gif', 'svg'];
// Company field set mirrors DEMO.company in server.js.
const COMPANY_FIELDS = ['company', 'addr1', 'city', 'zip', 'state', 'country', 'tel', 'web', 'email', 'account', 'bankID', 'registration', 'taxID', 'termsURL', 'terms', 'tagline'];
// Document field set: same fields as a company for now; documents have no logo.
const DOCUMENT_FIELDS = ['document_number', 'document_date', ...COMPANY_FIELDS];

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

// Document ownership check: the document's issuer company must belong to $uid.
function ownDocument(mysqli $db, int $did, int $uid): bool
{
    $st = $db->prepare('SELECT d.id FROM documents d JOIN companies c ON c.id = d.issuer_id WHERE d.id = ? AND c.user_id = ?');
    $st->bind_param('ii', $did, $uid);
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
    $dirs = @scandir(I18N_DIR);
    if (is_array($dirs)) {
        foreach ($dirs as $d) {
            if ($d === '.' || $d === '..') {
                continue;
            }
            if (is_dir(I18N_DIR . '/' . $d)
                && is_file(I18N_DIR . '/' . $d . '/translation.json')
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

// Same contract as src/globals.js: t(key) -> i18n/{lang}/translation.json,
// missing key falls back to en, then to $fallback.
function t(string $key, string $fallback = ''): string
{
    static $data = null;
    static $enData = null;
    $lang = currentLang();
    if ($data === null) {
        $raw = @file_get_contents(I18N_DIR . '/' . $lang . '/translation.json');
        $data = $raw === false ? [] : (json_decode($raw, true) ?: []);
        $rawEn = @file_get_contents(I18N_DIR . '/en/translation.json');
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

// GET-logout: header link /?action=logout (no JS, no form).
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_GET['action'] ?? '') === 'logout') {
    $_SESSION = [];
    session_destroy();
    header('Location: /');
    exit;
}

$lang = currentLang();
$dbError = '';
$errors = [];
// Requested view: ?mode=… (also the /preview path), limited to known modes.
// Empty means the public landing page.
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

// Database: connect and bootstrap the schema on first request (users, companies).
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
    // Documents: issued by a company (issuer_id -> companies.id, cascade).
    // type keeps the document kind (only invoice for now) so more kinds can
    // share this table later. One-time migration: the former invoices table is
    // renamed and gains the type column.
    $st = $mysqli->query("SHOW TABLES LIKE 'invoices'");
    if ($st && $st->num_rows > 0) {
        $mysqli->query('RENAME TABLE invoices TO documents');
    }
    $mysqli->query(
        "CREATE TABLE IF NOT EXISTS documents (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            issuer_id INT UNSIGNED NOT NULL,
            type ENUM('invoice') NOT NULL DEFAULT 'invoice',
            data JSON NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_documents_issuer FOREIGN KEY (issuer_id)
                REFERENCES companies(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $st = $mysqli->query("SHOW COLUMNS FROM documents LIKE 'type'");
    if ($st && $st->num_rows === 0) {
        $mysqli->query("ALTER TABLE documents ADD COLUMN type ENUM('invoice') NOT NULL DEFAULT 'invoice' AFTER user_id");
    }
    $st = $mysqli->query(
        "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents'
           AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND CONSTRAINT_NAME = 'fk_invoices_user'"
    );
    if ($st && $st->num_rows > 0) {
        $mysqli->query('ALTER TABLE documents DROP FOREIGN KEY fk_invoices_user');
        $mysqli->query('ALTER TABLE documents ADD CONSTRAINT fk_documents_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
    }
    // Migration user_id -> issuer_id (documents belong to an issuing company).
    $st = $mysqli->query("SHOW COLUMNS FROM documents LIKE 'issuer_id'");
    if ($st && $st->num_rows === 0) {
        $mysqli->query("ALTER TABLE documents ADD COLUMN issuer_id INT UNSIGNED NULL AFTER user_id");
        // Backfill: each old document gets the user's earliest company.
        $mysqli->query(
            "UPDATE documents d
             JOIN (SELECT user_id, MIN(id) AS cid FROM companies GROUP BY user_id) m
               ON m.user_id = d.user_id
             SET d.issuer_id = m.cid"
        );
        // Orphans (a user without any company) cannot be adopted; drop them.
        $mysqli->query('DELETE FROM documents WHERE issuer_id IS NULL');
        $st = $mysqli->query(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents'
               AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND CONSTRAINT_NAME = 'fk_documents_user'"
        );
        if ($st && $st->num_rows > 0) {
            $mysqli->query('ALTER TABLE documents DROP FOREIGN KEY fk_documents_user');
        }
        $mysqli->query('ALTER TABLE documents DROP COLUMN user_id');
        $mysqli->query('ALTER TABLE documents MODIFY issuer_id INT UNSIGNED NOT NULL');
        $mysqli->query('ALTER TABLE documents ADD CONSTRAINT fk_documents_issuer FOREIGN KEY (issuer_id) REFERENCES companies(id) ON DELETE CASCADE');
    }

    // GET-delete: row link /?action=delete_document&document_id=N (no form).
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_GET['action'] ?? '') === 'delete_document') {
        $uid = $_SESSION['user_id'] ?? null;
        $did = (int)($_GET['document_id'] ?? 0);
        if (!$uid) {
            header('Location: /?mode=login');
            exit;
        }
        if ($did > 0 && ownDocument($mysqli, $did, $uid)) {
            $st = $mysqli->prepare('DELETE FROM documents WHERE id = ? AND issuer_id IN (SELECT id FROM companies WHERE user_id = ?)');
            $st->bind_param('ii', $did, $uid);
            $st->execute();
            $st->close();
        }
        header('Location: /?mode=cabinet&view=documents&saved=delete_document');
        exit;
    }

    // POST actions: register / login / update_email / update_password /
    // save_company / upload_logo / delete_company / save_document.
    // Each redirects on success.
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $action = $_POST['action'] ?? '';
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $needsCredentials = ($action === 'register' || $action === 'login');
//        if ($needsCredentials && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
//            $errors[] = t('err_invalid_email');
//        }
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
                header('Location: /?mode=cabinet&view=companies');
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
                header('Location: /?mode=cabinet&view=settings&saved=email');
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
                header('Location: /?mode=cabinet&view=settings&saved=password');
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
                $logoName = null;
                if (isset($_FILES['logo']) && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $f = $_FILES['logo'];
                    if ($f['error'] !== UPLOAD_ERR_OK) {
                        $errors[] = t('err_upload');
                    } elseif ($f['size'] > LOGO_MAX_BYTES) {
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
                                $logoName = $name;
                            }
                        }
                    }
                }
                if (!$errors) {
                    $isNew = $cid === 0;
                    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
                    if (!$isNew) {
                        $st = $mysqli->prepare('UPDATE companies SET data = ? WHERE id = ? AND user_id = ?');
                        $st->bind_param('sii', $json, $cid, $uid);
                    } else {
                        $st = $mysqli->prepare('INSERT INTO companies (user_id, data) VALUES (?, ?)');
                        $st->bind_param('is', $uid, $json);
                    }
                    $st->execute();
                    if ($isNew) {
                        $cid = $st->insert_id;
                    }
                    $st->close();
                    if ($logoName !== null) {
                        if (!$isNew) {
                            $st = $mysqli->prepare('SELECT img FROM companies WHERE id = ? AND user_id = ?');
                            $st->bind_param('ii', $cid, $uid);
                            $st->execute();
                            $st->bind_result($old);
                            if ($st->fetch() && $old) {
                                @unlink(UPLOADS_DIR . '/' . basename((string)$old));
                            }
                            $st->close();
                        }
                        $st = $mysqli->prepare('UPDATE companies SET img = ? WHERE id = ? AND user_id = ?');
                        $st->bind_param('sii', $logoName, $cid, $uid);
                        $st->execute();
                        $st->close();
                    }
                    header('Location: /?mode=cabinet&view=companies&company=' . $cid . '&saved=company');
                    exit;
                }
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
                            header('Location: /?mode=cabinet&view=companies&company=' . $cid . '&saved=logo');
                            exit;
                        }
                    }
                }
            }
        } elseif ($action === 'delete_company') {
            $mode = 'cabinet';
            $uid = $_SESSION['user_id'] ?? null;
            if (!$uid) {
                header('Location: /?mode=login');
                exit;
            }
            $cid = (int)($_POST['company_id'] ?? 0);
            if ($cid <= 0 || !ownCompany($mysqli, $cid, $uid)) {
                $errors[] = t('err_no_company');
            } else {
                $st = $mysqli->prepare('SELECT img FROM companies WHERE id = ? AND user_id = ?');
                $st->bind_param('ii', $cid, $uid);
                $st->execute();
                $st->bind_result($img);
                if ($st->fetch() && $img) {
                    @unlink(UPLOADS_DIR . '/' . basename((string)$img));
                }
                $st->close();
                $st = $mysqli->prepare('DELETE FROM companies WHERE id = ? AND user_id = ?');
                $st->bind_param('ii', $cid, $uid);
                $st->execute();
                $st->close();
                header('Location: /?mode=cabinet&view=companies&saved=delete');
                exit;
            }
        } elseif ($action === 'save_document') {
            $mode = 'cabinet';
            $uid = $_SESSION['user_id'] ?? null;
            if (!$uid) {
                header('Location: /?mode=login');
                exit;
            }
            $did = (int)($_POST['document_id'] ?? 0);
            if ($did > 0 && !ownDocument($mysqli, $did, $uid)) {
                $errors[] = t('err_no_document');
            } else {
                $eid = (int)($_POST['issuer_id'] ?? 0);
                if ($eid <= 0 || !ownCompany($mysqli, $eid, $uid)) {
                    $errors[] = t('err_no_company');
                }
                $data = [];
                foreach (DOCUMENT_FIELDS as $f) {
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
                // Variable body part: rows posted as body[col][]; empty rows dropped.
                $bodyFields = ['id', 'desc', 'qty', 'price', 'discount', 'tax'];
                $bodyCols = (array)($_POST['body'] ?? []);
                $bodyCount = 0;
                foreach ($bodyFields as $bf) {
                    $bodyCount = max($bodyCount, count((array)($bodyCols[$bf] ?? [])));
                }
                $data['body'] = [];
                for ($i = 0; $i < $bodyCount; $i++) {
                    $row = [];
                    $filled = false;
                    foreach ($bodyFields as $bf) {
                        $v = trim((string)(((array)($bodyCols[$bf] ?? []))[$i] ?? ''));
                        $row[$bf] = $v;
                        $filled = $filled || $v !== '';
                    }
                    if ($filled) {
                        $data['body'][] = $row;
                    }
                }
                if (!$errors) {
                    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
                    if ($did > 0) {
                        $st = $mysqli->prepare('UPDATE documents SET data = ?, issuer_id = ? WHERE id = ? AND issuer_id IN (SELECT id FROM companies WHERE user_id = ?)');
                        $st->bind_param('siii', $json, $eid, $did, $uid);
                    } else {
                        $st = $mysqli->prepare('INSERT INTO documents (issuer_id, data) VALUES (?, ?)');
                        $st->bind_param('is', $eid, $json);
                    }
                    $st->execute();
                    if ($did === 0) {
                        $did = $st->insert_id;
                    }
                    $st->close();
                    header('Location: /?mode=cabinet&view=documents&document=' . $did . '&saved=document');
                    exit;
                }
            }
        }
    }
    $uploadAlert = (isset($action) && $action === 'upload_logo' && $errors) ? end($errors) : null;
    // Logo bytes (mode=logo): serve the owner's logo file, 404 otherwise.
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

    // PDF preview (mode=preview, logged-in only; guests go to login): the
    // generator (node) has no DB access, so PHP assembles the current user's
    // company data and forwards it to the render API. header/footer come from
    // public/header.html + public/footer.html; body is pdfkit. No data entered
    // yet -> empty fields are rendered.
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
        $bill = [];
        $items = [];
        $cid = (int)($_GET['company'] ?? 0);
        $did = (int)($_GET['document'] ?? 0);
        if ($did > 0) {
            // Document preview: issuer = my company (data + logo), bill-to =
            // the payer stored in the document's own fields.
            $st = $mysqli->prepare('SELECT d.data, c.data, c.img FROM documents d JOIN companies c ON c.id = d.issuer_id WHERE d.id = ? AND c.user_id = ?');
            $st->bind_param('ii', $did, $uid);
            $st->execute();
            $st->bind_result($dData, $cData, $cImg);
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
                $docRow = json_decode((string)$dData, true) ?: [];
                foreach (DOCUMENT_FIELDS as $f) {
                    if (array_key_exists($f, $docRow)) {
                        $bill[$f] = $docRow[$f];
                    }
                }
                // Variable body part -> order.items; totals are computed by the
                // generator, never stored.
                $bodyFields = ['id', 'desc', 'qty', 'price', 'discount', 'tax'];
                foreach ((array)($docRow['body'] ?? []) as $r) {
                    if (!is_array($r)) {
                        continue;
                    }
                    $row = [];
                    $filled = false;
                    foreach ($bodyFields as $bf) {
                        $v = trim((string)($r[$bf] ?? ''));
                        $row[$bf] = $v;
                        $filled = $filled || $v !== '';
                    }
                    if ($filled) {
                        $items[] = $row;
                    }
                }
            }
            $st->close();
        } else {
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
        }

        $settings = [
            'company' => $company,
            'language' => currentLang(),
            'locale' => 'en-US',
            'currency' => 'EUR',
            'page' => ['size' => 'A4'],
        ];
        if ($logoB64 !== null) {
            $settings['logoBase64'] = $logoB64;
        }
        $orderData = (array)($docRow['order'] ?? []);
        $formatDate = function (?string $raw): string {
            if ($raw === null || $raw === '') {
                return '';
            }
            foreach (['Y-m-d', 'd.m.Y'] as $fmt) {
                $d = DateTime::createFromFormat('!' . $fmt, $raw);
                if ($d) {
                    return $d->format('d.m.Y');
                }
            }
            return $raw;
        };
        $docDate = $formatDate((string)($bill['document_date'] ?? ''));
        $orderDate = (array)($orderData['date'] ?? []);
        $payload = json_encode([
            'settings' => $settings,
            'order' => [
                'id' => (string)($bill['document_number'] ?? ''),
                'ref' => (string)($orderData['ref'] ?? ''),
                'clientID' => (string)($orderData['clientID'] ?? ''),
                'salesRep' => (string)($orderData['salesRep'] ?? ''),
                'date' => [
                    'created' => $docDate,
                    'due' => $formatDate((string)($orderDate['due'] ?? '')),
                ],
                'bill' => $bill, 'ship' => [], 'total' => [], 'items' => $items,
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
        header('Content-Disposition: inline; filename="document.pdf"');
        echo $pdf;
        exit;
    }

    // Cabinet data for the page: the logged-in user's companies + the selected one.
    $cabCompanies = [];
    $cabCompany = null;
    $cabDocuments = [];
    $cabDocument = null;
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
        $st = $mysqli->prepare(
            'SELECT d.id, d.data, d.updated_at, d.issuer_id, c.data AS issuer_data
             FROM documents d JOIN companies c ON c.id = d.issuer_id
             WHERE c.user_id = ? ORDER BY d.id'
        );
        $st->bind_param('i', $cabUid);
        $st->execute();
        $res = $st->get_result();
        while ($row = $res->fetch_assoc()) {
            $row['data'] = json_decode((string)$row['data'], true) ?: [];
            $row['issuer_data'] = json_decode((string)$row['issuer_data'], true) ?: [];
            $cabDocuments[] = $row;
        }
        $st->close();
        $dsel = (int)($_GET['document'] ?? $_POST['document_id'] ?? 0);
        foreach ($cabDocuments as $doc) {
            if ((int)$doc['id'] === $dsel) {
                $cabDocument = $doc;
                break;
            }
        }
    }
    $mysqli->close();
} catch (Throwable $ex) {
    $dbError = $ex->getMessage();
}

// Page-level flags: the cabinet view requires a session; guests hitting it are
// sent to login, and logged-in users are kept off the register/login screens.
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
// Cabinet sub-view: ?view=companies|documents|settings. A company or document
// selection implies its view. Empty means the cabinet shows only the cards.
$cabView = in_array($_GET['view'] ?? '', ['companies', 'documents', 'settings'], true)
    ? $_GET['view']
    : (isset($_GET['company']) ? 'companies' : (isset($_GET['document']) ? 'documents' : ''));
$title = $mode === 'register' ? t('register') : ($mode === 'login' ? t('login') : ($mode === 'cabinet' ? t('cabinet') : t('app_title')));
$langCount = (string)count(availableLangs());
$favicon = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect x='2' y='2' width='60' height='60' rx='12' fill='%23C9A13B'/%3E%3Ctext x='32' y='44' font-family='Georgia, serif' font-weight='bold' font-size='34' fill='%239E252B' text-anchor='middle'%3EIn%3C/text%3E%3C/svg%3E";

function prettyDump($data = null, $die = false, $showStack = false) {
        if (in_array($_SERVER['SERVER_NAME'], ['127.0.0.1', '::1', '0.0.0.0', 'localhost', '_'])) {
            $stack = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            echo "<pre>";
            if ($showStack) print_r($stack);
            if ($stack[0]['function'] == 'prettyDump') {
                echo __FUNCTION__ . '() из ' . $stack[0]['file'] . ' line ' . $stack[0]['line'] . '<br>';
            } else {
//			print_r($stack);
                echo __FUNCTION__ . '() из ' . ($stack[1]['args'][0] ? $stack[1]['args'][0] : $stack[2]['file']) . ' строка ' . $stack[0]['line'] . ':<br>';
            }
            if (is_bool($data) || is_null($data) || empty($data)) var_dump($data);
            else print_r($data);
            echo "</pre>";
            if ($die) die;
        }
    }

?>
<!doctype html>
<html lang="<?= e($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="<?= $favicon ?>">
<title><?= e($title) ?></title>
<link rel="stylesheet" href="/css/app.css?v=<?= (int)filemtime(__DIR__ . '/css/app.css') ?>">
<script src="/js/theme.js?v=<?= (int)filemtime(__DIR__ . '/js/theme.js') ?>"></script>
</head>
<body>

<?php if ($dbError): ?>
<!-- DB error view: only when the MySQL connection or schema bootstrap failed. -->
<div class="wrap" style="padding:60px 24px;text-align:center">
  <h1><?= e(t('err_db')) ?></h1>
  <p class="sub"><?= e(t('err_db_hint')) ?></p>
</div>
<?php else: ?>

<!-- Top navigation: always shown in the normal (non-error) view. -->
<header class="nav">
  <div class="wrap nav-in">
    <a class="brand" href="/"><img src="<?= $favicon ?>" alt=""> Inquistador</a>

    <button type="button" class="nav-burger" aria-label="<?= e(t('menu_toggle')) ?>" aria-expanded="false" aria-controls="main-nav">☰</button>

    <!-- Cabinet links inside the cabinet, landing anchors otherwise. -->
    <nav class="nav-links" id="main-nav">
      <?php if ($isCabinet): ?>
        <a href="/preview/" target="_blank"><?= e(t('preview')) ?></a>
        <a href="/?mode=cabinet&view=companies"><?= e(t('my_companies')) ?></a>
        <a href="/?mode=cabinet&view=documents"><?= e(t('my_documents')) ?></a>
        <a href="/?mode=cabinet&view=settings"><?= e(t('settings')) ?></a>
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
      <button type="button" class="theme-toggle" data-label="<?= e(t('theme_toggle')) ?>" aria-label="<?= e(t('theme_toggle')) ?>">◐</button>
      <!-- Logged in: cabinet + logout; guest: login / register buttons. -->
      <?php if ($userEmail): ?>
        <?php if (!$isCabinet): ?>
          <a class="btn primary small" href="/?mode=cabinet"><?= e(t('cabinet')) ?></a>
        <?php else: ?>
          <span class="btn primary small" style="cursor:default"><?= e($userEmail) ?></span>
        <?php endif; ?>
        <a id="logout" href="/?action=logout" class="btn secondary small"><?= e(t('logout')) ?></a>
      <?php else: ?>
        <a class="btn ghost small" href="/?mode=login#start"><?= e(t('nav_login')) ?></a>
<!--        <a class="btn primary small" href="#start">--><?php //= e(t('hero_primary')) ?><!--</a>-->
      <?php endif; ?>
    </div>
  </div>
</header>

<?php if ($isCabinet): ?>
<!-- Cabinet: only when mode=cabinet and a session exists (cards, sub-sections). -->
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
    <?php elseif ($saved === 'delete'): ?>
      <div class="ok"><?= e(t('saved_delete')) ?></div>
    <?php elseif ($saved === 'document'): ?>
      <div class="ok"><?= e(t('saved_document')) ?></div>
    <?php elseif ($saved === 'delete_document'): ?>
      <div class="ok"><?= e(t('saved_delete_document')) ?></div>
    <?php endif; ?>
    <?php foreach ($errors as $err): ?>
      <div class="err" style="max-width:720px;margin:0 auto 12px"><?= e($err) ?></div>
    <?php endforeach; ?>
    <?php if ($uploadAlert): ?>
      <script>alert(<?= json_encode($uploadAlert, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>)</script>
    <?php endif; ?>

    <?php define('CABINET_PART', true); ?>
    <?php include __DIR__ . '/cabinet-menu.php'; ?>
    <?php if ($cabView === 'companies'): ?>
      <?php include __DIR__ . '/cabinet-companies.php'; ?>
    <?php elseif ($cabView === 'documents'): ?>
      <?php include __DIR__ . '/cabinet-documents.php'; ?>
    <?php elseif ($cabView === 'settings'): ?>
      <?php include __DIR__ . '/cabinet-settings.php'; ?>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<?php if (!$isCabinet): ?>
<!-- Landing view: hero, features, how-it-works, guest auth card and CTA. -->
<div class="hero">
  <div class="wrap hero-grid">
    <div>
      <span class="badge"><?= e(t('hero_badge')) ?></span>
      <h1><?= e(t('hero_title')) ?></h1>
      <p class="sub"><?= e(t('hero_sub')) ?></p>
      <div class="cta-row">
        <?php if ($userEmail): ?>
          <a class="btn primary" href="/preview/"><?= e(t('nav_templates')) ?></a>
          <a class="btn ghost" href="/?mode=cabinet"><?= e(t('cabinet')) ?></a>
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

<!-- Features: landing only. -->
<section class="block" id="features">
  <div class="wrap">
    <h2><?= e(t('feat_title')) ?></h2>
    <p class="center-sub"><?= e(t('feat_sub')) ?></p>
    <div class="bento">
      <div class="tile"><div class="ico">📄</div><span class="soon">soon</span><h3><?= e(t('f1t')) ?></h3><p><?= e(t('f1d')) ?></p></div>
      <div class="tile"><div class="ico">🌍</div><h3><?= e(t('f2t')) ?></h3><p><?= e(t('f2d')) ?></p></div>
      <div class="tile"><div class="ico">👁</div><h3><?= e(t('f3t')) ?></h3><p><?= e(t('f3d')) ?></p></div>
      <div class="tile"><div class="ico">🐳</div><h3><?= e(t('f4t')) ?></h3><p><?= e(t('f4d')) ?></p></div>
    </div>
  </div>
</section>

<!-- How it works: landing only. -->
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
<!-- Auth card: guests only (no session); toggles between register and login. -->
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
          <input type="text" name="email" placeholder="<?= e(t('email')) ?>" required
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

<!-- Final call to action: landing only. -->
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

<!-- Footer: always shown in the normal (non-error) view. -->
<footer>
  <div class="wrap">
    <span>©2026 &nbsp;<img class="foot-ico" src="<?= $favicon ?>" alt="">&nbsp;Inquistador — <?= e(t('foot_note')) ?></span>
<!--    <a href="/preview/">--><?php //= e(t('pdf_templates')) ?><!--</a>-->
  </div>
</footer>

<?php endif; ?>
</body>
</html>
