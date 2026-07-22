<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require_once DOL_DOCUMENT_ROOT.'/core/modules/propale/doc/pdf_cyan.modules.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/signature.lib.php';
dol_include_once('/lmdbpropalpv/class/lmdbpropalpvstudyservice.class.php');
dol_include_once('/lmdbpropalpv/lib/lmdbpropalpv.lib.php');

/**
 * Shared renderer for the two PV Signature proposal models.
 *
 * The proposal model selected in the module settings remains the source of
 * truth for commercial lines, VAT, discounts, multicurrency, public notes and
 * payment conditions. This renderer prepends a modern cover and appends the
 * optional financial study, then merges the pages through Dolibarr's native
 * TCPDI stack.
 */
abstract class LmdbPropalPVPdfBase extends pdf_cyan
{
	/** @var bool */
	protected $withPictures = false;

	/**
	 * @param Propal    $object Proposal
	 * @param Translate $outputlangs Output language
	 * @param string    $srctemplatepath Source template
	 * @param int       $hidedetails Hide details
	 * @param int       $hidedesc Hide descriptions
	 * @param int       $hideref Hide references
	 * @return int
	 */
	public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
	{
		global $conf, $mysoc;
		$this->configureEmitterForEntity($object);
		$baseProposalModel = $this->resolveBaseProposalModel($object);

		$hadPictureSetting = isset($conf->global->MAIN_GENERATE_PROPOSALS_WITH_PICTURE);
		$previousPictureSetting = $hadPictureSetting ? $conf->global->MAIN_GENERATE_PROPOSALS_WITH_PICTURE : null;
		$hadSignatureSetting = isset($conf->global->PROPAL_DISABLE_SIGNATURE);
		$previousSignatureSetting = $hadSignatureSetting ? $conf->global->PROPAL_DISABLE_SIGNATURE : null;
		$hadFreeText = isset($conf->global->PROPOSAL_FREE_TEXT);
		$previousFreeText = $hadFreeText ? $conf->global->PROPOSAL_FREE_TEXT : null;
		$hadFootDetails = isset($conf->global->MAIN_GENERATE_DOCUMENTS_SHOW_FOOT_DETAILS);
		$previousFootDetails = $hadFootDetails ? $conf->global->MAIN_GENERATE_DOCUMENTS_SHOW_FOOT_DETAILS : null;
		$previousEmitter = $mysoc;
		$previousObjectModel = isset($object->model_pdf) ? (string) $object->model_pdf : '';
		$conf->global->MAIN_GENERATE_PROPOSALS_WITH_PICTURE = $this->withPictures ? 1 : 0;
		// The shared renderer owns the final acceptance page and the final native
		// footer, after the financial study.
		$conf->global->PROPAL_DISABLE_SIGNATURE = 1;
		$conf->global->PROPOSAL_FREE_TEXT = '';
		$conf->global->MAIN_GENERATE_DOCUMENTS_SHOW_FOOT_DETAILS = lmdbpropalpvGetEntityStringConstant($this->db, 'MAIN_GENERATE_DOCUMENTS_SHOW_FOOT_DETAILS', '0', (int) $object->entity);
		$mysoc = $this->emetteur;
		$result = 0;
		$sourcePaginationState = $this->suppressSourcePagination();
		try {
			$result = $object->generateDocument($baseProposalModel, $outputlangs, $hidedetails, $hidedesc, $hideref);
		} finally {
			$this->restoreSourcePagination($sourcePaginationState);
			$mysoc = $previousEmitter;
			$object->model_pdf = $previousObjectModel;
			if ($hadPictureSetting) {
				$conf->global->MAIN_GENERATE_PROPOSALS_WITH_PICTURE = $previousPictureSetting;
			} else {
				unset($conf->global->MAIN_GENERATE_PROPOSALS_WITH_PICTURE);
			}
			if ($hadSignatureSetting) {
				$conf->global->PROPAL_DISABLE_SIGNATURE = $previousSignatureSetting;
			} else {
				unset($conf->global->PROPAL_DISABLE_SIGNATURE);
			}
			if ($hadFreeText) {
				$conf->global->PROPOSAL_FREE_TEXT = $previousFreeText;
			} else {
				unset($conf->global->PROPOSAL_FREE_TEXT);
			}
			if ($hadFootDetails) {
				$conf->global->MAIN_GENERATE_DOCUMENTS_SHOW_FOOT_DETAILS = $previousFootDetails;
			} else {
				unset($conf->global->MAIN_GENERATE_DOCUMENTS_SHOW_FOOT_DETAILS);
			}
		}
		if ($result <= 0 || !is_object($outputlangs)) {
			return $result;
		}
		$outputlangs->loadLangs(array('main', 'propal', 'products', 'companies', 'lmdbpropalpv@lmdbpropalpv'));

		$file = $this->getGeneratedFilePath($object);
		if ($file === '' || !is_readable($file)) {
			$this->error = $outputlangs->transnoentities('LmdbPropalPVErrorGeneratedFileUnreadable');
			return 0;
		}

		$study = (new LmdbPropalPVStudyService($this->db))->buildStudy($object);
		$temporarySuffix = substr(dol_hash(uniqid((string) $object->id, true), 3), 0, 16);
		$supplement = dirname($file).'/'.pathinfo($file, PATHINFO_FILENAME).'_lmdbpropalpv_'.$temporarySuffix.'_supplement.pdf';
		$merged = dirname($file).'/'.pathinfo($file, PATHINFO_FILENAME).'_lmdbpropalpv_'.$temporarySuffix.'_merged.pdf';
		$sourcePaginationState = $this->suppressSourcePagination();
		try {
			$supplementCreated = $this->createSupplement($object, $outputlangs, $study, $supplement);
		} catch (Throwable $exception) {
			$this->error = $outputlangs->transnoentities('LmdbPropalPVErrorPdfSupplement').': '.$exception->getMessage();
			dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
			$supplementCreated = false;
		} finally {
			$this->restoreSourcePagination($sourcePaginationState);
		}
		if (!$supplementCreated) {
			dol_delete_file($supplement, 0, 1, 1, null, false, 0);
			return 0;
		}
		if (!$this->mergeDocuments($supplement, $file, $merged, $object, $outputlangs)) {
			dol_delete_file($supplement, 0, 1, 1, null, false, 0);
			dol_delete_file($merged, 0, 1, 1, null, false, 0);
			return 0;
		}
		$resultMove = dol_move($merged, $file, '0', 1, 0, 0);
		dol_delete_file($supplement, 0, 1, 1, null, false, 0);
		if ($resultMove <= 0) {
			dol_delete_file($merged, 0, 1, 1, null, false, 0);
			$this->error = $outputlangs->transnoentities('LmdbPropalPVErrorPdfInstall');
			return 0;
		}
		$this->result = array('fullpath' => $file);

		return 1;
	}

	/**
	 * Move the constituent PDF counter outside the page while its model renders.
	 * The legal footer remains untouched and the final merge writes the only
	 * visible counter with the total number of composed pages.
	 *
	 * @return array{had_value:bool,value:int|string|null}
	 */
	private function suppressSourcePagination()
	{
		global $conf;

		$hadValue = isset($conf->global->PDF_FOOTER_PAGE_NUMBER_X);
		$previousValue = $hadValue ? $conf->global->PDF_FOOTER_PAGE_NUMBER_X : null;
		// A large negative offset keeps TCPDF's X coordinate positive and moves
		// the source counter beyond the right edge without changing the footer Y.
		$conf->global->PDF_FOOTER_PAGE_NUMBER_X = -1000;

		return array('had_value' => $hadValue, 'value' => $previousValue);
	}

	/**
	 * Restore the pagination offset after a constituent PDF has been generated.
	 *
	 * @param array{had_value:bool,value:int|string|null} $state Previous state
	 * @return void
	 */
	private function restoreSourcePagination(array $state)
	{
		global $conf;

		if ($state['had_value']) {
			$conf->global->PDF_FOOTER_PAGE_NUMBER_X = $state['value'];
		} else {
			unset($conf->global->PDF_FOOTER_PAGE_NUMBER_X);
		}
	}

