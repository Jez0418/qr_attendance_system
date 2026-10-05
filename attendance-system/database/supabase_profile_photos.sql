-- Profile photos are stored in the database (Vercel has no persistent disk for uploads/).
-- The browser resizes the photo to a small JPEG and the app saves it as a data URI
-- (data:image/jpeg;base64,...), which does not fit in VARCHAR(255). Safe to re-run.
ALTER TABLE students ALTER COLUMN photo TYPE TEXT;
ALTER TABLE teachers ALTER COLUMN photo TYPE TEXT;
