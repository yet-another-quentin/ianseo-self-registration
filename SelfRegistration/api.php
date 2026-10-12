<?php
/*
 * Self registration — JSON API of the administration page.
 * Same rights as Partecipants/ListLoad.php; Turnstile keys and module updates require administrator rights.
 */
require_once(dirname(__FILE__, 4) . '/config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/security.php');

if (!CheckTourSession()) JsonOut(array('error' => sr_t('noTournament')));
if (!hasFullACL(AclParticipants, 'pAdvancedEntries', AclReadWrite)) JsonOut(array('error' => sr_t('forbidden')));
// JSON body only: a cross-site form cannot send it
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' or stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) JsonOut(array('error' => sr_t('invalid')));

$TourId = intval($_SESSION['TourId']);
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) JsonOut(array('error' => sr_t('invalid')));
$action = (string)($in['action'] ?? '');

function sr_require_admin() {
	if (!hasFullACL(AclRoot, '', AclReadWrite)) JsonOut(array('error' => sr_t('adminOnly')));
}

try {
	sr_ensure_schema();
	switch ($action) {
		case 'context':
			JsonOut(sr_context($TourId));

		case 'settings':
			$s = is_array($in['settings'] ?? null) ? $in['settings'] : array();
			$sessions = array_column(sr_sessions($TourId), 'order');
			$divisions = array_column(sr_divisions($TourId), 'id');
			$quotas = array();
			foreach ((array)($s['quotas'] ?? array()) as $ses => $divs) {
				if (!in_array(intval($ses), $sessions, true) or !is_array($divs)) continue;
				foreach ($divs as $div => $max) {
					if (in_array((string)$div, $divisions, true) and intval($max) > 0) $quotas[intval($ses)][(string)$div] = min(9999, intval($max));
				}
			}
			$deadline = (string)($s['deadline'] ?? '');
			sr_param_set($TourId, 'settings', array(
				'open' => !empty($s['open']),
				'deadline' => sr_valid_date($deadline) ? $deadline : '',
				'sessions' => array_values(array_intersect($sessions, array_map('intval', (array)($s['sessions'] ?? array())))),
				'divisions' => array_values(array_intersect($divisions, array_map('strval', (array)($s['divisions'] ?? array())))),
				'quotas' => $quotas ?: new stdClass(),
				'allowFace' => !empty($s['allowFace']),
				'mails' => array_map(function ($k) use ($s) { return !empty($s['mails'][$k]); },
					array('received' => 'received', 'validated' => 'validated', 'refused' => 'refused', 'organiser' => 'organiser')),
			));
			JsonOut(sr_context($TourId));

		case 'turnstile':
			sr_require_admin();
			$keys = sr_turnstile_keys();
			$site = trim((string)($in['site'] ?? ''));
			$secret = trim((string)($in['secret'] ?? ''));
			if ($site !== '' and !preg_match('/^[A-Za-z0-9_-]{10,100}$/', $site)) JsonOut(array('error' => sr_t('invalid')));
			if ($secret !== '' and !preg_match('/^[A-Za-z0-9_-]{10,100}$/', $secret)) JsonOut(array('error' => sr_t('invalid')));
			// an empty secret keeps the stored one (it is never sent back to the page), unless the keys are cleared
			sr_param_set(0, 'turnstile', array('site' => $site, 'secret' => $site === '' ? '' : ($secret !== '' ? $secret : $keys['secret'])));
			sr_param_set(0, 'trustCf', !empty($in['trustCf']));
			JsonOut(sr_context($TourId));

		case 'validate':
			// lines for Partecipants/ListLoad.php; the requests are marked validated
			JsonOut(sr_validate($TourId, $in['ids'] ?? array()));

		case 'refuse':
			sr_set_status($TourId, $in['ids'] ?? array(), 2);
			sr_notify_refused($TourId, $in['ids'] ?? array());
			JsonOut(sr_context($TourId));

		case 'reset':
			sr_set_status($TourId, $in['ids'] ?? array(), 0);
			JsonOut(sr_context($TourId));

		case 'purge':
			$n = sr_purge_processed($TourId, intval($in['days'] ?? 30));
			JsonOut(array('purged' => $n) + sr_context($TourId));

		case 'update-check':
			sr_require_admin();
			JsonOut(sr_update_check(!empty($in['force'])));

		case 'update-channel':
			sr_require_admin();
			if (!in_array($in['channel'] ?? '', array('stable', 'nightly'), true)) JsonOut(array('error' => sr_t('channelInvalid')));
			sr_param_set(0, 'channel', $in['channel']);
			JsonOut(array('ok' => true, 'channel' => $in['channel']));

		case 'update-install':
			sr_require_admin();
			JsonOut(sr_update_install());
	}
	JsonOut(array('error' => sr_t('unknownAction')));
} catch (Throwable $e) {
	JsonOut(array('error' => $e->getMessage()));
}
