<?php
/*
 * Self registration — PUBLIC form (no IANSEO login): register.php?c=<competition code>.
 * Stand-alone HTML page (no IANSEO template or menu) with a strict CSP: no inline script, the context is a JSON
 * data block read by register.js. Without a code, lists the competitions open for registration.
 */
require_once(__DIR__ . '/security.php');
sr_session_ini();
$SKIP_AUTH = true;
require_once(dirname(__FILE__, 4) . '/config.php');
require_once(__DIR__ . '/lib.php');

sr_ensure_schema();
sr_security_headers(true);
header('Content-Type: text/html; charset=UTF-8');

$keys = sr_turnstile_keys();
$ctx = array(
	'lang' => sr_lang(true),
	'csrf' => sr_csrf_token(),
	'turnstileSite' => $keys['site'],
	'state' => 'list',
	'tournaments' => array(),
	'tour' => null,
);

$code = (string)($_GET['c'] ?? '');
if ($code === '') {
	$ctx['tournaments'] = sr_open_tournaments();
} else {
	$TourId = preg_match('/^[A-Za-z0-9_.-]{1,20}$/', $code) ? intval(getIdFromCode($code)) : 0;
	$tour = $TourId ? sr_tour($TourId) : null;
	$closed = $tour ? sr_closed_reason($tour, sr_settings($TourId)) : 'closed';
	if ($closed === 'closed') {
		// unknown and closed competitions look the same
		http_response_code(404);
		$ctx['state'] = 'closed';
	} else {
		$ctx['tour'] = sr_tour_public($tour);
		// schedule of the sessions open for registration (places are only shown once the archer is identified)
		$ctx['tour']['sessions'] = array_map(function ($s) {
			return array('order' => $s['order'], 'name' => $s['name'], 'start' => $s['start']);
		}, sr_offer($TourId, sr_settings($TourId))['sessions']);
		if ($closed) {
			$ctx['state'] = $closed;
		} elseif ($keys['site'] === '' or $keys['secret'] === '' or !(sr_is_https() or sr_is_local())) {
			$ctx['state'] = 'unavailable';
		} else {
			$ctx['state'] = 'form';
		}
	}
}
$lang = $ctx['lang'];
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?php echo htmlspecialchars($ctx['tour']['name'] ?? ($lang === 'fr' ? 'Inscriptions en ligne' : 'Online registration'), ENT_QUOTES); ?></title>
<link rel="stylesheet" href="<?php echo sr_asset('register.css'); ?>">
<script type="application/json" id="sr-ctx"><?php echo json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<script src="<?php echo sr_asset('i18n.js'); ?>"></script>
<script src="<?php echo sr_asset('register.js'); ?>" defer></script>
<?php if ($ctx['state'] === 'form') { ?>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit" async defer></script>
<?php } ?>
</head>
<body>
<main id="sr-app">
	<noscript><?php echo $lang === 'fr' ? 'JavaScript est nécessaire pour s\'inscrire.' : 'JavaScript is required to register.'; ?></noscript>
</main>
</body>
</html>
