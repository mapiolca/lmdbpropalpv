ALTER TABLE llx_lmdbpropalpv_tariff_rule ADD INDEX idx_lmdbpropalpv_rule_set (fk_tariff_set);
ALTER TABLE llx_lmdbpropalpv_tariff_rule ADD INDEX idx_lmdbpropalpv_rule_lookup (fk_tariff_set, metric, option_code, subscription_kva, min_kwp, max_kwp);
