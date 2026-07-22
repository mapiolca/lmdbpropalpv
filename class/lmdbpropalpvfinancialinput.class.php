<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

/** Immutable normalized inputs for one financial projection scenario. */
class LmdbPropalPVFinancialInput
{
	public float $investmentTtc;
	public string $currencyCode;
	public float $peakPowerKwp;
	public float $annualProductionKwh;
	public float $selfConsumptionRate;
	public float $firstYearPanelDegradationRate;
	public float $annualPanelDegradationRate;
	public float $electricityGrowthRate;
	public float $retailPricePerKwh;
	public float $feedInPricePerKwh;
	public float $premiumPerKwp;
	public int $projectionYears;

	public function __construct(
		float $investmentTtc,
		string $currencyCode,
		float $peakPowerKwp,
		float $annualProductionKwh,
		float $selfConsumptionRate,
		float $firstYearPanelDegradationRate,
		float $annualPanelDegradationRate,
		float $electricityGrowthRate,
		float $retailPricePerKwh,
		float $feedInPricePerKwh,
		float $premiumPerKwp,
		int $projectionYears = 20
	) {
		$this->investmentTtc = $investmentTtc;
		$this->currencyCode = $currencyCode;
		$this->peakPowerKwp = $peakPowerKwp;
		$this->annualProductionKwh = $annualProductionKwh;
		$this->selfConsumptionRate = $selfConsumptionRate;
		$this->firstYearPanelDegradationRate = $firstYearPanelDegradationRate;
		$this->annualPanelDegradationRate = $annualPanelDegradationRate;
		$this->electricityGrowthRate = $electricityGrowthRate;
		$this->retailPricePerKwh = $retailPricePerKwh;
		$this->feedInPricePerKwh = $feedInPricePerKwh;
		$this->premiumPerKwp = $premiumPerKwp;
		$this->projectionYears = $projectionYears;
	}
}
