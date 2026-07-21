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

	public function __construct(float $peakPowerKwp, ?float $inverterNominalPowerKva, float $subscribedPowerKva, string $phaseMode, bool $inverterDataComplete)
	{
		$this->peakPowerKwp = $peakPowerKwp;
		$this->inverterNominalPowerKva = $inverterNominalPowerKva;
		$this->subscribedPowerKva = $subscribedPowerKva;
		$this->phaseMode = $phaseMode;
		$this->inverterDataComplete = $inverterDataComplete;
	}
}
