<?php
header('Content-Type: application/json');


/** PROD SETTINGS **/
const DEV_MODE = false;                 // set true only when troubleshooting
$DB_HOST = 'localhost';
$DB_NAME = 'playfast_waitlist';
$DB_USER = 'playfast_dbuser'; // update
$DB_PASS = 'Playfast_dbpassword!'; // update
$TABLE = 'waitlist';
$DEV     = false; 


/** CORS (adjust for your domain in production) **/
if (isset($_SERVER['HTTP_ORIGIN'])) {
  header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']); // or hardcode your domain
  header('Vary: Origin');
}
header('Access-Control-Allow-Headers: Content-Type, Accept');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204); exit;
}

function fail($code, $msg, $extra = []) {
  http_response_code($code);
  $out = ['ok'=>false, 'error'=>$msg];
  if (DEV_MODE && $extra) $out['debug'] = $extra;
  echo json_encode($out); exit;
}
function ok($data = []) { http_response_code(200); echo json_encode(['ok'=>true] + $data); exit; }

/** Parse input (JSON or form) **/
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') fail(405, 'Method not allowed', ['method'=>$method]);

$ctype = $_SERVER['CONTENT_TYPE'] ?? '';
$raw   = file_get_contents('php://input') ?: '';
$payload = (stripos($ctype, 'application/json') !== false && $raw !== '')
  ? json_decode($raw, true)
  : null;

if (!is_array($payload)) {
  if (!empty($_POST)) {
    $payload = $_POST;
  } else {
    $tmp = []; parse_str($raw, $tmp);
    if (!empty($tmp)) $payload = $tmp;
  }
}
if (!is_array($payload)) {
  fail(400, 'No parsable body', DEV_MODE ? ['content_type'=>$ctype,'raw_len'=>strlen($raw)] : []);
}

$fname = trim($payload['fname'] ?? '');
$lname = trim($payload['lname'] ?? '');
$email = trim($payload['email'] ?? '');
$phone = trim($payload['phone'] ?? '');
$features = $payload['features'] ?? []; // Read the features array

if ($fname === '' || $lname === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  fail(400, 'Invalid input', DEV_MODE ? ['parsed'=>compact('fname','lname','email','phone','features')] : []);
}
if (!extension_loaded('pdo_mysql')) fail(500, 'pdo_mysql not loaded');

try {
  $pdo = new PDO(
    "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
    $DB_USER, $DB_PASS,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
  );
} catch (Throwable $e) {
  fail(500, 'DB connect failed', DEV_MODE ? ['msg'=>$e->getMessage()] : []);
}

/** Optional soft duplicate check (still add UNIQUE(email) at DB level) **/
try {
  $c = $pdo->prepare("SELECT 1 FROM `$TABLE` WHERE email=? LIMIT 1");
  $c->execute([$email]);
  if ($c->fetch()) fail(409, 'Email already registered');
} catch (Throwable $e) { /* non-fatal */ }

/** Insert **/
try {
  $stmt = $pdo->prepare("
    INSERT INTO `$TABLE` (created_at, fname, lname, email, phone, ip, ua)
    VALUES (NOW(), ?, ?, ?, ?, ?, ?)
  ");
  $stmt->execute([
    $fname,
    $lname,
    $email,
    ($phone !== '' ? $phone : null),
    $_SERVER['REMOTE_ADDR'] ?? '',
    $_SERVER['HTTP_USER_AGENT'] ?? ''
  ]);

  // Get the ID of the new waitlist entry
  $waitlist_id = $pdo->lastInsertId();

  // If features were selected, insert them into the feature_interest table
  if ($waitlist_id && is_array($features) && !empty($features)) {
    $feature_stmt = $pdo->prepare("
      INSERT INTO `feature_interest` (waitlist_id, feature_name, created_at)
      VALUES (?, ?, NOW())
    ");
    foreach ($features as $feature_name) {
      // Basic validation for feature name
      if (is_string($feature_name) && trim($feature_name) !== '') {
        $feature_stmt->execute([$waitlist_id, trim($feature_name)]);
      }
    }
  }

  // Send confirmation emails
  try {
    require_once __DIR__ . '/mailer.php';
    // Mailer::init([]); // Using default settings from mailer.php
    Mailer::sendWaitlist(
      $email,
      $fname,
      $lname,
      $phone,
      ['features' => is_array($features) ? implode(', ', $features) : '']
    );
  } catch (Throwable $e) {
    // Non-fatal: Log email error but still return success to the user
    if (DEV_MODE) error_log('Email sending failed: ' . $e->getMessage());
  }

  ok();
} catch (Throwable $e) {
  $state = ($e instanceof PDOException) ? $e->getCode() : null;
  fail(500, 'Insert failed', DEV_MODE ? ['sqlstate'=>$state, 'msg'=>$e->getMessage()] : []);
}
