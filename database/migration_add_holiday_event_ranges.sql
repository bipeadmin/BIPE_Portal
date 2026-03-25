ALTER TABLE holiday_events
    ADD COLUMN end_date DATE DEFAULT NULL AFTER event_date,
    ADD INDEX idx_holiday_range (academic_year_id, event_date, end_date);
