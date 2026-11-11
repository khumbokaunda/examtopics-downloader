-- What the pricing page shows of a plan: amounts (so monthly and yearly can be compared), its
-- features, whether it is the one to recommend, and whether it includes the plan before it.
-- Also which plan a contact message asks about.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plans' AND COLUMN_NAME = 'amount_monthly');
SET @s := IF(@c = 0, 'ALTER TABLE plans ADD COLUMN amount_monthly DECIMAL(12,2) NULL AFTER price, ADD COLUMN amount_yearly DECIMAL(12,2) NULL AFTER amount_monthly,
    ADD COLUMN features TEXT NULL AFTER summary, ADD COLUMN featured TINYINT(1) NOT NULL DEFAULT 0 AFTER public, ADD COLUMN includes_previous TINYINT(1) NOT NULL DEFAULT 0 AFTER featured', 'DO 0');
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- A price written as "MWK 20,000/month" becomes an amount; the text stays as it was.
UPDATE plans SET amount_monthly = CAST(REGEXP_REPLACE(SUBSTRING_INDEX(price, '/', 1), '[^0-9.]', '') AS DECIMAL(12,2))
 WHERE amount_monthly IS NULL AND price REGEXP '^[A-Za-z]{0,4} ?[0-9][0-9,. ]* ?(/|a |per )? ?(month|mo)';

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contact_messages' AND COLUMN_NAME = 'plan_id');
SET @s := IF(@c = 0, 'ALTER TABLE contact_messages ADD COLUMN plan_id INT UNSIGNED NULL AFTER phone, ADD CONSTRAINT fk_contact_messages_plan FOREIGN KEY (plan_id) REFERENCES plans (id) ON DELETE SET NULL', 'DO 0');
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