	/**
	 * Render the footer of the PV-owned pages with the proposal owner entity.
	 *
	 * @return int
	 */
	protected function _pagefoot(&$pdf, $object, $outputlangs, $hidefreetext = 0)
	{
		global $conf;

		if (empty($object->entity) || (int) $object->entity === (int) $conf->entity) {
			return parent::_pagefoot($pdf, $object, $outputlangs, $hidefreetext);
		}
		$hadFreeText = isset($conf->global->PROPOSAL_FREE_TEXT);
		$previousFreeText = $hadFreeText ? $conf->global->PROPOSAL_FREE_TEXT : null;
		$hadFootDetails = isset($conf->global->MAIN_GENERATE_DOCUMENTS_SHOW_FOOT_DETAILS);
		$previousFootDetails = $hadFootDetails ? $conf->global->MAIN_GENERATE_DOCUMENTS_SHOW_FOOT_DETAILS : null;
		$conf->global->PROPOSAL_FREE_TEXT = lmdbpropalpvGetEntityStringConstant($this->db, 'PROPOSAL_FREE_TEXT', '', (int) $object->entity);
		$conf->global->MAIN_GENERATE_DOCUMENTS_SHOW_FOOT_DETAILS = lmdbpropalpvGetEntityStringConstant($this->db, 'MAIN_GENERATE_DOCUMENTS_SHOW_FOOT_DETAILS', '0', (int) $object->entity);
		try {
			return parent::_pagefoot($pdf, $object, $outputlangs, $hidefreetext);
		} finally {
			if ($hadFreeText) {
				$conf->global->PROPOSAL_FREE_TEXT = $previousFreeText;
			} else {
				unset($conf->global->PROPOSAL_FREE_TEXT);
			}
			if ($hadFootDetails) {
				$conf->global->MAIN_GENERATE_DOCUMENTS_SHOW_FOOT_DETAILS = $previousFootDetails;
			} else {
				unset($conf->global->MAIN_GENERATE_DOCUMENTS_SHOW_FOOT_DETAILS);
			}
		}
	}

	/**
	 * Resolve the entity-owned proposal model used for the commercial PDF body.
	 *
	 * @param Propal $object Proposal
	 * @return string
	 */
	private function resolveBaseProposalModel($object)
	{
		global $conf;

		$entity = !empty($object->entity) ? (int) $object->entity : (int) $conf->entity;
		$configured = lmdbpropalpvGetEntityStringConstant($this->db, 'LMDBPROPALPV_BASE_PROPOSAL_PDF_MODEL', 'cyan', $entity);
		$options = lmdbpropalpvGetBaseProposalModelOptions($this->db, $entity);
		if (lmdbpropalpvBaseProposalModelNameIsSafe($configured) && isset($options[$configured])) {
			return $configured;
		}

		dol_syslog(__METHOD__.' unavailable or recursive proposal model '.$configured.', fallback to cyan', LOG_WARNING);

		return 'cyan';
	}

	/**
	 * Load issuer identity from the proposal owner entity when a shared proposal
	 * is generated from another Multicompany environment.
	 *
	 * @return void
	 */
	private function configureEmitterForEntity($object)
	{
		global $conf;

		$ownerEntity = !empty($object->entity) ? (int) $object->entity : (int) $conf->entity;
		if ($ownerEntity === (int) $conf->entity) {
			return;
		}
		$mapping = array(
			'name' => 'MAIN_INFO_SOCIETE_NOM',
			'address' => 'MAIN_INFO_SOCIETE_ADDRESS',
			'zip' => 'MAIN_INFO_SOCIETE_ZIP',
			'town' => 'MAIN_INFO_SOCIETE_TOWN',
			'region_code' => 'MAIN_INFO_SOCIETE_REGION',
			'phone' => 'MAIN_INFO_SOCIETE_TEL',
			'phone_mobile' => 'MAIN_INFO_SOCIETE_MOBILE',
			'fax' => 'MAIN_INFO_SOCIETE_FAX',
			'url' => 'MAIN_INFO_SOCIETE_WEB',
			'email' => 'MAIN_INFO_SOCIETE_MAIL',
			'idprof1' => 'MAIN_INFO_SIREN',
			'idprof2' => 'MAIN_INFO_SIRET',
			'idprof3' => 'MAIN_INFO_APE',
			'idprof4' => 'MAIN_INFO_RCS',
			'idprof5' => 'MAIN_INFO_PROFID5',
			'idprof6' => 'MAIN_INFO_PROFID6',
			'tva_intra' => 'MAIN_INFO_TVAINTRA',
			'managers' => 'MAIN_INFO_SOCIETE_MANAGERS',
			'forme_juridique_code' => 'MAIN_INFO_SOCIETE_FORME_JURIDIQUE',
			'logo' => 'MAIN_INFO_SOCIETE_LOGO',
			'logo_small' => 'MAIN_INFO_SOCIETE_LOGO_SMALL',
			'logo_mini' => 'MAIN_INFO_SOCIETE_LOGO_MINI',
		);
		foreach ($mapping as $property => $constant) {
			$this->emetteur->{$property} = lmdbpropalpvGetEntityStringConstant($this->db, $constant, '', $ownerEntity);
		}
		$this->emetteur->nom = $this->emetteur->name;
		$this->emetteur->entity = $ownerEntity;

		$country = explode(':', lmdbpropalpvGetEntityStringConstant($this->db, 'MAIN_INFO_SOCIETE_COUNTRY', '', $ownerEntity));
		$this->emetteur->country_id = isset($country[0]) && is_numeric($country[0]) ? (int) $country[0] : 0;
		$this->emetteur->country_code = isset($country[1]) ? (string) $country[1] : '';
		$this->emetteur->country = isset($country[2]) ? (string) $country[2] : '';
		$state = explode(':', lmdbpropalpvGetEntityStringConstant($this->db, 'MAIN_INFO_SOCIETE_STATE', '', $ownerEntity));
		$this->emetteur->state_id = isset($state[0]) && is_numeric($state[0]) ? (int) $state[0] : 0;
		$this->emetteur->state_code = isset($state[1]) ? (string) $state[1] : '';
		$this->emetteur->state = isset($state[2]) ? (string) $state[2] : '';
	}

	/** @return string */
	private function getGeneratedFilePath($object)
	{
		global $conf;

		if (!empty($object->specimen)) {
			$base = !empty($conf->propal->multidir_output[$object->entity]) ? $conf->propal->multidir_output[$object->entity] : $conf->propal->dir_output;
			return $base.'/SPECIMEN.pdf';
		}
		$dir = getMultidirOutput($object, 'propal', 1);
		if (empty($dir) || str_starts_with((string) $dir, 'error-')) {
			$base = !empty($conf->propal->multidir_output[$object->entity]) ? $conf->propal->multidir_output[$object->entity] : $conf->propal->dir_output;
			$dir = $base.'/'.dol_sanitizeFileName($object->ref);
		}

		return rtrim((string) $dir, '/').'/'.dol_sanitizeFileName($object->ref).'.pdf';
	}

	/**
	 * @param array<string,mixed> $study Study
	 * @return bool
	 */
	private function createSupplement($object, $outputlangs, array $study, $file)
	{
		$pdf = pdf_getInstance($this->format);
		if (!is_object($pdf)) {
			$this->error = $outputlangs->transnoentities('LmdbPropalPVErrorPdfEngine');
			return false;
		}
		if (method_exists($pdf, 'setPrintHeader')) {
			$pdf->setPrintHeader(false);
			$pdf->setPrintFooter(false);
		}
		$pdf->SetFont(pdf_getPDFFont($outputlangs));
		$pdf->SetMargins($this->marge_gauche, $this->marge_haute, $this->marge_droite);
		$pdf->SetAutoPageBreak(true, $this->getFooterHeight($pdf, $object, true));
		$pdf->SetTitle($outputlangs->convToOutputCharset($object->ref));
		$pdf->SetCreator('Dolibarr '.DOL_VERSION.' / LmdbPropalPV 1.0.0');

		$this->addProtectedPage($pdf, $object, true);
		$this->drawCover($pdf, $object, $outputlangs, $study);
		$this->drawProtectedFooter($pdf, $object, $outputlangs, true);

		if ($study['complete'] && $study['result'] instanceof LmdbPropalPVFinancialResult) {
			$this->drawFinancialPages($pdf, $object, $outputlangs, $study, $study['result']);
		}
		$this->addProtectedPage($pdf, $object, false);
		$this->drawAcceptancePage($pdf, $object, $outputlangs);
		$this->drawProtectedFooter($pdf, $object, $outputlangs, false);

		$pdf->Output($file, 'F');
		if (!is_readable($file)) {
			$this->error = $outputlangs->transnoentities('LmdbPropalPVErrorPdfSupplement');
			return false;
		}

		return true;
	}

	/** @return void */
	private function addProtectedPage(&$pdf, $object, $hideFreeText)
	{
		$footerHeight = $this->getFooterHeight($pdf, $object, (bool) $hideFreeText);
		$pdf->AddPage();
		if (method_exists($pdf, 'setPageOrientation')) {
			$pdf->setPageOrientation('', true, $footerHeight);
		}
		$pdf->SetAutoPageBreak(true, $footerHeight);
	}

