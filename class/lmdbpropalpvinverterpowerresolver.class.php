<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

/** Resolve inverter nominal AC power and phases from proposal PowerPlantPV products. */
class LmdbPropalPVInverterPowerResolver
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
	 * @return array{total_nominal_power_kva:?float,data_complete:bool,has_three_phase:bool,warning_keys:list<string>,product_refs:list<string>,source:string}
	 */
	public function resolveForProposal($propal): array
	{
		if (!is_object($propal) || empty($propal->id) || !self::isSchemaAvailable($this->db)) {
			return self::fallback('LmdbPropalPVConnectionInverterSchemaUnavailable');
		}

		$quantities = $this->fetchProductQuantities((int) $propal->id, !empty($propal->entity) ? (int) $propal->entity : 0);
		if ($quantities === null) {
			return self::fallback('LmdbPropalPVConnectionInverterSchemaUnavailable');
		}
		if (empty($quantities)) {
			return self::fallback('LmdbPropalPVConnectionNoEligibleInverter');
		}

		$rows = $this->fetchInverterRows($quantities);
		if ($rows === null) {
			return self::fallback('LmdbPropalPVConnectionInverterSchemaUnavailable');
		}

		return self::aggregate($rows);
	}

	/** Check the PowerPlantPV inverter table and the exact columns used by this module. */
	public static function isSchemaAvailable($db): bool
	{
		if (!is_object($db)) {
			return false;
		}
		$resql = $db->query('SELECT ac_nominal_power, phase_count FROM '.MAIN_DB_PREFIX.'powerplantpv_product_inverter WHERE 1 = 0');
		if (!$resql) {
			return false;
		}
		$db->free($resql);

		return true;
	}

	/**
	 * Pure aggregation used by the resolver and unit tests.
	 *
	 * @param list<array{product_ref:string,quantity:float,ac_nominal_power_w:?float,phase_count:?int}> $rows
	 * @return array{total_nominal_power_kva:?float,data_complete:bool,has_three_phase:bool,warning_keys:list<string>,product_refs:list<string>,source:string}
	 */
	public static function aggregate(array $rows): array
	{
		if (empty($rows)) {
			return self::fallback('LmdbPropalPVConnectionNoEligibleInverter');
		}

		$totalWatts = 0.0;
		$hasUsablePower = false;
		$dataComplete = true;
		$hasThreePhase = false;
		$problemRefs = array();
		foreach ($rows as $row) {
			$quantity = (float) $row['quantity'];
			$nominalPower = $row['ac_nominal_power_w'];
			if ($quantity <= 0.0) {
				$dataComplete = false;
				if ($row['product_ref'] !== '') {
					$problemRefs[] = $row['product_ref'];
				}
				continue;
			}
			if ($row['phase_count'] === 3) {
				$hasThreePhase = true;
			} elseif ($row['phase_count'] !== 1) {
				$dataComplete = false;
				if ($row['product_ref'] !== '') {
					$problemRefs[] = $row['product_ref'];
				}
			}
			if ($nominalPower === null || $nominalPower <= 0.0) {
				$dataComplete = false;
				if ($row['product_ref'] !== '') {
					$problemRefs[] = $row['product_ref'];
				}
				continue;
			}
			$hasUsablePower = true;
			$totalWatts += $quantity * $nominalPower;
		}

		if (!$hasUsablePower) {
			$result = self::fallback('LmdbPropalPVConnectionInverterDataUnavailable');
			$result['product_refs'] = array_values(array_unique($problemRefs));
			return $result;
		}

		return array(
			'total_nominal_power_kva' => $totalWatts / 1000.0,
			'data_complete' => $dataComplete,
			'has_three_phase' => $hasThreePhase,
			'warning_keys' => $dataComplete ? array() : array('LmdbPropalPVConnectionInverterDataUnavailable'),
			'product_refs' => array_values(array_unique($problemRefs)),
			'source' => 'powerplantpv',
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
	 * @return list<array{product_ref:string,quantity:float,ac_nominal_power_w:?float,phase_count:?int}>|null
	 */
	private function fetchInverterRows(array $quantities): ?array
	{
		$productIds = array_values(array_filter(array_map('intval', array_keys($quantities))));
		if (empty($productIds)) {
			return array();
		}
		$sql = 'SELECT p.rowid, p.ref, inv.entity as technical_entity, inv.ac_nominal_power, inv.phase_count';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'product as p';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product_extrafields as pe ON pe.fk_object = p.rowid';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'c_powerplantpv_categorypv as cat ON cat.rowid = pe.categorie_photovoltaique';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'powerplantpv_product_inverter as inv ON inv.fk_product = p.rowid AND inv.entity IN ('.getEntity('product').')';
		$sql .= ' WHERE p.rowid IN ('.implode(',', $productIds).')';
		$sql .= ' AND p.entity IN ('.getEntity('product').')';
		$sql .= " AND cat.code = 'ONDULE'";
		$sql .= ' ORDER BY p.rowid ASC, inv.entity DESC';
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
			$rows[] = array(
				'product_ref' => (string) $obj->ref,
				'quantity' => (float) $quantities[$productId],
				'ac_nominal_power_w' => is_numeric($obj->ac_nominal_power) ? (float) $obj->ac_nominal_power : null,
				'phase_count' => is_numeric($obj->phase_count) ? (int) $obj->phase_count : null,
			);
		}
		$this->db->free($resql);

		return $rows;
	}

	/**
	 * @return array{total_nominal_power_kva:?float,data_complete:bool,has_three_phase:bool,warning_keys:list<string>,product_refs:list<string>,source:string}
	 */
	private static function fallback(string $warningKey): array
	{
		return array(
			'total_nominal_power_kva' => null,
			'data_complete' => false,
			'has_three_phase' => false,
			'warning_keys' => array($warningKey),
			'product_refs' => array(),
			'source' => 'fallback_peak_power',
		);
	}
}
