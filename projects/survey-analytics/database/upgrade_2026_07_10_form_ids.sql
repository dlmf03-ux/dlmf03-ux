ALTER TABLE survey_forms
  ADD COLUMN yandex_form_id VARCHAR(64) NULL UNIQUE AFTER slug;

UPDATE survey_forms SET yandex_form_id = '6a38ab6f90fa7b77b648e38d', title = 'Анкета гостя (столовая)' WHERE slug = 'dining';
UPDATE survey_forms SET yandex_form_id = '6a4dc926f47e732096a7b4d7', title = 'Анкета гостя (медицинский центр)' WHERE slug = 'medical';
UPDATE survey_forms SET yandex_form_id = '6a4dcab61f1eb5216f30bcb1', title = 'Анкета гостя (условия проживания)' WHERE slug = 'room';
UPDATE survey_forms SET yandex_form_id = '6a4dce7750569021d1990f3c', title = 'Анкета гостя (организация досуга)' WHERE slug = 'leisure';
UPDATE survey_forms SET yandex_form_id = '6a4dcf414936392135c2ca19', title = 'Анкета гостя (итоговая анкета при выезде)' WHERE slug = 'final';
UPDATE survey_forms SET yandex_form_id = '6a50ae1690fa7ba016664189', title = 'МИНИ-АНКЕТА' WHERE slug = 'quick';