	/** Draw one native footer without allowing TCPDF to create a footer-only page. @return void */
	private function drawProtectedFooter(&$pdf, $object, $outputlangs, $hideFreeText)
	{
		$footerHeight = $this->getFooterHeight($pdf, $object, (bool) $hideFreeText);
		$pdf->SetAutoPageBreak(false, 0);
		try {
			$this->_pagefoot($pdf, $object, $outputlangs, $hideFreeText ? 1 : 0);
		} finally {
			$pdf->SetAutoPageBreak(true, $footerHeight);
		}
	}

	/** @return float */
	private function getFooterHeight(&$pdf, $object, $hideFreeText)
	{
		$ownerEntity = !empty($object->entity) ? (int) $object->entity : 0;
		$freetext = $hideFreeText ? '' : lmdbpropalpvGetEntityStringConstant($this->db, 'PROPOSAL_FREE_TEXT', '', $ownerEntity);
		$freetextHeight = $freetext !== '' && function_exists('pdfGetHeightForHtmlContent') ? (float) pdfGetHeightForHtmlContent($pdf, $freetext) : 0.0;
		$detailsHeight = (int) lmdbpropalpvGetEntityStringConstant($this->db, 'MAIN_GENERATE_DOCUMENTS_SHOW_FOOT_DETAILS', '0', $ownerEntity) ? 16.0 : 8.0;

		return max(24.0, (float) $this->marge_basse + $detailsHeight + $freetextHeight + 4.0);
	}

	/**
	 * @param array<string,mixed> $study Study
	 * @return void
	 */
	private function drawCover($pdf, $object, $outputlangs, array $study)
	{
		global $conf;

		$primary = $this->hexToRgb(lmdbpropalpvGetEntityStringConstant($this->db, 'LMDBPROPALPV_PDF_PRIMARY_COLOR', '#16324F', (int) $object->entity));
		$accent = $this->hexToRgb(lmdbpropalpvGetEntityStringConstant($this->db, 'LMDBPROPALPV_PDF_ACCENT_COLOR', '#F2B705', (int) $object->entity));
		$pdf->SetFillColor($primary[0], $primary[1], $primary[2]);
		$pdf->Rect(0, 0, $this->page_largeur, 76, 'F');
		$pdf->SetFillColor($accent[0], $accent[1], $accent[2]);
		$pdf->Rect(0, 76, $this->page_largeur, 5, 'F');

		$this->drawEntityLogo($pdf, $object);
		$pdf->SetTextColor(255, 255, 255);
		$pdf->SetFont('', '', 7.5);
		$entityLines = array($this->emetteur->name);
		if (!empty($this->emetteur->address)) {
			$entityLines[] = $this->emetteur->address;
		}
		$entityTown = trim((string) $this->emetteur->zip.' '.(string) $this->emetteur->town);
		if ($entityTown !== '') {
			$entityLines[] = $entityTown;
		}
		if (!empty($this->emetteur->phone)) {
			$entityLines[] = $this->emetteur->phone;
		}
		if (!empty($this->emetteur->email)) {
			$entityLines[] = $this->emetteur->email;
		}
		$pdf->SetXY(126, 10);
		$pdf->MultiCell(71, 4, $outputlangs->convToOutputCharset(implode("\n", $entityLines)), 0, 'R');
		$pdf->SetFont('', 'B', 22);
		$pdf->SetXY($this->marge_gauche, 38);
		$pdf->MultiCell(130, 10, $outputlangs->convToOutputCharset($outputlangs->transnoentities('LmdbPropalPVOurProposal')), 0, 'L');
		$pdf->SetFont('', '', 11);
		$pdf->SetXY($this->marge_gauche, 61);
		$pdf->Cell(120, 6, $outputlangs->convToOutputCharset($object->ref), 0, 0, 'L');

		$pdf->SetTextColor($primary[0], $primary[1], $primary[2]);
		$pdf->SetFont('', 'B', 16);
		$pdf->SetXY($this->marge_gauche, 94);
		$customerName = is_object($object->thirdparty) ? $object->thirdparty->name : '';
		$pdf->MultiCell(120, 8, $outputlangs->convToOutputCharset($customerName), 0, 'L');
		$customerAddress = '';
		if (is_object($object->thirdparty)) {
			$customerAddress = trim((string) $object->thirdparty->address."\n".trim((string) $object->thirdparty->zip.' '.(string) $object->thirdparty->town));
		}
		if ($customerAddress !== '') {
			$pdf->SetFont('', '', 8);
			$pdf->SetXY($this->marge_gauche, 104);
			$pdf->MultiCell(105, 4, $outputlangs->convToOutputCharset($customerAddress), 0, 'L');
		}
		$pdf->SetFont('', '', 10);
		$pdf->SetXY($this->marge_gauche, 112);
		$pdf->Cell(75, 6, $outputlangs->transnoentities('Date').' : '.dol_print_date($object->date, 'day', false, $outputlangs, true), 0, 0, 'L');
		$pdf->SetXY($this->marge_gauche, 120);
		$pdf->Cell(75, 6, $outputlangs->transnoentities('AmountTTC').' : '.price(price2num($study['investment_ttc'], 'MT'), 0, $outputlangs, 1, -1, -1, $study['currency_code']), 0, 0, 'L');
		if (!empty($object->fin_validite)) {
			$pdf->SetXY($this->marge_gauche, 128);
			$pdf->Cell(100, 6, $outputlangs->transnoentities('DateEnd').' : '.dol_print_date($object->fin_validite, 'day', false, $outputlangs, true), 0, 0, 'L');
		}

		$pdf->SetFillColor(247, 249, 252);
		if (method_exists($pdf, 'RoundedRect')) {
			$pdf->RoundedRect($this->marge_gauche, 140, 185, 42, 3, '1111', 'F');
		} else {
			$pdf->Rect($this->marge_gauche, 140, 185, 42, 'F');
		}
		$this->drawCoverMetric($pdf, $this->marge_gauche + 6, 148, $outputlangs->transnoentities('LmdbPropalPVPeakPower'), price(price2num($study['peak_power_kwp'], 'MT'), 0, $outputlangs).' kWc', $primary);
		if ($study['complete'] && $study['result'] instanceof LmdbPropalPVFinancialResult) {
			$batteryResult = $study['battery_result'] instanceof LmdbPropalPVFinancialResult ? $study['battery_result'] : null;
			$projectionYears = (int) $study['projection_years'];
			$paybackWithout = $study['result']->paybackYears === null ? $outputlangs->transnoentities('LmdbPropalPVPaybackNotReachedAtYears', $projectionYears) : price(price2num($study['result']->paybackYears, 'MT'), 0, $outputlangs).' '.$outputlangs->transnoentities('LmdbPropalPVYears');
			$paybackWith = $batteryResult !== null ? ($batteryResult->paybackYears === null ? $outputlangs->transnoentities('LmdbPropalPVPaybackNotReachedAtYears', $projectionYears) : price(price2num($batteryResult->paybackYears, 'MT'), 0, $outputlangs).' '.$outputlangs->transnoentities('LmdbPropalPVYears')) : $outputlangs->transnoentities('LmdbPropalPVNotConfigured');
			$netGainWith = $batteryResult !== null ? price(price2num($batteryResult->netGain, 'MT'), 0, $outputlangs, 1, -1, -1, $study['currency_code']) : $outputlangs->transnoentities('LmdbPropalPVNotConfigured');
			$battery = $this->hexToRgb(lmdbpropalpvGetEntityStringConstant($this->db, 'LMDBPROPALPV_BATTERY_COLOR', '#2E7D32', (int) $object->entity));
			$this->drawCoverComparisonMetric($pdf, $this->marge_gauche + 68, 148, $outputlangs->transnoentities('LmdbPropalPVPayback'), $paybackWithout, $paybackWith, $primary, $battery, $outputlangs);
			$this->drawCoverComparisonMetric($pdf, $this->marge_gauche + 130, 148, $outputlangs->transnoentities('LmdbPropalPVNetGainAtYears', $projectionYears), price(price2num($study['result']->netGain, 'MT'), 0, $outputlangs, 1, -1, -1, $study['currency_code']), $netGainWith, $primary, $battery, $outputlangs);
		}

		$pdf->SetTextColor(70, 70, 70);
		$pdf->SetFont('', '', 8);
		$pdf->SetXY($this->marge_gauche, 193);
		$pdf->MultiCell(185, 5, $outputlangs->convToOutputCharset($outputlangs->transnoentities('LmdbPropalPVFinancialNotice')), 0, 'L');
		$this->drawConnectionCheck($pdf, $outputlangs, $study, $accent);

	}

