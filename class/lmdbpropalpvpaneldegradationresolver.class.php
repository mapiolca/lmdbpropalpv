<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

/** Resolve proposal panel degradation assumptions from PowerPlantPV product data. */
class LmdbPropalPVPanelDegradationResolver
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
	 * @return array{first_year_degradation_pct:float,annual_degradation_pct:float,source:string,used_defaults:bool,total_power_wp:float,warning_keys:list<string>,fallback_product_refs:list<string>}
	 */
	public function resolveForProposal($propal, float $defaultFirstYearPct, float $defaultAnnualPct): array
	{
		if (!is_object($propal) || empty($propal->id)) {
			return self::fallback($defaultFirstYearPct, $defaultAnnualPct, 'LmdbPropalPVDegradationFallbackNoEligibleModule');
		}
		if (!function_exists('powerplantpvGetPhotovoltaicModuleCategoryIds')) {
			dol_include_once('/powerplantpv/lib/powerplantpv.lib.php');
		}
		if (!function_exists('powerplantpvGetPhotovoltaicModuleCategoryIds')) {
			return self::fallback($defaultFirstYearPct, $defaultAnnualPct, 'LmdbPropalPVDegradationFallbackUnavailable');
		}

		$categories = powerplantpvGetPhotovoltaicModuleCategoryIds();
		if (!is_array($categories) || (int) ($categories['result'] ?? -1) < 0 || empty($categories['ids']) || !is_array($categories['ids'])) {
			return self::fallback($defaultFirstYearPct, $defaultAnnualPct, 'LmdbPropalPVDegradationFallbackUnavailable');
		}

		$quantities = $this->fetchProductQuantities((int) $propal->id, !empty($propal->entity) ? (int) $propal->entity : 0);
		if ($quantities === null) {
			return self::fallback($defaultFirstYearPct, $defaultAnnualPct, 'LmdbPropalPVDegradationFallbackUnavailable');
		}
		if (empty($quantities)) {
			return self::fallback($defaultFirstYearPct, $defaultAnnualPct, 'LmdbPropalPVDegradationFallbackNoEligibleModule');
		}

		$rows = $this->fetchPanelRows($quantities, array_values(array_map('intval', $categories['ids'])));
		if ($rows === null) {
			return self::fallback($defaultFirstYearPct, $defaultAnnualPct, 'LmdbPropalPVDegradationFallbackUnavailable');
		}

		return self::aggregate($rows, $defaultFirstYearPct, $defaultAnnualPct);
	}

	/** @return bool */
	public static function isSchemaAvailable($db): bool
	{
		if (!is_object($db)) {
			return false;
		}
		$sql = 'SELECT pmax, first_year_degradation, annual_degradation';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'powerplantpv_product_pvpanel WHERE 1 = 0';
		$resql = $db->query($sql);
		if (!$resql) {
			return false;
		}
		$db->free($resql);
		return true;
	}

	/**
	 * Pure weighted aggregation used by the resolver and unit tests.
	 *
	 * @param list<array{product_ref:string,weight_wp:float,first_year_degradation_pct:?float,annual_degradation_pct:?float}> $rows
	 * @return array{first_year_degradation_pct:float,annual_degradation_pct:float,source:string,used_defaults:bool,total_power_wp:float,warning_keys:list<string>,fallback_product_refs:list<string>}
	 */
	public static function aggregate(array $rows, float $defaultFirstYearPct, float $defaultAnnualPct): array
	{
		$totalPowerWp = 0.0;
		$weightedFirstYear = 0.0;
		$weightedAnnual = 0.0;
		$usedDefaults = false;
		$fallbackRefs = array();

		foreach ($rows as $row) {
			$weightWp = (float) $row['weight_wp'];
			if ($weightWp <= 0.0) {
				$usedDefaults = true;
				if ($row['product_ref'] !== '') {
					$fallbackRefs[] = $row['product_ref'];
				}
				continue;
			}
			$firstYear = self::validPercentage($row['first_year_degradation_pct']) ? (float) $row['first_year_degradation_pct'] : $defaultFirstYearPct;
			$annual = self::validPercentage($row['annual_degradation_pct']) ? (float) $row['annual_degradation_pct'] : $defaultAnnualPct;
			if (!self::validPercentage($row['first_year_degradation_pct']) || !self::validPercentage($row['annual_degradation_pct'])) {
				$usedDefaults = true;
				if ($row['product_ref'] !== '') {
					$fallbackRefs[] = $row['product_ref'];
				}
			}
			$totalPowerWp += $weightWp;
			$weightedFirstYear += $weightWp * $firstYear;
			$weightedAnnual += $weightWp * $annual;
		}

		if ($totalPowerWp <= 0.0) {
			$result = self::fallback($defaultFirstYearPct, $defaultAnnualPct, 'LmdbPropalPVDegradationFallbackNoEligibleModule');
			$result['fallback_product_refs'] = array_values(array_unique($fallbackRefs));
			return $result;
		}

		return array(
			'first_year_degradation_pct' => $weightedFirstYear / $totalPowerWp,
			'annual_degradation_pct' => $weightedAnnual / $totalPowerWp,
			'source' => 'powerplantpv',
			'used_defaults' => $usedDefaults,
			'total_power_wp' => $totalPowerWp,
			'warning_keys' => $usedDefaults ? array('LmdbPropalPVDegradationFallbackProductData') : array(),
			'fallback_product_refs' => array_values(array_unique($fallbackRefs)),
		);
	}

	/** @return array<int,float>|null */
	private function fetchProductQuantities(int $proposalId, int $entity): ?array
	{
		$sql = 'SELECT l.fk_product, l.qty FROM '.MAIN_DB_PREFIX.'propaldet as l';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'propal as p ON p.rowid = l.fk_propal';
		$sql .= ' WHERE l.fk_propal = '.$proposalId.' AND l.fk_product > 0 AND l.qty > 0';
		if ($entity > 0) {
			$sql .= ' AND p.entity = '.$entity;
		}
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_WARNING);
			return null;
		}
		$quantities = array();
		while (is_object($obj = $this->db->fetch_object($resql))) {
			$productId = (int) $obj->fk_product;
			$quantities[$productId] = ($quantities[$productId] ?? 0.0) + (float) $obj->qty;
		}
		$this->db->free($resql);
		return $quantities;
	}

	/**
	 * @param array<int,float> $quantities
	 * @param list<int> $categoryIds
	 * @return list<array{product_ref:string,weight_wp:float,first_year_degradation_pct:?float,annual_degradation_pct:?float}>|null
	 */
	private function fetchPanelRows(array $quantities, array $categoryIds): ?array
	{
		$productIds = array_values(array_filter(array_map('intval', array_keys($quantities))));
		$categoryIds = array_values(array_filter(array_map('intval', $categoryIds)));
		if (empty($productIds) || empty($categoryIds)) {
			return array();
		}
		$sql = 'SELECT p.rowid, p.ref, pv.entity as technical_entity, pv.pmax, pv.first_year_degradation, pv.annual_degradation';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'product as p';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product_extrafields as pe ON pe.fk_object = p.rowid';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'powerplantpv_product_pvpanel as pv ON pv.fk_product = p.rowid AND pv.entity IN ('.getEntity('product').')';
		$sql .= ' WHERE p.rowid IN ('.implode(',', $productIds).')';
		$sql .= ' AND p.entity IN ('.getEntity('product').')';
		$sql .= ' AND pe.categorie_photovoltaique IN ('.implode(',', $categoryIds).')';
		$sql .= ' ORDER BY p.rowid ASC, pv.entity DESC';
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_WARNING);
			return null;
		}

		$rows = array();
		$selected = array();
		while (is_object($obj = $this->db->fetch_object($resql))) {
			$productId = (int) $obj->rowid;
			if (isset($selected[$productId])) {
				continue;
			}
			$selected[$productId] = true;
			$pmax = is_numeric($obj->pmax) ? (float) $obj->pmax : 0.0;
			$firstYearDegradation = is_numeric($obj->first_year_degradation) ? (float) $obj->first_year_degradation : null;
			$annualDegradation = is_numeric($obj->annual_degradation) ? (float) $obj->annual_degradation : null;
			$rows[] = array(
				'product_ref' => (string) $obj->ref,
				'weight_wp' => $pmax > 0.0 ? $pmax * (float) $quantities[$productId] : 0.0,
				'first_year_degradation_pct' => $firstYearDegradation,
				'annual_degradation_pct' => $annualDegradation,
			);
		}
		$this->db->free($resql);
		return $rows;
	}

	/** @return bool */
	private static function validPercentage(?float $value): bool
	{
		return $value !== null && $value >= 0.0 && $value < 100.0;
	}

	/**
	 * @return array{first_year_degradation_pct:float,annual_degradation_pct:float,source:string,used_defaults:bool,total_power_wp:float,warning_keys:list<string>,fallback_product_refs:list<string>}
	 */
	private static function fallback(float $defaultFirstYearPct, float $defaultAnnualPct, string $warningKey): array
	{
		return array(
			'first_year_degradation_pct' => $defaultFirstYearPct,
			'annual_degradation_pct' => $defaultAnnualPct,
			'source' => 'entity_default',
			'used_defaults' => true,
			'total_power_wp' => 0.0,
			'warning_keys' => array($warningKey),
			'fallback_product_refs' => array(),
		);
	}
}
