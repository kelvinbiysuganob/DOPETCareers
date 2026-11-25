<?php
// ============ SETTINGS: copy these 4 values from your hosting control panel ============
const DB_HOST = 'localhost';              // often NOT localhost on free hosts, e.g. sql123.example.com
const DB_NAME = 'your_database_name';
const DB_USER = 'your_database_user';
const DB_PASS = 'your_database_password';
// =======================================================================================

const UPLOAD_DIR = __DIR__ . '/uploads/';
const MAX_CV = 5242880; // 5 MB

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')]);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function out($d, $c = 200) { http_response_code($c); echo json_encode($d); exit; }

try {
  $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (Throwable $e) { out(['error' => 'Database connection failed. Check the settings at the top of api.php.'], 500); }

$act = $_GET['action'] ?? '';
$d = $_POST ?: (json_decode(file_get_contents('php://input'), true) ?: []);
$g = fn($k, $max = 255) => mb_substr(trim((string)($d[$k] ?? '')), 0, $max);

function user() {
  global $pdo;
  if (empty($_SESSION['uid'])) return null;
  $s = $pdo->prepare('SELECT id, full_name, email, role FROM users WHERE id=?');
  $s->execute([$_SESSION['uid']]);
  return $s->fetch() ?: null;
}
function ref($id, $at) { return 'DOP-' . substr((string)$at, 0, 4) . '-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT); }
function with_ref($rows) { return array_map(fn($r) => $r + ['ref' => ref($r['id'], $r['applied_at'])], $rows); }
function need() { $u = user(); if (!$u) out(['error' => 'Please log in.'], 401); return $u; }
function admin() { $u = need(); if ($u['role'] !== 'admin') out(['error' => 'Staff only.'], 403); return $u; }

// Validates and stores an uploaded CV. Returns [stored_name, original_name, size] or null if no file was sent.
function save_cv() {
  if (empty($_FILES['cv']) || $_FILES['cv']['error'] === UPLOAD_ERR_NO_FILE) return null;
  $f = $_FILES['cv'];
  if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > MAX_CV) out(['error' => 'CV must be a file up to 5 MB.'], 400);
  $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
  if (!in_array($ext, ['pdf', 'doc', 'docx'], true)) out(['error' => 'CV must be a PDF, DOC or DOCX file.'], 400);
  if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
  $name = bin2hex(random_bytes(16)) . '.' . $ext;
  if (!move_uploaded_file($f['tmp_name'], UPLOAD_DIR . $name)) out(['error' => 'Could not save the file.'], 500);
  return [$name, mb_substr(basename($f['name']), 0, 200), (int)$f['size']];
}

try {
switch ($act) {

  case 'register':
    $email = strtolower($g('email', 160)); $pw = (string)($d['password'] ?? ''); $name = $g('name', 120);
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pw) < 6) out(['error' => 'Please enter your name, a valid email and a password of at least 6 characters.'], 400);
    $s = $pdo->prepare('SELECT 1 FROM users WHERE email=?'); $s->execute([$email]);
    if ($s->fetch()) out(['error' => 'That email is already registered.'], 409);
    $pdo->prepare('INSERT INTO users(full_name,email,password_hash) VALUES(?,?,?)')->execute([$name, $email, password_hash($pw, PASSWORD_DEFAULT)]);
    session_regenerate_id(true); $_SESSION['uid'] = (int)$pdo->lastInsertId();
    out(['ok' => true]);

  case 'login':
    $s = $pdo->prepare('SELECT * FROM users WHERE email=?'); $s->execute([strtolower($g('email', 160))]);
    $u = $s->fetch();
    if (!$u || !password_verify((string)($d['password'] ?? ''), $u['password_hash'])) { usleep(400000); out(['error' => 'Wrong email or password.'], 401); }
    session_regenerate_id(true); $_SESSION['uid'] = (int)$u['id'];
    out(['ok' => true]);

  case 'logout':
    $_SESSION = []; session_destroy(); out(['ok' => true]);

  case 'me':
    $u = user();
    if (!$u) out(['user' => null]);
    $s = $pdo->prepare('SELECT phone, location, headline, skills, about, cv_name, cv_size FROM profiles WHERE user_id=?'); $s->execute([$u['id']]);
    out(['user' => $u, 'profile' => $s->fetch() ?: new stdClass()]);

  case 'saveProfile':
    $u = need(); $cv = save_cv();
    $pdo->prepare('UPDATE users SET full_name=? WHERE id=?')->execute([$g('name', 120) ?: $u['full_name'], $u['id']]);
    $pdo->prepare('INSERT INTO profiles(user_id,phone,location,headline,skills,about) VALUES(?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE phone=VALUES(phone), location=VALUES(location), headline=VALUES(headline), skills=VALUES(skills), about=VALUES(about)')
      ->execute([$u['id'], $g('phone', 40), $g('loc', 120), $g('head', 160), $g('skills', 1000), $g('about', 5000)]);
    if ($cv) $pdo->prepare('UPDATE profiles SET cv_path=?, cv_name=?, cv_size=? WHERE user_id=?')->execute([$cv[0], $cv[1], $cv[2], $u['id']]);
    out(['ok' => true]);

  case 'jobs': // public list of open jobs
    out(['jobs' => $pdo->query('SELECT id, title, dept, location AS loc, type, description AS `desc` FROM jobs WHERE is_open=1 ORDER BY posted_at DESC')->fetchAll()]);

  case 'apply':
    $u = need();
    $pos = $g('job', 160);
    if ($pos === '') out(['error' => 'Please enter the position.'], 400);
    $key = mb_strtolower(preg_replace('/\s+/', ' ', $pos));
    $s = $pdo->prepare('SELECT id, applied_at FROM applications WHERE user_id=? AND position_key=?'); $s->execute([$u['id'], $key]);
    if ($x = $s->fetch()) out(['error' => 'You have already applied for this position (reference ' . ref($x['id'], $x['applied_at']) . '). You cannot apply twice.'], 409);
    $cv = save_cv();
    $s = $pdo->prepare('SELECT cv_path, cv_name FROM profiles WHERE user_id=?'); $s->execute([$u['id']]); $p = $s->fetch();
    $path = $cv ? $cv[0] : ($p['cv_path'] ?? null); $cname = $cv ? $cv[1] : ($p['cv_name'] ?? null);
    if (!$path) out(['error' => 'Please upload your CV.'], 400);
    try {
      $pdo->prepare('INSERT INTO applications(user_id,position,position_key,phone,address,education,experience_years,expected_salary,note,cv_path,cv_name) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$u['id'], $pos, $key, $g('phone', 40), $g('addr'), $g('edu', 160), (int)($d['years'] ?? 0), $g('sal', 60), $g('note', 5000), $path, $cname]);
    } catch (PDOException $e) {
      if ($e->getCode() === '23000') out(['error' => 'You have already applied for this position. You cannot apply twice.'], 409);
      throw $e;
    }
    $newId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO profiles(user_id,phone,location) VALUES(?,?,?) ON DUPLICATE KEY UPDATE phone=VALUES(phone), location=VALUES(location)')->execute([$u['id'], $g('phone', 40), $g('addr', 120)]);
    if ($cv) $pdo->prepare('UPDATE profiles SET cv_path=?, cv_name=?, cv_size=? WHERE user_id=?')->execute([$cv[0], $cv[1], $cv[2], $u['id']]);
    out(['ok' => true, 'ref' => ref($newId, date('Y-m-d H:i:s')), 'position' => $pos]);

  case 'myApplications':
    $u = need(); $s = $pdo->prepare('SELECT id, position, status, cv_name, applied_at FROM applications WHERE user_id=? ORDER BY applied_at DESC'); $s->execute([$u['id']]);
    out(['apps' => with_ref($s->fetchAll())]);

  // ---------- staff only ----------
  case 'adminApplications':
    admin();
    out(['apps' => with_ref($pdo->query('SELECT a.id, a.position, a.phone, a.address, a.education, a.experience_years, a.expected_salary, a.note, a.cv_name, a.status, a.applied_at, u.full_name, u.email
      FROM applications a JOIN users u ON u.id=a.user_id ORDER BY a.applied_at DESC')->fetchAll())]);

  case 'setStatus':
    admin();
    if (!in_array($d['status'] ?? '', ['Submitted', 'Under review', 'Interview', 'Offer', 'Rejected'], true)) out(['error' => 'Invalid status.'], 400);
    $pdo->prepare('UPDATE applications SET status=? WHERE id=?')->execute([$d['status'], (int)$d['id']]);
    out(['ok' => true]);

  case 'addJob':
    admin();
    if ($g('title') === '') out(['error' => 'Job title is required.'], 400);
    $type = in_array($d['type'] ?? '', ['Full-time', 'Part-time', 'Contract', 'Internship'], true) ? $d['type'] : 'Full-time';
    $pdo->prepare('INSERT INTO jobs(title,dept,location,type,description) VALUES(?,?,?,?,?)')->execute([$g('title', 160), $g('dept', 80), $g('loc', 120), $type, $g('desc', 5000)]);
    out(['ok' => true]);

  case 'removeJob': // hides the job; the row stays so old applications keep their position
    admin();
    $pdo->prepare('UPDATE jobs SET is_open=0 WHERE id=?')->execute([(int)($d['id'] ?? 0)]);
    out(['ok' => true]);

  case 'cv': // staff, or the applicant who owns the file
    $u = need();
    $s = $pdo->prepare('SELECT user_id, cv_path, cv_name FROM applications WHERE id=?'); $s->execute([(int)($_GET['id'] ?? 0)]); $r = $s->fetch();
    if (!$r || !$r['cv_path']) out(['error' => 'Not found.'], 404);
    if ($u['role'] !== 'admin' && (int)$r['user_id'] !== (int)$u['id']) out(['error' => 'Not allowed.'], 403);
    $file = UPLOAD_DIR . basename($r['cv_path']);
    if (!is_file($file)) out(['error' => 'File is missing on the server.'], 404);
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $types = ['pdf' => 'application/pdf', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Content-Disposition: ' . (($ext === 'pdf' && empty($_GET['dl'])) ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $r['cv_name']) . '"');
    header('Content-Length: ' . filesize($file));
    readfile($file); exit;

  default: out(['error' => 'Unknown action.'], 404);
}
} catch (Throwable $e) { out(['error' => 'Server error. Please try again.'], 500); }