	/**
	 * Draw the same non-blocking connection diagnostic on both proposal models.
	 *
	 * @param array{connection_result:LmdbPropalPVConnectionPowerResult,connection_warning_keys:list<string>,connection_product_refs:list<string>} $study Study
	 * @param array{0:int,1:int,2:int} $accent Accent color
	 * @return void
	 */
	private function drawConnectionCheck($pdf, $outputlangs, array $study, array $accent)
	{
		$connection = $study['connection_result'];
		$statusTranslation = array(
			LmdbPropalPVConnectionPowerResult::STATUS_COMPLIANT => 'LmdbPropalPVConnectionStatusCompliant',
			LmdbPropalPVConnectionPowerResult::STATUS_INCREASE_TO_CHECK => 'LmdbPropalPVConnectionStatusIncreaseToCheck',
			LmdbPropalPVConnectionPowerResult::STATUS_PHASE_INCOMPATIBLE => 'LmdbPropalPVConnectionStatusPhaseIncompatible',
			LmdbPropalPVConnectionPowerResult::STATUS_INCOMPLETE => 'LmdbPropalPVConnectionStatusIncomplete',
		);
		$phaseKey = $connection->phaseMode === 'three' ? 'LmdbPropalPVThreePhase' : 'LmdbPropalPVSinglePhase';
		$recommended = $connection->recommendedSubscribedPowerKva === null
			? $outputlangs->transnoentities('LmdbPropalPVSpecificConnectionStudy')
			: price(price2num($connection->recommendedSubscribedPowerKva, 'MT'), 0, $outputlangs).' kVA';
		$inverterPower = $connection->inverterNominalPowerKva === null
			? $outputlangs->transnoentities('LmdbPropalPVUnavailable')
			: price(price2num($connection->inverterNominalPowerKva, 'MT'), 0, $outputlangs).' kVA'.(!$connection->inverterDataComplete ? ' ('.$outputlangs->transnoentities('LmdbPropalPVPartialValue').')' : '');
		$details = $outputlangs->transnoentities('LmdbPropalPVPeakPower').' : '.price(price2num($connection->peakPowerKwp, 'MT'), 0, $outputlangs).' kWc';
		$details .= ' · '.$outputlangs->transnoentities('LmdbPropalPVInverterNominalPower').' : '.$inverterPower;
		$details .= ' · '.$outputlangs->transnoentities('LmdbPropalPVConnectionReferencePower').' : '.price(price2num($connection->referencePowerKva, 'MT'), 0, $outputlangs).' kVA';
		$details .= ' · '.$outputlangs->transnoentities('LmdbPropalPVSubscribedPower').' : '.price(price2num($connection->subscribedPowerKva, 'MT'), 0, $outputlangs).' kVA';
		$details .= ' · '.$outputlangs->transnoentities('LmdbPropalPVConnectionPhaseMode').' : '.$outputlangs->transnoentities($phaseKey);
		$details .= ' · '.$outputlangs->transnoentities('LmdbPropalPVRecommendedSubscribedPower').' : '.$recommended;

		$warnings = array();
		foreach ($study['connection_warning_keys'] as $warningKey) {
			$references = implode(', ', $study['connection_product_refs']);
			$warnings[] = $references !== '' && $warningKey === 'LmdbPropalPVConnectionInverterDataUnavailable'
				? $outputlangs->transnoentities($warningKey, $references)
				: $outputlangs->transnoentities($warningKey);
		}

		$pdf->SetFillColor(255, 248, 218);
		$pdf->SetDrawColor($accent[0], $accent[1], $accent[2]);
		if (method_exists($pdf, 'RoundedRect')) {
			$pdf->RoundedRect($this->marge_gauche, 211, 185, 53, 2, '1111', 'DF');
		} else {
			$pdf->Rect($this->marge_gauche, 211, 185, 53, 'DF');
		}
		$pdf->SetTextColor(65, 65, 65);
		$pdf->SetFont('', 'B', 8.5);
		$pdf->SetXY($this->marge_gauche + 5, 216);
		$pdf->MultiCell(175, 4, $outputlangs->convToOutputCharset($outputlangs->transnoentities('LmdbPropalPVConnectionPowerCheck').' — '.$outputlangs->transnoentities($statusTranslation[$connection->status] ?? 'LmdbPropalPVConnectionStatusIncomplete')), 0, 'L');
		$pdf->SetFont('', '', 6.6);
		$pdf->SetXY($this->marge_gauche + 5, 225);
		$pdf->MultiCell(175, 3.5, $outputlangs->convToOutputCharset($details), 0, 'L');
		if (!empty($warnings)) {
			$pdf->SetFont('', '', 6.2);
			$pdf->SetXY($this->marge_gauche + 5, 239);
			$pdf->MultiCell(175, 3.2, $outputlangs->convToOutputCharset(implode(' ', $warnings)), 0, 'L');
		}
	}

	/** @return void */
	private function drawAcceptancePage($pdf, $object, $outputlangs)
	{
		$primary = $this->hexToRgb(lmdbpropalpvGetEntityStringConstant($this->db, 'LMDBPROPALPV_PDF_PRIMARY_COLOR', '#16324F', (int) $object->entity));
		$accent = $this->hexToRgb(lmdbpropalpvGetEntityStringConstant($this->db, 'LMDBPROPALPV_PDF_ACCENT_COLOR', '#F2B705', (int) $object->entity));
		$pdf->SetFillColor($primary[0], $primary[1], $primary[2]);
		$pdf->Rect(0, 0, $this->page_largeur, 34, 'F');
		$pdf->SetFillColor($accent[0], $accent[1], $accent[2]);
		$pdf->Rect(0, 34, $this->page_largeur, 4, 'F');
		$pdf->SetTextColor(255, 255, 255);
		$pdf->SetFont('', 'B', 20);
		$pdf->SetXY($this->marge_gauche, 12);
		$pdf->Cell(185, 8, $outputlangs->transnoentities('LmdbPropalPVGoodForAgreement'), 0, 0, 'L');
		$pdf->SetTextColor($primary[0], $primary[1], $primary[2]);
		$pdf->SetFont('', 'B', 13);
		$pdf->SetXY($this->marge_gauche, 55);
		$pdf->Cell(120, 7, $outputlangs->transnoentities('Proposal').' '.$object->ref, 0, 0, 'L');
		$pdf->SetFont('', '', 9);
		$pdf->SetXY($this->marge_gauche, 70);
		$pdf->MultiCell(185, 5, $outputlangs->convToOutputCharset($outputlangs->transnoentities('LmdbPropalPVFinancialNotice')), 0, 'L');

		$signatureUrl = '';
		if ((int) lmdbpropalpvGetEntityStringConstant($this->db, 'PROPOSAL_ALLOW_ONLINESIGN', '0', (int) $object->entity) && (int) $object->statut > 0 && function_exists('getOnlineSignatureUrl')) {
			$signatureUrl = (string) getOnlineSignatureUrl(0, 'proposal', $object->ref, 1, $object);
		}
		if ($signatureUrl !== '' && method_exists($pdf, 'write2DBarcode')) {
			$pdf->SetFillColor(247, 249, 252);
			if (method_exists($pdf, 'RoundedRect')) {
				$pdf->RoundedRect($this->marge_gauche, 104, 185, 80, 3, '1111', 'F');
			} else {
				$pdf->Rect($this->marge_gauche, 104, 185, 80, 'F');
			}
			$pdf->SetFont('', 'B', 13);
			$pdf->SetXY($this->marge_gauche + 8, 120);
			$pdf->MultiCell(110, 8, $outputlangs->convToOutputCharset($outputlangs->transnoentities('LmdbPropalPVSignProposal')), 0, 'L');
			$pdf->write2DBarcode($signatureUrl, 'QRCODE,M', 151, 117, 45, 45, array('border' => 0, 'padding' => 0, 'fgcolor' => $primary, 'bgcolor' => false), 'N');
			$pdf->SetFont('', '', 7);
			$pdf->SetXY($this->marge_gauche + 8, 157);
			$pdf->MultiCell(115, 4, $signatureUrl, 0, 'L');
		} else {
			$this->drawAgreementBox($pdf, $outputlangs, $primary);
		}
	}

	/** @return void */
	private function drawEntityLogo($pdf, $object)
	{
		global $conf;

		if (getDolGlobalInt('PDF_DISABLE_MYCOMPANY_LOGO') || empty($this->emetteur->logo)) {
			return;
		}
		$logodir = !empty($conf->mycompany->multidir_output[$object->entity]) ? $conf->mycompany->multidir_output[$object->entity] : $conf->mycompany->dir_output;
		$logo = $logodir.'/logos/'.$this->emetteur->logo;
		if (is_readable($logo)) {
			$pdf->Image($logo, $this->marge_gauche, 12, 0, 18);
		}
	}

