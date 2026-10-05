-- ============================================================
-- Departments + programs for ISAP and MCNP (PostgreSQL / Supabase)
-- Converted from insert_departments.php and insert_programs.php.
-- Run AFTER supabase_schema.sql (SQL Editor -> paste -> Run).
-- Safe to re-run: existing rows are updated, not duplicated.
-- ============================================================

-- The base seed used the code 'BSPh'; the program list uses 'BSPH'.
UPDATE programs SET program_code = 'BSPH' WHERE program_code = 'BSPh'
  AND NOT EXISTS (SELECT 1 FROM programs WHERE program_code = 'BSPH');

-- ---------- DEPARTMENTS ----------
INSERT INTO departments (institution_id, department_code, department_name)
SELECT i.institution_id, v.code, v.name
FROM (VALUES
    ('ISAP', 'CASTE', 'College of Arts, Sciences and Teacher Education'),
    ('ISAP', 'CITE', 'College of Information Technology and Engineering'),
    ('ISAP', 'CBEM', 'College of Business Education and Management'),
    ('ISAP', 'CCJE', 'College of Criminal Justice Education'),
    ('ISAP', 'TVET', 'TVET Programs (TESDA)'),
    ('MCNP', 'ALLIED_HEALTH', 'Allied Health & Medical Programs'),
    ('MCNP', 'SHORT_TERM', 'Short-Term & Technical Programs')
) AS v(inst, code, name)
JOIN institutions i ON i.institution_code = v.inst
ON CONFLICT (institution_id, department_code) DO UPDATE SET department_name = EXCLUDED.department_name;

-- ---------- PROGRAMS ----------
INSERT INTO programs (program_code, program_name, program_type, duration_years, institution_id, department_id)
SELECT v.code, v.name, v.ptype, v.dur, i.institution_id, d.department_id
FROM (VALUES
    ('ISAP', 'BSSW', 'BS in Social Work', 'Bachelor''s Degree', 4.0, 'CASTE'),
    ('ISAP', 'BSED_ENG', 'BSEd Major in English', 'Bachelor''s Degree', 4.0, 'CASTE'),
    ('ISAP', 'BSED_MATH', 'BSEd Major in Mathematics', 'Bachelor''s Degree', 4.0, 'CASTE'),
    ('ISAP', 'BSED_SOCSCI', 'BSEd Major in Social Science', 'Bachelor''s Degree', 4.0, 'CASTE'),
    ('ISAP', 'BSED_SCI', 'BSEd Major in Science', 'Bachelor''s Degree', 4.0, 'CASTE'),
    ('ISAP', 'BSED_FIL', 'BSEd Major in Filipino', 'Bachelor''s Degree', 4.0, 'CASTE'),
    ('ISAP', 'BSPSY', 'BS in Psychology', 'Bachelor''s Degree', 4.0, 'CASTE'),
    ('ISAP', 'BSPE', 'BS in Physical Education', 'Bachelor''s Degree', 4.0, 'CASTE'),
    ('ISAP', 'BSIT', 'BS in Information Technology', 'Bachelor''s Degree', 4.0, 'CITE'),
    ('ISAP', 'BSCPE', 'BS in Computer Engineering', 'Bachelor''s Degree', 4.0, 'CITE'),
    ('ISAP', 'BSCS', 'Bachelor of Science in Computer Science', 'Bachelor''s Degree', 4.0, 'CITE'),
    ('ISAP', 'BSCA', 'BS in Customs Administration', 'Bachelor''s Degree', 4.0, 'CBEM'),
    ('ISAP', 'BSBA', 'BS in Business Administration', 'Bachelor''s Degree', 4.0, 'CBEM'),
    ('ISAP', 'BSA', 'BS in Accountancy', 'Bachelor''s Degree', 4.0, 'CBEM'),
    ('ISAP', 'BSHM', 'BS in Hospitality Management', 'Bachelor''s Degree', 4.0, 'CBEM'),
    ('ISAP', 'BSTM', 'BS in Tourism Management', 'Bachelor''s Degree', 4.0, 'CBEM'),
    ('ISAP', 'BSCRIM', 'BS in Criminology', 'Bachelor''s Degree', 4.0, 'CCJE'),
    ('ISAP', 'FBS_NCII', 'Food and Beverages NCII', 'Diploma', 1.0, 'TVET'),
    ('ISAP', 'HK_NCII', 'Housekeeping NCII', 'Diploma', 1.0, 'TVET'),
    ('ISAP', 'CSS_NCII', 'Computer Systems Servicing NCII', 'Diploma', 1.0, 'TVET'),
    ('MCNP', 'BSN', 'BS in Nursing', 'Bachelor''s Degree', 4.0, 'ALLIED_HEALTH'),
    ('MCNP', 'BSRT', 'BS in Radiologic Technology', 'Bachelor''s Degree', 4.0, 'ALLIED_HEALTH'),
    ('MCNP', 'BSMLS', 'BS in Medical Laboratory Science', 'Bachelor''s Degree', 4.0, 'ALLIED_HEALTH'),
    ('MCNP', 'BSPH', 'BS in Pharmacy', 'Bachelor''s Degree', 4.0, 'ALLIED_HEALTH'),
    ('MCNP', 'BSPT', 'BS in Physical Therapy', 'Bachelor''s Degree', 4.0, 'ALLIED_HEALTH'),
    ('MCNP', 'MIDWIFERY', 'Diploma in Midwifery', 'Diploma', 2.0, 'SHORT_TERM'),
    ('MCNP', 'DENTAL_TECH', 'Diploma in Dental Technology', 'Diploma', 2.0, 'SHORT_TERM'),
    ('MCNP', 'PHARMACY_AIDE', 'Diploma in Pharmacy Aide', 'Diploma', 2.0, 'SHORT_TERM'),
    ('MCNP', 'CAREGIVING_NCII', 'Caregiving NC II', 'Diploma', 1.0, 'SHORT_TERM'),
    ('MCNP', 'CSS_NCII_MCNP', 'Computer Systems Servicing NC II', 'Diploma', 1.0, 'SHORT_TERM')
) AS v(inst, code, name, ptype, dur, dept)
JOIN institutions i ON i.institution_code = v.inst
JOIN departments d ON d.institution_id = i.institution_id AND d.department_code = v.dept
ON CONFLICT (program_code) DO UPDATE SET
    program_name = EXCLUDED.program_name, program_type = EXCLUDED.program_type,
    duration_years = EXCLUDED.duration_years, institution_id = EXCLUDED.institution_id,
    department_id = EXCLUDED.department_id;

-- Check the result
SELECT i.institution_code, d.department_code, p.program_code, p.program_name
FROM programs p
LEFT JOIN institutions i ON i.institution_id = p.institution_id
LEFT JOIN departments d ON d.department_id = p.department_id
ORDER BY 1, 2, 3;
