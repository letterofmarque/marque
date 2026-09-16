-- Let the `marque` user create and drop its own per-worker test databases.
--
-- tools/test-engines runs packages in parallel, and parallelism is only safe
-- when each worker owns a database nobody else touches (job #10548: two
-- packages sharing one database produced 172 phantom failures). So the runner
-- needs marque_test_1, marque_test_2, ... on demand.
--
-- The official MySQL and MariaDB images grant MYSQL_USER rights to
-- MYSQL_DATABASE and nothing else, so `marque` can use marque_test but cannot
-- CREATE DATABASE marque_test_1 — it fails with ERROR 1044, which reads like a
-- connection problem rather than a missing grant. Postgres has no equivalent
-- restriction; its marque role may already create databases, which is why this
-- file has no Postgres counterpart.
--
-- Granting on the `marque\_test\_%` pattern rather than *.* keeps the blast
-- radius to databases this suite owns. The backslashes escape LIKE wildcards
-- so the pattern matches literal underscores.
GRANT ALL PRIVILEGES ON `marque\_test\_%`.* TO 'marque'@'%';
FLUSH PRIVILEGES;