	/** @param array{0:int,1:int,2:int} $primary @return void */
	private function drawCoverMetric($pdf, $x, $y, $label, $value, array $primary)
	{
		$pdf->SetTextColor(100, 100, 100);
		$pdf->SetFont('', '', 8);
		$pdf->SetXY($x, $y);
		$pdf->MultiCell(52, 5, $label, 0, 'L');
		$pdf->SetTextColor($primary[0], $primary[1], $primary[2]);
		$pdf->SetFont('', 'B', 12);
		$pdf->SetXY($x, $y + 10);
		$pdf->MultiCell(52, 7, $value, 0, 'L');
	}


	/** @param array{0:int,1:int,2:int} $primary @param array{0:int,1:int,2:int} $battery @return void */
	private function drawCoverComparisonMetric($pdf, $x, $y, $label, $withoutValue, $withValue, array $primary, array $battery, $outputlangs)
	{
		$pdf->SetTextColor(100, 100, 100);
		$pdf->SetFont('', '', 7.5);
		$pdf->SetXY($x, $y);
		$pdf->MultiCell(52, 5, $label, 0, 'L');
		$pdf->SetFont('', 'B', 7.2);
		$pdf->SetTextColor($primary[0], $primary[1], $primary[2]);
		$pdf->SetXY($x, $y + 9);
		$pdf->Cell(52, 4, $outputlangs->transnoentities('LmdbPropalPVWithoutBatteryShort').' : '.$withoutValue, 0, 0, 'L', false, '', 1);
		$pdf->SetTextColor($battery[0], $battery[1], $battery[2]);
		$pdf->SetXY($x, $y + 14);
		$pdf->Cell(52, 4, $outputlangs->transnoentities('LmdbPropalPVWithBatteryShort').' : '.$withValue, 0, 0, 'L', false, '', 1);
	}

	/** @param array{0:int,1:int,2:int} $primary @return void */
	private function drawAgreementBox($pdf, $outputlangs, array $primary)
	{
		$pdf->SetDrawColor($primary[0], $primary[1], $primary[2]);
		if (method_exists($pdf, 'RoundedRect')) {
			$pdf->RoundedRect($this->marge_gauche, 105, 185, 85, 2, '1111', 'D');
		} else {
			$pdf->Rect($this->marge_gauche, 105, 185, 85, 'D');
		}
		$pdf->SetTextColor($primary[0], $primary[1], $primary[2]);
		$pdf->SetFont('', 'B', 10);
		$pdf->SetXY($this->marge_gauche + 4, 112);
		$pdf->Cell(60, 5, $outputlangs->transnoentities('LmdbPropalPVGoodForAgreement'), 0, 0, 'L');
		$pdf->SetFont('', '', 8);
		$pdf->SetXY($this->marge_gauche + 4, 132);
		$pdf->Cell(55, 5, $outputlangs->transnoentities('LmdbPropalPVCustomerName').' :', 0, 0, 'L');
		$pdf->SetXY($this->marge_gauche + 68, 132);
		$pdf->Cell(45, 5, $outputlangs->transnoentities('LmdbPropalPVCustomerDate').' :', 0, 0, 'L');
		$pdf->SetXY($this->marge_gauche + 123, 132);
		$pdf->Cell(45, 5, $outputlangs->transnoentities('LmdbPropalPVCustomerSignature').' :', 0, 0, 'L');
		$pdf->SetXY($this->marge_gauche + 4, 155);
		$pdf->Cell(90, 5, $outputlangs->transnoentities('LmdbPropalPVCustomerMention').' :', 0, 0, 'L');
	}

	/**
	 * Draw the comparative financial projection on as many protected pages as required.
	 *
	 * @param array<string,mixed> $study Study
	 * @return void
	 */
	private function drawFinancialPages($pdf, $object, $outputlangs, array $study, LmdbPropalPVFinancialResult $result)
	{
		$primary = $this->hexToRgb(lmdbpropalpvGetEntityStringConstant($this->db, 'LMDBPROPALPV_PDF_PRIMARY_COLOR', '#16324F', (int) $object->entity));
		$battery = $this->hexToRgb(lmdbpropalpvGetEntityStringConstant($this->db, 'LMDBPROPALPV_BATTERY_COLOR', '#2E7D32', (int) $object->entity));
		$batteryResult = $study['battery_result'] instanceof LmdbPropalPVFinancialResult ? $study['battery_result'] : null;
		$totalRows = count($result->years);
		$offset = 0;
		$pageIndex = 0;
		$lastTableY = 0.0;
		$notesDrawn = false;

		while ($offset < $totalRows) {
			$this->addProtectedPage($pdf, $object, true);
			$firstPage = $pageIndex === 0;
			if ($firstPage) {
				$this->drawFinancialHeader($pdf, $object, $outputlangs, $study, $result, $batteryResult, $primary, $battery);
				$tableY = 118.0;
				$pageCapacity = 18;
			} else {
				$this->drawFinancialContinuationHeader($pdf, $outputlangs, (int) $study['projection_years'], $primary, $battery);
				$tableY = 42.0;
				$pageCapacity = 28;
			}
			$tableY = $this->drawFinancialTableHeader($pdf, $outputlangs, $tableY);
			$rowsOnPage = min($pageCapacity, $totalRows - $offset);
			for ($rowIndex = 0; $rowIndex < $rowsOnPage; $rowIndex++) {
				$absoluteIndex = $offset + $rowIndex;
				$batteryYear = $batteryResult !== null && isset($batteryResult->years[$absoluteIndex]) ? $batteryResult->years[$absoluteIndex] : null;
				$this->drawFinancialTableRow($pdf, $outputlangs, $result->years[$absoluteIndex], $batteryYear, $tableY, $primary, $battery);
				$tableY += 7.0;
			}
			$offset += $rowsOnPage;
			$lastTableY = $tableY;
			if ($offset >= $totalRows && $lastTableY <= 190.0) {
				$this->drawFinancialNotes($pdf, $object, $outputlangs, $study, $lastTableY + 4.0);
				$notesDrawn = true;
			}
			$this->drawProtectedFooter($pdf, $object, $outputlangs, true);
			$pageIndex++;
		}

		if (!$notesDrawn) {
			$this->addProtectedPage($pdf, $object, true);
			$this->drawFinancialContinuationHeader($pdf, $outputlangs, (int) $study['projection_years'], $primary, $battery);
			$this->drawFinancialNotes($pdf, $object, $outputlangs, $study, 46.0);
			$this->drawProtectedFooter($pdf, $object, $outputlangs, true);
		}
	}

	/** @param array<string,mixed> $study @param array{0:int,1:int,2:int} $primary @param array{0:int,1:int,2:int} $battery @return void */
	private function drawFinancialHeader($pdf, $object, $outputlangs, array $study, LmdbPropalPVFinancialResult $result, $batteryResult, array $primary, array $battery)
	{
		$projectionYears = (int) $study['projection_years'];
		$pdf->SetFillColor($primary[0], $primary[1], $primary[2]);
		$pdf->Rect(0, 0, $this->page_largeur, 28, 'F');
		$pdf->SetTextColor(255, 255, 255);
		$pdf->SetFont('', 'B', 18);
		$pdf->SetXY($this->marge_gauche, 10);
		$pdf->Cell(185, 8, $outputlangs->transnoentities('LmdbPropalPVProjectionYearsTitle', $projectionYears), 0, 0, 'L');
		$this->drawPdfLegend($pdf, $outputlangs, 12.0, 31.0, $primary, $battery);
		$metrics = array(
			array($outputlangs->transnoentities('LmdbPropalPVNetGainAtYears', $projectionYears), $result->netGain, $batteryResult instanceof LmdbPropalPVFinancialResult ? $batteryResult->netGain : null, 'money'),
			array($outputlangs->transnoentities('LmdbPropalPVROIAtYears', $projectionYears), $result->roiRate * 100.0, $batteryResult instanceof LmdbPropalPVFinancialResult ? $batteryResult->roiRate * 100.0 : null, 'percent'),
			array($outputlangs->transnoentities('LmdbPropalPVPayback'), $result->paybackYears, $batteryResult instanceof LmdbPropalPVFinancialResult ? $batteryResult->paybackYears : null, 'payback'),
		);
		foreach ($metrics as $index => $metric) {
			$x = 12.0 + ($index * 62.0);
			$pdf->SetTextColor($primary[0], $primary[1], $primary[2]);
			$pdf->SetFont('', 'B', 8.0);
			$pdf->SetXY($x, 39.0);
			$pdf->Cell(58.0, 5.0, $metric[0], 0, 0, 'L', false, '', 1);
			$withoutValue = $this->formatPdfMetric($outputlangs, $metric[1], $metric[3], $study['currency_code'], $projectionYears);
			$withValue = $batteryResult instanceof LmdbPropalPVFinancialResult ? $this->formatPdfMetric($outputlangs, $metric[2], $metric[3], $study['currency_code'], $projectionYears) : $outputlangs->transnoentities('LmdbPropalPVNotConfigured');
			$this->drawPdfSummaryPair($pdf, $outputlangs, $x, 47.0, 58.0, $withoutValue, $withValue, $primary, $battery);
		}
		$this->drawCashflowChart($pdf, $result, $batteryResult, $outputlangs, 12, 66, 185, 45, $primary, $battery);
	}

