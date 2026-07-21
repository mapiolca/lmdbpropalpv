CREATE TABLE llx_lmdbpropalpv_tariff_set (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	ref varchar(128) NOT NULL,
	label varchar(255) NOT NULL,
	date_start date NOT NULL,
	date_end date DEFAULT NULL,
	currency_code varchar(3) NOT NULL,
	source_url text,
	source_published_at date DEFAULT NULL,
	source_hash varchar(128) DEFAULT NULL,
	official smallint DEFAULT 0 NOT NULL,
	status smallint DEFAULT 1 NOT NULL,
	date_creation datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer DEFAULT NULL,
	fk_user_modif integer DEFAULT NULL,
	import_key varchar(14) DEFAULT NULL
) ENGINE=innodb;
