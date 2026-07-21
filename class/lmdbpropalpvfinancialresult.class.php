<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

/** One projection year. */
class LmdbPropalPVFinancialYear
{
	public int $year;
	public float $productionKwh;
	public float $retailPricePerKwh;
	public float $surplusSale;
	public float $electricitySavings;
	public float $premium;
	public float $annualGain;
	public float $cumulativeCashflow;
	public float $annualReturnRate;
}

/** Typed output of the financial calculator. */
class LmdbPropalPVFinancialResult
{
	/** @var list<LmdbPropalPVFinancialYear> */
	public array $years = array();
	public float $initialCashflow = 0.0;
	public float $totalProductionKwh = 0.0;
	public float $totalSurplusSale = 0.0;
	public float $totalElectricitySavings = 0.0;
	public float $totalPremium = 0.0;
	public float $totalGrossGain = 0.0;
	public float $netGain = 0.0;
	public float $roiRate = 0.0;
	public float $averageAnnualReturnRate = 0.0;
	public ?float $paybackYears = null;
	public float $simplifiedProductionCostPerKwh = 0.0;
}