	/** @param array{0:int,1:int,2:int} $primary @param array{0:int,1:int,2:int} $battery @return void */
	private function drawFinancialContinuationHeader($pdf, $outputlangs, $projectionYears, array $primary, array $battery)
	{
		$pdf->SetFillColor($primary[0], $primary[1], $primary[2]);
		$pdf->Rect(0, 0, $this->page_largeur, 28, 'F');
		$pdf->SetTextColor(255, 255, 255);
		$pdf->SetFont('', 'B', 15);
		$pdf->SetXY($this->marge_gauche, 9);
		$pdf->Cell(185, 7, $outputlangs->transnoentities('LmdbPropalPVProjectionYearsTitle', (int) $projectionYears), 0, 0, 'L');
		$this->drawPdfLegend($pdf, $outputlangs, 12.0, 31.0, $primary, $battery);
	}

	/** @param array{0:int,1:int,2:int} $primary @param array{0:int,1:int,2:int} $battery @return void */
	private function drawPdfLegend($pdf, $outputlangs, $x, $y, array $primary, array $battery)
	{
		$pdf->SetFont('', 'B', 6.8);
		$pdf->SetFillColor($primary[0], $primary[1], $primary[2]);
		$pdf->Rect($x, $y + 1.0, 7.0, 2.0, 'F');
		$pdf->SetTextColor(55, 55, 55);
		$pdf->SetXY($x + 9.0, $y);
		$pdf->Cell(45.0, 4.0, $outputlangs->transnoentities('LmdbPropalPVWithoutBattery'), 0, 0, 'L');
		$pdf->SetFillColor($battery[0], $battery[1], $battery[2]);
		$pdf->Rect($x + 62.0, $y + 1.0, 7.0, 2.0, 'F');
		$pdf->SetXY($x + 71.0, $y);
		$pdf->Cell(45.0, 4.0, $outputlangs->transnoentities('LmdbPropalPVWithBattery'), 0, 0, 'L');
	}

	/** @return float */
	private function drawFinancialTableHeader($pdf, $outputlangs, $y)
	{
		$headers = array('LmdbPropalPVYear', 'LmdbPropalPVProduction', 'LmdbPropalPVNetworkPrice', 'LmdbPropalPVSurplusSale', 'LmdbPropalPVElectricitySavings', 'LmdbPropalPVPremium', 'LmdbPropalPVAnnualGain', 'LmdbPropalPVCumulativeCashflow', 'LmdbPropalPVAnnualReturn');
		$widths = array(10, 21, 21, 21, 23, 18, 21, 28, 22);
		$x = $this->marge_gauche;
		$pdf->SetFont('', '', 5.3);
		$pdf->SetFillColor(235, 240, 246);
		$pdf->SetTextColor(30, 30, 30);
		foreach ($headers as $index => $header) {
			$pdf->SetXY($x, $y);
			$pdf->MultiCell($widths[$index], 9, $outputlangs->transnoentities($header), 1, 'C', true, 0);
			$x += $widths[$index];
		}

		return $y + 9.0;
	}

	/** @param array{0:int,1:int,2:int} $primary @param array{0:int,1:int,2:int} $battery @return void */
	private function drawFinancialTableRow($pdf, $outputlangs, LmdbPropalPVFinancialYear $year, $batteryYear, $y, array $primary, array $battery)
	{
		$widths = array(10, 21, 21, 21, 23, 18, 21, 28, 22);
		$common = array(
			(string) $year->year,
			price(price2num($year->productionKwh, 'MT'), 0, $outputlangs),
			price(price2num($year->retailPricePerKwh, 'MU'), 0, $outputlangs),
		);
		$x = $this->marge_gauche;
		$pdf->SetFont('', '', 4.8);
		$pdf->SetTextColor(30, 30, 30);
		foreach ($common as $index => $value) {
			$pdf->SetXY($x, $y);
			$pdf->Cell($widths[$index], 7.0, $value, 1, 0, $index === 0 ? 'C' : 'R', false, '', 1);
			$x += $widths[$index];
		}
		$pairs = array(
			array($year->surplusSale, $batteryYear instanceof LmdbPropalPVFinancialYear ? $batteryYear->surplusSale : null, 'money'),
			array($year->electricitySavings, $batteryYear instanceof LmdbPropalPVFinancialYear ? $batteryYear->electricitySavings : null, 'money'),
		);
		foreach ($pairs as $pairIndex => $pair) {
			$without = $this->formatPdfMetric($outputlangs, $pair[0], $pair[2], '', 0);
			$with = $pair[1] !== null ? $this->formatPdfMetric($outputlangs, $pair[1], $pair[2], '', 0) : $outputlangs->transnoentities('LmdbPropalPVNotConfigured');
			$this->drawPdfComparisonCell($pdf, $outputlangs, $x, $y, $widths[3 + $pairIndex], 7.0, $without, $with, $primary, $battery);
			$x += $widths[3 + $pairIndex];
		}
		$pdf->SetXY($x, $y);
		$pdf->SetTextColor(30, 30, 30);
		$pdf->Cell($widths[5], 7.0, price(price2num($year->premium, 'MT'), 0, $outputlangs), 1, 0, 'R', false, '', 1);
		$x += $widths[5];
		$remainingPairs = array(
			array($year->annualGain, $batteryYear instanceof LmdbPropalPVFinancialYear ? $batteryYear->annualGain : null, 'money'),
			array($year->cumulativeCashflow, $batteryYear instanceof LmdbPropalPVFinancialYear ? $batteryYear->cumulativeCashflow : null, 'money'),
			array($year->annualReturnRate * 100.0, $batteryYear instanceof LmdbPropalPVFinancialYear ? $batteryYear->annualReturnRate * 100.0 : null, 'percent'),
		);
		foreach ($remainingPairs as $pairIndex => $pair) {
			$without = $this->formatPdfMetric($outputlangs, $pair[0], $pair[2], '', 0);
			$with = $pair[1] !== null ? $this->formatPdfMetric($outputlangs, $pair[1], $pair[2], '', 0) : $outputlangs->transnoentities('LmdbPropalPVNotConfigured');
			$this->drawPdfComparisonCell($pdf, $outputlangs, $x, $y, $widths[6 + $pairIndex], 7.0, $without, $with, $primary, $battery);
			$x += $widths[6 + $pairIndex];
		}
	}

	/** @param array{0:int,1:int,2:int} $primary @param array{0:int,1:int,2:int} $battery @return void */
	private function drawPdfComparisonCell($pdf, $outputlangs, $x, $y, $width, $height, $without, $with, array $primary, array $battery)
	{
		$half = $height / 2.0;
		$pdf->SetDrawColor(190, 195, 200);
		$pdf->Rect($x, $y, $width, $height, 'D');
		$pdf->SetFillColor($primary[0], $primary[1], $primary[2]);
		$pdf->Rect($x + 0.5, $y + 0.4, $width - 1.0, $half - 0.5, 'F');
		$pdf->SetFillColor($battery[0], $battery[1], $battery[2]);
		$pdf->Rect($x + 0.5, $y + $half + 0.1, $width - 1.0, $half - 0.5, 'F');
		$pdf->SetFont('', 'B', 3.8);
		$this->setPdfContrastTextColor($pdf, $primary);
		$pdf->SetXY($x + 0.7, $y + 0.15);
		$pdf->Cell($width - 1.4, $half - 0.2, $outputlangs->transnoentities('LmdbPropalPVWithoutBatteryShort').' '.$without, 0, 0, 'R', false, '', 1);
		$this->setPdfContrastTextColor($pdf, $battery);
		$pdf->SetXY($x + 0.7, $y + $half - 0.05);
		$pdf->Cell($width - 1.4, $half - 0.2, $outputlangs->transnoentities('LmdbPropalPVWithBatteryShort').' '.$with, 0, 0, 'R', false, '', 1);
	}

