-- Default administrator: admin@wps.local / admin (change the password after the first login)
INSERT INTO users (email, password_hash, role)
VALUES ('admin@wps.local', '$2y$10$IPmEfr0osgTPQ9tJOdiHTuaTCg7sh2Kru7eJcGsBOUC88rSqTX89u', 'admin')
ON CONFLICT (email) DO NOTHING;
