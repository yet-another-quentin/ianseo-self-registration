<?php
/*
 * Self registration — PUBLIC JSON API of the form (no IANSEO login).
 * Actions: verify (license + birth date + Turnstile), offer (refresh places), submit (pending requests).
 * Errors are i18n keys of i18n.js ({error: key, params}) translated by register.js.
 * The visitor never gets an IANSEO competition session: the competition comes from its code (c).
 */
require_once(__DIR__ . '/security.php');
sr_session_ini();
$SKIP_AUTH = true;
require_once(dirname(__FILE__, 4) . '/config.php');
require_once(__DIR__ . '/lib.php');

// Books the requested sessions (one pending request per session). Caller holds the competition lock.
function sr_book($tour, $archer, $settings, $req, $comment) {
	$TourId = intval($tour->ToId);
	$offer = sr_archer_offer($tour, $archer, $settings);
	$division = null;
	foreach ($offer['divisions'] as $d) if ($d['id'] === $req['division']) $division = $d;
	$err = sr_check_request($req, array(
		'divisions' => array_column($offer['divisions'], 'id'),
		'faces' => $division ? array_column($division['faces'], 'id') : array(),
		'allowFace' => $settings['allowFace'],
		'sessions' => $division ? array_column($division['sessions'], 'order') : array(),
	));
	if ($err) return array('error' => $err);
	$full = sr_first_unavailable($req['sessions'], $division['sessions']);
	if ($full) return array('error' => 'err.sessionUnavailable', 'params' => $full, 'offer' => $offer);

	$face = $settings['allowFace'] ? intval($req['face']) : intval($division['faces'][0]['id'] ?? 0);
	$group = bin2hex(random_bytes(8));
	$now = StrSafe_DB(date('Y-m-d H:i:s'));
	foreach (array_map('intval', $req['sessions']) as $ses) {
		safe_w_sql("insert into SelfRegRequests set SrTournament=$TourId, SrGroup=" . StrSafe_DB($group)
			. ", SrCode=" . StrSafe_DB($archer['code']) . ", SrDivision=" . StrSafe_DB($division['id'])
			. ", SrClass=" . StrSafe_DB($division['class']['id']) . ", SrFace=$face, SrSession=$ses"
			. ", SrEmail=" . StrSafe_DB($req['email']) . ", SrComment=" . StrSafe_DB($comment)
			. ", SrStatus=0, SrCreated=$now");
	}
	return array('ok' => true, 'reference' => strtoupper(substr($group, 0, 8)), 'sessions' => array_map('intval', $req['sessions']));
}

try {
	sr_ensure_schema();
	sr_api_guard();

	$in = json_decode(file_get_contents('php://input'), true);
	if (!is_array($in)) sr_json(array('error' => 'err.request'), 400);
	$action = (string)($in['action'] ?? '');

	// competition: same answer for unknown and closed, so codes cannot be probed
	$code = (string)($in['c'] ?? '');
	$TourId = preg_match('/^[A-Za-z0-9_.-]{1,20}$/', $code) ? intval(getIdFromCode($code)) : 0;
	$tour = $TourId ? sr_tour($TourId) : null;
	$settings = $tour ? sr_settings($TourId) : null;
	if (!$tour or ($closed = sr_closed_reason($tour, $settings)) === 'closed') sr_json(array('error' => 'err.closed'), 404);
	if ($closed) sr_json(array('error' => 'err.' . $closed));
	$keys = sr_turnstile_keys();
	if ($keys['site'] === '' or $keys['secret'] === '' or !(sr_is_https() or sr_is_local())) sr_json(array('error' => 'err.unavailable'));

	$ip = sr_client_ip();

	switch ($action) {
		case 'verify':
			if (sr_rate_count('verify', 'ip', $ip, 900) >= SR_LIMIT_VERIFY_IP) sr_json(array('error' => 'err.rate'), 429);
			$license = sr_norm_license($in['license'] ?? '');
			$dob = (string)($in['dob'] ?? '');
			if (!sr_valid_license($license)) sr_json(array('error' => 'err.licenseFormat'));
			if (!sr_valid_date($dob)) sr_json(array('error' => 'err.dobFormat'));
			$key = sr_rate_key($license);
			if (sr_rate_count('verify', 'key', $key, 3600, true) >= SR_LIMIT_FAIL_LICENSE) sr_json(array('error' => 'err.rate'), 429);
			if (!sr_turnstile_verify($in['captcha'] ?? '', $ip)) {
				sr_rate_log($TourId, 'verify', $ip, '', false);
				sr_json(array('error' => 'err.captcha'));
			}
			$archer = sr_lookup($tour, $license, $dob);
			sr_rate_log($TourId, 'verify', $ip, $key, (bool)$archer);
			if (!$archer) sr_json(array('error' => 'err.identity'));
			if (!$archer['valid']) sr_json(array('error' => 'err.licenseStatus'));
			sr_set_verified($TourId, $archer);
			// falls through: answer with the offer

		case 'offer':
			$archer = sr_verified($TourId);
			if (!$archer) sr_json(array('error' => 'err.expired'));
			$offer = sr_archer_offer($tour, $archer, $settings);
			if (!$offer['divisions']) sr_json(array('error' => 'err.noDivision'));
			sr_json(array(
				// only what the form needs: no birth date, no sex
				'archer' => array('code' => $archer['code'], 'lastName' => $archer['lastName'], 'firstName' => $archer['firstName'], 'club' => $archer['club']),
				'offer' => $offer,
				'allowFace' => $settings['allowFace'],
				'ttl' => SR_VERIFY_TTL,
			));

		case 'submit':
			if (sr_rate_count('submit', 'ip', $ip, 3600) >= SR_LIMIT_SUBMIT_IP) sr_json(array('error' => 'err.rate'), 429);
			$archer = sr_verified($TourId);   // the license sent by the client, if any, is ignored
			if (!$archer) sr_json(array('error' => 'err.expired'));

			$sessions = is_array($in['sessions'] ?? null) ? array_values($in['sessions']) : array();
			$req = array(
				'division' => (string)($in['division'] ?? ''),
				'face' => intval($in['face'] ?? 0),
				'sessions' => $sessions,
				'email' => trim((string)($in['email'] ?? '')),
				'consent' => !empty($in['consent']),
			);
			$comment = sr_clean_text($in['comment'] ?? '', 500);

			// places are checked again and booked under a per-competition lock: two visitors cannot take the last place.
			// No sr_json() (exit) while the lock is held: the answer is sent after release_lock().
			$lock = StrSafe_DB('selfreg_' . $TourId);
			if (!intval(safe_fetch(safe_w_sql("select get_lock($lock, 5) as l"))->l)) sr_json(array('error' => 'err.busy'), 503);
			try {
				$answer = sr_book($tour, $archer, $settings, $req, $comment);
			} finally {
				safe_w_sql("select release_lock($lock)");
			}
			if (!empty($answer['ok'])) {
				sr_rate_log($TourId, 'submit', $ip, sr_rate_key($archer['code']), true);
				sr_clear_verified($TourId);
			}
			sr_json($answer);
	}
	sr_json(array('error' => 'err.request'), 400);
} catch (Throwable $e) {
	error_log('SelfRegistration: ' . $e->getMessage());
	sr_json(array('error' => 'err.server'), 500);
}