	/** @param array{0:int,1:int,2:int} $primary @param array{0:int,1:int,2:int} $battery @return void */
	private function drawPdfSummaryPair($pdf, $outputlangs, $x, $y, $width, $without, $with, array $primary, array $battery)
	{
		$pdf->SetFillColor($primary[0], $primary[1], $primary[2]);
		if (method_exists($pdf, 'RoundedRect')) {
			$pdf->RoundedRect($x, $y, $width, 6.0, 1.0, '1111', 'F');
		} else {
			$pdf->Rect($x, $y, $width, 6.0, 'F');
		}
		$pdf->SetFillColor($battery[0], $battery[1], $battery[2]);
		if (method_exists($pdf, 'RoundedRect')) {
			$pdf->RoundedRect($x, $y + 7.0, $width, 6.0, 1.0, '1111', 'F');
		} else {
			$pdf->Rect($x, $y + 7.0, $width, 6.0, 'F');
		}
		$pdf->SetFont('', 'B', 7.5);
		$this->setPdfContrastTextColor($pdf, $primary);
		$pdf->SetXY($x + 1.0, $y + 0.4);
		$pdf->Cell($width - 2.0, 5.0, $outputlangs->transnoentities('LmdbPropalPVWithoutBatteryShort').' : '.$without, 0, 0, 'C', false, '', 1);
		$this->setPdfContrastTextColor($pdf, $battery);
		$pdf->SetXY($x + 1.0, $y + 7.4);
		$pdf->Cell($width - 2.0, 5.0, $outputlangs->transnoentities('LmdbPropalPVWithBatteryShort').' : '.$with, 0, 0, 'C', false, '', 1);
	}


	/** @param array{0:int,1:int,2:int} $background @return void */
	private function setPdfContrastTextColor($pdf, array $background)
	{
		$luminance = (($background[0] * 299) + ($background[1] * 587) + ($background[2] * 114)) / 1000;
		if ($luminance >= 150.0) {
			$pdf->SetTextColor(15, 15, 15);
		} else {
			$pdf->SetTextColor(255, 255, 255);
		}
	}

	/** @return string */
	private function formatPdfMetric($outputlangs, $value, $type, $currencyCode, $projectionYears)
	{
		if ($type === 'payback') {
			return $value === null ? $outputlangs->transnoentities('LmdbPropalPVPaybackNotReachedAtYears', (int) $projectionYears) : price(price2num((float) $value, 'MT'), 0, $outputlangs).' '.$outputlangs->transnoentities('LmdbPropalPVYears');
		}
		if ($type === 'percent') {
			return price(price2num((float) $value, 'MT'), 0, $outputlangs).' %';
		}
		if ($currencyCode === '') {
			return price(price2num((float) $value, 'MT'), 0, $outputlangs);
		}
		return price(price2num((float) $value, 'MT'), 0, $outputlangs, 1, -1, -1, $currencyCode);
	}

	/** @param array<string,mixed> $study @return void */
	private function drawFinancialNotes($pdf, $object, $outputlangs, array $study, $y)
	{
		$pdf->SetFont('', '', 6.5);
		$pdf->SetTextColor(45, 45, 45);
		$pdf->SetXY($this->marge_gauche, $y);
		$assumptions = $outputlangs->transnoentities('LmdbPropalPVSelfConsumptionRateWithoutBattery').' '.price(price2num((float) $study['values']['self_consumption_pct'], 'MT'), 0, $outputlangs).' %';
		if ($study['battery_configured'] && $study['values']['battery_self_consumption_pct'] !== null) {
			$assumptions .= ' / '.$outputlangs->transnoentities('LmdbPropalPVSelfConsumptionRateWithBattery').' '.price(price2num((float) $study['values']['battery_self_consumption_pct'], 'MT'), 0, $outputlangs).' %';
		}
		$assumptions .= ' - '.$outputlangs->transnoentities('LmdbPropalPVFirstYearDegradation').' '.price(price2num((float) $study['values']['first_year_degradation_pct'], 'MT'), 0, $outputlangs).' %';
		$assumptions .= ' - '.$outputlangs->transnoentities('LmdbPropalPVPanelDegradation').' '.price(price2num((float) $study['values']['panel_degradation_pct'], 'MT'), 0, $outputlangs).' %';
		$assumptions .= ' - '.$outputlangs->transnoentities('LmdbPropalPVElectricityGrowth').' '.price(price2num((float) $study['values']['electricity_growth_pct'], 'MT'), 0, $outputlangs).' %';
		$pdf->MultiCell(185, 3.8, $outputlangs->convToOutputCharset($assumptions), 0, 'L');
		foreach ($study['degradation_warning_keys'] as $warningKey) {
			$references = implode(', ', $study['degradation_fallback_product_refs']);
			$warning = $references !== '' ? $outputlangs->transnoentities($warningKey, $references) : $outputlangs->transnoentities($warningKey);
			$pdf->MultiCell(185, 3.8, $outputlangs->convToOutputCharset($warning), 0, 'L');
		}
		foreach ($study['battery_warning_keys'] as $warningKey) {
			$pdf->MultiCell(185, 3.8, $outputlangs->convToOutputCharset($outputlangs->transnoentities($warningKey)), 0, 'L');
		}
		$pdf->MultiCell(185, 3.8, $outputlangs->convToOutputCharset($outputlangs->transnoentities('LmdbPropalPVFinancialNotice')), 0, 'L');
		$customNotice = lmdbpropalpvGetEntityStringConstant($this->db, 'LMDBPROPALPV_FINANCIAL_DISCLAIMER', '', (int) $object->entity);
		if ($customNotice !== '') {
			$pdf->MultiCell(185, 3.8, $outputlangs->convToOutputCharset(dol_string_nohtmltag($customNotice)), 0, 'L');
		}
	}

	/** @param array{0:int,1:int,2:int} $primary @param array{0:int,1:int,2:int} $battery @return void */
	private function drawCashflowChart($pdf, LmdbPropalPVFinancialResult $result, $batteryResult, $outputlangs, $x, $y, $width, $height, array $primary, array $battery)
	{
		$projectionYears = max(1, (int) $result->projectionYears);
		$series = array(array($result, $primary, 'LmdbPropalPVWithoutBatteryShort'));
		if ($batteryResult instanceof LmdbPropalPVFinancialResult) {
			$series[] = array($batteryResult, $battery, 'LmdbPropalPVWithBatteryShort');
		}
		$allValues = array(0.0);
		foreach ($series as $scenario) {
			$allValues[] = $scenario[0]->initialCashflow;
			foreach ($scenario[0]->years as $scenarioYear) {
				$allValues[] = $scenarioYear->cumulativeCashflow;
			}
		}
		$rawMin = min($allValues);
		$rawMax = max($allValues);
		$rawSpan = max(1.0, $rawMax - $rawMin);
		$magnitude = pow(10.0, floor(log10($rawSpan / 5.0)));
		$normalized = ($rawSpan / 5.0) / $magnitude;
		$step = ($normalized <= 1.0 ? 1.0 : ($normalized <= 2.0 ? 2.0 : ($normalized <= 5.0 ? 5.0 : 10.0))) * $magnitude;
		$min = floor(min(0.0, $rawMin) / $step) * $step;
		$max = ceil(max(0.0, $rawMax) / $step) * $step;
		if ($max <= $min) {
			$max = $min + $step;
		}
		$span = $max - $min;
		$plotX = $x + 15.0;
		$plotY = $y + 1.0;
		$plotWidth = $width - 18.0;
		$plotHeight = $height - 9.0;
		$pdf->SetFont('', '', 4.6);
		$pdf->SetTextColor(85, 85, 85);
		$pdf->SetDrawColor(210, 215, 220);
		$pdf->Rect($plotX, $plotY, $plotWidth, $plotHeight, 'D');
		for ($tick = $min; $tick <= $max + ($step / 2.0); $tick += $step) {
			$tickY = $plotY + $plotHeight - (($tick - $min) / $span) * $plotHeight;
			$pdf->SetDrawColor(225, 228, 232);
			$pdf->Line($plotX, $tickY, $plotX + $plotWidth, $tickY);
			$pdf->SetXY($x, $tickY - 1.6);
			$pdf->Cell(13.0, 3.2, price(price2num($tick, 'MT'), 0, $outputlangs), 0, 0, 'R', false, '', 1);
		}
		$xStep = $projectionYears <= 20 ? 5 : (int) ceil($projectionYears / 10.0);
		for ($year = 0; $year <= $projectionYears; $year += $xStep) {
			$tickX = $plotX + ($year / $projectionYears) * $plotWidth;
			$pdf->SetDrawColor(225, 228, 232);
			$pdf->Line($tickX, $plotY, $tickX, $plotY + $plotHeight);
			$pdf->SetXY($tickX - 4.0, $plotY + $plotHeight + 1.0);
			$pdf->Cell(8.0, 3.0, (string) $year, 0, 0, 'C');
		}
		if ($projectionYears % $xStep !== 0) {
			$pdf->SetXY($plotX + $plotWidth - 4.0, $plotY + $plotHeight + 1.0);
			$pdf->Cell(8.0, 3.0, (string) $projectionYears, 0, 0, 'C');
		}
		$zeroY = $plotY + $plotHeight - ((0.0 - $min) / $span) * $plotHeight;
		$previousPaybackX = null;
		foreach ($series as $seriesIndex => $scenario) {
			$scenarioResult = $scenario[0];
			$color = $scenario[1];
			$values = array($scenarioResult->initialCashflow);
			foreach ($scenarioResult->years as $scenarioYear) {
				$values[] = $scenarioYear->cumulativeCashflow;
			}
			if (method_exists($pdf, 'SetLineStyle')) {
				$pdf->SetLineStyle(array('width' => 0.55, 'dash' => 0, 'color' => $color));
			}
			$pdf->SetDrawColor($color[0], $color[1], $color[2]);
			$previousX = $plotX;
			$previousY = $plotY + $plotHeight - (($values[0] - $min) / $span) * $plotHeight;
			foreach ($values as $index => $value) {
				$currentX = $plotX + ($index / $projectionYears) * $plotWidth;
				$currentY = $plotY + $plotHeight - (($value - $min) / $span) * $plotHeight;
				if ($index > 0) {
					$pdf->Line($previousX, $previousY, $currentX, $currentY);
				}
				$previousX = $currentX;
				$previousY = $currentY;
			}
			if ($scenarioResult->paybackYears !== null) {
				$paybackX = $plotX + ($scenarioResult->paybackYears / $projectionYears) * $plotWidth;
				if (method_exists($pdf, 'SetLineStyle')) {
					$pdf->SetLineStyle(array('width' => 0.35, 'dash' => '2,2', 'color' => $color));
				}
				$pdf->Line($paybackX, $plotY, $paybackX, $plotY + $plotHeight);
				$pdf->SetFillColor($color[0], $color[1], $color[2]);
				if (method_exists($pdf, 'Circle')) {
					$pdf->Circle($paybackX, $zeroY, 1.0, 0, 360, 'F');
				}
				$labelY = $plotY + 1.0 + ($seriesIndex * 4.0);
				if ($previousPaybackX !== null && abs($previousPaybackX - $paybackX) < 38.0) {
					$labelY += 4.0;
				}
				$previousPaybackX = $paybackX;
				$label = $outputlangs->transnoentities($scenario[2]).' '.price(price2num($scenarioResult->paybackYears, 'MT'), 0, $outputlangs).' '.$outputlangs->transnoentities('LmdbPropalPVYears');
				$pdf->SetTextColor($color[0], $color[1], $color[2]);
				$pdf->SetFont('', 'B', 4.5);
				$pdf->SetXY(min($plotX + $plotWidth - 42.0, $paybackX + 1.0), $labelY);
				$pdf->Cell(42.0, 3.0, $label, 0, 0, 'L', false, '', 1);
			}
		}
		if (method_exists($pdf, 'SetLineStyle')) {
			$pdf->SetLineStyle(array('width' => 0.2, 'dash' => 0, 'color' => array(190, 195, 200)));
		}
		$pdf->SetDrawColor(190, 195, 200);
	}

