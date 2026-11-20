-- Prérequis PostgreSQL hors Docker : extensions à créer UNE FOIS par un superutilisateur, avant la première migration.
--
-- Dans Docker, l'utilisateur de la base est superutilisateur : les migrations créent elles-mêmes ces extensions.
-- Sur un serveur classique, le propriétaire de la base ne l'est pas, et la première migration échoue avec :
--   « permission denied to create extension "postgis" — Must be superuser to create this extension ».
-- Les migrations utilisent CREATE EXTENSION IF NOT EXISTS : une fois les extensions présentes, elles passent.
--
-- Paquets système nécessaires (Debian / Ubuntu, dépôt PGDG) : postgresql-16 postgresql-16-postgis-3
-- (pg_trgm, unaccent et btree_gist sont fournis avec PostgreSQL).
--
-- Exécution :
--   sudo -u postgres psql -d trouvemoi -f ops/postgres/extensions.sql
-- Idempotent : peut être rejoué sans risque.

\set ON_ERROR_STOP on

CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS unaccent;
CREATE EXTENSION IF NOT EXISTS btree_gist;

SELECT extname, extversion FROM pg_extension WHERE extname IN ('postgis', 'pg_trgm', 'unaccent', 'btree_gist') ORDER BY extname;
