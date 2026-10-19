<?php
/*
 * Self registration — pure logic, without database or IANSEO: input checks, session availability and quotas,
 * "List load" lines. Tested by tests/core.test.php.
 */

// French license: 6 or 7 digits + 1 letter (spaces, dots and dashes are ignored).
function sr_norm_license($s) {
	return strtoupper(preg_replace('/[\s.\-]/', '', (string)$s));
}

function sr_valid_license($s) {
	return (bool)preg_match('/^[0-9]{6,7}[A-Z]$/', sr_norm_license($s));
}

// Old 6-digit licenses now carry a leading 0: both forms name the same archer, whichever one the archer types
// or the lookup table holds.
function sr_license_variants($s) {
	$s = sr_norm_license($s);
	if (preg_match('/^[0-9]{6}[A-Z]$/', $s)) return array('0' . $s, $s);
	if (preg_match('/^0([0-9]{6}[A-Z])$/', $s, $m)) return array($s, $m[1]);
	return array($s);
}

// Real calendar date, yyyy-mm-dd.
function sr_valid_date($s) {
	if (!is_string($s) or !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) return false;
	return checkdate(intval($m[2]), intval($m[3]), intval($m[1])) and intval($m[1]) > 1900;
}

// Same rule as the ##EMAIL## line of Partecipants/ListLoad.php (no "+"), on top of PHP's own check.
function sr_valid_email($s) {
	$s = (string)$s;
	return strlen($s) <= 100 and filter_var($s, FILTER_VALIDATE_EMAIL) !== false
		and preg_match('/^[a-z0-9._#-]+@[a-z0-9._-]+$/i', $s);
}

// Free text kept in the module only (comment): no tags, no control characters, limited length.
function sr_clean_text($s, $max = 500) {
	$s = strip_tags((string)$s);
	$s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
	return mb_substr(trim($s), 0, $max);
}

/**
 * Public address of the form chosen by the administrator (a short sub-domain served by the reverse proxy, README):
 * https (http for localhost only), no credentials, query or fragment. Returns it with a final "/" — the competition
 * code is appended to it — or null; '' clears the setting.
 */
function sr_valid_form_url($s) {
	$s = trim((string)$s);
	if ($s === '') return '';
	if (strlen($s) > 200 or !preg_match('#^https?://[^\s<>"\'\\\\]+$#i', $s)) return null;
	$u = parse_url($s);
	if (!$u or empty($u['host']) or isset($u['user']) or isset($u['pass']) or isset($u['query']) or isset($u['fragment'])) return null;
	$scheme = strtolower($u['scheme']);
	$host = strtolower($u['host']);
	if ($scheme === 'http' and $host !== 'localhost' and $host !== '127.0.0.1') return null;
	return $scheme . '://' . $host . (isset($u['port']) ? ':' . $u['port'] : '') . rtrim($u['path'] ?? '', '/') . '/';
}

// Value inserted in a "List load" line: ListLoad splits on tab, newline and ";" — none may get through.
function sr_line_value($s) {
	return trim(str_replace(array("\t", "\r", "\n", ';'), ' ', (string)$s));
}

/**
 * Availability of each session for a division.
 * $sessions: [{order, capacity}]
 * $used:     [session => ['total' => n, 'div' => [DivId => n]]]  (entries + pending requests)
 * $quotas:   [session => [DivId => max]]  (missing or 0 = no quota)
 * $waitlist: the competition offers a waiting list: a full session (or reached quota) can still be requested.
 * Returns [{order, capacity, free, quota, quotaFree, available, reason, waitlist}] with reason '' | 'full' | 'quota'.
 */
function sr_availability($sessions, $used, $quotas, $div, $waitlist = false) {
	$out = array();
	foreach ($sessions as $s) {
		$o = intval($s['order']);
		$total = intval($used[$o]['total'] ?? 0);
		$inDiv = intval($used[$o]['div'][$div] ?? 0);
		$free = max(0, intval($s['capacity']) - $total);
		$quota = intval($quotas[$o][$div] ?? 0);
		$quotaFree = $quota > 0 ? max(0, $quota - $inDiv) : null;
		$reason = $free <= 0 ? 'full' : ($quotaFree === 0 ? 'quota' : '');
		$out[] = array(
			'order' => $o, 'capacity' => intval($s['capacity']), 'free' => $free,
			'quota' => $quota, 'quotaFree' => $quotaFree,
			'available' => $reason === '', 'reason' => $reason,
			'waitlist' => $waitlist and $reason !== '',
		);
	}
	return $out;
}

/**
 * Checks a submission against what the competition offers. Returns an i18n error key, or '' when valid.
 * $req:   ['division', 'face', 'sessions' => [n…], 'email', 'consent']
 * $offer: ['divisions' => [DivId…], 'faces' => [TfId…], 'allowFace' => bool, 'sessions' => [n…]]
 */
