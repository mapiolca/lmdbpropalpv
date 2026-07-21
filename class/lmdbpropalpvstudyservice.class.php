<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require_once __DIR__.'/lmdbpropalpvfinancialcalculator.class.php';
require_once __DIR__.'/lmdbpropalpvtariffresolver.class.php';
require_once __DIR__.'/lmdbpropalpvpaneldegradationresolver.class.php';
require_once dirname(__DIR__).'/lib/lmdbpropalpv.lib.php';

/** Build one proposal study from its immutable commercial and optional data. */
class LmdbPropalPVStudyService
{
	/** @var DoliDB */
	private $db;

	/** @param DoliDB $db Database handler */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * @param Propal $propal Loaded proposal
	 * @return array{complete:bool,missing:list<string>,input:?LmdbPropalPVFinancialInput,result:?LmdbPropalPVFinancialResult,peak_power_kwp:float,investment_ttc:float,currency_code:string,reference_date:string,values:array<string,mixed>,degradation_warning_keys:list<string>,degradation_fallback_product_refs:list<string>,degradation_source:string}
	 */
	public function buildStudy($propal)
	{
		if (method_exists($propal, 'fetch_optionals')) {
			$propal->fetch_optionals();
		}

		$currencyCode = !empty($propal->multicurrency_code) ? (string) $propal->multicurrency_code : (string) $GLOBALS['conf']->currency;
		$investmentTtcRaw = !empty($propal->multicurrency_code) && isset($propal->multicurrency_total_ttc)
			? (float) $propal->multicurrency_total_ttc
			: (float) $propal->total_ttc;
		$investmentTtc = (float) price2num($investmentTtcRaw, 'MT');
		$peakPowerKwp = $this->getPeakPowerKwp($propal);
		$ownerEntity = !empty($propal->entity) ? (int) $propal->entity : (int) $GLOBALS['conf']->entity;
		$proposalDate = !empty($propal->date) ? dol_print_date($propal->date, '%Y-%m-%d') : dol_print_date(dol_now(), '%Y-%m-%d');
		$hasFirstYearDegradation = $this->optionIsSet($propal, 'lmdbpropalpv_first_year_degradation_pct');
		$hasAnnualDegradation = $this->optionIsSet($propal, 'lmdbpropalpv_panel_degradation_pct');
		$degradationResolution = array(
			'first_year_degradation_pct' => (float) lmdbpropalpvGetEntityStringConstant($this->db, 'LMDBPROPALPV_DEFAULT_FIRST_YEAR_DEGRADATION_PCT', '0.45', $ownerEntity),
			'annual_degradation_pct' => (float) lmdbpropalpvGetEntityStringConstant($this->db, 'LMDBPROPALPV_DEFAULT_PANEL_DEGRADATION_PCT', '0.45', $ownerEntity),
			'source' => 'snapshot',
			'warning_keys' => array(),
			'fallback_product_refs' => array(),
		);
		if (!$hasFirstYearDegradation || !$hasAnnualDegradation) {
			$degradationResolution = $this->resolvePanelDegradation($propal);
		}
		$values = array(
			'annual_production_kwh' => $this->optionFloat($propal, 'lmdbpropalpv_annual_production_kwh', 0.0),
			'self_consumption_pct' => $this->optionFloat($propal, 'lmdbpropalpv_self_consumption_pct', (float) lmdbpropalpvGetEntityStringConstant($this->db, 'LMDBPROPALPV_DEFAULT_SELF_CONSUMPTION_PCT', '68', $ownerEntity)),
			'first_year_degradation_pct' => $hasFirstYearDegradation ? $this->optionFloat($propal, 'lmdbpropalpv_first_year_degradation_pct', 0.0) : (float) $degradationResolution['first_year_degradation_pct'],
			'panel_degradation_pct' => $hasAnnualDegradation ? $this->optionFloat($propal, 'lmdbpropalpv_panel_degradation_pct', 0.0) : (float) $degradationResolution['annual_degradation_pct'],
			'electricity_growth_pct' => $this->optionFloat($propal, 'lmdbpropalpv_electricity_growth_pct', (float) lmdbpropalpvGetEntityStringConstant($this->db, 'LMDBPROPALPV_DEFAULT_ELECTRICITY_GROWTH_PCT', '3', $ownerEntity)),
			'reference_date' => $this->optionDate($propal, 'lmdbpropalpv_tariff_reference_date', $proposalDate),
			'retail_mode' => $this->optionString($propal, 'lmdbpropalpv_retail_tariff_mode', lmdbpropalpvGetEntityStringConstant($this->db, 'LMDBPROPALPV_DEFAULT_RETAIL_TARIFF_MODE', 'base', $ownerEntity)),
			'subscription_kva' => $this->optionFloat($propal, 'lmdbpropalpv_retail_subscription_kva', (float) lmdbpropalpvGetEntityStringConstant($this->db, 'LMDBPROPALPV_DEFAULT_RETAIL_SUBSCRIPTION_KVA', '6', $ownerEntity)),
			'retail_price_per_kwh' => $this->optionFloat($propal, 'lmdbpropalpv_retail_price_per_kwh', 0.0),
			'feed_in_price_per_kwh' => $this->optionFloat($propal, 'lmdbpropalpv_feed_in_price_per_kwh', 0.0),
			'premium_per_kwp' => $this->optionFloat($propal, 'lmdbpropalpv_premium_per_kwp', 0.0),
			'tariff_set_id' => (int) $this->optionFloat($propal, 'lmdbpropalpv_tariff_set_id', 0.0),
		);

		$missing = array();
		if ($peakPowerKwp <= 0.0) {
			$missing[] = 'LmdbPropalPVMissingPeakPower';
		}
		if ($investmentTtc <= 0.0) {
			$missing[] = 'LmdbPropalPVMissingInvestment';
		}
		if ((float) $values['annual_production_kwh'] <= 0.0) {
			$missing[] = 'LmdbPropalPVMissingProduction';
		}
		if ((float) $values['self_consumption_pct'] < 0.0 || (float) $values['self_consumption_pct'] > 100.0) {
			$missing[] = 'LmdbPropalPVInvalidSelfConsumption';
		}
		if ((float) $values['first_year_degradation_pct'] < 0.0 || (float) $values['first_year_degradation_pct'] >= 100.0) {
			$missing[] = 'LmdbPropalPVInvalidFirstYearDegradation';
		}
		if ((float) $values['panel_degradation_pct'] < 0.0 || (float) $values['panel_degradation_pct'] >= 100.0) {
			$missing[] = 'LmdbPropalPVInvalidDegradation';
		}
		if ((float) $values['electricity_growth_pct'] <= -100.0) {
			$missing[] = 'LmdbPropalPVInvalidGrowth';
		}
		if (!in_array((string) $values['retail_mode'], array('base', 'peak', 'manual'), true)) {
			$missing[] = 'LmdbPropalPVInvalidTariffMode';
		}
		if ((string) $values['retail_mode'] !== 'manual' && (float) $values['subscription_kva'] <= 0.0) {
			$missing[] = 'LmdbPropalPVInvalidSubscription';
		}
		if ((float) $values['retail_price_per_kwh'] <= 0.0) {
			$missing[] = 'LmdbPropalPVMissingRetailPrice';
		}
		if (!$this->tariffSetMatchesProposal((int) $values['tariff_set_id'], $ownerEntity, $currencyCode)) {
			$missing[] = 'LmdbPropalPVNoPowerTariff';
		}
		if ((float) $values['feed_in_price_per_kwh'] < 0.0 || (float) $values['premium_per_kwp'] < 0.0) {
			$missing[] = 'LmdbPropalPVInvalidPowerTariff';
		}
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $values['reference_date']) || dol_stringtotime((string) $values['reference_date']) <= 0) {
			$missing[] = 'LmdbPropalPVMissingReferenceDate';
		}

		$input = null;
		$result = null;
		if (empty($missing)) {
			$input = new LmdbPropalPVFinancialInput(
				$investmentTtc,
				$currencyCode,
				$peakPowerKwp,
				(float) $values['annual_production_kwh'],
				(float) $values['self_consumption_pct'] / 100.0,
				(float) $values['first_year_degradation_pct'] / 100.0,
				(float) $values['panel_degradation_pct'] / 100.0,
				(float) $values['electricity_growth_pct'] / 100.0,
				(float) $values['retail_price_per_kwh'],
				(float) $values['feed_in_price_per_kwh'],
				(float) $values['premium_per_kwp']
			);
			$result = (new LmdbPropalPVFinancialCalculator())->calculate($input);
		}

		return array(
			'complete' => empty($missing),
			'missing' => $missing,
			'input' => $input,
			'result' => $result,
			'peak_power_kwp' => $peakPowerKwp,
			'investment_ttc' => $investmentTtc,
			'currency_code' => $currencyCode,
			'reference_date' => (string) $values['reference_date'],
			'values' => $values,
			'degradation_warning_keys' => array_values($degradationResolution['warning_keys']),
			'degradation_fallback_product_refs' => array_values($degradationResolution['fallback_product_refs']),
			'degradation_source' => (string) $degradationResolution['source'],
		);
	}

	/**
	 * @param Propal $propal Loaded proposal
	 * @return array{first_year_degradation_pct:float,annual_degradation_pct:float,source:string,used_defaults:bool,total_power_wp:float,warning_keys:list<string>,fallback_product_refs:list<string>}
	 */
	public function resolvePanelDegradation($propal): array
	{
		$ownerEntity = !empty($propal->entity) ? (int) $propal->entity : (int) $GLOBALS['conf']->entity;
		$defaultFirstYear = (float) lmdbpropalpvGetEntityStringConstant($this->db, 'LMDBPROPALPV_DEFAULT_FIRST_YEAR_DEGRADATION_PCT', '0.45', $ownerEntity);
		$defaultAnnual = (float) lmdbpropalpvGetEntityStringConstant($this->db, 'LMDBPROPALPV_DEFAULT_PANEL_DEGRADATION_PCT', '0.45', $ownerEntity);
		return (new LmdbPropalPVPanelDegradationResolver($this->db))->resolveForProposal($propal, $defaultFirstYear, $defaultAnnual);
	}

	/** @return bool */
	private function tariffSetMatchesProposal($tariffSetId, $entity, $currencyCode)
	{
		if ((int) $tariffSetId <= 0) {
			return false;
		}
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'lmdbpropalpv_tariff_set';
		$sql .= ' WHERE rowid = '.((int) $tariffSetId).' AND entity = '.((int) $entity);
		$sql .= " AND currency_code = '".$this->db->escape((string) $currencyCode)."'";
		$resql = $this->db->query($sql);
		if (!$resql) {
			return false;
		}
		$exists = is_object($this->db->fetch_object($resql));
		$this->db->free($resql);

		return $exists;
	}

	/** @return float */
	private function getPeakPowerKwp($propal)
	{
		if (!function_exists('powerplantpvGetObjectPeakPowerKwc')) {
			dol_include_once('/powerplantpv/lib/powerplantpv.lib.php');
		}

		return function_exists('powerplantpvGetObjectPeakPowerKwc') ? (float) powerplantpvGetObjectPeakPowerKwc($propal) : 0.0;
	}

	/** @return float */
	private function optionFloat($propal, $key, $default)
	{
		$optionKey = 'options_'.$key;
		if (!isset($propal->array_options[$optionKey]) || $propal->array_options[$optionKey] === '') {
			return (float) $default;
		}

		return (float) $propal->array_options[$optionKey];
	}

	/** @return bool */
	private function optionIsSet($propal, $key)
	{
		$optionKey = 'options_'.$key;
		return isset($propal->array_options[$optionKey]) && $propal->array_options[$optionKey] !== '';
	}

	/** @return string */
	private function optionString($propal, $key, $default)
	{
		$optionKey = 'options_'.$key;
		return isset($propal->array_options[$optionKey]) && $propal->array_options[$optionKey] !== '' ? (string) $propal->array_options[$optionKey] : (string) $default;
	}

	/** @return string */
	private function optionDate($propal, $key, $default)
	{
		$optionKey = 'options_'.$key;
		if (!isset($propal->array_options[$optionKey]) || $propal->array_options[$optionKey] === '') {
			return (string) $default;
		}
		$value = $propal->array_options[$optionKey];
		if (is_numeric($value)) {
			return dol_print_date((int) $value, '%Y-%m-%d');
		}

		return substr((string) $value, 0, 10);
	}
}
