<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

// Exercise the actual cover renderer with recorded PDF calls; no TCPDF or Dolibarr instance.
define('MAIN_DB_PREFIX', 'test_');
class pdf_cyan
{
	public $db;
	public $emetteur;
	public $page_largeur = 210;
	public $marge_gauche = 10;
}
function getDolGlobalString($name, $default = '') { return $GLOBALS['settings'][$name] ?? $default; }
function getDolGlobalInt($name) { return (int) getDolGlobalString($name, '0'); }
function price2num($value, $type = '') { return $value; }
function price($value, ...$args) { return (string) $value; }
function dol_print_date($value, $format) { return '2026-10-12'; }
require_once dirname(__DIR__).'/lib/lmdbpropalpv.lib.php';
require_once dirname(__DIR__).'/class/lmdbpropalpvconnectionpowerchecker.class.php';

// Keep class bodies unchanged, replacing only the unavailable core bootstrap above them.
foreach (array('lmdbpropalpv_pdf_base.class.php', 'pdf_lmdbpropalpv_withpictures.modules.php', 'pdf_lmdbpropalpv_withoutpictures.modules.php') as $file) {
	$source = file_get_contents(dirname(__DIR__).'/core/modules/propale/doc/'.$file);
	$start = strpos($source, $file === 'lmdbpropalpv_pdf_base.class.php' ? 'abstract class ' : 'class pdf_');
	if ($start === false) { throw new RuntimeException('Renderer class not found'); }
	eval(substr($source, $start));
}
class PdfConnectionTestDb
{
	public $ownerSettings = array(2 => '1', 3 => '0');
	public function escape($value) { return $value; }
	public function query($sql) { return $sql; }
	public function fetch_object($sql)
	{
		if (strpos($sql, 'LMDBPROPALPV_PDF_CONNECTION_CHECK') === false) { return false; }
		preg_match('/entity = ([0-9]+)/', $sql, $matches);
		return (object) array('value' => $this->ownerSettings[(int) $matches[1]]);
	}
	public function free($result) {}
}
class PdfCallRecorder
{
	public $texts = array();
	public function __call($name, $args)
	{
		if ($name === 'MultiCell' || $name === 'Cell') { $this->texts[] = (string) $args[2]; }
	}
}
class PdfTestTranslation
{
	public function transnoentities($key, ...$args) { return $key; }
	public function convToOutputCharset($value) { return $value; }
}
$conf = (object) array('entity' => 1);
$settings = array();
$db = new PdfConnectionTestDb();
$connection = (new LmdbPropalPVConnectionPowerChecker())->check(new LmdbPropalPVConnectionPowerInput(7.14, 7.0, 6.0, 'single', true));
$study = array('complete' => false, 'peak_power_kwp' => 7.14, 'investment_ttc' => 10000, 'currency_code' => 'EUR',
	'connection_result' => $connection, 'connection_warning_keys' => $connection->warningKeys, 'connection_product_refs' => array());
$cover = new ReflectionMethod('LmdbPropalPVPdfBase', 'drawCover');
if (version_compare(PHP_VERSION, '8.1.0', '<')) { $cover->setAccessible(true); }
foreach (array('pdf_lmdbpropalpv_withpictures', 'pdf_lmdbpropalpv_withoutpictures') as $model) {
	$renderer = (new ReflectionClass($model))->newInstanceWithoutConstructor();
	$renderer->db = $db;
	$renderer->emetteur = (object) array('name' => 'Company', 'zip' => '', 'town' => '');
	foreach (array(array(null, 1, false), array('0', 1, false), array('1', 1, true), array('0', 2, true), array('1', 3, false)) as $case) {
		$settings = $case[0] === null ? array() : array('LMDBPROPALPV_PDF_CONNECTION_CHECK' => $case[0]);
		$object = (object) array('entity' => $case[1], 'ref' => 'PV-TEST', 'thirdparty' => null, 'date' => 1);
		$pdf = new PdfCallRecorder();
		$cover->invoke($renderer, $pdf, $object, new PdfTestTranslation(), $study);
		$text = implode('\n', $pdf->texts);
		$shown = strpos($text, 'LmdbPropalPVConnectionPowerCheck') !== false;
		$warned = strpos($text, 'LmdbPropalPVConnectionSinglePhaseLimit') !== false;
		if ($shown !== $case[2] || $warned !== $case[2]) { throw new RuntimeException('PDF visibility/owner setting failed: '.$model); }
		if (strpos($text, 'LmdbPropalPVFinancialNotice') === false || strpos($text, 'LmdbPropalPVRecommendedSubscribedPower') !== false) {
			throw new RuntimeException('Financial notice or obsolete subscription suggestion regression');
		}
	}
}
print "All PDF connection cover simulations passed.\n";