function sr_check_request($req, $offer) {
	if (!in_array((string)($req['division'] ?? ''), array_map('strval', $offer['divisions']), true)) return 'err.division';
	if (!empty($offer['allowFace']) and !in_array(intval($req['face'] ?? 0), array_map('intval', $offer['faces']), true)) return 'err.face';
	$sessions = $req['sessions'] ?? array();
	if (!is_array($sessions) or !$sessions) return 'err.noSession';
	foreach ($sessions as $s) {
		if (!is_numeric($s) or !in_array(intval($s), array_map('intval', $offer['sessions']), true)) return 'err.session';
	}
	if (count(array_unique(array_map('intval', $sessions))) != count($sessions)) return 'err.session';
	if (!sr_valid_email($req['email'] ?? '')) return 'err.email';
	if (empty($req['consent'])) return 'err.consent';
	return '';
}

/**
 * Splits the requested sessions into booked ones and waiting-list ones.
 * A session with a free place is booked, even if the archer expected the waiting list. A full session goes to the
 * waiting list only when the archer accepted it ($accepted, the sessions shown as "waiting list" in the form):
 * a session that filled up while the archer was choosing is reported, not silently put on the list.
 * $availability: result of sr_availability() for the requested division.
 * Returns ['book' => [n…], 'wait' => [n…]] or ['unavailable' => ['order', 'reason']].
 */
function sr_plan_sessions($sessions, $accepted, $availability) {
	$byOrder = array();
	foreach ($availability as $a) $byOrder[$a['order']] = $a;
	$accepted = array_map('intval', (array)$accepted);
	$out = array('book' => array(), 'wait' => array());
	foreach ($sessions as $s) {
		$s = intval($s);
		$a = $byOrder[$s] ?? null;
		if ($a and $a['available']) $out['book'][] = $s;
		elseif ($a and !empty($a['waitlist']) and in_array($s, $accepted, true)) $out['wait'][] = $s;
		else return array('unavailable' => array('order' => $s, 'reason' => $a ? $a['reason'] : 'full'));
	}
	return $out;
}

const SR_PRIORITIES = array(1, 0, -1);   // high, normal, low

/**
 * Waiting list of a session, in the order places are offered: priority (high first), then first come.
 * $rows: [{priority, created, id}…]
 */
function sr_waitlist_sort($rows) {
	usort($rows, function ($a, $b) {
		return array(-intval($a['priority']), (string)$a['created'], intval($a['id']))
			<=> array(-intval($b['priority']), (string)$b['created'], intval($b['id']));
	});
	return $rows;
}

/**
 * "List load" lines for validated requests (Partecipants/ListLoad.php, without "update": every line inserts an entry):
 * - one 4-column line per session (license, session, division, class): IANSEO takes name, sex, birth date and club
 *   from its license database, and creates one entry per session;
 * - then, once the entries exist (ListLoad processes lines in order), ##TARGET## and ##EMAIL##.
 *   ListLoad's ##TARGET## query crosses ALL the archer's entries (same code, division, class) with the matching faces
 *   ordered by TfId, and updates the entry of row n ("LIMIT n,1"). An archer with N such entries after the import
 *   needs N lines, n = faceIndex×N … faceIndex×N+N−1, to get the face on each of them.
 * $requests: [{code, session, division, class, faceIndex (int|null), email}]
 * $existing: ["code|division|class" => entries already in the competition]
 */
function sr_build_lines($requests, $existing = array()) {
	$entries = array();
	$count = array();
	$faces = array();
	$emails = array();
	foreach ($requests as $r) {
		$code = sr_line_value($r['code']);
		$div = sr_line_value($r['division']);
		$cl = sr_line_value($r['class']);
		$entries[] = implode("\t", array($code, intval($r['session']), $div, $cl));
		$key = "$code|$div|$cl";
		$count[$key] = ($count[$key] ?? 0) + 1;
		if ($r['faceIndex'] !== null and $r['faceIndex'] >= 0) $faces[$key] = array($code, $div, $cl, intval($r['faceIndex']));
		if (!empty($r['email']) and sr_valid_email($r['email'])) {
			$emails[$code] = implode("\t", array('##EMAIL##', $code, sr_line_value($r['email'])));
		}
	}
	$targets = array();
	foreach ($faces as $key => list($code, $div, $cl, $index)) {
		$n = $count[$key] + intval($existing[$key] ?? 0);
		for ($i = 0; $i < $n; $i++) $targets[] = implode("\t", array('##TARGET##', $code, $div, $cl, $index * $n + $i));
	}
	return array_merge($entries, $targets, array_values($emails));
}
