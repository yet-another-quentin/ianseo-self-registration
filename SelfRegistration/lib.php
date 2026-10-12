<?php
/*
 * Self registration — PHP helpers shared by the admin page, the public form and their APIs.
 * The module never writes to IANSEO's archer tables (Entries, Qualifications…): validated requests are imported
 * through Partecipants/ListLoad.php by the administrator's browser. It only writes to its own tables
 * (SelfRegRequests, SelfRegAttempts) and to ModulesParameters.
 * Every function takes the competition id explicitly: the public form has no IANSEO competition session.
 */

require_once(__DIR__ . '/core.php');

const SR_MODULE = 'SelfRegistration';
const SR_SCHEMA = 2;

// URL of a module asset with a cache buster: version + file time (the version stays 0.0.0-dev in development).
function sr_asset($file) {
	return $file . '?v=' . rawurlencode(sr_version_info()['version'] . '-' . intval(@filemtime(__DIR__ . '/' . $file)));
}

function sr_version_info() {
	$v = @json_decode(@file_get_contents(__DIR__ . '/version.json'), true);
	return is_array($v) ? $v : array('version' => '0.0.0', 'repo' => '', 'asset' => SR_MODULE . '.zip');
}

// ---------- language ----------

// 'fr' or 'en'. For the public pages (no IANSEO login): ?Lang=, then IANSEO's language cookie, then the browser's
// languages by primary subtag ("fr-FR" counts as French; IANSEO's SelectLanguage() only knows exact codes, and
// prefers the competition's print language). Elsewhere: IANSEO's language.
function sr_lang($public = false) {
	if ($public) {
		foreach (array($_REQUEST['Lang'] ?? '', $_COOKIE['UseLanguage'] ?? '') as $l) {
			if (preg_match('/^(fr|en)/i', $l, $m)) return strtolower($m[1]);
		}
		foreach (explode(',', (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) as $l) {
			$l = strtolower(trim(explode(';', $l)[0]));
			if (preg_match('/^(fr|en)\b/', $l, $m)) return $m[1];
		}
		return 'en';
	}
	return preg_match('/^fr/i', (string)SelectLanguage()) ? 'fr' : 'en';
}

// Server-side messages (admin API, menu, updates). The pages have their own dictionaries (i18n.js).
function sr_t($key, $params = array()) {
	static $dict = array(
		'en' => array(
			'menu' => 'Online registration',
			'noTournament' => 'No competition open',
			'forbidden' => 'Insufficient rights',
			'adminOnly' => 'Administrator only',
			'unknownAction' => 'Unknown action',
			'invalid' => 'Invalid data',
			'channelInvalid' => 'Invalid channel',
			'repoMissing' => 'Repository not configured',
			'connection' => 'connection failed',
			'connectionFopen' => 'connection failed (curl missing, allow_url_fopen or SSL?)',
			'githubUnexpected' => 'unexpected GitHub answer',
			'noRelease' => 'no release published yet',
			'noUpdate' => 'No new version',
			'manual' => ' — Manual update: download {asset} and unzip it into Modules/Custom/.',
			'zipMissing' => 'PHP "zip" extension missing',
			'notWritable' => 'Module folder not writable by the web server',
			'downloadFailed' => 'Download failed: {error}',
			'zipUnreadable' => 'Unreadable archive',
			'zipRefused' => 'Archive refused: unexpected path "{name}"',
			'zipIncomplete' => 'Incomplete archive',
			'versionInvalid' => 'Invalid version.json in the archive',
			'backupFailed' => 'Backup failed',
			'copyFailed' => 'Cannot copy the new files: previous version restored',
		),
		'fr' => array(
			'menu' => 'Inscriptions en ligne',
			'noTournament' => 'Aucune compétition ouverte',
			'forbidden' => 'Droits insuffisants',
			'adminOnly' => 'Réservé à l\'administrateur',
			'unknownAction' => 'Action inconnue',
			'invalid' => 'Données invalides',
			'channelInvalid' => 'Canal invalide',
			'repoMissing' => 'Dépôt non configuré',
			'connection' => 'connexion impossible',
			'connectionFopen' => 'connexion impossible (curl absent, allow_url_fopen ou SSL ?)',
			'githubUnexpected' => 'réponse GitHub inattendue',
			'noRelease' => 'aucune version publiée pour le moment',
			'noUpdate' => 'Aucune nouvelle version',
			'manual' => ' — Mise à jour manuelle : télécharger {asset} et décompresser son contenu dans Modules/Custom/.',
			'zipMissing' => 'Extension PHP « zip » absente',
			'notWritable' => 'Dossier du module non modifiable par le serveur web',
			'downloadFailed' => 'Téléchargement impossible : {error}',
			'zipUnreadable' => 'Archive illisible',
			'zipRefused' => 'Archive refusée : chemin inattendu « {name} »',
			'zipIncomplete' => 'Archive incomplète',
			'versionInvalid' => 'version.json invalide dans l\'archive',
			'backupFailed' => 'Sauvegarde impossible',
			'copyFailed' => 'Copie des nouveaux fichiers impossible : ancienne version restaurée',
		),
	);
	$s = $dict[sr_lang()][$key] ?? $dict['en'][$key] ?? $key;
	foreach ($params as $k => $v) $s = str_replace('{' . $k . '}', (string)$v, $s);
	return $s;
}

// ---------- parameters ----------
// Stored like setModuleParameter() does (serialized), but the value is always a JSON string, so that no PHP object
// is ever unserialized. Direct SQL: setModuleParameter() replaces an empty TourId with the open competition, and
// the public form has no open competition. TourId 0 = installation-wide.

function sr_param_get($TourId, $param, $default = null) {
	$q = safe_r_sql("select MpValue from ModulesParameters where MpModule=" . StrSafe_DB(SR_MODULE)
		. " and MpParameter=" . StrSafe_DB($param) . " and MpTournament=" . intval($TourId));
	if ($r = safe_fetch($q)) {
		$v = @unserialize($r->MpValue, array('allowed_classes' => false));
		$d = json_decode(is_string($v) ? $v : $r->MpValue, true);
		return $d === null ? $default : $d;
	}
	return $default;
}

function sr_param_set($TourId, $param, $value) {
	$v = StrSafe_DB(serialize(json_encode($value, JSON_UNESCAPED_UNICODE)));
	safe_w_sql("insert into ModulesParameters set MpModule=" . StrSafe_DB(SR_MODULE)
		. ", MpParameter=" . StrSafe_DB($param) . ", MpTournament=" . intval($TourId) . ", MpValue=$v"
		. " on duplicate key update MpValue=$v");
}

function sr_settings($TourId) {
	$s = sr_param_get($TourId, 'settings', array());
	if (!is_array($s)) $s = array();
	$quotas = array();
	foreach ((array)($s['quotas'] ?? array()) as $ses => $divs) {
		foreach ((array)$divs as $div => $max) {
			if (intval($max) > 0) $quotas[intval($ses)][(string)$div] = intval($max);
		}
	}
	return array(
		'open' => !empty($s['open']),
		'deadline' => (isset($s['deadline']) and sr_valid_date($s['deadline'])) ? $s['deadline'] : '',
		'sessions' => array_values(array_map('intval', (array)($s['sessions'] ?? array()))),
		'divisions' => array_values(array_map('strval', (array)($s['divisions'] ?? array()))),
		'quotas' => $quotas,
		'allowFace' => !empty($s['allowFace']),
		// emails sent through the Mailer module, when installed and configured (all on by default)
		'mails' => array(
			'received' => !isset($s['mails']['received']) || !empty($s['mails']['received']),
			'validated' => !isset($s['mails']['validated']) || !empty($s['mails']['validated']),
			'refused' => !isset($s['mails']['refused']) || !empty($s['mails']['refused']),
			'organiser' => !isset($s['mails']['organiser']) || !empty($s['mails']['organiser']),
		),
	);
}

// ---------- schema (module tables only) ----------

function sr_ensure_schema() {
	static $done = false;
	if ($done) return;
	$done = true;
	$version = intval(sr_param_get(0, 'schema', 0));
	if ($version >= SR_SCHEMA) return;
	safe_w_sql("CREATE TABLE IF NOT EXISTS `SelfRegRequests` (
		`SrId` int unsigned NOT NULL AUTO_INCREMENT,
		`SrTournament` int unsigned NOT NULL,
		`SrGroup` varchar(16) NOT NULL,
		`SrCode` varchar(25) NOT NULL,
		`SrDivision` varchar(4) NOT NULL,
		`SrClass` varchar(6) NOT NULL,
		`SrFace` int NOT NULL DEFAULT 0,
		`SrSession` tinyint unsigned NOT NULL,
		`SrEmail` varchar(100) NOT NULL DEFAULT '',
		`SrComment` varchar(500) NOT NULL DEFAULT '',
		`SrStatus` tinyint NOT NULL DEFAULT 0,
		`SrCreated` datetime NOT NULL,
		`SrDecided` datetime NULL,
		PRIMARY KEY (`SrId`),
		KEY `SrTourStatus` (`SrTournament`, `SrStatus`, `SrSession`),
		KEY `SrTourCode` (`SrTournament`, `SrCode`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	safe_w_sql("CREATE TABLE IF NOT EXISTS `SelfRegAttempts` (
		`SaId` int unsigned NOT NULL AUTO_INCREMENT,
		`SaTournament` int unsigned NOT NULL,
		`SaIp` varchar(45) NOT NULL,
		`SaKey` varchar(64) NOT NULL DEFAULT '',
		`SaType` varchar(10) NOT NULL,
		`SaOk` tinyint NOT NULL DEFAULT 0,
		`SaTime` datetime NOT NULL,
		PRIMARY KEY (`SaId`),
		KEY `SaIp` (`SaType`, `SaIp`, `SaTime`),
		KEY `SaKey` (`SaType`, `SaKey`, `SaTime`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	if ($version < 2) {
		// v2: language of the form (emails to the archer) and emails sent (1 = validated, 2 = refused); 1060 = column exists
		safe_w_sql("ALTER TABLE `SelfRegRequests` ADD `SrLang` varchar(5) NOT NULL DEFAULT ''", false, array(1060));
		safe_w_sql("ALTER TABLE `SelfRegRequests` ADD `SrNotified` tinyint NOT NULL DEFAULT 0", false, array(1060));
	}
	sr_param_set(0, 'schema', SR_SCHEMA);
}

// IANSEO's tour_delete() does not know the module tables: rows of deleted competitions are removed here.
function sr_purge_orphans() {
	safe_w_sql("delete from SelfRegRequests where SrTournament not in (select ToId from Tournament)");
	safe_w_sql("delete from SelfRegAttempts where SaTime < " . StrSafe_DB(date('Y-m-d H:i:s', time() - 86400)));
	safe_w_sql("delete from ModulesParameters where MpModule=" . StrSafe_DB(SR_MODULE)
		. " and MpTournament>0 and MpTournament not in (select ToId from Tournament)");
}

// ---------- competition ----------

// Competition row, without the image blobs (only their sizes, used to know if they exist and as cache key).
function sr_tour($TourId) {
	$q = safe_r_sql("select ToId, ToCode, ToName, ToComDescr, ToWhere, ToVenue, ToIocCode, ToWhenFrom, ToWhenTo,
		length(ToImgL) as ImgL, length(ToImgR) as ImgR, length(ToImgB) as ImgB
		from Tournament where ToId=" . intval($TourId));
	return safe_fetch($q);
}

// What the public form shows about a competition: details entered in IANSEO (Competition › Competition data)
// and the URLs of its images (left/right logos, bottom banner) served by register-logo.php.
function sr_tour_public($tour) {
	$images = array();
	foreach (array('L', 'R', 'B') as $t) {
		$size = intval($tour->{'Img' . $t});
		if ($size > 0) $images[$t] = 'register-logo.php?c=' . rawurlencode($tour->ToCode) . '&t=' . $t . '&v=' . $size;
	}
	return array(
		'code' => $tour->ToCode, 'name' => $tour->ToName, 'organiser' => (string)$tour->ToComDescr,
		'where' => (string)$tour->ToWhere, 'venue' => (string)$tour->ToVenue,
		'from' => $tour->ToWhenFrom, 'to' => $tour->ToWhenTo, 'images' => $images ?: new stdClass(),
	);
}

/**
 * Competition image (L: left logo, R: right logo, B: bottom banner) for the public form.
 * Re-encoded as PNG with GD (and reduced to $maxW × $maxH), so that only a real image ever leaves the server;
 * without GD, sent as-is only when it is a PNG, JPEG or GIF. Returns [mime, bytes] or null.
 */
function sr_tour_image($TourId, $type, $maxW = 1200, $maxH = 600) {
	if (!in_array($type, array('L', 'R', 'B'), true)) return null;
	$r = safe_fetch(safe_r_sql("select ToImg$type as img from Tournament where ToId=" . intval($TourId)));
	if (!$r or !strlen((string)$r->img)) return null;
	$data = $r->img;
	if (function_exists('imagecreatefromstring')) {
		$im = @imagecreatefromstring($data);
		if ($im === false) return null;
		$w = imagesx($im);
		$h = imagesy($im);
		$scale = min(1, $maxW / $w, $maxH / $h);
		if ($scale < 1) {
			$nw = max(1, intval($w * $scale));
			$nh = max(1, intval($h * $scale));
			$small = imagecreatetruecolor($nw, $nh);
			imagealphablending($small, false);
			imagesavealpha($small, true);
			imagecopyresampled($small, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
			imagedestroy($im);
			$im = $small;
		} else {
			imagesavealpha($im, true);
		}
		ob_start();
		imagepng($im);
		imagedestroy($im);
		return array('image/png', ob_get_clean());
	}
	$info = @getimagesizefromstring($data);
	if (!$info or !in_array($info['mime'], array('image/png', 'image/jpeg', 'image/gif'), true)) return null;
	return array($info['mime'], $data);
}

// Closed reason ('' when open): not opened, deadline passed, competition over.
function sr_closed_reason($tour, $settings) {
	$today = date('Y-m-d');
	if (!$settings['open']) return 'closed';
	if ($settings['deadline'] and $today > $settings['deadline']) return 'deadline';
	if ($tour->ToWhenTo and $today > $tour->ToWhenTo) return 'over';
	return '';
}

function sr_open_tournaments() {
	$out = array();
	$q = safe_r_sql("select MpTournament from ModulesParameters where MpModule=" . StrSafe_DB(SR_MODULE)
		. " and MpParameter='settings' and MpTournament>0");
	while ($r = safe_fetch($q)) {
		$tour = sr_tour($r->MpTournament);
		if ($tour and sr_closed_reason($tour, sr_settings($tour->ToId)) === '') {
			$out[] = sr_tour_public($tour);
		}
	}
	usort($out, function ($a, $b) { return strcmp($a['from'], $b['from']); });
	return $out;
}

// Qualification sessions; capacity = targets × archers per target, as in Partecipants/CheckSession.php.
function sr_sessions($TourId) {
	$out = array();
	$q = safe_r_sql("select SesOrder, SesName, SesTar4Session, SesAth4Target, SesDtStart from Session
		where SesTournament=" . intval($TourId) . " and SesType='Q' order by SesOrder");
	while ($r = safe_fetch($q)) {
		$out[] = array(
			'order' => intval($r->SesOrder),
			'name' => (string)$r->SesName,
			'start' => ($r->SesDtStart and substr($r->SesDtStart, 0, 4) != '0000') ? $r->SesDtStart : '',
			'capacity' => intval($r->SesTar4Session) * intval($r->SesAth4Target),
		);
	}
	return $out;
}

function sr_divisions($TourId) {
	$out = array();
	$q = safe_r_sql("select DivId, DivDescription, DivIsPara from Divisions
		where DivTournament=" . intval($TourId) . " and DivAthlete='1' order by DivViewOrder");
	while ($r = safe_fetch($q)) {
		$out[] = array('id' => $r->DivId, 'name' => $r->DivDescription, 'para' => intval($r->DivIsPara) == 1);
	}
	return $out;
}

// What the public form offers: open sessions and allowed divisions (empty setting = all).
function sr_offer($TourId, $settings) {
	$sessions = array_values(array_filter(sr_sessions($TourId), function ($s) use ($settings) {
		return in_array($s['order'], $settings['sessions'], true) and $s['capacity'] > 0;
	}));
	$divisions = array_values(array_filter(sr_divisions($TourId), function ($d) use ($settings) {
		return !$settings['divisions'] or in_array($d['id'], $settings['divisions'], true);
	}));
	return array('sessions' => $sessions, 'divisions' => $divisions);
}

// ---------- license database ----------

/**
 * License + birth date check. Same lookup as Partecipants/ListLoad.php (exact LueCode, competition IOC code,
 * ORDER BY LueDefault DESC), so the import finds the same archer. Returns null when unknown or when the birth date
 * does not match: callers must not tell the two cases apart.
 * Validity like Partecipants/SearchArcher.php: status 5 when the license expires before the competition.
 */
function sr_lookup($tour, $code, $dob) {
	$q = safe_r_sql("select * from LookUpEntries where LueCode=" . StrSafe_DB(sr_norm_license($code))
		. " and LueIocCode=" . StrSafe_DB($tour->ToIocCode) . " order by LueDefault desc limit 1");
	$r = safe_fetch($q);
	if (!$r or !$r->LueCtrlCode or $r->LueCtrlCode === '0000-00-00' or !hash_equals((string)$r->LueCtrlCode, (string)$dob)) return null;
	$status = intval($r->LueStatus);
	if ($r->LueStatusValidUntil and $r->LueStatusValidUntil != '0000-00-00' and $tour->ToWhenFrom > $r->LueStatusValidUntil) $status = 5;
	return array(
		'code' => strtoupper($r->LueCode),
		'lastName' => $r->LueFamilyName,
		'firstName' => $r->LueName,
		'sex' => intval($r->LueSex),
		'para' => intval($r->LueClassified) > 0,
		'dob' => $r->LueCtrlCode,
		'club' => $r->LueCoShort ?: $r->LueCoDescr,
		'status' => $status,
		'valid' => in_array($status, array(0, 1), true),
	);
}

/**
 * Class the archer will be entered in for a division: their age class, computed like ListLoad does
 * (calculateAgeClass() in Common/Lib/Fun_Entries.inc.php: age = year of the competition end − birth year,
 * classes ordered by ClViewOrder). ListLoad keeps a class that is one of the age classes, so the import agrees.
 * Returns ['id', 'name'] or null when the division has no class for this archer.
 */
function sr_class_for($tour, $dob, $sex, $div) {
	$age = intval(substr($tour->ToWhenTo, 0, 4)) - intval(substr($dob, 0, 4));
	$q = safe_r_sql("select distinct ClId, ClDescription from Classes
		inner join Divisions on DivTournament=ClTournament and DivAthlete=ClAthlete and DivId=" . StrSafe_DB($div) . "
		where ClTournament=" . intval($tour->ToId) . " and DivAthlete='1'
			and (ClDivisionsAllowed='' or find_in_set(DivId, ClDivisionsAllowed))
			and ClSex in (-1, " . intval($sex) . ")
			and ClAgeFrom<=$age and ClAgeTo>=$age
		order by ClViewOrder, DivViewOrder limit 1");
	$r = safe_fetch($q);
	return $r ? array('id' => $r->ClId, 'name' => $r->ClDescription) : null;
}

/**
 * Target faces for a division + class, ordered like getTargets() (Participants/getRows.php): the first one is the
 * default face; all non-default faces are kept as alternatives.
 */
function sr_faces($TourId, $div, $class) {
	$dc = StrSafe_DB(trim($div) . trim($class));
	$q = safe_r_sql("select TfId, TfName, TfDefault from TargetFaces
		where TfTournament=" . intval($TourId) . " and if(TfRegExp>'', $dc REGEXP TfRegExp, $dc like TfClasses)
		order by TfDefault desc, TfRegExp>'' desc, $dc = TfClasses desc,
			left(TfClasses,1)!='_' and left(TfClasses,1)!='%' desc, left(TfClasses,1)='_' desc, TfClasses desc, TfClasses='%'");
	$out = array();
	$hasDefault = false;
	while ($r = safe_fetch($q)) {
		if ($r->TfDefault) {
			if ($hasDefault) continue;
			$hasDefault = true;
		}
		$out[] = array('id' => intval($r->TfId), 'name' => get_text($r->TfName, 'Tournament', '', true));
	}
	return $out;
}

// Index of a face for the ##TARGET## line: same query as Partecipants/ListLoad.php (matching faces ordered by TfId).
function sr_face_index($TourId, $div, $class, $TfId) {
	$dc = StrSafe_DB($div . $class);
	$q = safe_r_sql("select TfId from TargetFaces where TfTournament=" . intval($TourId) . "
		and ($dc like TfClasses or if(TfRegExp!='', $dc REGEXP TfRegExp, false)) order by TfId");
	$i = 0;
	while ($r = safe_fetch($q)) {
		if (intval($r->TfId) === intval($TfId)) return $i;
		$i++;
	}
	return null;
}

// ---------- places ----------

/**
 * Places taken per session and division: archers already entered (any status, like IANSEO's own session check)
 * plus pending requests. Validated requests are counted through the entries they created.
 */
function sr_usage($TourId) {
	$TourId = intval($TourId);
	$used = array();
	$add = function ($ses, $div, $n) use (&$used) {
		$ses = intval($ses);
		if (!isset($used[$ses])) $used[$ses] = array('total' => 0, 'div' => array());
		$used[$ses]['total'] += $n;
		$used[$ses]['div'][$div] = ($used[$ses]['div'][$div] ?? 0) + $n;
	};
	$q = safe_r_sql("select QuSession, EnDivision, count(*) as n from Entries inner join Qualifications on QuId=EnId
		where EnTournament=$TourId and EnAthlete=1 and QuSession>0 group by QuSession, EnDivision");
	while ($r = safe_fetch($q)) $add($r->QuSession, $r->EnDivision, intval($r->n));
	$q = safe_r_sql("select SrSession, SrDivision, count(*) as n from SelfRegRequests
		where SrTournament=$TourId and SrStatus=0 group by SrSession, SrDivision");
	while ($r = safe_fetch($q)) $add($r->SrSession, $r->SrDivision, intval($r->n));
	return $used;
}

// Sessions in which this license already has an entry or a pending/validated request.
function sr_taken_sessions($TourId, $code) {
	$TourId = intval($TourId);
	$code = StrSafe_DB(sr_norm_license($code));
	$taken = array();
	$q = safe_r_sql("select distinct QuSession from Entries inner join Qualifications on QuId=EnId where EnTournament=$TourId and EnCode=$code");
	while ($r = safe_fetch($q)) $taken[intval($r->QuSession)] = true;
	$q = safe_r_sql("select distinct SrSession from SelfRegRequests where SrTournament=$TourId and SrCode=$code and SrStatus in (0, 1)");
	while ($r = safe_fetch($q)) $taken[intval($r->SrSession)] = true;
	return array_keys($taken);
}

// Everything the form shows once the archer is identified, for one division or all offered ones.
function sr_archer_offer($tour, $archer, $settings) {
	$offer = sr_offer($tour->ToId, $settings);
	$used = sr_usage($tour->ToId);
	$taken = sr_taken_sessions($tour->ToId, $archer['code']);
	$divisions = array();
	foreach ($offer['divisions'] as $d) {
		// para divisions only for archers with a para classification in the license database
		if ($d['para'] and empty($archer['para'])) continue;
		$class = sr_class_for($tour, $archer['dob'], $archer['sex'], $d['id']);
		if (!$class) continue;
		$avail = sr_availability($offer['sessions'], $used, $settings['quotas'], $d['id']);
		foreach ($avail as &$a) {
			if (in_array($a['order'], $taken, true)) { $a['available'] = false; $a['reason'] = 'already'; }
		}
		unset($a);
		$divisions[] = array(
			'id' => $d['id'], 'name' => $d['name'], 'para' => $d['para'],
			'class' => $class, 'faces' => sr_faces($tour->ToId, $d['id'], $class['id']),
			'sessions' => $avail,
		);
	}
	return array('sessions' => $offer['sessions'], 'divisions' => $divisions);
}

// ---------- requests (admin) ----------

function sr_requests($TourId) {
	$TourId = intval($TourId);
	$tour = sr_tour($TourId);
	$rows = array();
	$codes = array();
	$q = safe_r_sql("select * from SelfRegRequests where SrTournament=$TourId order by SrCreated, SrId");
	while ($r = safe_fetch($q)) {
		$rows[] = $r;
		$codes[$r->SrCode] = true;
	}
	$names = array();
	$entries = array();
	foreach (array_chunk(array_keys($codes), 200) as $chunk) {
		$in = implode(',', array_map('StrSafe_DB', $chunk));
		$q = safe_r_sql("select LueCode, LueFamilyName, LueName, LueCoShort, LueCoDescr from LookUpEntries
			where LueCode in ($in) and LueIocCode=" . StrSafe_DB($tour->ToIocCode) . " order by LueDefault");
		while ($r = safe_fetch($q)) $names[strtoupper($r->LueCode)] = array($r->LueFamilyName, $r->LueName, $r->LueCoShort ?: $r->LueCoDescr);
		$q = safe_r_sql("select EnCode, QuSession from Entries inner join Qualifications on QuId=EnId where EnTournament=$TourId and EnCode in ($in)");
		while ($r = safe_fetch($q)) $entries[strtoupper($r->EnCode) . '|' . intval($r->QuSession)] = true;
	}
	$faces = array();
	$q = safe_r_sql("select TfId, TfName from TargetFaces where TfTournament=$TourId");
	while ($r = safe_fetch($q)) $faces[intval($r->TfId)] = get_text($r->TfName, 'Tournament', '', true);

	$out = array();
	foreach ($rows as $r) {
		$code = strtoupper($r->SrCode);
		$n = $names[$code] ?? array('', '', '');
		$out[] = array(
			'id' => intval($r->SrId), 'group' => $r->SrGroup, 'code' => $code,
			'lastName' => $n[0], 'firstName' => $n[1], 'club' => $n[2],
			'division' => $r->SrDivision, 'class' => $r->SrClass,
			'face' => intval($r->SrFace), 'faceName' => $faces[intval($r->SrFace)] ?? '',
			'session' => intval($r->SrSession), 'email' => $r->SrEmail, 'comment' => $r->SrComment,
			// ISO 8601 with offset: IANSEO runs PHP in UTC, the browser shows local time
			'status' => intval($r->SrStatus), 'created' => date('c', strtotime($r->SrCreated)),
			'decided' => $r->SrDecided ? date('c', strtotime($r->SrDecided)) : null,
			'hasEntry' => isset($entries[$code . '|' . intval($r->SrSession)]),
		);
	}
	return $out;
}

/**
 * "List load" lines for pending requests $ids, and marks them validated. Requests whose archer already has an
 * entry in that session are only marked (no second entry).
 */
function sr_validate($TourId, $ids) {
	$TourId = intval($TourId);
	$ids = array_values(array_filter(array_map('intval', (array)$ids)));
	if (!$ids) return array('lines' => array(), 'ids' => array());
	$q = safe_r_sql("select * from SelfRegRequests where SrTournament=$TourId and SrStatus=0 and SrId in (" . implode(',', $ids) . ") order by SrId");
	$requests = array();
	$done = array();
	while ($r = safe_fetch($q)) {
		$done[] = intval($r->SrId);
		if (in_array(intval($r->SrSession), sr_entry_sessions($TourId, $r->SrCode), true)) continue;
		$requests[] = array(
			'code' => $r->SrCode, 'session' => intval($r->SrSession), 'division' => $r->SrDivision, 'class' => $r->SrClass,
			'faceIndex' => $r->SrFace ? sr_face_index($TourId, $r->SrDivision, $r->SrClass, $r->SrFace) : null,
			'email' => $r->SrEmail,
		);
	}
	if ($done) sr_set_status($TourId, $done, 1);
	return array('lines' => sr_build_lines($requests), 'ids' => $done);
}

function sr_entry_sessions($TourId, $code) {
	$out = array();
	$q = safe_r_sql("select QuSession from Entries inner join Qualifications on QuId=EnId
		where EnTournament=" . intval($TourId) . " and EnCode=" . StrSafe_DB($code));
	while ($r = safe_fetch($q)) $out[] = intval($r->QuSession);
	return $out;
}

function sr_set_status($TourId, $ids, $status) {
	$ids = array_values(array_filter(array_map('intval', (array)$ids)));
	if (!$ids) return 0;
	// a new decision (refused after validated, back to pending…) gets its own email: the "email sent" flag is reset
	// when the status changes. MySQL applies the assignments in order: SrNotified is computed with the old SrStatus.
	safe_w_sql("update SelfRegRequests set SrNotified=if(SrStatus=" . intval($status) . ", SrNotified, 0), SrStatus=" . intval($status)
		. ", SrDecided=" . ($status ? StrSafe_DB(date('Y-m-d H:i:s')) : 'NULL')
		. " where SrTournament=" . intval($TourId) . " and SrId in (" . implode(',', $ids) . ")");
	return safe_w_affected_rows();
}

// Removes processed (validated or refused) requests decided more than $days days ago.
function sr_purge_processed($TourId, $days) {
	safe_w_sql("delete from SelfRegRequests where SrTournament=" . intval($TourId) . " and SrStatus in (1, 2)
		and SrDecided < " . StrSafe_DB(date('Y-m-d H:i:s', time() - 86400 * max(0, intval($days)))));
	return safe_w_affected_rows();
}

// ---------- admin page context ----------

function sr_channel() {
	return sr_param_get(0, 'channel', 'stable') === 'nightly' ? 'nightly' : 'stable';
}

// Absolute URL of a file of this module, from the current request (all module pages are in the module folder).
function sr_module_url($file) {
	return getMyScheme() . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/' . $file;
}

function sr_public_url($code) {
	return (sr_base_url() ?: sr_module_url('')) . 'register.php?c=' . rawurlencode($code);
}

/**
 * Address of the module folder used in emails and shown as public link. Remembered from the admin pages (admin
 * rights: a trusted Host) when they are served over HTTPS, or when none is known yet — not from a LAN visit over
 * http. Public pages only read it: a visitor's Host header is never put in an email.
 */
function sr_base_url($remember = false) {
	$stored = (string)sr_param_get(0, 'baseUrl', '');
	if ($remember and ($stored === '' or sr_is_https())) {
		$url = sr_module_url('');
		if ($url !== $stored) sr_param_set(0, 'baseUrl', $url);
		return $url;
	}
	return $stored;
}

// Link to a module file for an email, or '' when the site address is not known yet (the template hides it).
function sr_mail_url($file) {
	$base = sr_base_url();
	return $base === '' ? '' : $base . $file;
}

function sr_context($TourId) {
	global $CFG;
	sr_base_url(true);
	sr_notify_validated($TourId);
	$tour = sr_tour($TourId);
	$q = safe_r_sql("select count(*) as n from LookUpEntries where LueIocCode=" . StrSafe_DB($tour->ToIocCode));
	$version = sr_version_info();
	$isAdmin = hasFullACL(AclRoot, '', AclReadWrite);
	$keys = sr_turnstile_keys();
	$settings = sr_settings($TourId);
	return array(
		'lang' => sr_lang(),
		'tour' => array('code' => $tour->ToCode, 'name' => $tour->ToName, 'ioc' => $tour->ToIocCode, 'from' => $tour->ToWhenFrom, 'to' => $tour->ToWhenTo),
		'sessions' => sr_sessions($TourId),
		'divisions' => sr_divisions($TourId),
		'settings' => $settings,
		'closed' => sr_closed_reason($tour, $settings),
		'usage' => sr_usage($TourId) ?: new stdClass(),
		'requests' => sr_requests($TourId),
		'lookupCount' => intval(safe_fetch($q)->n),
		'turnstile' => ($keys['site'] !== '' && $keys['secret'] !== ''),
		'turnstileSite' => $isAdmin ? $keys['site'] : '',
		'trustCf' => (bool)sr_param_get(0, 'trustCf', false),
		'https' => (sr_is_https() || sr_is_local()),
		'publicUrl' => sr_public_url($tour->ToCode),
		'isAdmin' => $isAdmin,
		'channel' => sr_channel(),
		'version' => $version['version'],
		'listLoadUrl' => $CFG->ROOT_DIR . 'Partecipants/ListLoad.php',
		'syncUrl' => $CFG->ROOT_DIR . 'Partecipants/LookupTableLoad.php',
		'mailer' => sr_mailer_info($TourId),
	);
}

// ---------- emails (optional Mailer module: Modules/Custom/Mailer) ----------
// Templates: mailer-templates.php. Nothing here fails when Mailer is missing or not configured: no email is sent.

function sr_mailer_installed() {
	return is_file(dirname(__DIR__) . '/Mailer/api.php');
}

function sr_mailer() {
	static $ok = null;
	if ($ok !== null) return $ok;
	if (!sr_mailer_installed()) return $ok = false;
	require_once(dirname(__DIR__) . '/Mailer/api.php');
	return $ok = (defined('MAILER_API') and MAILER_API >= 1 and mailer_available());
}

function sr_mailer_info($TourId) {
	$installed = sr_mailer_installed();
	$available = sr_mailer();
	return array(
		'installed' => $installed,
		'available' => $available,
		'url' => $installed ? (function_exists('mailer_admin_path') ? mailer_admin_path() : '') : '',
		'contact' => $available ? mailer_contact($TourId)['email'] : '',
	);
}

// "Départ 1 – Samedi matin – samedi 12 décembre 2026 à 10:00", one line per session.
function sr_session_lines($TourId, $orders, $lang) {
	$byOrder = array();
	foreach (sr_sessions($TourId) as $s) $byOrder[$s['order']] = $s;
	$orders = array_unique(array_map('intval', $orders));
	sort($orders);
	$lines = array();
	foreach ($orders as $o) {
		$s = $byOrder[$o] ?? array('name' => '', 'start' => '');
		$lines[] = implode(' – ', array_filter(array(
			($lang === 'fr' ? 'Départ ' : 'Session ') . $o, $s['name'], $s['start'] ? mailer_format_date($s['start'], $lang, true) : '',
		), 'strlen'));
	}
	return implode("\n", $lines);
}

function sr_category_label($TourId, $div, $class) {
	$d = safe_fetch(safe_r_sql("select DivDescription from Divisions where DivTournament=" . intval($TourId) . " and DivId=" . StrSafe_DB($div)));
	$c = safe_fetch(safe_r_sql("select ClDescription from Classes where ClTournament=" . intval($TourId) . " and ClId=" . StrSafe_DB($class)));
	return implode(' – ', array_filter(array($d ? $d->DivDescription : $div, $c ? $c->ClDescription : $class), 'strlen'));
}

function sr_face_name($TourId, $TfId) {
	$r = safe_fetch(safe_r_sql("select TfName from TargetFaces where TfTournament=" . intval($TourId) . " and TfId=" . intval($TfId)));
	return $r ? get_text($r->TfName, 'Tournament', '', true) : '';
}

// Names and club of a license, from the license database.
function sr_archer_names($tour, $code) {
	$r = safe_fetch(safe_r_sql("select LueFamilyName, LueName, LueCoShort, LueCoDescr from LookUpEntries where LueCode=" . StrSafe_DB($code)
		. " and LueIocCode=" . StrSafe_DB($tour->ToIocCode) . " order by LueDefault desc limit 1"));
	return array(
		'firstName' => $r ? (string)$r->LueName : '', 'lastName' => $r ? (string)$r->LueFamilyName : '',
		'club' => $r ? (string)($r->LueCoShort ?: $r->LueCoDescr) : '',
	);
}

/**
 * Variables of an email about requests of one submission (one archer, division and face; one row per session).
 * $rows: SelfRegRequests rows.
 */
function sr_mail_vars($tour, $rows, $lang) {
	$first = $rows[0];
	$names = sr_archer_names($tour, $first->SrCode);
	return array(
		'archer.firstName' => $names['firstName'], 'archer.lastName' => $names['lastName'],
		'archer.license' => $first->SrCode, 'archer.club' => $names['club'],
		'category' => sr_category_label($tour->ToId, $first->SrDivision, $first->SrClass),
		'face' => $first->SrFace ? sr_face_name($tour->ToId, $first->SrFace) : '',
		'sessions' => sr_session_lines($tour->ToId, array_map(function ($r) { return $r->SrSession; }, $rows), $lang),
		'reference' => strtoupper(substr($first->SrGroup, 0, 8)),
		'comment' => (string)$first->SrComment,
		'email' => (string)$first->SrEmail,
		'formUrl' => sr_mail_url('register.php?c=' . rawurlencode($tour->ToCode)),
	);
}

// Rows of the given ids (or of a submission), grouped by submission.
function sr_rows_by_group($TourId, $where) {
	$groups = array();
	$q = safe_r_sql("select * from SelfRegRequests where SrTournament=" . intval($TourId) . " and ($where) order by SrSession");
	while ($r = safe_fetch($q)) $groups[$r->SrGroup][] = $r;
	return $groups;
}

/**
 * New submission: acknowledgement to the archer (in the language of the form) and notification to the competition
 * contact (reply-to: the archer). Returns true when the archer's email was queued.
 */
function sr_notify_received($tour, $group) {
	$settings = sr_settings($tour->ToId);
	if (!sr_mailer()) return false;
	$rows = sr_rows_by_group($tour->ToId, "SrGroup=" . StrSafe_DB($group))[$group] ?? array();
	if (!$rows) return false;
	$lang = $rows[0]->SrLang ?: 'fr';
	$sent = false;
	if ($settings['mails']['received']) {
		$sent = (bool)mailer_queue('selfreg.received', $tour->ToId, $rows[0]->SrEmail, sr_mail_vars($tour, $rows, $lang),
			array('lang' => $lang, 'ref' => $group));
	}
	$contact = mailer_contact($tour->ToId);
	if ($settings['mails']['organiser'] and $contact['email'] !== '') {
		// in the competition's language, not the archer's
		$orgLang = mailer_default_lang($tour->ToId);
		$vars = sr_mail_vars($tour, $rows, $orgLang) + array('adminUrl' => sr_mail_url('index.php'));
		mailer_queue('selfreg.new', $tour->ToId, $contact['email'], $vars, array('lang' => $orgLang, 'replyTo' => $rows[0]->SrEmail, 'ref' => $group));
	}
	return $sent;
}

/**
 * Claims rows for an email: sets SrNotified from 0 to $flag in one UPDATE. Only the request that changed every row
 * sends the email, so two admin calls at the same time never send it twice. Returns false (and releases what it
 * took) when another request was first.
 */
function sr_claim($rows, $status, $flag) {
	$ids = implode(',', array_map(function ($r) { return intval($r->SrId); }, $rows));
	safe_w_sql("update SelfRegRequests set SrNotified=" . intval($flag) . " where SrId in ($ids) and SrNotified=0 and SrStatus=" . intval($status));
	$n = safe_w_affected_rows();
	if ($n === count($rows)) return true;
	if ($n > 0) sr_unclaim($rows, $flag);
	return false;
}

function sr_unclaim($rows, $flag) {
	$ids = implode(',', array_map(function ($r) { return intval($r->SrId); }, $rows));
	safe_w_sql("update SelfRegRequests set SrNotified=0 where SrId in ($ids) and SrNotified=" . intval($flag));
}

// Refused requests: one email per submission, listing the refused sessions.
function sr_notify_refused($TourId, $ids) {
	$ids = array_values(array_filter(array_map('intval', (array)$ids)));
	if (!$ids or !sr_mailer() or !sr_settings($TourId)['mails']['refused']) return;
	$tour = sr_tour($TourId);
	foreach (sr_rows_by_group($TourId, "SrStatus=2 and SrNotified=0 and SrId in (" . implode(',', $ids) . ")") as $group => $rows) {
		if (!sr_claim($rows, 2, 2)) continue;
		$lang = $rows[0]->SrLang ?: 'fr';
		if (!mailer_queue('selfreg.refused', $TourId, $rows[0]->SrEmail, sr_mail_vars($tour, $rows, $lang), array('lang' => $lang, 'ref' => $group))) {
			sr_unclaim($rows, 2);
		}
	}
}

/**
 * Validated requests whose entry now exists in IANSEO (the List load import went through): one email per
 * submission. Called on every admin page load and API call. Only requests decided in the last 7 days, so that
 * enabling emails later does not write to archers validated long ago.
 */
function sr_notify_validated($TourId) {
	if (!sr_mailer() or !sr_settings($TourId)['mails']['validated']) return;
	$tour = sr_tour($TourId);
	$since = StrSafe_DB(date('Y-m-d H:i:s', time() - 7 * 86400));
	$groups = sr_rows_by_group($TourId, "SrStatus=1 and SrNotified=0 and SrDecided >= $since");
	if (!$groups) return;
	// entries of these archers, in one query: code|session => true
	$codes = array();
	foreach ($groups as $rows) foreach ($rows as $r) $codes[$r->SrCode] = true;
	$entries = array();
	$q = safe_r_sql("select EnCode, QuSession from Entries inner join Qualifications on QuId=EnId where EnTournament=" . intval($TourId)
		. " and EnCode in (" . implode(',', array_map('StrSafe_DB', array_keys($codes))) . ")");
	while ($r = safe_fetch($q)) $entries[strtoupper($r->EnCode) . '|' . intval($r->QuSession)] = true;
	foreach ($groups as $group => $rows) {
		// one email per submission, once every validated session of it is in IANSEO (a partly imported request
		// waits: the archer must not read "session 1 confirmed" as "session 3 refused")
		foreach ($rows as $r) if (empty($entries[strtoupper($r->SrCode) . '|' . intval($r->SrSession)])) continue 2;
		if (!sr_claim($rows, 1, 1)) continue;
		$lang = $rows[0]->SrLang ?: 'fr';
		if (!mailer_queue('selfreg.validated', $TourId, $rows[0]->SrEmail, sr_mail_vars($tour, $rows, $lang), array('lang' => $lang, 'ref' => $group))) {
			sr_unclaim($rows, 1);
		}
	}
}

// ---------- environment ----------

function sr_is_https() {
	return getMyScheme() === 'https'
		|| (sr_param_get(0, 'trustCf', false) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

// Local test install (http allowed): loopback client, or a localhost host name (Docker dev). A forged Host header
// only lets its sender use plain http for their own requests.
function sr_is_local() {
	$host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
	return in_array($_SERVER['REMOTE_ADDR'] ?? '', array('127.0.0.1', '::1'), true) or in_array($host, array('localhost', '127.0.0.1', '[::1]'), true);
}

function sr_turnstile_keys() {
	$k = sr_param_get(0, 'turnstile', array());
	return array('site' => (string)($k['site'] ?? ''), 'secret' => (string)($k['secret'] ?? ''));
}

// ---------- updates from GitHub releases (same mechanism as ianseo-entry-import) ----------

function sr_http($url, $accept = '*/*', $post = null, $timeout = 60) {
	$ua = 'IANSEO-SelfRegistration';
	if (function_exists('curl_init')) {
		$ch = curl_init($url);
		$opts = array(
			CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => $post === null, CURLOPT_MAXREDIRS => 5,
			CURLOPT_CONNECTTIMEOUT => min(10, $timeout), CURLOPT_TIMEOUT => $timeout,
			CURLOPT_HTTPHEADER => array('User-Agent: ' . $ua, 'Accept: ' . $accept),
		);
		if ($post !== null) {
			$opts[CURLOPT_POST] = true;
			$opts[CURLOPT_POSTFIELDS] = http_build_query($post);
		}
		curl_setopt_array($ch, $opts);
		$body = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err = curl_error($ch);
		curl_close($ch);
		if ($body === false) return array(0, '', $err ?: sr_t('connection'));
		return array($code, $body, $code >= 400 ? "HTTP $code" : '');
	}
	$http = array('header' => "User-Agent: $ua\r\nAccept: $accept\r\n", 'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => $post === null ? 1 : 0);
	if ($post !== null) {
		$http['method'] = 'POST';
		$http['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n";
		$http['content'] = http_build_query($post);
	}
	$body = @file_get_contents($url, false, stream_context_create(array('http' => $http)));
	if ($body === false) return array(0, '', sr_t('connectionFopen'));
	$code = 0;
	if (!empty($http_response_header)) {
		foreach ($http_response_header as $h) if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $code = intval($m[1]);
	}
	return array($code, $body, $code >= 400 ? "HTTP $code" : '');
}

/**
 * Is $latest an update for $current on this channel?
 * Stable: strictly newer version. Nightly: any different build, unless the installed version is a newer stable
 * (nightly versions are "X.Y.(Z+1)-nightly.DATE.SHA", which version_compare() puts before "X.Y.(Z+1)").
 */
function sr_is_update($channel, $latest, $current) {
	if ($latest === '' or $latest === $current) return false;
	if ($channel === 'nightly') return strpos($current, '-nightly') !== false or version_compare($latest, $current, '>');
	return version_compare($latest, $current, '>');
}

function sr_update_check($force = false) {
	$info = sr_version_info();
	$channel = sr_channel();
	$cache = sr_param_get(0, 'updateCheck', array());
	if (!$force and !empty($cache['at']) and time() - $cache['at'] < 86400
		and ($cache['current'] ?? '') === $info['version'] and ($cache['channel'] ?? '') === $channel) {
		return $cache;
	}
	$res = array('at' => time(), 'channel' => $channel, 'current' => $info['version'], 'latest' => '', 'notes' => '', 'asset' => '', 'url' => '', 'error' => '');
	if (empty($info['repo'])) {
		$res['error'] = sr_t('repoMissing');
	} else {
		// `api` (optional in version.json) allows testing against a fake release server
		$api = rtrim($info['api'] ?? 'https://api.github.com', '/');
		$path = $channel === 'nightly' ? '/releases/tags/nightly' : '/releases/latest';
		list($code, $body, $err) = sr_http($api . '/repos/' . $info['repo'] . $path, 'application/vnd.github+json');
		$j = $err ? null : json_decode($body, true);
		if ($code == 404) {
			$res['error'] = sr_t('noRelease');
		} elseif (!$j or empty($j['tag_name'])) {
			$res['error'] = $err ?: sr_t('githubUnexpected');
		} else {
			$res['notes'] = (string)($j['body'] ?? '');
			$res['url'] = (string)($j['html_url'] ?? '');
			foreach ((array)($j['assets'] ?? array()) as $a) {
				if (($a['name'] ?? '') === $info['asset']) $res['asset'] = $a['browser_download_url'];
			}
			// the nightly tag is fixed ("nightly"): its version is in the release name
			$res['latest'] = $channel === 'nightly' ? trim((string)($j['name'] ?? '')) : ltrim($j['tag_name'], 'vV');
		}
	}
	$res['available'] = ($res['asset'] !== '' and sr_is_update($channel, $res['latest'], $info['version']));
	sr_param_set(0, 'updateCheck', $res);
	return $res;
}

function sr_rrmdir($dir) {
	if (!is_dir($dir)) return;
	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
		$f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
	}
	@rmdir($dir);
}

function sr_rcopy($src, $dst) {
	if (!is_dir($dst) and !@mkdir($dst, 0775, true)) return false;
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
	foreach ($it as $f) {
		$target = $dst . DIRECTORY_SEPARATOR . $it->getSubPathName();
		if ($f->isDir()) {
			if (!is_dir($target) and !@mkdir($target, 0775, true)) return false;
		} elseif (!@copy($f->getPathname(), $target)) {
			return false;
		}
	}
	return true;
}

/**
 * Installs the release found by sr_update_check(). The current version is backed up in the server's temporary
 * folder — not in Modules/Custom/, where IANSEO would load its menu.php — and restored if any step fails.
 * Files are copied rather than renamed (Windows/XAMPP may lock them).
 */
function sr_update_install() {
	$check = sr_update_check(true);
	if (!$check['available']) return array('error' => $check['error'] ?: sr_t('noUpdate'));
	$manual = sr_t('manual', array('asset' => $check['asset']));
	if (!class_exists('ZipArchive')) return array('error' => sr_t('zipMissing') . $manual);

	$dir = __DIR__;
	if (!is_writable($dir)) return array('error' => sr_t('notWritable') . $manual);

	list($code, $body, $err) = sr_http($check['asset'], 'application/octet-stream');
	if ($err or !$body) return array('error' => sr_t('downloadFailed', array('error' => $err)) . $manual);

	$tmp = tempnam(sys_get_temp_dir(), 'sr');
	$extracted = $tmp . '-new';
	$backup = $tmp . '-backup';
	file_put_contents($tmp, $body);
	$cleanup = function () use ($tmp, $extracted, $backup) { @unlink($tmp); sr_rrmdir($extracted); sr_rrmdir($backup); };

	$zip = new ZipArchive();
	if ($zip->open($tmp) !== true) { $cleanup(); return array('error' => sr_t('zipUnreadable') . $manual); }
	$hasVersion = false;
	for ($i = 0; $i < $zip->numFiles; $i++) {
		$name = str_replace('\\', '/', $zip->getNameIndex($i));
		if (strpos($name, '..') !== false or substr($name, 0, strlen(SR_MODULE) + 1) !== SR_MODULE . '/') {
			$zip->close(); $cleanup();
			return array('error' => sr_t('zipRefused', array('name' => $name)));
		}
		if ($name === SR_MODULE . '/version.json') $hasVersion = true;
	}
	if (!$hasVersion or !$zip->extractTo($extracted)) { $zip->close(); $cleanup(); return array('error' => sr_t('zipIncomplete') . $manual); }
	$zip->close();

	$new = @json_decode(@file_get_contents($extracted . '/' . SR_MODULE . '/version.json'), true);
	if (empty($new['version'])) { $cleanup(); return array('error' => sr_t('versionInvalid')); }

	if (!sr_rcopy($dir, $backup)) { $cleanup(); return array('error' => sr_t('backupFailed') . $manual); }
	if (!sr_rcopy($extracted . '/' . SR_MODULE, $dir)) {
		sr_rcopy($backup, $dir);
		$cleanup();
		return array('error' => sr_t('copyFailed') . $manual);
	}
	$cleanup();
	if (function_exists('opcache_reset')) @opcache_reset();
	sr_param_set(0, 'updateCheck', array());
	return array('ok' => true, 'version' => $new['version']);
}
