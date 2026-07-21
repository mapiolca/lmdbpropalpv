<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

/** Typed result of the pure connection-power check. */
class LmdbPropalPVConnectionPowerResult
{
	public const STATUS_COMPLIANT = 'conforme';
	public const STATUS_INCREASE_TO_CHECK = 'augmentation_a_verifier';
	public const STATUS_PHASE_INCOMPATIBLE = 'phase_incompatible';
	public const STATUS_INCOMPLETE = 'verification_incomplete';

	/** @var float */
	public $peakPowerKwp;

	/** @var float|null */
	public $inverterNominalPowerKva;

	/** @var float */
	public $referencePowerKva;

	/** @var float */
	public $subscribedPowerKva;

	/** @var float|null */
	public $recommendedSubscribedPowerKva;

	/** @var string */
	public $phaseMode;

	/** @var string */
	public $status;

	/** @var list<string> */
	public $warningKeys;

	/** @var bool */
	public $inverterDataComplete;

	/**
	 * @param list<string> $warningKeys Translation keys
	 */
	public function __construct(float $peakPowerKwp, ?float $inverterNominalPowerKva, float $referencePowerKva, float $subscribedPowerKva, ?float $recommendedSubscribedPowerKva, string $phaseMode, string $status, array $warningKeys, bool $inverterDataComplete)
	{
		$this->peakPowerKwp = $peakPowerKwp;
		$this->inverterNominalPowerKva = $inverterNominalPowerKva;
		$this->referencePowerKva = $referencePowerKva;
		$this->subscribedPowerKva = $subscribedPowerKva;
		$this->recommendedSubscribedPowerKva = $recommendedSubscribedPowerKva;
		$this->phaseMode = $phaseMode;
		$this->status = $status;
		$this->warningKeys = $warningKeys;
		$this->inverterDataComplete = $inverterDataComplete;
	}
}
