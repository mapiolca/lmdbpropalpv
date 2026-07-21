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
 * Cyan remains the source of truth for commercial lines, VAT, discounts,
 * multicurrency, public notes and payment conditions. This renderer prepends a
 * modern cover and appends the optional financial study, then merges the pages
 * through Dolibarr's native TCPDI stack.
 */
abstract class LmdbPropalPVPdfBase extends pdf_cyan
{
	/** @var bool */
	protected $withPictures = false;
	/** @var bool */
	private $commercialBodyInProgress = false;

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
		global $conf;
		$this->configureEmitterForEntity($object);

		$hadPictureSetting = isset($conf->global->MAIN_GENERATE_PROPOSALS_WITH_PICTURE);
		$previousPictureSetting = $hadPictureSetting ? $conf->global->MAIN_GENERATE_PROPOSALS_WITH_PICTURE : null;
		$hadSignatureSetting = isset($conf->global->PROPAL_DISABLE_SIGNATURE);
		$previousSignatureSetting = $hadSignatureSetting ? $conf->global->PROPAL_DISABLE_SIGNATURE : null;
		$hadFreeText = isset($conf->global->PROPOSAL_FREE_TEXT);
		$previousFreeText = $hadFreeText ? $conf->global->PROPOSAL_FREE_TEXT : null;
		$hadFootDetails = isset($conf->global->MAIN_GENERATE_DOCUMENTS_SHOW_FOOT_DETAILS);
		$previousFootDetails = $hadFootDetails ? $conf->global->MAIN_GENERATE_DOCUMENTS_SHOW_FOOT_DETAILS : null;
		$conf->global->MAIN_GENERATE_PROPOSALS_WITH_PICTURE = $this->withPictures ? 1 : 0;
		// The shared renderer owns the final acceptance page and the final native
		// footer, after the financial study.
		$conf->global->PROPAL_DISABLE_SIGNATURE = 1;
		$conf->global->PROPOSAL_FREE_TEXT = '';
		$conf->global->MAIN_GENERATE_DOCUMENTS_SHOW_FOOT_DETAILS = lmdbpropalpvGetEntityStringConstant($this->db, 'MAIN_GENERATE_DOCUMENTS_SHOW_FOOT_DETAILS', '0', (int) $object->entity);
		$result = 0;
		$this->commercialBodyInProgress = true;
		try {
			$result = parent::write_file($object, $outputlangs, $srctemplatepath, $hidedetails, $hidedesc, $hideref);
		} finally {
			$this->commercialBodyInProgress = false;
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
		try {
			$supplementCreated = $this->createSupplement($object, $outputlangs, $study, $supplement);
		} catch (Throwable $exception) {
			$this->error = $outputlangs->transnoentities('LmdbPropalPVErrorPdfSupplement').': '.$exception->getMessage();
			dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
			$supplementCreated = false;
		}
		if (!$supplementCreated) {
			dol_delete_file($supplement, 0, 1, 1, null, false, 0);
			return 0;
		}
		if (!$this->mergeDocuments($supplement, $file, $merged, $outputlangs)) {
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

		return 1;
	}

	/**
	 * Keep every page of the commercial body in intermediate-footer mode.
	 * The final footer is printed only on the acceptance page after composition.
	 *
	 * @return int
	 */
	protected function _pagefoot(&$pdf, $object, $outputlangs, $hidefreetext = 0)
	{
		global $conf;

		if ($this->commercialBodyInProgress || empty($object->entity) || (int) $object->entity === (int) $conf->entity) {
			return parent::_pagefoot($pdf, $object, $outputlangs, $this->commercialBodyInProgress ? 1 : $hidefreetext);
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
	 * @param array{complete:bool,result:?LmdbPropalPVFinancialResult,peak_power_kwp:float,investment_ttc:float,currency_code:string,values:array<string,mixed>} $study Study
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
		$this->_pagefoot($pdf, $object, $outputlangs, 1);

		if ($study['complete'] && $study['result'] instanceof LmdbPropalPVFinancialResult) {
			$this->addProtectedPage($pdf, $object, true);
			$this->drawFinancialPage($pdf, $object, $outputlangs, $study, $study['result']);
			$this->_pagefoot($pdf, $object, $outputlangs, 1);
		}
		$this->addProtectedPage($pdf, $object, false);
		$this->drawAcceptancePage($pdf, $object, $outputlangs);
		$this->_pagefoot($pdf, $object, $outputlangs, 0);

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
	 * @param array{complete:bool,peak_power_kwp:float,investment_ttc:float,currency_code:string,result:?LmdbPropalPVFinancialResult} $study Study
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
		$pdf->Cell(75, 6, $outputlangs->transnoentities('AmountTTC').' : '.price($study['investment_ttc'], 0, $outputlangs, 1, -1, -1, $study['currency_code']), 0, 0, 'L');
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
		$this->drawCoverMetric($pdf, $this->marge_gauche + 6, 148, $outputlangs->transnoentities('LmdbPropalPVPeakPower'), price($study['peak_power_kwp']).' kWc', $primary);
		if ($study['complete'] && $study['result'] instanceof LmdbPropalPVFinancialResult) {
			$payback = $study['result']->paybackYears === null ? $outputlangs->transnoentities('LmdbPropalPVPaybackNotReached') : price($study['result']->paybackYears).' '.$outputlangs->transnoentities('LmdbPropalPVYears');
			$this->drawCoverMetric($pdf, $this->marge_gauche + 68, 148, $outputlangs->transnoentities('LmdbPropalPVPayback'), $payback, $primary);
			$this->drawCoverMetric($pdf, $this->marge_gauche + 130, 148, $outputlangs->transnoentities('LmdbPropalPVNetGain'), price($study['result']->netGain, 0, $outputlangs, 1, -1, -1, $study['currency_code']), $primary);
		}

		$pdf->SetTextColor(70, 70, 70);
		$pdf->SetFont('', '', 8);
		$pdf->SetXY($this->marge_gauche, 193);
		$pdf->MultiCell(185, 5, $outputlangs->convToOutputCharset($outputlangs->transnoentities('LmdbPropalPVFinancialNotice')), 0, 'L');

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
	 * @param array{currency_code:string,values:array<string,mixed>} $study Study
	 * @return void
	 */
	private function drawFinancialPage($pdf, $object, $outputlangs, array $study, LmdbPropalPVFinancialResult $result)
	{
		$primary = $this->hexToRgb(lmdbpropalpvGetEntityStringConstant($this->db, 'LMDBPROPALPV_PDF_PRIMARY_COLOR', '#16324F', (int) $object->entity));
		$accent = $this->hexToRgb(lmdbpropalpvGetEntityStringConstant($this->db, 'LMDBPROPALPV_PDF_ACCENT_COLOR', '#F2B705', (int) $object->entity));
		$pdf->SetFillColor($primary[0], $primary[1], $primary[2]);
		$pdf->Rect(0, 0, $this->page_largeur, 28, 'F');
		$pdf->SetTextColor(255, 255, 255);
		$pdf->SetFont('', 'B', 18);
		$pdf->SetXY($this->marge_gauche, 10);
		$pdf->Cell(185, 8, $outputlangs->transnoentities('LmdbPropalPVProjection20Years'), 0, 0, 'L');

		$pdf->SetTextColor($primary[0], $primary[1], $primary[2]);
		$pdf->SetFont('', 'B', 11);
		$pdf->SetXY($this->marge_gauche, 36);
		$pdf->Cell(58, 6, $outputlangs->transnoentities('LmdbPropalPVNetGain'), 0, 0, 'L');
		$pdf->SetXY(76, 36);
		$pdf->Cell(58, 6, $outputlangs->transnoentities('LmdbPropalPVROI20'), 0, 0, 'L');
		$pdf->SetXY(139, 36);
		$pdf->Cell(58, 6, $outputlangs->transnoentities('LmdbPropalPVPayback'), 0, 0, 'L');
		$pdf->SetFont('', 'B', 14);
		$pdf->SetTextColor($accent[0], $accent[1], $accent[2]);
		$pdf->SetXY($this->marge_gauche, 46);
		$pdf->Cell(58, 7, price($result->netGain, 0, $outputlangs, 1, -1, -1, $study['currency_code']), 0, 0, 'L');
		$pdf->SetXY(76, 46);
		$pdf->Cell(58, 7, price($result->roiRate * 100.0).' %', 0, 0, 'L');
		$pdf->SetXY(139, 46);
		$pdf->Cell(58, 7, ($result->paybackYears === null ? $outputlangs->transnoentities('LmdbPropalPVPaybackNotReached') : price($result->paybackYears).' '.$outputlangs->transnoentities('LmdbPropalPVYears')), 0, 0, 'L');

		$this->drawCashflowChart($pdf, $result, 12, 62, 185, 48, $primary, $accent);
		$pdf->SetFont('', '', 5.8);
		$pdf->SetTextColor(30, 30, 30);
		$headers = array('LmdbPropalPVYear', 'LmdbPropalPVProduction', 'LmdbPropalPVNetworkPrice', 'LmdbPropalPVSurplusSale', 'LmdbPropalPVElectricitySavings', 'LmdbPropalPVPremium', 'LmdbPropalPVAnnualGain', 'LmdbPropalPVCumulativeCashflow', 'LmdbPropalPVAnnualReturn');
		$widths = array(10, 21, 21, 21, 23, 18, 21, 28, 22);
		$x = $this->marge_gauche;
		$y = 118;
		$pdf->SetFillColor(235, 240, 246);
		foreach ($headers as $index => $header) {
			$pdf->SetXY($x, $y);
			$pdf->MultiCell($widths[$index], 9, $outputlangs->transnoentities($header), 1, 'C', true, 0);
			$x += $widths[$index];
		}
		$y += 9;
		foreach ($result->years as $year) {
			$x = $this->marge_gauche;
			$values = array($year->year, price($year->productionKwh), price($year->retailPricePerKwh), price($year->surplusSale), price($year->electricitySavings), price($year->premium), price($year->annualGain), price($year->cumulativeCashflow), price($year->annualReturnRate * 100.0).' %');
			foreach ($values as $index => $value) {
				$pdf->SetXY($x, $y);
				$pdf->Cell($widths[$index], 5.2, (string) $value, 1, 0, $index === 0 ? 'C' : 'R');
				$x += $widths[$index];
			}
			$y += 5.2;
		}
		$pdf->SetFont('', '', 7);
		$pdf->SetXY($this->marge_gauche, $y + 4);
		$assumptions = $outputlangs->transnoentities('LmdbPropalPVSelfConsumption').' '.price((float) $study['values']['self_consumption_pct']).' % · '.$outputlangs->transnoentities('LmdbPropalPVPanelDegradation').' '.price((float) $study['values']['panel_degradation_pct']).' % · '.$outputlangs->transnoentities('LmdbPropalPVElectricityGrowth').' '.price((float) $study['values']['electricity_growth_pct']).' %';
		$pdf->MultiCell(185, 4, $assumptions, 0, 'L');
		$pdf->MultiCell(185, 4, $outputlangs->convToOutputCharset($outputlangs->transnoentities('LmdbPropalPVFinancialNotice')), 0, 'L');
		$customNotice = lmdbpropalpvGetEntityStringConstant($this->db, 'LMDBPROPALPV_FINANCIAL_DISCLAIMER', '', (int) $object->entity);
		if ($customNotice !== '') {
			$pdf->MultiCell(185, 4, $outputlangs->convToOutputCharset(dol_string_nohtmltag($customNotice)), 0, 'L');
		}
	}

	/** @param array{0:int,1:int,2:int} $primary @param array{0:int,1:int,2:int} $accent @return void */
	private function drawCashflowChart($pdf, LmdbPropalPVFinancialResult $result, $x, $y, $width, $height, array $primary, array $accent)
	{
		$values = array($result->initialCashflow);
		foreach ($result->years as $year) {
			$values[] = $year->cumulativeCashflow;
		}
		$min = min(0.0, min($values));
		$max = max(0.0, max($values));
		$span = max(1.0, $max - $min);
		$pdf->SetDrawColor(210, 215, 220);
		$pdf->Rect($x, $y, $width, $height, 'D');
		$zeroY = $y + $height - ((0.0 - $min) / $span) * $height;
		$pdf->Line($x, $zeroY, $x + $width, $zeroY);
		$pdf->SetDrawColor($primary[0], $primary[1], $primary[2]);
		$previousX = $x;
		$previousY = $y + $height - (($values[0] - $min) / $span) * $height;
		$lastIndex = max(1, count($values) - 1);
		foreach ($values as $index => $value) {
			$currentX = $x + ($index / $lastIndex) * $width;
			$currentY = $y + $height - (($value - $min) / $span) * $height;
			if ($index > 0) {
				$pdf->Line($previousX, $previousY, $currentX, $currentY);
			}
			$previousX = $currentX;
			$previousY = $currentY;
		}
		$pdf->SetFillColor($accent[0], $accent[1], $accent[2]);
		if (method_exists($pdf, 'Circle')) {
			$pdf->Circle($previousX, $previousY, 1.3, 0, 360, 'F');
		}
	}

	/** @return bool */
	private function mergeDocuments($supplementFile, $bodyFile, $mergedFile, $outputlangs)
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
		try {
			$supplementPages = $pdf->setSourceFile($supplementFile);
			$this->importPage($pdf, 1);
			$bodyPages = $pdf->setSourceFile($bodyFile);
			for ($page = 1; $page <= $bodyPages; $page++) {
				$this->importPage($pdf, $page);
			}
			if ($supplementPages > 1) {
				$pdf->setSourceFile($supplementFile);
				for ($page = 2; $page <= $supplementPages; $page++) {
					$this->importPage($pdf, $page);
				}
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
	private function importPage($pdf, $page)
	{
		$template = $pdf->importPage($page);
		$size = $pdf->getTemplateSize($template);
		$width = isset($size['width']) ? (float) $size['width'] : (float) $size['w'];
		$height = isset($size['height']) ? (float) $size['height'] : (float) $size['h'];
		$pdf->AddPage($width > $height ? 'L' : 'P', array($width, $height));
		$pdf->useTemplate($template);
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