	/**
	 * Merge the completed commercial PDF with the PV pages and add one global
	 * pagination pass. The commercial source is counted after its own model has
	 * applied the configured terms-of-sale and product-sheet options.
	 *
	 * @return bool
	 */
	private function mergeDocuments($supplementFile, $bodyFile, $mergedFile, $object, $outputlangs)
	{
		$pdf = pdf_getInstance($this->format);
		if (!is_object($pdf) || !method_exists($pdf, 'setSourceFile')) {
			$this->error = $outputlangs->transnoentities('LmdbPropalPVErrorPdfComposition');
			return false;
		}
		if (method_exists($pdf, 'setPrintHeader')) {
			$pdf->setPrintHeader(false);
			$pdf->setPrintFooter(false);
		}
		// Imported pages are already fully laid out. Keeping TCPDF automatic page
		// breaks enabled here would move the pagination cell to a new blank page.
		$pdf->SetAutoPageBreak(false, 0);
		$pdf->SetFont(pdf_getPDFFont($outputlangs), '', 7);
		try {
			$supplementPages = $pdf->setSourceFile($supplementFile);
			$bodyPages = $pdf->setSourceFile($bodyFile);
			$pagePlan = lmdbpropalpvBuildPdfMergePagePlan($supplementPages, $bodyPages);
			if (empty($pagePlan)) {
				$this->error = $outputlangs->transnoentities('LmdbPropalPVErrorPdfComposition');
				return false;
			}
			$currentSource = '';
			foreach ($pagePlan as $pageData) {
				if ($pageData['source'] !== $currentSource) {
					$currentSource = $pageData['source'];
					$pdf->setSourceFile($currentSource === 'body' ? $bodyFile : $supplementFile);
				}
				$this->importPage(
					$pdf,
					$pageData['source_page'],
					$object,
					$outputlangs,
					$pageData['final_page'],
					$pageData['total_pages']
				);
			}
			$pdf->Output($mergedFile, 'F');
		} catch (Throwable $exception) {
			$this->error = $outputlangs->transnoentities('LmdbPropalPVErrorPdfComposition').': '.$exception->getMessage();
			return false;
		}
		if (!is_readable($mergedFile)) {
			$this->error = $outputlangs->transnoentities('LmdbPropalPVErrorPdfComposition');
			return false;
		}

		return true;
	}

	/** @return void */
	private function importPage($pdf, $page, $object, $outputlangs, $finalPage, $totalPages)
	{
		$template = $pdf->importPage($page);
		$size = $pdf->getTemplateSize($template);
		$width = isset($size['width']) ? (float) $size['width'] : (float) $size['w'];
		$height = isset($size['height']) ? (float) $size['height'] : (float) $size['h'];
		$pdf->AddPage($width > $height ? 'L' : 'P', array($width, $height));
		$pdf->useTemplate($template);
		$this->drawFinalPagination($pdf, $object, $outputlangs, (int) $finalPage, (int) $totalPages, $width, $height);
	}

	/**
	 * Replace the constituent document number with the final composed number.
	 *
	 * A compact opaque area is deliberately limited to the native Dolibarr page
	 * number zone. Imported terms and product sheets keep their original content
	 * and receive the same global pagination without having their footer rebuilt.
	 *
	 * @return void
	 */
	private function drawFinalPagination($pdf, $object, $outputlangs, $finalPage, $totalPages, $pageWidth, $pageHeight)
	{
		$ownerEntity = !empty($object->entity) ? (int) $object->entity : 0;
		$offsetX = (int) lmdbpropalpvGetEntityStringConstant($this->db, 'PDF_FOOTER_PAGE_NUMBER_X', '0', $ownerEntity);
		$offsetY = (int) lmdbpropalpvGetEntityStringConstant($this->db, 'PDF_FOOTER_PAGE_NUMBER_Y', '0', $ownerEntity);
		$boxWidth = 24.0;
		$boxHeight = 4.0;
		$positionX = max(0.5, (float) $pageWidth - (float) $this->marge_droite - $boxWidth - (float) $offsetX);
		$positionY = max(0.5, (float) $pageHeight - (float) $this->marge_basse - 0.5 - (float) $offsetY);

		$backgroundColor = array(255, 255, 255);
		$footerBackground = lmdbpropalpvGetEntityStringConstant($this->db, 'PDF_FOOTER_BACKGROUND_COLOR', '', $ownerEntity);
		if (preg_match('/^\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*$/', $footerBackground, $matches)) {
			$backgroundColor = array(min(255, (int) $matches[1]), min(255, (int) $matches[2]), min(255, (int) $matches[3]));
		}
		$pdf->SetFillColor($backgroundColor[0], $backgroundColor[1], $backgroundColor[2]);
		$pdf->SetDrawColor($backgroundColor[0], $backgroundColor[1], $backgroundColor[2]);
		$pdf->Rect($positionX - 0.5, $positionY - 0.8, $boxWidth + 1.0, $boxHeight, 'F');
		$pdf->SetTextColor(0, 0, 0);
		$footerColor = lmdbpropalpvGetEntityStringConstant($this->db, 'PDF_FOOTER_TEXT_COLOR', '', $ownerEntity);
		if (preg_match('/^\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*$/', $footerColor, $matches)) {
			$pdf->SetTextColor(min(255, (int) $matches[1]), min(255, (int) $matches[2]), min(255, (int) $matches[3]));
		}
		$pdf->SetFont(pdf_getPDFFont($outputlangs), '', 7);
		$pdf->SetXY($positionX, $positionY);
		$pdf->Cell($boxWidth, 2.0, ((string) $finalPage).' / '.((string) $totalPages), 0, 0, 'R', 0);
	}

	/** @return array{0:int,1:int,2:int} */
	private function hexToRgb($color)
	{
		if (!preg_match('/^#([0-9A-Fa-f]{6})$/', (string) $color, $matches)) {
			$matches[1] = '16324F';
		}
		return array(hexdec(substr($matches[1], 0, 2)), hexdec(substr($matches[1], 2, 2)), hexdec(substr($matches[1], 4, 2)));
	}
}
