<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require_once __DIR__.'/lmdbpropalpvfinancialcalculator.class.php';
require_once __DIR__.'/lmdbpropalpvtariffresolver.class.php';
require_once __DIR__.'/lmdbpropalpvpaneldegradationresolver.class.php';
require_once __DIR__.'/lmdbpropalpvinverterpowerresolver.class.php';
require_once __DIR__.'/lmdbpropalpvconnectionpowerchecker.class.php';
require_once dirname(__DIR__).'/lib/lmdbpropalpv.lib.php';

/**
 * Build the proposal studies with and without a battery.
 *
 * @phpstan-type BatteryProposalSnapshot array{id:int,label:string,amount_ttc:float,ref:string,ref_client:string}
 * @phpstan-type FinancialStudy array{
 *     complete:bool,
 *     missing:list<string>,
 *     input:?LmdbPropalPVFinancialInput,
 *     result:?LmdbPropalPVFinancialResult,
 *     projection_years:int,
 *     battery_configured:bool,
 *     battery_complete:bool,
 *     battery_missing:list<string>,
 *     battery_input:?LmdbPropalPVFinancialInput,
 *     battery_result:?LmdbPropalPVFinancialResult,
 *     battery_extra_investment_ttc:?float,
 *     battery_investment_ttc:float,
 *     battery_proposal_id:int,
 *     battery_proposal_source:?BatteryProposalSnapshot,
 *     battery_warning_keys:list<string>,
 *     peak_power_kwp:float,
 *     investment_ttc:float,
 *     currency_code:string,
 *     reference_date:string,
 *     values:array<string,mixed>,
 *     degradation_warning_keys:list<string>,
 *     degradation_fallback_product_refs:list<string>,
 *     degradation_source:string,
 *     connection_result:LmdbPropalPVConnectionPowerResult,
 *     connection_warning_keys:list<string>,
 *     connection_product_refs:list<string>,
 *     connection_source:string
 * }
 */
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
	 * @return FinancialStudy
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
		$projectionYears = (int) lmdbpropalpvGetEntityStringConstant($this->db, 'LMDBPROPALPV_PROJECTION_YEARS', '20', $ownerEntity);
		if ($projectionYears < LmdbPropalPVFinancialCalculator::MIN_PROJECTION_YEARS || $projectionYears > LmdbPropalPVFinancialCalculator::MAX_PROJECTION_YEARS) {
			$projectionYears = 20;
		}
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
		$inverterResolution = (new LmdbPropalPVInverterPowerResolver($this->db))->resolveForProposal($propal);
		$inverterPower = $inverterResolution['total_nominal_power_kva'];
		$proposedReferencePower = $inverterResolution['data_complete'] && $inverterPower !== null && $inverterPower > 0.0
			? min($peakPowerKwp, $inverterPower)
			: $peakPowerKwp;
		$suggestedPhaseMode = $proposedReferencePower > 6.0 || $inverterResolution['has_three_phase'] ? 'three' : 'single';
		$phaseMode = $this->optionString($propal, 'lmdbpropalpv_connection_phase_mode', $suggestedPhaseMode);
		if (!in_array($phaseMode, array('single', 'three'), true)) {
			$phaseMode = $suggestedPhaseMode;
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
			'connection_phase_mode' => $phaseMode,
			'retail_price_per_kwh' => $this->optionFloat($propal, 'lmdbpropalpv_retail_price_per_kwh', 0.0),
			'feed_in_price_per_kwh' => $this->optionFloat($propal, 'lmdbpropalpv_feed_in_price_per_kwh', 0.0),
			'premium_per_kwp' => $this->optionFloat($propal, 'lmdbpropalpv_premium_per_kwp', 0.0),
			'tariff_set_id' => (int) $this->optionFloat($propal, 'lmdbpropalpv_tariff_set_id', 0.0),
			'battery_self_consumption_pct' => $this->optionNullableFloat($propal, 'lmdbpropalpv_battery_self_consumption_pct'),
			'battery_proposal_id' => (int) $this->optionFloat($propal, 'lmdbpropalpv_fk_battery_propal', 0.0),
			'battery_extra_investment_ttc' => $this->optionNullableFloat($propal, 'lmdbpropalpv_battery_extra_investment_ttc'),
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
		if ((string) $values['retail_mode'] !== 'manual' && !lmdbpropalpvSubscribedPowerIsSupported((float) $values['subscription_kva'])) {
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

		$batteryRate = $values['battery_self_consumption_pct'];
		$batteryExtraInvestment = $values['battery_extra_investment_ttc'];
		$batteryProposalId = (int) $values['battery_proposal_id'];
		$batteryConfigured = $batteryRate !== null || $batteryExtraInvestment !== null || $batteryProposalId > 0;
		$batteryMissing = array();
		if ($batteryConfigured && ($batteryRate === null || (float) $batteryRate < 0.0 || (float) $batteryRate > 100.0)) {
			$batteryMissing[] = $batteryRate === null ? 'LmdbPropalPVMissingBatterySelfConsumption' : 'LmdbPropalPVInvalidBatterySelfConsumption';
		}
		if ($batteryConfigured && ($batteryExtraInvestment === null || (float) $batteryExtraInvestment <= 0.0)) {
			$batteryMissing[] = 'LmdbPropalPVMissingBatteryExtraInvestment';
		}
		$batterySource = null;
		$batteryWarningKeys = array();
		if ($batteryProposalId > 0) {
			$batterySource = $this->resolveBatteryProposalSnapshot($propal, $batteryProposalId);
			if ($batterySource === null) {
				$batteryWarningKeys[] = 'LmdbPropalPVBatteryProposalUnavailable';
			}
		}
		$batteryComplete = $batteryConfigured && empty($batteryMissing);
		$batteryInvestmentTtc = (float) price2num($investmentTtc + ($batteryExtraInvestment !== null ? (float) $batteryExtraInvestment : 0.0), 'MT');

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
				(float) $values['premium_per_kwp'],
				$projectionYears
			);
			$result = (new LmdbPropalPVFinancialCalculator())->calculate($input);
		}
		$batteryInput = null;
		$batteryResult = null;
		if (empty($missing) && $batteryComplete && $batteryRate !== null) {
			$batteryInput = new LmdbPropalPVFinancialInput(
				$batteryInvestmentTtc,
				$currencyCode,
				$peakPowerKwp,
				(float) $values['annual_production_kwh'],
				(float) $batteryRate / 100.0,
				(float) $values['first_year_degradation_pct'] / 100.0,
				(float) $values['panel_degradation_pct'] / 100.0,
				(float) $values['electricity_growth_pct'] / 100.0,
				(float) $values['retail_price_per_kwh'],
				(float) $values['feed_in_price_per_kwh'],
				(float) $values['premium_per_kwp'],
				$projectionYears
			);
			$batteryResult = (new LmdbPropalPVFinancialCalculator())->calculate($batteryInput);
		}
		$connectionResult = (new LmdbPropalPVConnectionPowerChecker())->check(new LmdbPropalPVConnectionPowerInput(
			$peakPowerKwp,
			$inverterPower,
			(float) $values['subscription_kva'],
			$phaseMode,
			(bool) $inverterResolution['data_complete']
		));

		return array(
			'complete' => empty($missing),
			'missing' => $missing,
			'input' => $input,
			'result' => $result,
			'projection_years' => $projectionYears,
			'battery_configured' => $batteryConfigured,
			'battery_complete' => $batteryComplete,
			'battery_missing' => $batteryMissing,
			'battery_input' => $batteryInput,
			'battery_result' => $batteryResult,
			'battery_extra_investment_ttc' => $batteryExtraInvestment,
			'battery_investment_ttc' => $batteryInvestmentTtc,
			'battery_proposal_id' => $batteryProposalId,
			'battery_proposal_source' => $batterySource,
			'battery_warning_keys' => $batteryWarningKeys,
			'peak_power_kwp' => $peakPowerKwp,
			'investment_ttc' => $investmentTtc,
			'currency_code' => $currencyCode,
			'reference_date' => (string) $values['reference_date'],
			'values' => $values,
			'degradation_warning_keys' => array_values($degradationResolution['warning_keys']),
			'degradation_fallback_product_refs' => array_values($degradationResolution['fallback_product_refs']),
			'degradation_source' => (string) $degradationResolution['source'],
			'connection_result' => $connectionResult,
			'connection_warning_keys' => array_values(array_unique(array_merge($inverterResolution['warning_keys'], $connectionResult->warningKeys))),
			'connection_product_refs' => array_values($inverterResolution['product_refs']),
			'connection_source' => (string) $inverterResolution['source'],
		);
	}


	/**
	 * List battery proposals compatible with the current proposal.
	 *
	 * @param Propal $propal Loaded current proposal
	 * @return array<int,array{id:int,label:string,amount_ttc:float,ref:string,ref_client:string}>
	 */
	public function getBatteryProposalOptions($propal)
	{
		$options = array();
		if ((int) $propal->socid <= 0 || (int) $propal->id <= 0) {
			return $options;
		}
		$currencyCode = $this->proposalCurrencyCode($propal);
		$sql = 'SELECT p.rowid, p.ref, p.ref_client, p.total_ttc, p.multicurrency_total_ttc, p.multicurrency_code';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'propal AS p';
		$sql .= ' WHERE p.entity = '.((int) $propal->entity);
		$sql .= ' AND p.fk_soc = '.((int) $propal->socid);
		$sql .= ' AND p.rowid <> '.((int) $propal->id);
		$sql .= ' ORDER BY p.datep DESC, p.rowid DESC';
		$resql = $this->db->query($sql);
		if (!$resql) {
			return $options;
		}
		while (is_object($obj = $this->db->fetch_object($resql))) {
			$candidateCurrency = !empty($obj->multicurrency_code) ? (string) $obj->multicurrency_code : (string) $GLOBALS['conf']->currency;
			if ($candidateCurrency !== $currencyCode) {
				continue;
			}
			$amountRaw = !empty($obj->multicurrency_code) ? (float) $obj->multicurrency_total_ttc : (float) $obj->total_ttc;
			$label = (string) $obj->ref;
			if (!empty($obj->ref_client)) {
				$label .= ' - '.(string) $obj->ref_client;
			}
			$options[(int) $obj->rowid] = array(
				'id' => (int) $obj->rowid,
				'label' => $label,
				'amount_ttc' => (float) price2num($amountRaw, 'MT'),
				'ref' => (string) $obj->ref,
				'ref_client' => (string) $obj->ref_client,
			);
		}
		$this->db->free($resql);

		return $options;
	}

	/**
	 * Resolve and validate a proposal used as the source of a battery snapshot.
	 *
	 * @param Propal $propal            Loaded current proposal
	 * @param int    $batteryProposalId Source proposal ID
	 * @return array{id:int,label:string,amount_ttc:float,ref:string,ref_client:string}|null
	 */
	public function resolveBatteryProposalSnapshot($propal, $batteryProposalId)
	{
		$options = $this->getBatteryProposalOptions($propal);
		return isset($options[(int) $batteryProposalId]) ? $options[(int) $batteryProposalId] : null;
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


	/** @return string */
	private function proposalCurrencyCode($propal)
	{
		return !empty($propal->multicurrency_code) ? (string) $propal->multicurrency_code : (string) $GLOBALS['conf']->currency;
	}

	/** @return float|null */
	private function optionNullableFloat($propal, $key)
	{
		$optionKey = 'options_'.$key;
		if (!isset($propal->array_options[$optionKey]) || $propal->array_options[$optionKey] === '') {
			return null;
		}

		return (float) $propal->array_options[$optionKey];
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
