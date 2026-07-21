<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require_once __DIR__.'/lmdbpropalpv_pdf_base.class.php';

/** Illustrated PV Signature proposal model. */
class pdf_lmdbpropalpv_withpictures extends LmdbPropalPVPdfBase
{
	/** @param DoliDB $db Database handler */
	public function __construct($db)
	{
		global $langs;
		parent::__construct($db);
		$langs->load('lmdbpropalpv@lmdbpropalpv');
		$this->name = 'lmdbpropalpv_withpictures';
		$this->description = $langs->trans('DocModelLmdbPropalPVWithPicturesDescription');
		$this->withPictures = true;
		$this->update_main_doc_field = 1;
	}
}
