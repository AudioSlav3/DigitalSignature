-- SQL schema for the mw_digital_signatures table
CREATE TABLE IF NOT EXISTS /*_*/mw_digital_signatures (
  ds_id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
  ds_page_id INT NOT NULL,
  ds_rev_id INT NOT NULL,
  ds_user_id INT NOT NULL,
  ds_timestamp VARBINARY(14) NOT NULL,
  ds_content_hash VARBINARY(32) NOT NULL, -- Assuming SHA256, 32 bytes
  ds_is_valid TINYINT(1) NOT NULL DEFAULT 1,
  ds_remarks TEXT NULL,

  INDEX ds_page_rev_idx (ds_page_id, ds_rev_id),
  INDEX ds_page_valid_idx (ds_page_id, ds_is_valid)
) /*$wgDBTableOptions*/;
