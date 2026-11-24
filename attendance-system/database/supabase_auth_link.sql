-- Add a stable mapping from the app's existing integer profiles to Supabase Auth.
-- User profiles and attendance history remain in public.users and its related tables.
-- Password hashes can be cleared after Auth profiles are linked because Supabase Auth
-- becomes the credential source.

ALTER TABLE public.users
    ADD COLUMN IF NOT EXISTS auth_user_id uuid UNIQUE REFERENCES auth.users(id) ON DELETE SET NULL;

ALTER TABLE public.users
    ALTER COLUMN password DROP NOT NULL;

