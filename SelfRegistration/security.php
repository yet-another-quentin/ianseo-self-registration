<?php
/*
 * Self registration — protections of the public form (register.php / register-api.php).
 * IANSEO itself has no CSRF tokens, captcha or rate limiting: everything is here.
 *
 * sr_session_ini() must run BEFORE IANSEO's config.php, which starts the PHP session (Common/Globals.inc.php).
 */

const SR_VERIFY_TTL = 1200;          // seconds a license + birth date check stays valid for submitting
const SR_LIMIT_VERIFY_IP = 10;       // checks per IP per 15 minutes
const SR_LIMIT_FAIL_LICENSE = 5;     // failed checks per license per hour (any IP)
const SR_LIMIT_SUBMIT_IP = 5;        // submissions per IP per hour

function sr_request_is_https() {
	return (!empty($_SERVER['HTTPS']) and $_SERVER['HTTPS'] !== 'off')
		or strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

// Session cookie: not readable by scripts, not sent by cross-site requests, HTTPS only when served over HTTPS.
function sr_session_ini() {
	if (session_status() !== PHP_SESSION_NONE) return;
	ini_set('session.use_strict_mode', '1');
	ini_set('session.use_only_cookies', '1');
	ini_set('session.cookie_httponly', '1');
	ini_set('session.cookie_samesite', 'Lax');
	if (sr_request_is_https()) ini_set('session.cookie_secure', '1');
}

function sr_security_headers($html = false) {
	header('X-Content-Type-Options: nosniff');
	header('X-Frame-Options: DENY');
	header('Referrer-Policy: same-origin');
	header('Cache-Control: no-store');
	if ($html) {
		header("Content-Security-Policy: default-src 'self'; script-src 'self' https://challenges.cloudflare.com; "
			. "frame-src https://challenges.cloudflare.com; connect-src 'self'; style-src 'self'; img-src 'self' data:; "
			. "object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
	} else {
		header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
	}
}

// JSON answer of the public API. IANSEO's JsonOut() is not used: it allows any origin (CORS *).
function sr_json($data, $status = 200) {
	http_response_code($status);
	sr_security_headers(false);
	header('Content-Type: application/json; charset=UTF-8');
	echo json_encode($data, JSON_UNESCAPED_UNICODE);
	exit;
}

function sr_client_ip() {
	if (function_exists('sr_param_get') and sr_param_get(0, 'trustCf', false) and !empty($_SERVER['HTTP_CF_CONNECTING_IP'])
		and filter_var($_SERVER['HTTP_CF_CONNECTING_IP'], FILTER_VALIDATE_IP)) {
		return $_SERVER['HTTP_CF_CONNECTING_IP'];
	}
	return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

// ---------- CSRF ----------

function sr_csrf_token() {
	if (empty($_SESSION['SR']['csrf'])) $_SESSION['SR']['csrf'] = bin2hex(random_bytes(32));
	return $_SESSION['SR']['csrf'];
}

/**
 * Every public API call: POST only, from a page of this host (Origin, or Referer when Origin is absent),
 * with the session's CSRF token in the X-SR-Token header.
 */
function sr_api_guard() {
	if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') sr_json(array('error' => 'err.request'), 405);
	$host = strtolower($_SERVER['HTTP_HOST'] ?? '');
	$from = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
	$fromHost = strtolower((string)parse_url($from, PHP_URL_HOST) . (parse_url($from, PHP_URL_PORT) ? ':' . parse_url($from, PHP_URL_PORT) : ''));
	if ($host === '' or $fromHost !== $host) sr_json(array('error' => 'err.request'), 403);
	$token = (string)($_SERVER['HTTP_X_SR_TOKEN'] ?? '');
	if (empty($_SESSION['SR']['csrf']) or !hash_equals($_SESSION['SR']['csrf'], $token)) sr_json(array('error' => 'err.expired'), 403);
}

// ---------- Cloudflare Turnstile ----------

// Cloudflare's documented test secret keys answer with a fixed hostname: the hostname check is skipped for them.
function sr_turnstile_is_test_key($secret) {
	return (bool)preg_match('/^[123]x0{31}AA$/', $secret);
}

function sr_turnstile_verify($token, $ip) {
	$keys = sr_turnstile_keys();
	if ($keys['secret'] === '' or !is_string($token) or $token === '' or strlen($token) > 2048) return false;
	list($code, $body, $err) = sr_http('https://challenges.cloudflare.com/turnstile/v0/siteverify', 'application/json',
		array('secret' => $keys['secret'], 'response' => $token, 'remoteip' => $ip), 5);
	$j = $err ? null : json_decode($body, true);
	if (!is_array($j) or empty($j['success'])) return false;
	if (!sr_turnstile_is_test_key($keys['secret'])) {
		$host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
		if (strtolower((string)($j['hostname'] ?? '')) !== $host) return false;
	}
	return true;
}

// ---------- rate limiting (SelfRegAttempts, rows older than 24 h purged) ----------

function sr_rate_key($license) {
	return hash('sha256', 'sr|' . sr_norm_license($license));
}

function sr_rate_count($type, $field, $value, $seconds, $failedOnly = false) {
	$col = $field === 'ip' ? 'SaIp' : 'SaKey';
	$q = safe_r_sql("select count(*) as n from SelfRegAttempts where SaType=" . StrSafe_DB($type)
		. " and $col=" . StrSafe_DB($value) . " and SaTime >= " . StrSafe_DB(date('Y-m-d H:i:s', time() - $seconds))
		. ($failedOnly ? " and SaOk=0" : ''));
	return intval(safe_fetch($q)->n);
}

function sr_rate_log($TourId, $type, $ip, $key, $ok) {
	safe_w_sql("insert into SelfRegAttempts set SaTournament=" . intval($TourId) . ", SaIp=" . StrSafe_DB(substr($ip, 0, 45))
		. ", SaKey=" . StrSafe_DB($key) . ", SaType=" . StrSafe_DB($type) . ", SaOk=" . ($ok ? 1 : 0)
		. ", SaTime=" . StrSafe_DB(date('Y-m-d H:i:s')));
	if (mt_rand(1, 50) === 1) {
		safe_w_sql("delete from SelfRegAttempts where SaTime < " . StrSafe_DB(date('Y-m-d H:i:s', time() - 86400)));
	}
}

// ---------- verified identity (server side only) ----------

function sr_verified($TourId) {
	$v = $_SESSION['SR']['verified'][intval($TourId)] ?? null;
	if (!is_array($v) or time() - intval($v['at'] ?? 0) > SR_VERIFY_TTL) return null;
	return $v['archer'];
}

function sr_set_verified($TourId, $archer) {
	session_regenerate_id(true);
	$_SESSION['SR']['verified'][intval($TourId)] = array('archer' => $archer, 'at' => time());
}

function sr_clear_verified($TourId) {
	unset($_SESSION['SR']['verified'][intval($TourId)]);
}
