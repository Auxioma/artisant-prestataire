-- SECURITY.md §7 (T-905a) — droits PostgreSQL de production : séparation propriétaire / application.
--
-- * trouvemoi_owner : propriétaire du schéma, utilisé UNIQUEMENT par `doctrine:migrations:migrate` au déploiement.
-- * trouvemoi_app   : rôle de l'application et des workers ; lecture / écriture des données, aucun droit de
--   structure (pas de CREATE, ALTER, DROP, TRUNCATE) et ne possède aucune table : il ne peut donc ni supprimer ni
--   désactiver les déclencheurs « ajout seul » (journal d'audit, grand livre des crédits, consentements…).
--
-- Exécution (superutilisateur ou administrateur du serveur géré), mots de passe fournis par le coffre, jamais écrits :
--   psql "$ADMIN_URL" -v db=trouvemoi -v owner_password="$OWNER_PW" -v app_password="$APP_PW" -f ops/postgres/roles.sql
-- Puis : DATABASE_URL de l'application → trouvemoi_app ; URL des migrations → trouvemoi_owner.
-- Idempotent : peut être rejoué après chaque migration (nouvelles tables, nouveaux schémas).

\set ON_ERROR_STOP on

SELECT format('CREATE ROLE trouvemoi_owner LOGIN PASSWORD %L', :'owner_password')
 WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'trouvemoi_owner') \gexec
SELECT format('CREATE ROLE trouvemoi_app LOGIN PASSWORD %L', :'app_password')
 WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'trouvemoi_app') \gexec
ALTER ROLE trouvemoi_app NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;

REVOKE ALL ON DATABASE :"db" FROM PUBLIC;
GRANT CONNECT, TEMPORARY ON DATABASE :"db" TO trouvemoi_app;
GRANT ALL ON DATABASE :"db" TO trouvemoi_owner;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;

-- Toutes les tables et séquences des schémas applicatifs (hors extensions) : propriété → owner, données → app.
DO $$
DECLARE s text; o record;
BEGIN
    FOR s IN SELECT nspname FROM pg_namespace
             WHERE nspname NOT LIKE 'pg\_%' AND nspname NOT IN ('information_schema', 'tiger', 'tiger_data', 'topology')
    LOOP
        EXECUTE format('ALTER SCHEMA %I OWNER TO trouvemoi_owner', s);
        EXECUTE format('REVOKE CREATE ON SCHEMA %I FROM trouvemoi_app', s);
        EXECUTE format('GRANT USAGE ON SCHEMA %I TO trouvemoi_app', s);
        FOR o IN SELECT c.relname, c.relkind FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                 LEFT JOIN pg_depend d ON d.objid = c.oid AND d.deptype = 'e'
                 WHERE n.nspname = s AND c.relkind IN ('r', 'p', 'S', 'v', 'm') AND d.objid IS NULL
                   -- séquences liées à une colonne : elles suivent leur table
                   AND NOT EXISTS (SELECT 1 FROM pg_depend a WHERE a.objid = c.oid AND a.deptype IN ('a', 'i'))
        LOOP
            EXECUTE format('ALTER %s %I.%I OWNER TO trouvemoi_owner',
                CASE o.relkind WHEN 'S' THEN 'SEQUENCE' WHEN 'v' THEN 'VIEW' WHEN 'm' THEN 'MATERIALIZED VIEW' ELSE 'TABLE' END, s, o.relname);
        END LOOP;
        EXECUTE format('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA %I TO trouvemoi_app', s);
        EXECUTE format('REVOKE TRUNCATE, REFERENCES, TRIGGER ON ALL TABLES IN SCHEMA %I FROM trouvemoi_app', s);
        EXECUTE format('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA %I TO trouvemoi_app', s);
        EXECUTE format('GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA %I TO trouvemoi_app', s);
        EXECUTE format('ALTER DEFAULT PRIVILEGES FOR ROLE trouvemoi_owner IN SCHEMA %I GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO trouvemoi_app', s);
        EXECUTE format('ALTER DEFAULT PRIVILEGES FOR ROLE trouvemoi_owner IN SCHEMA %I GRANT USAGE, SELECT ON SEQUENCES TO trouvemoi_app', s);
    END LOOP;
END $$;

-- Tables en ajout seul : en plus des déclencheurs, l'application n'a ni UPDATE ni DELETE.
-- Exceptions : purge de rétention du journal d'audit (CDC §11) et consentements expirés (DELETE seulement).
REVOKE UPDATE, DELETE ON admin.setting_history, artisan.verification_decision, billing.credit_ledger_entry,
    identity.legal_acceptance, identity.legal_document, notification.template_version FROM trouvemoi_app;
REVOKE UPDATE ON admin.audit_log, identity.consent FROM trouvemoi_app;
