ALTER TABLE llx_lmdbpropalpv_tariff_set ADD UNIQUE INDEX uk_lmdbpropalpv_tariff_set_ref (entity, ref);
ALTER TABLE llx_lmdbpropalpv_tariff_set ADD INDEX idx_lmdbpropalpv_tariff_set_entity (entity);
ALTER TABLE llx_lmdbpropalpv_tariff_set ADD INDEX idx_lmdbpropalpv_tariff_set_period (entity, date_start, date_end, status);
ALTER TABLE llx_lmdbpropalpv_tariff_set ADD INDEX idx_lmdbpropalpv_tariff_set_currency (entity, currency_code);
