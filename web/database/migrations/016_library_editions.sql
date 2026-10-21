-- The library shows editions of one exam on one card, grouped by name (App\Services\Editions).
-- An administrator can correct that: edition_group "solo" keeps an exam on its own card, any
-- other value is the group it joins; edition_first marks the edition a card shows first.
ALTER TABLE exams
    ADD COLUMN edition_group VARCHAR(190) NULL,
    ADD COLUMN edition_first TINYINT(1) NOT NULL DEFAULT 0;
