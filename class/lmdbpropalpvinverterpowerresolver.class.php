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
		$quantities = $this->fetchExpandedProductQuantities($quantities);
		if ($quantities === null) {
			return self::fallback('LmdbPropalPVConnectionKitCompositionUnavailable');
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
	 * Add the recursively expanded native Dolibarr kit components to proposal quantities.
	 *
	 * @param array<int,float> $quantities Direct proposal product quantities
	 * @return array<int,float>|null Expanded quantities, or null when a kit tree cannot be read
	 */
	private function fetchExpandedProductQuantities(array $quantities): ?array
	{
		if (empty($quantities)) {
			return array();
		}
		if (!class_exists('Product')) {
			require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
		}

		$product = new Product($this->db);
		$kitTrees = array();
		foreach ($quantities as $productId => $quantity) {
			$tree = $product->getChildsArbo($productId);
			if (!is_array($tree)) {
				dol_syslog(__METHOD__.' unable to load kit tree for product '.((string) $productId), LOG_WARNING);
				return null;
			}
			$kitTrees[$productId] = $tree;
		}

		return self::expandProductQuantities($quantities, $kitTrees);
	}

	/**
	 * Pure expansion of proposal quantities through native Dolibarr kit trees.
	 *
	 * The tree nodes use the numeric shape returned by Product::getChildsArbo(). Values
	 * are validated at this Dolibarr boundary before being used by the calculation.
	 *
	 * @param array<int,float> $proposalQuantities Direct proposal product quantities
	 * @param array<int,array<int,array<int|string,mixed>>> $kitTrees Trees indexed by proposal product ID
	 * @return array<int,float>|null Expanded quantities, or null when a tree is malformed
	 */
	public static function expandProductQuantities(array $proposalQuantities, array $kitTrees): ?array
	{
		$expanded = array();
		foreach ($proposalQuantities as $productId => $quantity) {
			$productId = (int) $productId;
			$quantity = (float) $quantity;
			if ($productId <= 0 || $quantity <= 0.0 || !is_finite($quantity) || !isset($kitTrees[$productId]) || !is_array($kitTrees[$productId])) {
				return null;
			}
			$expanded[$productId] = ($expanded[$productId] ?? 0.0) + $quantity;
			if (!self::appendKitTreeQuantities($kitTrees[$productId], $quantity, $expanded)) {
				return null;
			}
		}

		return $expanded;
	}

	/**
	 * @param array<int,array<int|string,mixed>> $tree Native Dolibarr kit tree
	 * @param float $parentQuantity Effective parent quantity
	 * @param array<int,float> $expanded Accumulated effective product quantities
	 * @return bool False when a native tree node is malformed
	 */
	private static function appendKitTreeQuantities(array $tree, float $parentQuantity, array &$expanded): bool
	{
		foreach ($tree as $node) {
			if (!is_array($node) || !isset($node[0], $node[1]) || !is_numeric($node[0]) || !is_numeric($node[1])) {
				return false;
			}
			$productId = (int) $node[0];
			$componentQuantity = (float) $node[1];
			$effectiveQuantity = $parentQuantity * $componentQuantity;
			if ($productId <= 0 || $componentQuantity <= 0.0 || !is_finite($componentQuantity) || !is_finite($effectiveQuantity)) {
				return false;
			}
			$expanded[$productId] = ($expanded[$productId] ?? 0.0) + $effectiveQuantity;

			if (array_key_exists('childs', $node)) {
				if (!is_array($node['childs']) || !self::appendKitTreeQuantities($node['childs'], $effectiveQuantity, $expanded)) {
					return false;
				}
			}
		}

		return true;
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
