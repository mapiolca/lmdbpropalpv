<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

/** Immutable input for the pure connection-power check. */
class LmdbPropalPVConnectionPowerInput
{
	/** @var float */
	public $peakPowerKwp;

	/** @var float|null */
	public $inverterNominalPowerKva;

	/** @var float */
	public $subscribedPowerKva;

	/** @var string single|three */
	public $phaseMode;

	/** @var bool */
	public $inverterDataComplete;

	/** @var float|null Declared export limit; null means no confirmed limitation. */
	public $injectionLimitKva;

	public function __construct(float $peakPowerKwp, ?float $inverterNominalPowerKva, float $subscribedPowerKva, string $phaseMode, bool $inverterDataComplete, ?float $injectionLimitKva = null)
	{
		$this->peakPowerKwp = $peakPowerKwp;
		$this->inverterNominalPowerKva = $inverterNominalPowerKva;
		$this->subscribedPowerKva = $subscribedPowerKva;
		$this->phaseMode = $phaseMode;
		$this->inverterDataComplete = $inverterDataComplete;
		$this->injectionLimitKva = $injectionLimitKva;
	}
}
