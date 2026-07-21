<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

/** Immutable normalized inputs for the 20-year financial projection. */
class LmdbPropalPVFinancialInput
{
	public float $investmentTtc;
	public string $currencyCode;
	public float $peakPowerKwp;
	public float $annualProductionKwh;
	public float $selfConsumptionRate;
	public float $panelDegradationRate;
	public float $electricityGrowthRate;
	public float $retailPricePerKwh;
	public float $feedInPricePerKwh;
	public float $premiumPerKwp;

	public function __construct(
		float $investmentTtc,
		string $currencyCode,
		float $peakPowerKwp,
		float $annualProductionKwh,
		float $selfConsumptionRate,
		float $panelDegradationRate,
		float $electricityGrowthRate,
		float $retailPricePerKwh,
		float $feedInPricePerKwh,
		float $premiumPerKwp
	) {
		$this->investmentTtc = $investmentTtc;
		$this->currencyCode = $currencyCode;
		$this->peakPowerKwp = $peakPowerKwp;
		$this->annualProductionKwh = $annualProductionKwh;
		$this->selfConsumptionRate = $selfConsumptionRate;
		$this->panelDegradationRate = $panelDegradationRate;
		$this->electricityGrowthRate = $electricityGrowthRate;
		$this->retailPricePerKwh = $retailPricePerKwh;
		$this->feedInPricePerKwh = $feedInPricePerKwh;
		$this->premiumPerKwp = $premiumPerKwp;
	}
}
