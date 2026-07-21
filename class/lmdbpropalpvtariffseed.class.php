<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

/** Idempotent release-time seed of official tariff history. */
class LmdbPropalPVTariffSeed
{
	/** @var DoliDB */
	private $db;
	/** @var string */
	public $error = '';

	/** @param DoliDB $db Database handler */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/** @return int */
	public function seed($entity)
	{
		$this->db->begin();
		if ($this->seedS21((int) $entity) < 0 || $this->seedTrve((int) $entity) < 0) {
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();

		return 1;
	}

	/** @return int */
	private function seedS21($entity)
	{
		$source = 'https://www.cre.fr/documents/open-data/arretes-tarifaires-photovoltaiques-en-metropole.html';
		$periods = array(
			array('S21-2021-10', '2021-10-09', '2022-01-31', 0.10, 0.06, 380.0, 290.0, 160.0, 80.0),
			array('S21-2022-02', '2022-02-01', '2022-04-30', 0.10, 0.06, 380.0, 290.0, 160.0, 80.0),
			array('S21-2022-05-A', '2022-05-01', '2022-07-31', 0.10, 0.06, 390.0, 290.0, 160.0, 80.0),
			array('S21-2022-05-B', '2022-05-01', '2022-07-31', 0.10, 0.06, 410.0, 310.0, 170.0, 90.0),
			array('S21-2022-08', '2022-08-01', '2022-10-31', 0.10, 0.06, 430.0, 320.0, 180.0, 90.0),
			array('S21-2022-11', '2022-11-01', '2023-01-31', 0.12534590605109395, 0.07520754363065636, 476.314442994157, 357.2358322456178, 200.55344968175032, 100.27672484087516),
			array('S21-2023-02', '2023-02-01', '2023-04-30', 0.13129210574328642, 0.07877526344597186, 498.9100018244884, 374.18250136836634, 210.06736918925828, 105.03368459462914),
			array('S21-2023-05', '2023-05-01', '2023-07-31', 0.13385332869765676, 0.08031199721859405, 508.6426490510957, 381.4819867883218, 214.16532591625081, 107.08266295812541),
			array('S21-2023-08', '2023-08-01', '2023-10-31', 0.13385332869765676, 0.08031199721859405, 441.09080020004215, 330.81810015003166, 211.65772381280665, 105.82886190640332),
			array('S21-2023-11', '2023-11-01', '2024-01-31', 0.1300179084028533, 0.07801074504171198, 368.4363518174027, 276.3272638630521, 203.0762721473593, 101.53813607367965),
			array('S21-2024-02', '2024-02-01', '2024-04-30', 0.1297374001754202, 0.07784244010525213, 352.05346757223904, 264.0401006791793, 200.22446771342228, 100.11223385671114),
			array('S21-2024-05', '2024-05-01', '2024-07-31', 0.13013254964133392, 0.07807952978480035, 303.66158069894306, 227.7461855242073, 199.02982915154083, 99.51491457577041),
			array('S21-2024-08', '2024-08-01', '2024-10-31', 0.1275702322693557, 0.07654213936161341, 255.98453567917057, 191.9884017593779, 193.61804813239913, 96.80902406619957),
			array('S21-2024-11', '2024-11-01', '2025-01-31', 0.12687014539126453, 0.07612208723475872, 220.0, 160.0, 190.0, 100.0),
			array('S21-2025-02', '2025-02-01', '2025-03-27', 0.12690398922843741, 0.07614239353706245, 210.0, 160.0, 190.0, 100.0),
			array('S21-2025-03-28', '2025-03-28', '2025-03-31', 0.04, 0.07614239353706245, 80.0, 80.0, 190.0, 100.0),
			array('S21-2025-04', '2025-04-01', '2025-06-30', 0.04, 0.07614031549630547, 80.0, 80.0, 192.567852835914, 96.283926417957),
			array('S21-2025-07', '2025-07-01', '2025-09-30', 0.04, 0.0730623893056442, 80.0, 80.0, 184.78341388449115, 92.39170694224558),
			array('S21-2025-10', '2025-10-01', '2026-01-01', 0.04, 0.0616610235066602, 80.0, 80.0, 155.94801286210233, 77.97400643105117),
			array('S21-2026-01', '2026-01-01', '2026-04-01', 0.04, 0.05355013243601948, 80.0, 80.0, 135.43461115266156, 67.71730557633078),
			array('S21-2026-04', '2026-04-01', '2026-06-04', 0.04, 0.04732180786754383, 80.0, 80.0, 119.682433827542, 59.841216913771235),
			array('S21-2026-06-05', '2026-06-05', null, 0.011, 0.011, 0.0, 0.0, 0.0, 0.0),
		);

		foreach ($periods as $period) {
			$setId = $this->ensureSet($entity, $period[0], 'S21 '.$period[0], $period[1], $period[2], 'EUR', $source, '2026-06-08', '5fb83f3d4ba59fc040c25e51adf6ca9e2e6592e4a4b59a90fe8132dca4350876', 1);
			if ($setId <= 0) {
				return -1;
			}
			$rules = array(
				array('feed_in', 'surplus', null, 0.0, 9.0, $period[3], 'EUR/kWh'),
				array('feed_in', 'surplus', null, 9.0, 100.0, $period[4], 'EUR/kWh'),
				array('premium', 'surplus', null, 0.0, 3.0, $period[5], 'EUR/kWp'),
				array('premium', 'surplus', null, 3.0, 9.0, $period[6], 'EUR/kWp'),
				array('premium', 'surplus', null, 9.0, 36.0, $period[7], 'EUR/kWp'),
				array('premium', 'surplus', null, 36.0, 100.0, $period[8], 'EUR/kWp'),
			);
			foreach ($rules as $rule) {
				if ($this->ensureRule($setId, $rule[0], $rule[1], $rule[2], $rule[3], $rule[4], $rule[5], $rule[6]) < 0) {
					return -1;
				}
			}
		}

		return 1;
	}

	/** @return int */
	private function seedTrve($entity)
	{
		$source = 'https://www.data.gouv.fr/datasets/historique-des-tarifs-reglementes-de-vente-delectricite-pour-les-consommateurs-residentiels';
		$periods = array(
			// Applicable slice of the official 2020-08-01 to 2021-01-31 period,
			// truncated to the requested history start on 2021-01-01.
			array('TRVE-2021-01', '2021-01-01', '2021-01-31', array(3 => 0.1557, 6 => 0.1557, 9 => 0.1597, 12 => 0.1597, 15 => 0.1597), 0.1798),
			array('TRVE-2021-02', '2021-02-01', '2021-07-31', array(3 => 0.1582, 6 => 0.1582, 9 => 0.163, 12 => 0.163, 15 => 0.163), 0.1853),
			array('TRVE-2021-08', '2021-08-01', '2022-01-31', array(3 => 0.1558, 6 => 0.1558, 9 => 0.1605, 12 => 0.1605, 15 => 0.1605), 0.1821),
			array('TRVE-2022-02', '2022-02-01', '2022-07-31', array(3 => 0.174, 6 => 0.174, 9 => 0.174, 12 => 0.174, 15 => 0.174), 0.1841),
			array('TRVE-2022-08', '2022-08-01', '2023-01-31', array(3 => 0.174, 6 => 0.174, 9 => 0.174, 12 => 0.174, 15 => 0.174), 0.1841),
			array('TRVE-2023-02', '2023-02-01', '2023-07-31', array(3 => 0.2062, 6 => 0.2062, 9 => 0.2062, 12 => 0.2062, 15 => 0.2062), 0.2228),
			array('TRVE-2023-08', '2023-08-01', '2024-01-31', array(3 => 0.2276, 6 => 0.2276, 9 => 0.2276, 12 => 0.2276, 15 => 0.2276), 0.246),
			array('TRVE-2024-02', '2024-02-01', '2025-01-31', array(3 => 0.2516, 6 => 0.2516, 9 => 0.2516, 12 => 0.2516, 15 => 0.2516), 0.27),
			array('TRVE-2025-02', '2025-02-01', '2025-07-31', array(3 => 0.2016, 6 => 0.2016, 9 => 0.2016, 12 => 0.2016, 15 => 0.2016), 0.2146),
			array('TRVE-2025-08', '2025-08-01', '2026-01-31', array(3 => 0.1952, 6 => 0.1952, 9 => 0.1952, 12 => 0.1952, 15 => 0.1952), 0.2081),
			array('TRVE-2026-02', '2026-02-01', null, array(3 => 0.19398, 6 => 0.19398, 9 => 0.19266, 12 => 0.19266, 15 => 0.19266), 0.2065),
		);
		$peakPowers = array(6, 9, 12, 15, 18, 24, 30, 36);

		foreach ($periods as $period) {
			$setId = $this->ensureSet($entity, $period[0], 'TRVE '.$period[0], $period[1], $period[2], 'EUR', $source, '2026-02-01', '51df3b89a5ebf4fdefab782a696e37b210f595ecbabb465a41699c80a5e1eaf5c53c8612ea26d150eafb2bae54ea2223134050f13b073dcb0b9340d633aacd9f', 1);
			if ($setId <= 0) {
				return -1;
			}
			foreach ($period[3] as $kva => $value) {
				if ($this->ensureRule($setId, 'retail', 'base', (float) $kva, null, null, (float) $value, 'EUR/kWh') < 0) {
					return -1;
				}
			}
			foreach ($peakPowers as $kva) {
				if ($this->ensureRule($setId, 'retail', 'peak', (float) $kva, null, null, (float) $period[4], 'EUR/kWh') < 0) {
					return -1;
				}
			}
		}

		return 1;
	}

	/** @return int */
	private function ensureSet($entity, $ref, $label, $dateStart, $dateEnd, $currency, $sourceUrl, $publishedAt, $sourceHash, $official)
	{
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'lmdbpropalpv_tariff_set';
		$sql .= ' WHERE entity = '.((int) $entity)." AND ref = '".$this->db->escape($ref)."'";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (is_object($obj)) {
			return (int) $obj->rowid;
		}

		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'lmdbpropalpv_tariff_set (entity, ref, label, date_start, date_end, currency_code, source_url, source_published_at, source_hash, official, status, date_creation) VALUES (';
		$sql .= ((int) $entity).", '".$this->db->escape($ref)."', '".$this->db->escape($label)."', '".$this->db->escape($dateStart)."', ";
		$sql .= $dateEnd === null ? 'NULL, ' : "'".$this->db->escape($dateEnd)."', ";
		$sql .= "'".$this->db->escape($currency)."', '".$this->db->escape($sourceUrl)."', '".$this->db->escape($publishedAt)."', '".$this->db->escape($sourceHash)."', ".((int) $official).', 1, ';
		$sql .= "'".$this->db->idate(dol_now())."')";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		return (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'lmdbpropalpv_tariff_set');
	}

	/** @return int */
	private function ensureRule($setId, $metric, $optionCode, $subscriptionKva, $minKwp, $maxKwp, $value, $unit)
	{
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'lmdbpropalpv_tariff_rule WHERE fk_tariff_set = '.((int) $setId);
		$sql .= " AND metric = '".$this->db->escape($metric)."'";
		$sql .= $optionCode === null ? ' AND option_code IS NULL' : " AND option_code = '".$this->db->escape($optionCode)."'";
		$sql .= $subscriptionKva === null ? ' AND subscription_kva IS NULL' : ' AND subscription_kva = '.((float) $subscriptionKva);
		$sql .= $minKwp === null ? ' AND min_kwp IS NULL' : ' AND min_kwp = '.((float) $minKwp);
		$sql .= $maxKwp === null ? ' AND max_kwp IS NULL' : ' AND max_kwp = '.((float) $maxKwp);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$exists = is_object($this->db->fetch_object($resql));
		$this->db->free($resql);
		if ($exists) {
			return 1;
		}

		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'lmdbpropalpv_tariff_rule (fk_tariff_set, metric, option_code, subscription_kva, min_kwp, max_kwp, value, unit, date_creation) VALUES (';
		$sql .= ((int) $setId).", '".$this->db->escape($metric)."', ";
		$sql .= $optionCode === null ? 'NULL, ' : "'".$this->db->escape($optionCode)."', ";
		$sql .= $subscriptionKva === null ? 'NULL, ' : ((float) $subscriptionKva).', ';
		$sql .= $minKwp === null ? 'NULL, ' : ((float) $minKwp).', ';
		$sql .= $maxKwp === null ? 'NULL, ' : ((float) $maxKwp).', ';
		$sql .= ((float) $value).", '".$this->db->escape($unit)."', '".$this->db->idate(dol_now())."')";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		return 1;
	}
}
