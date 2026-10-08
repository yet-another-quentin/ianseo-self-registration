<?php
/*
 * Self registration — PUBLIC images of a competition for the form: register-logo.php?c=<ToCode>&t=L|R|B
 * (left logo, right logo, bottom banner, as entered in IANSEO). IANSEO's Common/TourLogo.php needs a competition
 * session; this one serves only competitions whose form can be shown (not "closed"), re-encoded by sr_tour_image().
 */
require_once(__DIR__ . '/security.php');
sr_session_ini();
ini_set('session.cache_limiter', '');   // IANSEO starts a session: keep the image cacheable
$SKIP_AUTH = true;
require_once(dirname(__FILE__, 4) . '/config.php');
require_once(__DIR__ . '/lib.php');

$code = (string)($_GET['c'] ?? '');
$type = (string)($_GET['t'] ?? '');
$TourId = preg_match('/^[A-Za-z0-9_.-]{1,20}$/', $code) ? intval(getIdFromCode($code)) : 0;
$tour = $TourId ? sr_tour($TourId) : null;
$img = ($tour and sr_closed_reason($tour, sr_settings($TourId)) !== 'closed') ? sr_tour_image($TourId, $type, $type === 'B' ? 1600 : 600, $type === 'B' ? 600 : 300) : null;

header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
if (!$img) {
	http_response_code(404);
	exit;
}
// the URL carries the image size (v=…), so a new image gets a new URL
header_remove('Pragma');   // IANSEO's Globals.inc.php sends no-cache headers
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 86400) . ' GMT');
header('Cache-Control: public, max-age=86400');
header('Content-Type: ' . $img[0]);
header('Content-Length: ' . strlen($img[1]));
echo $img[1];
