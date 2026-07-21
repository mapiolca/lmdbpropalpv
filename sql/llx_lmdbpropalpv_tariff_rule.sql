CREATE TABLE llx_lmdbpropalpv_tariff_rule (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	fk_tariff_set integer NOT NULL,
	metric varchar(32) NOT NULL,
	option_code varchar(32) DEFAULT NULL,
	subscription_kva double(24,8) DEFAULT NULL,
	min_kwp double(24,8) DEFAULT NULL,
	max_kwp double(24,8) DEFAULT NULL,
	value double(24,8) NOT NULL,
	unit varchar(16) NOT NULL,
	date_creation datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer DEFAULT NULL,
	fk_user_modif integer DEFAULT NULL
) ENGINE=innodb;
