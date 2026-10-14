<?php
// Pure logic tests, without IANSEO or database: php tests/core.test.php
require __DIR__ . '/../SelfRegistration/core.php';

$failures = 0;
$count = 0;
function check($name, $actual, $expected) {
	global $failures, $count;
	$count++;
	if ($actual !== $expected) {
		$failures++;
		echo "FAIL $name\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
	}
}

// ---------- input checks ----------
check('license ok', sr_valid_license('123456A'), true);
check('license lower case and spaces', sr_valid_license(' 123 456-a '), true);
check('license normalised', sr_norm_license(' 123.456-a'), '123456A');
check('license too short', sr_valid_license('12345A'), false);
check('license no letter', sr_valid_license('1234567'), false);
check('license 7 digits', sr_valid_license('1041458U'), true);
check('license too long', sr_valid_license('12345678A'), false);
check('license variants 6 digits', sr_license_variants('405719z'), array('0405719Z', '405719Z'));
check('license variants leading 0', sr_license_variants('0405719Z'), array('0405719Z', '405719Z'));
check('license variants 7 digits', sr_license_variants('1041458U'), array('1041458U'));
check('license injection', sr_valid_license("123456A\t##TARGET##"), false);

check('date ok', sr_valid_date('2010-02-01'), true);
check('date impossible', sr_valid_date('2010-02-30'), false);
check('date format', sr_valid_date('01/02/2010'), false);
check('date not a string', sr_valid_date(array('2010-02-01')), false);

check('email ok', (bool)sr_valid_email('jean.dupont@club-arc.fr'), true);
check('email with + refused like ListLoad', (bool)sr_valid_email('jean+arc@club.fr'), false);
check('email with tab', (bool)sr_valid_email("a@b.fr\t##X##"), false);
check('email too long', (bool)sr_valid_email(str_repeat('a', 95) . '@b.fr'), false);

check('form url sub-domain', sr_valid_form_url(' https://Inscription.Club.fr '), 'https://inscription.club.fr/');
check('form url path kept', sr_valid_form_url('https://club.fr/inscription//'), 'https://club.fr/inscription/');
check('form url port kept', sr_valid_form_url('https://club.fr:8443'), 'https://club.fr:8443/');
check('form url cleared', sr_valid_form_url(''), '');
check('form url http refused', sr_valid_form_url('http://club.fr/'), null);
check('form url http localhost', sr_valid_form_url('http://localhost:8089/'), 'http://localhost:8089/');
check('form url query refused', sr_valid_form_url('https://club.fr/?c=X'), null);
check('form url credentials refused', sr_valid_form_url('https://a:b@club.fr/'), null);
check('form url other scheme', sr_valid_form_url('javascript:alert(1)//x'), null);
check('form url quote', sr_valid_form_url('https://club.fr/"><b>'), null);

check('comment tags stripped', sr_clean_text('<script>alert(1)</script>Bonjour'), 'alert(1)Bonjour');
check('comment length', mb_strlen(sr_clean_text(str_repeat('é', 600))), 500);
check('line value separators', sr_line_value("a\tb;c\nd"), 'a b c d');

// ---------- availability and quotas ----------
$sessions = array(array('order' => 1, 'capacity' => 4), array('order' => 2, 'capacity' => 4), array('order' => 3, 'capacity' => 4));
$used = array(
	1 => array('total' => 2, 'div' => array('CO' => 1, 'CL' => 1)),
	2 => array('total' => 4, 'div' => array('CL' => 4)),
);
$quotas = array(1 => array('CO' => 1));

$co = sr_availability($sessions, $used, $quotas, 'CO');
check('quota reached for CO in session 1', array($co[0]['available'], $co[0]['reason'], $co[0]['quotaFree']), array(false, 'quota', 0));
check('session 2 full', array($co[1]['available'], $co[1]['reason'], $co[1]['free']), array(false, 'full', 0));
check('session 3 empty', array($co[2]['available'], $co[2]['free'], $co[2]['quotaFree']), array(true, 4, null));

$cl = sr_availability($sessions, $used, $quotas, 'CL');
check('CL still possible in session 1', array($cl[0]['available'], $cl[0]['free']), array(true, 2));

$quotas2 = array(3 => array('BB' => 2));
$bb = sr_availability($sessions, array(3 => array('total' => 1, 'div' => array('BB' => 1))), $quotas2, 'BB');
check('quota free shown', array($bb[2]['available'], $bb[2]['quotaFree'], $bb[2]['free']), array(true, 1, 3));

check('first unavailable', sr_first_unavailable(array(3, 1), $co), array('order' => 1, 'reason' => 'quota'));
check('all available', sr_first_unavailable(array(3), $co), null);
check('unknown session', sr_first_unavailable(array(9), $co), array('order' => 9, 'reason' => 'full'));

// ---------- request checks ----------
$offer = array('divisions' => array('CL', 'CO'), 'faces' => array(3, 4), 'allowFace' => true, 'sessions' => array(1, 2, 3));
$req = array('division' => 'CO', 'face' => 4, 'sessions' => array(1, 3), 'email' => 'a@b.fr', 'consent' => true);
check('valid request', sr_check_request($req, $offer), '');
check('division not offered', sr_check_request(array('division' => 'BB') + $req, $offer), 'err.division');
check('face not offered', sr_check_request(array('face' => 9) + $req, $offer), 'err.face');
check('face ignored when not chosen by archers', sr_check_request(array('face' => 9) + $req, array('allowFace' => false) + $offer), '');
check('no session', sr_check_request(array('sessions' => array()) + $req, $offer), 'err.noSession');
check('session not open', sr_check_request(array('sessions' => array(1, 7)) + $req, $offer), 'err.session');
check('session not numeric', sr_check_request(array('sessions' => array('1 or 1=1')) + $req, $offer), 'err.session');
check('session twice', sr_check_request(array('sessions' => array(1, '1')) + $req, $offer), 'err.session');
check('bad email', sr_check_request(array('email' => 'nope') + $req, $offer), 'err.email');
check('no consent', sr_check_request(array('consent' => false) + $req, $offer), 'err.consent');

// ---------- List load lines ----------
$lines = sr_build_lines(array(
	array('code' => '123456A', 'session' => 1, 'division' => 'CO', 'class' => 'S1H', 'faceIndex' => 2, 'email' => 'a@b.fr'),
	array('code' => '123456A', 'session' => 3, 'division' => 'CO', 'class' => 'S1H', 'faceIndex' => 2, 'email' => 'a@b.fr'),
	array('code' => '654321B', 'session' => 2, 'division' => 'CL', 'class' => 'U18F', 'faceIndex' => null, 'email' => ''),
));
check('List load lines', $lines, array(
	"123456A\t1\tCO\tS1H",
	"123456A\t3\tCO\tS1H",
	"654321B\t2\tCL\tU18F",
	"##TARGET##\t123456A\tCO\tS1H\t2",
	"##EMAIL##\t123456A\ta@b.fr",
));
$evil = sr_build_lines(array(array('code' => "1;2\n##NOC##", 'session' => '1x', 'division' => "CO\t", 'class' => 'S1H', 'faceIndex' => 0, 'email' => "x@y.fr\n##X##")));
check('no injected line or column', array(count($evil), substr_count(implode("\n", $evil), "\n"), substr_count($evil[0], "\t")), array(2, 1, 3));

echo $failures ? "\n$failures/$count failed\n" : "$count passed\n";
exit($failures ? 1 : 0);
