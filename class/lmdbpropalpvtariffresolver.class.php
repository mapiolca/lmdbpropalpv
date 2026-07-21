<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require_once __DIR__.'/lmdbpropalpvtariffmatcher.class.php';

/** Resolve the dated tariff snapshot applicable to a proposal. */
class LmdbPropalPVTariffResolver
{
	/** @var DoliDB */
	private $db;

	/** @param DoliDB $db Database handler */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * @return array{tariff_set_id:int,retail_price_per_kwh:?float,feed_in_price_per_kwh:?float,premium_per_kwp:?float,errors:list<string>}
	 */
	public function resolveForProposal(int $entity, string $referenceDate, string $currencyCode, float $peakPowerKwp, string $retailMode, float $subscriptionKva): array
	{
		$result = array(
			'tariff_set_id' => 0,
			'retail_price_per_kwh' => null,
			'feed_in_price_per_kwh' => null,
			'premium_per_kwp' => null,
			'errors' => array(),
		);

		$feedRules = $this->fetchPowerRules((int) $entity, (string) $referenceDate, (string) $currencyCode, (float) $peakPowerKwp);
		if ($feedRules === null) {
			$result['errors'][] = 'LmdbPropalPVNoPowerTariff';
		} else {
			$result['tariff_set_id'] = $feedRules['set_id'];
			$result['feed_in_price_per_kwh'] = $feedRules['feed'];
			$result['premium_per_kwp'] = $feedRules['premium'];
		}

		if ($retailMode !== 'manual') {
			$retail = $this->fetchRetailRule((int) $entity, (string) $referenceDate, (string) $currencyCode, (string) $retailMode, (float) $subscriptionKva);
			if ($retail === null) {
				$result['errors'][] = 'LmdbPropalPVNoRetailTariff';
			} else {
				$result['retail_price_per_kwh'] = $retail;
			}
		}

		return $result;
	}

	/** @return array{set_id:int,feed:float,premium:float}|null */
	private function fetchPowerRules(int $entity, string $referenceDate, string $currencyCode, float $peakPowerKwp): ?array
	{
		$sql = 'SELECT ts.rowid, r.metric, r.min_kwp, r.max_kwp, r.value FROM '.MAIN_DB_PREFIX.'lmdbpropalpv_tariff_set AS ts';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'lmdbpropalpv_tariff_rule AS r ON r.fk_tariff_set = ts.rowid';
		$sql .= ' WHERE ts.entity = '.$entity;
		$sql .= " AND ts.currency_code = '".$this->db->escape($currencyCode)."'";
		$sql .= " AND ts.date_start <= '".$this->db->escape($referenceDate)."'";
		$sql .= " AND (ts.date_end IS NULL OR ts.date_end >= '".$this->db->escape($referenceDate)."')";
		$sql .= ' AND ts.status = 1 AND r.metric IN (\'feed_in\', \'premium\')';
		// An active administrator-created tariff overrides the embedded official
		// history. Official sets remain the immutable fallback.
		$sql .= ' ORDER BY ts.official ASC, ts.source_published_at DESC, ts.rowid DESC, r.metric ASC';
		$resql = $this->db->query($sql);
		if (!$resql) {
			return null;
		}

		$selectedSetId = 0;
		$feed = null;
		$premium = null;
		while (is_object($obj = $this->db->fetch_object($resql))) {
			$minKwp = $obj->min_kwp !== null ? (float) $obj->min_kwp : null;
			$maxKwp = $obj->max_kwp !== null ? (float) $obj->max_kwp : null;
			if (!LmdbPropalPVTariffMatcher::powerRangeMatches($peakPowerKwp, $minKwp, $maxKwp)) {
				continue;
			}
			$setId = (int) $obj->rowid;
			if ($selectedSetId === 0) {
				$selectedSetId = $setId;
			}
			if ($setId !== $selectedSetId) {
				continue;
			}
			if ((string) $obj->metric === 'feed_in') {
				$feed = (float) $obj->value;
			} elseif ((string) $obj->metric === 'premium') {
				$premium = (float) $obj->value;
			}
		}
		$this->db->free($resql);

		return $selectedSetId > 0 && $feed !== null && $premium !== null
			? array('set_id' => $selectedSetId, 'feed' => $feed, 'premium' => $premium)
			: null;
	}

	/** @return float|null */
	private function fetchRetailRule(int $entity, string $referenceDate, string $currencyCode, string $retailMode, float $subscriptionKva): ?float
	{
		$sql = 'SELECT r.value FROM '.MAIN_DB_PREFIX.'lmdbpropalpv_tariff_set AS ts';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'lmdbpropalpv_tariff_rule AS r ON r.fk_tariff_set = ts.rowid';
		$sql .= ' WHERE ts.entity = '.$entity;
		$sql .= " AND ts.currency_code = '".$this->db->escape($currencyCode)."'";
		$sql .= " AND ts.date_start <= '".$this->db->escape($referenceDate)."'";
		$sql .= " AND (ts.date_end IS NULL OR ts.date_end >= '".$this->db->escape($referenceDate)."')";
		$sql .= " AND ts.status = 1 AND r.metric = 'retail'";
		$sql .= " AND r.option_code = '".$this->db->escape($retailMode)."'";
		$sql .= ' AND r.subscription_kva = '.((float) $subscriptionKva);
		$sql .= ' ORDER BY ts.official ASC, ts.source_published_at DESC, ts.rowid DESC';
		$resql = $this->db->query($sql);
		if (!$resql) {
			return null;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);

		return is_object($obj) ? (float) $obj->value : null;
	}
}
