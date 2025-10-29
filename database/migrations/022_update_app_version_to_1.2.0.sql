-- Update application version to 1.2.0
INSERT INTO settings (setting_key, setting_value)
VALUES ('app_version', '1.2.0')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);


