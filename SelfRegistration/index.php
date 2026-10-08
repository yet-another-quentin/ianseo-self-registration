<?php
/*
 * Self registration — administration page of the open competition: settings, quotas, Turnstile keys, requests.
 * Validated requests are imported by posting "List load" lines to Partecipants/ListLoad.php (admin.js).
 */
require_once(dirname(__FILE__, 4) . '/config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/security.php');

CheckTourSession(true);
checkFullACL(AclParticipants, 'pAdvancedEntries', AclReadWrite);

sr_ensure_schema();
sr_purge_orphans();

$PAGE_TITLE = sr_t('menu');
$JS_SCRIPT = array(
	'<link href="./' . sr_asset('admin.css') . '" rel="stylesheet" type="text/css">',
	'<script src="./' . sr_asset('i18n.js') . '"></script>',
	'<script src="./' . sr_asset('admin.js') . '" defer></script>',
	'<script>window.SR_CONTEXT = ' . json_encode(sr_context($_SESSION['TourId']), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>',
);

include('Common/Templates/head.php');
?>
<div id="sr-admin">
	<noscript>JavaScript is required for this module.</noscript>
</div>
<?php
include('Common/Templates/tail.php');
