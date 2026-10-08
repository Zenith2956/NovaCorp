SET local check_function_bodies = off;

CREATE SCHEMA "automation";

CREATE EXTENSION "pg_cron";

CREATE EXTENSION "pg_net" SCHEMA "extensions";

CREATE SEQUENCE "public"."chiffres_affaires_id_seq" AS bigint INCREMENT BY 1 MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1 NO CYCLE;

CREATE SEQUENCE "public"."connexion_logs_id_seq" AS bigint INCREMENT BY 1 MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1 NO CYCLE;

CREATE SEQUENCE "public"."demandes_id_seq" AS bigint INCREMENT BY 1 MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1 NO CYCLE;

CREATE SEQUENCE "public"."etapes_circuit_id_seq" AS bigint INCREMENT BY 1 MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1 NO CYCLE;

CREATE SEQUENCE "public"."failed_jobs_id_seq" AS bigint INCREMENT BY 1 MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1 NO CYCLE;

CREATE SEQUENCE "public"."historique_id_seq" AS bigint INCREMENT BY 1 MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1 NO CYCLE;

CREATE SEQUENCE "public"."jobs_id_seq" AS bigint INCREMENT BY 1 MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1 NO CYCLE;

CREATE SEQUENCE "public"."mails_sortants_id_seq" AS bigint INCREMENT BY 1 MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1 NO CYCLE;

CREATE SEQUENCE "public"."migrations_id_seq" AS integer INCREMENT BY 1 MINVALUE 1 MAXVALUE 2147483647 START WITH 1 CACHE 1 NO CYCLE;

CREATE SEQUENCE "public"."pieces_jointes_id_seq" AS bigint INCREMENT BY 1 MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1 NO CYCLE;

CREATE SEQUENCE "public"."projet_user_id_seq" AS bigint INCREMENT BY 1 MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1 NO CYCLE;

CREATE SEQUENCE "public"."projets_id_seq" AS bigint INCREMENT BY 1 MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1 NO CYCLE;

CREATE SEQUENCE "public"."roles_id_seq" AS bigint INCREMENT BY 1 MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1 NO CYCLE;

CREATE SEQUENCE "public"."stats_quotidiennes_id_seq" AS bigint INCREMENT BY 1 MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1 NO CYCLE;

CREATE SEQUENCE "public"."taches_id_seq" AS bigint INCREMENT BY 1 MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1 NO CYCLE;

CREATE SEQUENCE "public"."transitions_id_seq" AS bigint INCREMENT BY 1 MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1 NO CYCLE;

CREATE SEQUENCE "public"."types_demande_id_seq" AS bigint INCREMENT BY 1 MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1 NO CYCLE;

CREATE SEQUENCE "public"."users_id_seq" AS bigint INCREMENT BY 1 MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1 NO CYCLE;

CREATE TABLE "public"."attachments" (
  "id"         uuid                     NOT NULL DEFAULT gen_random_uuid(),
  "request_id" uuid                     NOT NULL,
  "file_url"   text                     NOT NULL,
  "created_at" timestamp with time zone NOT NULL DEFAULT now(),
  CONSTRAINT "attachments_pkey" PRIMARY KEY (id)
);

ALTER TABLE "public"."attachments"
  ENABLE ROW LEVEL SECURITY;

CREATE TABLE "public"."audit_logs" (
  "id"         bigint                   GENERATED ALWAYS AS IDENTITY NOT NULL,
  "user_id"    uuid,
  "action"     text                     NOT NULL,
  "entity"     text,
  "entity_id"  text,
  "metadata"   jsonb                    NOT NULL DEFAULT '{}'::jsonb,
  "created_at" timestamp with time zone NOT NULL DEFAULT now(),
  CONSTRAINT "audit_logs_pkey" PRIMARY KEY (id)
);

ALTER TABLE "public"."audit_logs"
  ENABLE ROW LEVEL SECURITY;

CREATE TABLE "public"."cache_locks" (
  "key"        character varying(191) NOT NULL,
  "owner"      character varying(191) NOT NULL,
  "expiration" bigint                 NOT NULL,
  CONSTRAINT "cache_locks_pkey" PRIMARY KEY (key)
);

ALTER TABLE "public"."cache_locks"
  ENABLE ROW LEVEL SECURITY;

CREATE TABLE "public"."cache" (
  "key"        character varying(191) NOT NULL,
  "value"      text                   NOT NULL,
  "expiration" bigint                 NOT NULL,
  CONSTRAINT "cache_pkey" PRIMARY KEY (key)
);

ALTER TABLE "public"."cache"
  ENABLE ROW LEVEL SECURITY;

CREATE TABLE "public"."chiffres_affaires" (
  "id"          bigint                         NOT NULL DEFAULT nextval('public.chiffres_affaires_id_seq'::regclass),
  "periode"     date                           NOT NULL,
  "montant"     numeric(14,2)                  NOT NULL,
  "projet_id"   bigint,
  "commentaire" character varying(191),
  "created_at"  timestamp(0) without time zone,
  "updated_at"  timestamp(0) without time zone,
  CONSTRAINT "chiffres_affaires_pkey" PRIMARY KEY (id)
);

ALTER TABLE "public"."chiffres_affaires"
  ENABLE ROW LEVEL SECURITY;

CREATE TABLE "public"."connexion_logs" (
  "id"         bigint                         NOT NULL DEFAULT nextval('public.connexion_logs_id_seq'::regclass),
  "user_id"    bigint,
  "email"      character varying(191),
  "evenement"  character varying(30)          NOT NULL,
  "ip_address" character varying(45),
  "user_agent" text,
  "created_at" timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT "connexion_logs_pkey" PRIMARY KEY (id)
);

ALTER TABLE "public"."connexion_logs"
  ENABLE ROW LEVEL SECURITY;

CREATE TABLE "public"."demandes" (
  "id"                    bigint                         NOT NULL DEFAULT nextval('public.demandes_id_seq'::regclass),
  "demandeur_id"          bigint                         NOT NULL,
  "manager_id"            bigint,
  "type"                  character varying(50)          NOT NULL,
  "objet"                 character varying(191)         NOT NULL,
  "message"               text                           NOT NULL,
  "statut"                character varying(20)          NOT NULL DEFAULT 'en_attente'::character varying,
  "envoyee_at"            timestamp(0) without time zone,
  "created_at"            timestamp(0) without time zone,
  "updated_at"            timestamp(0) without time zone,
  "jeton_decision"        uuid,
  "decision_at"           timestamp(0) without time zone,
  "decision_par"          bigint,
  "date_souhaitee"        date,
  "urgente"               boolean                        NOT NULL DEFAULT false,
  "relance_le"            date,
  "echeance_le"           date,
  "deadline"              date,
  "montant"               numeric(10,2),
  "date_debut"            date,
  "date_fin"              date,
  "nb_jours_ouvres"       smallint,
  "etape"                 smallint                       NOT NULL DEFAULT '1'::smallint,
  "commentaire_decision"  text,
  "traite_par"            bigint,
  "traite_at"             timestamp(0) without time zone,
  "derniere_action_par"   bigint,
  "derniere_action_canal" character varying(20),
  CONSTRAINT "demandes_jeton_decision_unique" UNIQUE (jeton_decision),
  CONSTRAINT "demandes_pkey" PRIMARY KEY (id)
);

ALTER TABLE "public"."demandes"
  ENABLE ROW LEVEL SECURITY;

CREATE TABLE "public"."employees" (
  "id"         uuid                     NOT NULL,
  "email"      text                     NOT NULL,
  "first_name" text,
  "last_name"  text,
  "phone"      text,
  "manager_id" uuid,
  "created_at" timestamp with time zone NOT NULL DEFAULT now(),
  CONSTRAINT "employees_email_key" UNIQUE (email),
  CONSTRAINT "employees_pkey" PRIMARY KEY (id)
);

ALTER TABLE "public"."employees"
  ENABLE ROW LEVEL SECURITY;

CREATE TABLE "public"."etapes_circuit" (
  "id"              bigint                         NOT NULL DEFAULT nextval('public.etapes_circuit_id_seq'::regclass),
  "type_code"       character varying(50)          NOT NULL,
  "ordre"           smallint                       NOT NULL,
  "libelle"         character varying(100)         NOT NULL,
  "valideur"        character varying(50)          NOT NULL,
  "condition_champ" character varying(50),
  "condition_seuil" numeric(12,2),
  "delai_relance"   smallint                       NOT NULL DEFAULT '2'::smallint,
  "delai_escalade"  smallint                       NOT NULL DEFAULT '5'::smallint,
  "created_at"      timestamp(0) without time zone,
  "updated_at"      timestamp(0) without time zone,
  CONSTRAINT "etapes_circuit_pkey" PRIMARY KEY (id),
  CONSTRAINT "etapes_circuit_type_code_ordre_unique" UNIQUE (type_code, ordre)
);

ALTER TABLE "public"."etapes_circuit"
  ENABLE ROW LEVEL SECURITY;

CREATE TABLE "public"."failed_jobs" (
  "id"         bigint                         NOT NULL DEFAULT nextval('public.failed_jobs_id_seq'::regclass),
  "uuid"       character varying(191)         NOT NULL,
  "connection" character varying(191)         NOT NULL,
  "queue"      character varying(191)         NOT NULL,
  "payload"    text                           NOT NULL,
  "exception"  text                           NOT NULL,
  "failed_at"  timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT "failed_jobs_pkey" PRIMARY KEY (id),
  CONSTRAINT "failed_jobs_uuid_unique" UNIQUE (uuid)
);

ALTER TABLE "public"."failed_jobs"
  ENABLE ROW LEVEL SECURITY;

CREATE TABLE "public"."historique" (
  "id"             bigint                         NOT NULL DEFAULT nextval('public.historique_id_seq'::regclass),
  "objet"          character varying(20)          NOT NULL,
  "objet_id"       bigint                         NOT NULL,
  "ancien_statut"  character varying(30),
  "nouveau_statut" character varying(30)          NOT NULL,
  "etape"          smallint,
  "auteur_id"      bigint,
  "canal"          character varying(20)          NOT NULL DEFAULT 'systeme'::character varying,
  "commentaire"    text,
  "created_at"     timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT "historique_pkey" PRIMARY KEY (id)
);

ALTER TABLE "public"."historique"
  ENABLE ROW LEVEL SECURITY;

CREATE TABLE "public"."job_batches" (
  "id"             character varying(191) NOT NULL,
  "name"           character varying(191) NOT NULL,
  "total_jobs"     integer                NOT NULL,
  "pending_jobs"   integer                NOT NULL,
  "failed_jobs"    integer                NOT NULL,
  "failed_job_ids" text                   NOT NULL,
  "options"        text,
  "cancelled_at"   integer,
  "created_at"     integer                NOT NULL,
  "finished_at"    integer,
  CONSTRAINT "job_batches_pkey" PRIMARY KEY (id)
);

ALTER TABLE "public"."job_batches"
  ENABLE ROW LEVEL SECURITY;

CREATE TABLE "public"."jobs" (
  "id"           bigint                 NOT NULL DEFAULT nextval('public.jobs_id_seq'::regclass),
  "queue"        character varying(191) NOT NULL,
  "payload"      text                   NOT NULL,
  "attempts"     smallint               NOT NULL,
  "reserved_at"  integer,
  "available_at" integer                NOT NULL,
  "created_at"   integer                NOT NULL,
  CONSTRAINT "jobs_pkey" PRIMARY KEY (id)
);

ALTER TABLE "public"."jobs"
  ENABLE ROW LEVEL SECURITY;

CREATE TABLE "public"."jours_feries" (
  "jour"       date                           NOT NULL,
  "libelle"    character varying(100)         NOT NULL,
  "created_at" timestamp(0) without time zone,
  "updated_at" timestamp(0) without time zone,
  CONSTRAINT "jours_feries_pkey" PRIMARY KEY (jour)
);

ALTER TABLE "public"."jours_feries"
  ENABLE ROW LEVEL SECURITY;

CREATE TABLE "public"."mails_sortants" (
  "id"                bigint                         NOT NULL DEFAULT nextval('public.mails_sortants_id_seq'::regclass),
  "demande_id"        bigint,
  "type"              character varying(30)          NOT NULL,
  "destinataires"     jsonb                          NOT NULL,
  "copies"            jsonb,
  "statut"            character varying(20)          NOT NULL DEFAULT 'a_envoyer'::character varying,
  "tentatives"        smallint                       NOT NULL DEFAULT '0'::smallint,
  "prochain_essai_at" timestamp(0) without time zone,
  "derniere_erreur"   text,
  "fournisseur_id"    character varying(191),
  "envoye_at"         timestamp(0) without time zone,
  "created_at"        timestamp(0) without time zone,
  "updated_at"        timestamp(0) without time zone,
  "tache_id"          bigint,
  "donnees"           jsonb,
  CONSTRAINT "mails_sortants_cible_check" CHECK (((demande_id IS NOT NULL) OR (tache_id IS NOT NULL))),
  CONSTRAINT "mails_sortants_pkey" PRIMARY KEY (id)
);

ALTER TABLE "public"."mails_sortants"
  ENABLE ROW LEVEL SECURITY;

CREATE TABLE "public"."migrations" (
  "id"        integer                NOT NULL DEFAULT nextval('public.migrations_id_seq'::regclass),
  "migration" character varying(191) NOT NULL,
  "batch"     integer                NOT NULL,
  CONSTRAINT "migrations_pkey" PRIMARY KEY (id)
);

ALTER TABLE "public"."migrations"
  ENABLE ROW LEVEL SECURITY;

CREATE TABLE "public"."password_reset_tokens" (
  "email"      character varying(191)         NOT NULL,
  "token"      character varying(191)         NOT NULL,
  "created_at" timestamp(0) without time zone,
  CONSTRAINT "password_reset_tokens_pkey" PRIMARY KEY (email)
);

ALTER TABLE "public"."password_reset_tokens"
  ENABLE ROW LEVEL SECURITY;

CREATE TABLE "public"."pieces_jointes" (
  "id"           bigint                         NOT NULL DEFAULT nextval('public.pieces_jointes_id_seq'::regclass),
  "demande_id"   bigint                         NOT NULL,
  "nom_original" character varying(191)         NOT NULL,
  "chemin"       character varying(191)         NOT NULL,
  "mime_type"    character varying(150),
  "categorie"    character varying(20)          NOT NULL,
  "taille"       bigint                         NOT NULL,
  "created_at"   timestamp(0) without time zone,
  "updated_at"   timestamp(0) without time zone,
  CONSTRAINT "pieces_jointes_pkey" PRIMARY KEY (id)
);

ALTER TABLE "public"."pieces_jointes"
  ENABLE ROW LEVEL SECURITY;

CREATE TABLE "public"."projects" (
  "id"         uuid          NOT NULL DEFAULT gen_random_uuid(),
  "name"       text          NOT NULL,
  "revenue"    numeric(14,2) NOT NULL DEFAULT 0,
  "manager_id" uuid,
  CONSTRAINT "projects_pkey" PRIMARY KEY (id)
);

ALTER TABLE "public"."projects"
  ENABLE ROW LEVEL SECURITY;

CREATE TABLE "public"."projet_user" (
  "id"        bigint NOT NULL DEFAULT nextval('public.projet_user_id_seq'::regclass),
  "projet_id" bigint NOT NULL,
  "user_id"   bigint NOT NULL,
  CONSTRAINT "projet_user_pkey" PRIMARY KEY (id),
  CONSTRAINT "projet_user_projet_id_user_id_unique" UNIQUE (projet_id, user_id)
);

ALTER TABLE "public"."projet_user"
  ENABLE ROW LEVEL SECURITY;

CREATE TABLE "public"."projets" (
  "id"             bigint                         NOT NULL DEFAULT nextval('public.projets_id_seq'::regclass),
  "nom"            character varying(191)         NOT NULL,
  "description"    text,
  "statut"         character varying(30)          NOT NULL DEFAULT 'en_cours'::character varying,
  "chef_projet_id" bigint,
  "budget"         numeric(12,2),
  "date_debut"     date,
  "date_fin"       date,
  "created_at"     timestamp(0) without time zone,
  "updated_at"     timestamp(0) without time zone,
  CONSTRAINT "projets_pkey" PRIMARY KEY (id)
);

ALTER TABLE "public"."projets"
  ENABLE ROW LEVEL SECURITY;

CREATE TABLE "public"."request_types" (
  "code"         text    NOT NULL,
  "label"        text    NOT NULL,
  "default_days" integer NOT NULL DEFAULT 7,
  CONSTRAINT "request_types_pkey" PRIMARY KEY (code)
);

ALTER TABLE "public"."request_types"
  ENABLE ROW LEVEL SECURITY;

CREATE TABLE "public"."requests" (
  "id"          uuid                     NOT NULL DEFAULT gen_random_uuid(),
  "type"        text                     NOT NULL,
  "description" text,
  "manager_id"  uuid,
  "created_at"  timestamp with time zone NOT NULL DEFAULT now(),
  "updated_at"  timestamp with time zone NOT NULL DEFAULT now(),
  "deadline"    timestamp with time zone,
  CONSTRAINT "requests_pkey" PRIMARY KEY (id),
  "employee_id" uuid                     NOT NULL DEFAULT auth.uid()
);

ALTER TABLE "public"."requests"
  ENABLE ROW LEVEL SECURITY;

CREATE TABLE "public"."role_rules" (
  "id"          integer GENERATED ALWAYS AS IDENTITY NOT NULL,
  "match_type"  text    NOT NULL,
  "match_value" text    NOT NULL,
  CONSTRAINT "role_rules_match_type_check" CHECK ((match_type = ANY (ARRAY['email'::text, 'domain'::text]))),
  CONSTRAINT "role_rules_match_type_match_value_key" UNIQUE (match_type, match_value),
  CONSTRAINT "role_rules_pkey" PRIMARY KEY (id)
);

ALTER TABLE "public"."role_rules"
  ENABLE ROW LEVEL SECURITY;

CREATE TABLE "public"."roles" (
  "id"         bigint                         NOT NULL DEFAULT nextval('public.roles_id_seq'::regclass),
  "slug"       character varying(50)          NOT NULL,
  "libelle"    character varying(100)         NOT NULL,
  "created_at" timestamp(0) without time zone,
  "updated_at" timestamp(0) without time zone,
  CONSTRAINT "roles_pkey" PRIMARY KEY (id),
  CONSTRAINT "roles_slug_unique" UNIQUE (slug)
);

ALTER TABLE "public"."roles"
  ENABLE ROW LEVEL SECURITY;

CREATE TABLE "public"."sessions" (
  "id"            character varying(191) NOT NULL,
  "user_id"       bigint,
  "ip_address"    character varying(45),
  "user_agent"    text,
  "payload"       text                   NOT NULL,
  "last_activity" integer                NOT NULL,
  CONSTRAINT "sessions_pkey" PRIMARY KEY (id)
);

ALTER TABLE "public"."sessions"
  ENABLE ROW LEVEL SECURITY;

CREATE TABLE "public"."stats_quotidiennes" (
  "id"                 bigint                   NOT NULL DEFAULT nextval('public.stats_quotidiennes_id_seq'::regclass),
  "jour"               date                     NOT NULL,
  "manager_id"         bigint,
  "demandes_creees"    integer                  NOT NULL DEFAULT 0,
  "decisions"          integer                  NOT NULL DEFAULT 0,
  "en_attente"         integer                  NOT NULL DEFAULT 0,
  "en_retard"          integer                  NOT NULL DEFAULT 0,
  "temps_moyen_jours"  numeric(6,1),
  "dans_les_temps_pct" numeric(5,1),
  "detail"             jsonb                    NOT NULL DEFAULT '{}'::jsonb,
  "updated_at"         timestamp with time zone NOT NULL DEFAULT now(),
  CONSTRAINT "stats_quotidiennes_pkey" PRIMARY KEY (id)
);

ALTER TABLE "public"."stats_quotidiennes"
  ENABLE ROW LEVEL SECURITY;

REVOKE ALL ON TABLE "public"."stats_quotidiennes" FROM "anon";

CREATE TABLE "public"."taches" (
  "id"                     bigint                         NOT NULL DEFAULT nextval('public.taches_id_seq'::regclass),
  "titre"                  character varying(191)         NOT NULL,
  "description"            text,
  "projet_id"              bigint,
  "responsable_id"         bigint                         NOT NULL,
  "cree_par"               bigint,
  "statut"                 character varying(20)          NOT NULL DEFAULT 'a_faire'::character varying,
  "deadline"               date,
  "deadline_initiale"      date,
  "nb_reports"             smallint                       NOT NULL DEFAULT '0'::smallint,
  "deadline_fixee_par"     bigint,
  "deadline_fixee_at"      timestamp(0) without time zone,
  "jeton_deadline"         uuid,
  "termine_at"             timestamp(0) without time zone,
  "created_at"             timestamp(0) without time zone,
  "updated_at"             timestamp(0) without time zone,
  "commentaire_validation" text,
  "derniere_action_par"    bigint,
  "derniere_action_canal"  character varying(20),
  CONSTRAINT "taches_jeton_deadline_unique" UNIQUE (jeton_deadline),
  CONSTRAINT "taches_pkey" PRIMARY KEY (id)
);

ALTER TABLE "public"."taches"
  ENABLE ROW LEVEL SECURITY;

CREATE TABLE "public"."transitions" (
  "id"     bigint                NOT NULL DEFAULT nextval('public.transitions_id_seq'::regclass),
  "objet"  character varying(20) NOT NULL,
  "de"     character varying(30) NOT NULL,
  "vers"   character varying(30) NOT NULL,
  "acteur" character varying(30) NOT NULL,
  CONSTRAINT "transitions_objet_de_vers_unique" UNIQUE (objet, de, vers),
  CONSTRAINT "transitions_pkey" PRIMARY KEY (id)
);

ALTER TABLE "public"."transitions"
  ENABLE ROW LEVEL SECURITY;

CREATE TABLE "public"."types_demande" (
  "id"              bigint                         NOT NULL DEFAULT nextval('public.types_demande_id_seq'::regclass),
  "code"            character varying(50)          NOT NULL,
  "libelle"         character varying(100)         NOT NULL,
  "delai_relance"   smallint                       NOT NULL,
  "delai_escalade"  smallint                       NOT NULL,
  "created_at"      timestamp(0) without time zone,
  "updated_at"      timestamp(0) without time zone,
  "role_traitement" character varying(50),
  CONSTRAINT "types_demande_code_unique" UNIQUE (code),
  CONSTRAINT "types_demande_pkey" PRIMARY KEY (id)
);

ALTER TABLE "public"."types_demande"
  ENABLE ROW LEVEL SECURITY;

CREATE TABLE "public"."users" (
  "id"                bigint                         NOT NULL DEFAULT nextval('public.users_id_seq'::regclass),
  "nom"               character varying(100)         NOT NULL,
  "prenom"            character varying(100)         NOT NULL,
  "email"             character varying(191)         NOT NULL,
  "telephone"         character varying(20),
  "role_id"           bigint,
  "manager_id"        bigint,
  "actif"             boolean                        NOT NULL DEFAULT true,
  "email_verified_at" timestamp(0) without time zone,
  "password"          character varying(191)         NOT NULL,
  "remember_token"    character varying(100),
  "created_at"        timestamp(0) without time zone,
  "updated_at"        timestamp(0) without time zone,
  CONSTRAINT "users_email_unique" UNIQUE (email),
  CONSTRAINT "users_pkey" PRIMARY KEY (id)
);

ALTER TABLE "public"."users"
  ENABLE ROW LEVEL SECURITY;

ALTER SEQUENCE "public"."chiffres_affaires_id_seq" OWNED BY "public"."chiffres_affaires"."id";

ALTER SEQUENCE "public"."connexion_logs_id_seq" OWNED BY "public"."connexion_logs"."id";

ALTER SEQUENCE "public"."demandes_id_seq" OWNED BY "public"."demandes"."id";

ALTER SEQUENCE "public"."etapes_circuit_id_seq" OWNED BY "public"."etapes_circuit"."id";

ALTER SEQUENCE "public"."failed_jobs_id_seq" OWNED BY "public"."failed_jobs"."id";

ALTER SEQUENCE "public"."historique_id_seq" OWNED BY "public"."historique"."id";

ALTER SEQUENCE "public"."jobs_id_seq" OWNED BY "public"."jobs"."id";

ALTER SEQUENCE "public"."mails_sortants_id_seq" OWNED BY "public"."mails_sortants"."id";

ALTER SEQUENCE "public"."migrations_id_seq" OWNED BY "public"."migrations"."id";

ALTER SEQUENCE "public"."pieces_jointes_id_seq" OWNED BY "public"."pieces_jointes"."id";

ALTER SEQUENCE "public"."projet_user_id_seq" OWNED BY "public"."projet_user"."id";

ALTER SEQUENCE "public"."projets_id_seq" OWNED BY "public"."projets"."id";

ALTER SEQUENCE "public"."roles_id_seq" OWNED BY "public"."roles"."id";

ALTER SEQUENCE "public"."stats_quotidiennes_id_seq" OWNED BY "public"."stats_quotidiennes"."id";

ALTER SEQUENCE "public"."taches_id_seq" OWNED BY "public"."taches"."id";

ALTER SEQUENCE "public"."transitions_id_seq" OWNED BY "public"."transitions"."id";

ALTER SEQUENCE "public"."types_demande_id_seq" OWNED BY "public"."types_demande"."id";

ALTER SEQUENCE "public"."users_id_seq" OWNED BY "public"."users"."id";

CREATE TYPE "public"."app_role" AS ENUM (
  'admin',
  'direction',
  'manager',
  'rh',
  'dev',
  'commercial',
  'comptable',
  'employee'
);

ALTER TABLE "public"."employees"
  ADD COLUMN "role" public.app_role NOT NULL DEFAULT 'employee'::public.app_role;

ALTER TABLE "public"."role_rules"
  ADD COLUMN "role" public.app_role NOT NULL;

CREATE TYPE "public"."attachment_type" AS ENUM (
  'doc',
  'photo',
  'audio',
  'video'
);

ALTER TABLE "public"."attachments"
  ADD COLUMN "type" public.attachment_type NOT NULL;

CREATE TYPE "public"."request_status" AS ENUM (
  'pending',
  'validated',
  'refused',
  'expired'
);

ALTER TABLE "public"."requests"
  ADD COLUMN "status" public.request_status NOT NULL DEFAULT 'pending'::public.request_status;

CREATE OR REPLACE FUNCTION automation.ajouter_jours_ouvres (
  depart date,
  n      integer
)
  RETURNS date
  LANGUAGE plpgsql
  STABLE
  SET search_path TO ''
  AS $function$
DECLARE d date := depart; restant integer := n;
BEGIN
    WHILE restant > 0 LOOP
        d := d + 1;
        IF automation.est_jour_ouvre(d) THEN restant := restant - 1; END IF;
    END LOOP;
    RETURN d;
END;
$function$;

CREATE OR REPLACE FUNCTION automation.appeler_envoyer_mails()
  RETURNS bigint
  LANGUAGE sql
  SET search_path TO ''
  AS $function$
  select net.http_post(
    url := (select decrypted_secret from vault.decrypted_secrets where name = 'project_url') || '/functions/v1/envoyer-mails',
    headers := jsonb_build_object(
      'Content-Type', 'application/json',
      'x-cron-secret', (select decrypted_secret from vault.decrypted_secrets where name = 'cron_secret')),
    body := '{}'::jsonb,
    timeout_milliseconds := 60000);
$function$;

CREATE OR REPLACE FUNCTION automation.aujourdhui()
  RETURNS date
  LANGUAGE sql
  STABLE
  SET search_path TO ''
  AS $function$
    SELECT (now() AT TIME ZONE 'Europe/Paris')::date;
$function$;

CREATE OR REPLACE FUNCTION automation.calculer_dates_demande()
  RETURNS TRIGGER
  LANGUAGE plpgsql
  SET search_path TO ''
  AS $function$
DECLARE base date; d_relance integer; d_escalade integer; normale date;
BEGIN
    -- Assignation automatique : le manager de l'employé, à défaut un membre de la direction
    NEW.manager_id := coalesce(NEW.manager_id,
        (SELECT u.manager_id FROM public.users u WHERE u.id = NEW.demandeur_id),
        (SELECT u.id FROM public.users u JOIN public.roles r ON r.id = u.role_id
          WHERE r.slug = 'direction' AND u.actif ORDER BY u.id LIMIT 1));
    NEW.etape := coalesce(NEW.etape, 1);
    IF NEW.date_debut IS NOT NULL AND NEW.date_fin IS NOT NULL THEN
        NEW.nb_jours_ouvres := automation.jours_ouvres_periode(NEW.date_debut, NEW.date_fin);
    END IF;

    SELECT t.delai_relance, t.delai_escalade INTO d_relance, d_escalade
      FROM public.types_demande t WHERE t.code = NEW.type;
    d_relance  := coalesce(d_relance, 2);
    d_escalade := coalesce(d_escalade, 5);
    base := automation.date_paris(coalesce(NEW.envoyee_at, NEW.created_at, now()::timestamp));

    NEW.relance_le  := coalesce(NEW.relance_le,  automation.ajouter_jours_ouvres(base, d_relance));
    normale         := automation.ajouter_jours_ouvres(base, d_escalade);
    NEW.echeance_le := coalesce(NEW.echeance_le, normale);

    IF NEW.date_souhaitee IS NOT NULL AND NEW.date_souhaitee <= normale THEN
        NEW.urgente     := true;
        NEW.echeance_le := greatest(base, automation.jour_ouvre_precedent(NEW.date_souhaitee));
        NEW.relance_le  := least(NEW.relance_le, NEW.echeance_le);
    END IF;

    NEW.deadline := coalesce(NEW.deadline, NEW.date_souhaitee, automation.ajouter_jours_ouvres(NEW.echeance_le, 5));
    RETURN NEW;
END;
$function$;

CREATE OR REPLACE FUNCTION automation.calculer_stats (
  p_manager bigint,
  p_debut   date,
  p_fin     date
)
  RETURNS jsonb
  LANGUAGE sql
  STABLE
  SET search_path TO ''
  AS $function$
WITH d AS (
    SELECT x.*,
           automation.date_paris(x.created_at) AS jour_creation,
           automation.date_paris(coalesce(x.envoyee_at, x.created_at)) AS jour_envoi,
           automation.date_paris(x.decision_at) AS jour_decision
      FROM public.demandes x
     WHERE p_manager IS NULL OR x.manager_id = p_manager
),
creees AS (SELECT * FROM d WHERE jour_creation BETWEEN p_debut AND p_fin),
decidees AS (
    SELECT d.*, automation.jours_ouvres_periode(d.jour_envoi + 1, d.jour_decision) AS duree
      FROM d WHERE d.decision_at IS NOT NULL AND d.jour_decision BETWEEN p_debut AND p_fin
),
-- Temps passé dans chaque état, d'après l'historique : attribué au service qui devait agir
etats AS (
    SELECT h.*, d.type, lead(h.created_at) OVER (PARTITION BY h.objet_id ORDER BY h.id) AS fin_etat
      FROM public.historique h JOIN d ON d.id = h.objet_id
     WHERE h.objet = 'demande'
),
passages AS (
    SELECT CASE
               WHEN e.nouveau_statut = 'en_attente' THEN coalesce(ec.valideur, 'manager')
               WHEN e.nouveau_statut = 'a_completer' THEN 'employe'
               WHEN e.nouveau_statut IN ('validee', 'en_traitement') THEN td.role_traitement
           END AS service,
           extract(epoch FROM (e.fin_etat - e.created_at)) / 86400.0 AS jours
      FROM etats e
      LEFT JOIN public.etapes_circuit ec ON ec.type_code = e.type AND ec.ordre = e.etape
      LEFT JOIN public.types_demande td ON td.code = e.type
     WHERE e.fin_etat IS NOT NULL AND automation.date_paris(e.fin_etat) BETWEEN p_debut AND p_fin
),
services AS (
    SELECT p.service, count(*) AS passages, round(avg(p.jours)::numeric, 1) AS jours_moyens
      FROM passages p WHERE p.service IS NOT NULL GROUP BY p.service
),
t AS (
    SELECT tk.* FROM public.taches tk LEFT JOIN public.projets pr ON pr.id = tk.projet_id
     WHERE p_manager IS NULL OR pr.chef_projet_id = p_manager
)
SELECT jsonb_build_object(
    'perimetre', CASE WHEN p_manager IS NULL THEN 'entreprise' ELSE 'equipe' END,
    'periode', jsonb_build_object('debut', p_debut, 'fin', p_fin),
    'volume', jsonb_build_object(
        'creees', (SELECT count(*) FROM creees),
        'par_type', (SELECT coalesce(jsonb_object_agg(type, n), '{}'::jsonb) FROM (SELECT type, count(*) AS n FROM creees GROUP BY type) v),
        'par_statut', (SELECT coalesce(jsonb_object_agg(statut, n), '{}'::jsonb) FROM (SELECT statut, count(*) AS n FROM creees GROUP BY statut) v)),
    'par_jour', (SELECT coalesce(jsonb_agg(jsonb_build_object(
                        'jour', g.j,
                        'creees', (SELECT count(*) FROM creees c WHERE c.jour_creation = g.j),
                        'decisions', (SELECT count(*) FROM decidees x WHERE x.jour_decision = g.j)) ORDER BY g.j), '[]'::jsonb)
                   FROM (SELECT s::date AS j FROM generate_series(p_debut::timestamp, p_fin::timestamp, interval '1 day') AS s) g),
    'decisions', jsonb_build_object(
        'total', (SELECT count(*) FROM decidees),
        'validees', (SELECT count(*) FROM decidees WHERE statut <> 'refusee'),
        'refusees', (SELECT count(*) FROM decidees WHERE statut = 'refusee'),
        'temps_moyen_jours_ouvres', (SELECT round(avg(duree), 1) FROM decidees),
        'temps_moyen_par_type', (SELECT coalesce(jsonb_object_agg(type, m), '{}'::jsonb)
                                   FROM (SELECT type, round(avg(duree), 1) AS m FROM decidees GROUP BY type) v),
        'dans_les_temps_pct', (SELECT round(100.0 * avg(CASE WHEN jour_decision <= echeance_le THEN 1 ELSE 0 END), 1) FROM decidees)),
    'services', (SELECT coalesce(jsonb_agg(jsonb_build_object(
                        'service', s.service,
                        'libelle', CASE s.service WHEN 'manager' THEN 'Managers' WHEN 'employe' THEN 'Employés (compléments)'
                                                  ELSE coalesce(r.libelle, s.service) END,
                        'passages', s.passages, 'jours_moyens', s.jours_moyens) ORDER BY s.passages DESC), '[]'::jsonb)
                   FROM services s LEFT JOIN public.roles r ON r.slug = s.service),
    'en_cours', jsonb_build_object(
        'en_attente', (SELECT count(*) FROM d WHERE statut = 'en_attente'),
        'en_retard', (SELECT count(*) FROM d WHERE statut = 'en_attente' AND echeance_le < automation.aujourdhui()),
        'a_completer', (SELECT count(*) FROM d WHERE statut = 'a_completer'),
        'a_traiter', (SELECT count(*) FROM d WHERE statut = 'validee'
                        AND EXISTS (SELECT 1 FROM public.types_demande y WHERE y.code = d.type AND y.role_traitement IS NOT NULL)),
        'en_traitement', (SELECT count(*) FROM d WHERE statut = 'en_traitement')),
    'taches', (SELECT jsonb_build_object(
                    'a_faire', count(*) FILTER (WHERE statut = 'a_faire'),
                    'en_cours', count(*) FILTER (WHERE statut = 'en_cours'),
                    'a_valider', count(*) FILTER (WHERE statut = 'a_valider'),
                    'expirees', count(*) FILTER (WHERE statut = 'expiree'),
                    'terminees_periode', count(*) FILTER (WHERE statut = 'terminee'
                        AND automation.date_paris(termine_at) BETWEEN p_debut AND p_fin))
                 FROM t),
    'calcule_le', now()
);
$function$;

CREATE OR REPLACE FUNCTION automation.controler_tache()
  RETURNS TRIGGER
  LANGUAGE plpgsql
  SET search_path TO ''
  AS $function$
DECLARE chef bigint; mini date;
BEGIN
    IF NEW.projet_id IS NOT NULL THEN
        SELECT p.chef_projet_id INTO chef FROM public.projets p WHERE p.id = NEW.projet_id;
        IF NEW.responsable_id IS DISTINCT FROM chef AND NOT EXISTS (
            SELECT 1 FROM public.projet_user pu WHERE pu.projet_id = NEW.projet_id AND pu.user_id = NEW.responsable_id) THEN
            RAISE EXCEPTION 'Le responsable doit être membre du projet';
        END IF;
    END IF;

    IF TG_OP = 'INSERT' THEN
        IF NEW.projet_id IS NULL THEN
            -- Tâche hors projet : deadline libre, choisie par l'employé
            IF NEW.deadline IS NULL THEN RAISE EXCEPTION 'Une tâche hors projet doit avoir une deadline'; END IF;
            IF NEW.deadline < automation.aujourdhui() THEN RAISE EXCEPTION 'La deadline doit être aujourd''hui ou plus tard'; END IF;
            NEW.deadline_fixee_par := coalesce(NEW.deadline_fixee_par, NEW.cree_par);
            NEW.deadline_fixee_at  := now();
        ELSIF NEW.deadline IS NOT NULL THEN
            -- Tâche assignée par le chef de projet : au moins création + 5 jours ouvrés
            mini := automation.ajouter_jours_ouvres(automation.aujourdhui(), 5);
            IF NEW.deadline < mini THEN
                RAISE EXCEPTION 'Deadline de projet : au plus tôt le % (création + 5 jours ouvrés)', to_char(mini, 'DD/MM/YYYY');
            END IF;
            NEW.deadline_fixee_par := coalesce(NEW.deadline_fixee_par, chef);
            NEW.deadline_fixee_at  := now();
        ELSE
            -- Tâche créée par un membre : le chef de projet fixera la deadline (lien à usage unique)
            NEW.jeton_deadline := coalesce(NEW.jeton_deadline, gen_random_uuid());
        END IF;
        NEW.deadline_initiale := NEW.deadline;

    ELSIF NEW.deadline IS DISTINCT FROM OLD.deadline THEN
        IF NEW.deadline IS NULL THEN RAISE EXCEPTION 'La deadline ne peut pas être retirée'; END IF;

        IF OLD.deadline IS NULL THEN
            -- Première deadline d'une tâche de projet : création + 5 jours ouvrés minimum
            IF NEW.projet_id IS NOT NULL THEN
                mini := automation.ajouter_jours_ouvres(automation.date_paris(OLD.created_at), 5);
                IF NEW.deadline < mini THEN
                    RAISE EXCEPTION 'Deadline de projet : au plus tôt le % (création + 5 jours ouvrés)', to_char(mini, 'DD/MM/YYYY');
                END IF;
            END IF;
            NEW.deadline_initiale := NEW.deadline;
            NEW.deadline_fixee_at := now();
            NEW.jeton_deadline    := NULL;
        ELSE
            -- Modification : projet = report d'au moins 1 jour ; hors projet = libre (future)
            IF NEW.projet_id IS NOT NULL AND NEW.deadline < OLD.deadline + 1 THEN
                RAISE EXCEPTION 'Une deadline de projet ne peut être que repoussée (au moins 1 jour)';
            END IF;
            IF NEW.deadline < automation.aujourdhui() THEN
                RAISE EXCEPTION 'La deadline doit être aujourd''hui ou plus tard';
            END IF;
            IF NEW.deadline > OLD.deadline THEN NEW.nb_reports := OLD.nb_reports + 1; END IF;
            -- Une tâche expirée dont la deadline est repoussée redevient « à faire »
            IF OLD.statut = 'expiree' AND NEW.statut = 'expiree' THEN NEW.statut := 'a_faire'; END IF;
        END IF;
    END IF;

    IF NEW.statut = 'terminee' AND (TG_OP = 'INSERT' OR OLD.statut IS DISTINCT FROM 'terminee') THEN
        NEW.termine_at := now();
    END IF;
    RETURN NEW;
END;
$function$;

CREATE OR REPLACE FUNCTION automation.controler_transition()
  RETURNS TRIGGER
  LANGUAGE plpgsql
  SET search_path TO ''
  AS $function$
BEGIN
    IF NEW.statut IS DISTINCT FROM OLD.statut AND NOT EXISTS (
        SELECT 1 FROM public.transitions t
         WHERE t.objet = TG_ARGV[0] AND t.de = OLD.statut AND t.vers = NEW.statut) THEN
        RAISE EXCEPTION 'Transition interdite : % → %', OLD.statut, NEW.statut;
    END IF;
    RETURN NEW;
END;
$function$;

CREATE OR REPLACE FUNCTION automation.date_paris (
  ts timestamp without time zone
)
  RETURNS date
  LANGUAGE sql
  STABLE
  SET search_path TO ''
  AS $function$
    SELECT ((ts AT TIME ZONE 'UTC') AT TIME ZONE 'Europe/Paris')::date;
$function$;

CREATE OR REPLACE FUNCTION automation.emails_role (
  p_role text
)
  RETURNS jsonb
  LANGUAGE sql
  STABLE
  SET search_path TO ''
  AS $function$
    SELECT coalesce(jsonb_agg(u.email ORDER BY u.id), '[]'::jsonb)
      FROM public.users u JOIN public.roles r ON r.id = u.role_id
     WHERE r.slug = p_role AND u.actif;
$function$;

CREATE OR REPLACE FUNCTION automation.est_jour_ouvre (
  d date
)
  RETURNS boolean
  LANGUAGE sql
  STABLE
  SET search_path TO ''
  AS $function$
    SELECT extract(isodow FROM d) < 6
       AND NOT EXISTS (SELECT 1 FROM public.jours_feries f WHERE f.jour = d);
$function$;

CREATE OR REPLACE FUNCTION automation.expirer()
  RETURNS integer
  LANGUAGE plpgsql
  SET search_path TO ''
  AS $function$
DECLARE total integer := 0; n integer;
BEGIN
    UPDATE public.demandes
       SET statut = 'expiree', jeton_decision = NULL, derniere_action_par = NULL, derniere_action_canal = 'cron', updated_at = now()
     WHERE statut IN ('en_attente', 'a_completer') AND deadline < automation.aujourdhui();
    GET DIAGNOSTICS n = ROW_COUNT; total := total + n;

    UPDATE public.taches
       SET statut = 'expiree', derniere_action_par = NULL, derniere_action_canal = 'cron', updated_at = now()
     WHERE statut IN ('a_faire', 'en_cours') AND deadline < automation.aujourdhui();
    GET DIAGNOSTICS n = ROW_COUNT; total := total + n;
    RETURN total;
END;
$function$;

CREATE OR REPLACE FUNCTION automation.historiser()
  RETURNS TRIGGER
  LANGUAGE plpgsql
  SET search_path TO ''
  AS $function$
DECLARE
    v_etape smallint;
    v_commentaire text;
BEGIN
    IF TG_ARGV[0] = 'demande' THEN
        v_etape := NEW.etape;
        v_commentaire := CASE WHEN NEW.statut IN ('refusee', 'a_completer') THEN NEW.commentaire_decision END;
        IF TG_OP = 'UPDATE' AND NEW.statut IS NOT DISTINCT FROM OLD.statut AND NEW.etape IS NOT DISTINCT FROM OLD.etape THEN
            RETURN NEW;
        END IF;
    ELSE
        v_commentaire := CASE WHEN TG_OP = 'UPDATE' AND OLD.statut = 'a_valider' AND NEW.statut = 'en_cours'
                              THEN NEW.commentaire_validation END;
        IF TG_OP = 'UPDATE' AND NEW.statut IS NOT DISTINCT FROM OLD.statut THEN
            RETURN NEW;
        END IF;
    END IF;

    INSERT INTO public.historique (objet, objet_id, ancien_statut, nouveau_statut, etape, auteur_id, canal, commentaire, created_at)
    VALUES (TG_ARGV[0], NEW.id, CASE WHEN TG_OP = 'UPDATE' THEN OLD.statut END, NEW.statut, v_etape,
            NEW.derniere_action_par, coalesce(NEW.derniere_action_canal, 'systeme'), v_commentaire, now());
    RETURN NEW;
END;
$function$;

CREATE OR REPLACE FUNCTION automation.jour_ouvre_precedent (
  depart date
)
  RETURNS date
  LANGUAGE plpgsql
  STABLE
  SET search_path TO ''
  AS $function$
DECLARE d date := depart - 1;
BEGIN
    WHILE NOT automation.est_jour_ouvre(d) LOOP d := d - 1; END LOOP;
    RETURN d;
END;
$function$;

CREATE OR REPLACE FUNCTION automation.jours_ouvres_depuis (
  debut timestamp with time zone
)
  RETURNS integer
  LANGUAGE sql
  STABLE
  SET search_path TO ''
  AS $function$
    SELECT count(*)::integer
    FROM generate_series((debut AT TIME ZONE 'Europe/Paris')::date + 1, automation.aujourdhui(), interval '1 day') AS jour
    WHERE automation.est_jour_ouvre(jour::date);
$function$;

CREATE OR REPLACE FUNCTION automation.jours_ouvres_periode (
  debut date,
  fin   date
)
  RETURNS integer
  LANGUAGE sql
  STABLE
  SET search_path TO ''
  AS $function$
    SELECT count(*)::integer FROM generate_series(debut, fin, interval '1 day') AS j
     WHERE automation.est_jour_ouvre(j::date);
$function$;

CREATE OR REPLACE FUNCTION automation.mail_decision()
  RETURNS TRIGGER
  LANGUAGE plpgsql
  SET search_path TO ''
  AS $function$
DECLARE
    e_email text;
    role_traitement text;
    libelle_etape text;
BEGIN
    SELECT u.email INTO e_email FROM public.users u WHERE u.id = NEW.demandeur_id;

    -- Passage à l'étape suivante du circuit (statut toujours « en attente »)
    IF NEW.statut = 'en_attente' AND OLD.statut = 'en_attente' AND NEW.etape IS DISTINCT FROM OLD.etape THEN
        SELECT ec.libelle INTO libelle_etape FROM public.etapes_circuit ec WHERE ec.type_code = NEW.type AND ec.ordre = NEW.etape;
        INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, donnees, created_at, updated_at)
        VALUES (NEW.id, 'etape_suivante', automation.valideurs_etape(NEW.id), '[]'::jsonb,
                jsonb_build_object('etape', NEW.etape, 'libelle', libelle_etape), now(), now());
        RETURN NEW;
    END IF;

    IF NEW.statut IS NOT DISTINCT FROM OLD.statut THEN
        RETURN NEW;
    END IF;

    IF OLD.statut = 'en_attente' AND NEW.statut IN ('validee', 'refusee') THEN
        INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, donnees, created_at, updated_at)
        VALUES (NEW.id, 'decision', jsonb_build_array(e_email), '[]'::jsonb,
                jsonb_build_object('commentaire', CASE WHEN NEW.statut = 'refusee' THEN NEW.commentaire_decision END), now(), now());
        IF NEW.statut = 'validee' THEN
            SELECT t.role_traitement INTO role_traitement FROM public.types_demande t WHERE t.code = NEW.type;
            IF role_traitement IS NOT NULL THEN
                INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, donnees, created_at, updated_at)
                VALUES (NEW.id, 'a_traiter', automation.emails_role(role_traitement), '[]'::jsonb,
                        jsonb_build_object('service', role_traitement), now(), now());
            END IF;
        END IF;

    ELSIF NEW.statut = 'a_completer' THEN
        INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, donnees, created_at, updated_at)
        VALUES (NEW.id, 'a_completer', jsonb_build_array(e_email), '[]'::jsonb,
                jsonb_build_object('commentaire', NEW.commentaire_decision, 'etape', NEW.etape), now(), now());

    ELSIF OLD.statut = 'a_completer' AND NEW.statut = 'en_attente' THEN
        INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, donnees, created_at, updated_at)
        VALUES (NEW.id, 'complement_recu', automation.valideurs_etape(NEW.id), '[]'::jsonb,
                jsonb_build_object('etape', NEW.etape), now(), now());

    ELSIF NEW.statut = 'annulee' THEN
        INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, created_at, updated_at)
        VALUES (NEW.id, 'annulation', automation.valideurs_etape(NEW.id), '[]'::jsonb, now(), now());

    ELSIF NEW.statut = 'expiree' THEN
        INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, created_at, updated_at)
        SELECT NEW.id, 'expiration', jsonb_build_array(e_email),
               (SELECT coalesce(jsonb_agg(DISTINCT x.email), '[]'::jsonb) FROM (
                    SELECT m.email FROM public.users m WHERE m.id = NEW.manager_id
                    UNION SELECT jsonb_array_elements_text(automation.emails_role('rh'))) AS x(email)),
               now(), now()
        ON CONFLICT DO NOTHING;

    ELSIF OLD.statut = 'en_traitement' AND NEW.statut = 'terminee' THEN
        INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, created_at, updated_at)
        VALUES (NEW.id, 'traitement_termine', jsonb_build_array(e_email), '[]'::jsonb, now(), now());
    END IF;
    RETURN NEW;
END;
$function$;

CREATE OR REPLACE FUNCTION automation.mail_nouvelle_demande()
  RETURNS TRIGGER
  LANGUAGE plpgsql
  SET search_path TO ''
  AS $function$
BEGIN
    INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, created_at, updated_at)
    SELECT NEW.id, 'nouvelle_demande', jsonb_build_array(m.email), '[]'::jsonb, now(), now()
    FROM public.users m
    WHERE m.id = NEW.manager_id
    ON CONFLICT DO NOTHING;
    RETURN NEW;
END;
$function$;

CREATE OR REPLACE FUNCTION automation.mail_tache()
  RETURNS TRIGGER
  LANGUAGE plpgsql
  SET search_path TO ''
  AS $function$
DECLARE chef_email text; resp_email text; sup_email text;
BEGIN
    SELECT u.email INTO resp_email FROM public.users u WHERE u.id = NEW.responsable_id;
    IF NEW.projet_id IS NOT NULL THEN
        SELECT u.email INTO chef_email
          FROM public.projets p JOIN public.users u ON u.id = p.chef_projet_id WHERE p.id = NEW.projet_id;
    END IF;

    IF TG_OP = 'INSERT' THEN
        IF NEW.projet_id IS NOT NULL AND NEW.deadline IS NULL AND chef_email IS NOT NULL THEN
            INSERT INTO public.mails_sortants (tache_id, type, destinataires, copies, created_at, updated_at)
            VALUES (NEW.id, 'tache_deadline_a_fixer', jsonb_build_array(chef_email), '[]'::jsonb, now(), now())
            ON CONFLICT DO NOTHING;
        ELSIF NEW.projet_id IS NOT NULL AND NEW.deadline IS NOT NULL AND NEW.responsable_id IS DISTINCT FROM NEW.cree_par THEN
            INSERT INTO public.mails_sortants (tache_id, type, destinataires, copies, donnees, created_at, updated_at)
            VALUES (NEW.id, 'tache_deadline_fixee', jsonb_build_array(resp_email), '[]'::jsonb,
                    jsonb_build_object('deadline', NEW.deadline, 'assignation', true), now(), now());
        END IF;
        RETURN NEW;
    END IF;

    IF OLD.deadline IS NULL AND NEW.deadline IS NOT NULL THEN
        INSERT INTO public.mails_sortants (tache_id, type, destinataires, copies, donnees, created_at, updated_at)
        VALUES (NEW.id, 'tache_deadline_fixee', jsonb_build_array(resp_email), '[]'::jsonb,
                jsonb_build_object('deadline', NEW.deadline), now(), now());
    ELSIF OLD.deadline IS NOT NULL AND NEW.deadline IS DISTINCT FROM OLD.deadline THEN
        IF NEW.projet_id IS NULL THEN
            SELECT m.email INTO sup_email
              FROM public.users e JOIN public.users m ON m.id = e.manager_id WHERE e.id = NEW.responsable_id;
            IF sup_email IS NOT NULL THEN
                INSERT INTO public.mails_sortants (tache_id, type, destinataires, copies, donnees, created_at, updated_at)
                VALUES (NEW.id, 'tache_deadline_modifiee', jsonb_build_array(sup_email), '[]'::jsonb,
                        jsonb_build_object('ancienne', OLD.deadline, 'nouvelle', NEW.deadline), now(), now());
            END IF;
        ELSE
            INSERT INTO public.mails_sortants (tache_id, type, destinataires, copies, donnees, created_at, updated_at)
            VALUES (NEW.id, 'tache_deadline_fixee', jsonb_build_array(resp_email), '[]'::jsonb,
                    jsonb_build_object('deadline', NEW.deadline, 'ancienne', OLD.deadline), now(), now());
        END IF;
    END IF;

    IF NEW.statut IS DISTINCT FROM OLD.statut THEN
        IF NEW.statut = 'expiree' THEN
            INSERT INTO public.mails_sortants (tache_id, type, destinataires, copies, donnees, created_at, updated_at)
            VALUES (NEW.id, 'tache_expiration', jsonb_build_array(resp_email),
                    CASE WHEN chef_email IS NOT NULL AND chef_email <> resp_email THEN jsonb_build_array(chef_email) ELSE '[]'::jsonb END,
                    jsonb_build_object('deadline', NEW.deadline), now(), now());
        ELSIF NEW.statut = 'a_valider' AND chef_email IS NOT NULL THEN
            INSERT INTO public.mails_sortants (tache_id, type, destinataires, copies, created_at, updated_at)
            VALUES (NEW.id, 'tache_a_valider', jsonb_build_array(chef_email), '[]'::jsonb, now(), now());
        ELSIF OLD.statut = 'a_valider' AND NEW.statut = 'en_cours' THEN
            INSERT INTO public.mails_sortants (tache_id, type, destinataires, copies, donnees, created_at, updated_at)
            VALUES (NEW.id, 'tache_renvoyee', jsonb_build_array(resp_email), '[]'::jsonb,
                    jsonb_build_object('commentaire', NEW.commentaire_validation), now(), now());
        ELSIF OLD.statut = 'a_valider' AND NEW.statut = 'terminee' THEN
            INSERT INTO public.mails_sortants (tache_id, type, destinataires, copies, created_at, updated_at)
            VALUES (NEW.id, 'tache_validee', jsonb_build_array(resp_email), '[]'::jsonb, now(), now());
        END IF;
    END IF;
    RETURN NEW;
END;
$function$;

CREATE OR REPLACE FUNCTION automation.paques (
  annee integer
)
  RETURNS date
  LANGUAGE plpgsql
  IMMUTABLE
  SET search_path TO ''
  AS $function$
DECLARE a int; b int; c int; d int; e int; f int; g int; h int; i int; k int; l int; m int;
BEGIN
    a := annee % 19; b := annee / 100; c := annee % 100; d := b / 4; e := b % 4;
    f := (b + 8) / 25; g := (b - f + 1) / 3; h := (19 * a + b - d - g + 15) % 30;
    i := c / 4; k := c % 4; l := (32 + 2 * e + 2 * i - h - k) % 7; m := (a + 11 * h + 22 * l) / 451;
    RETURN make_date(annee, (h + l - 7 * m + 114) / 31, ((h + l - 7 * m + 114) % 31) + 1);
END;
$function$;

CREATE OR REPLACE FUNCTION automation.planifier_rappels()
  RETURNS integer
  LANGUAGE plpgsql
  SET search_path TO ''
  AS $function$
DECLARE j date := automation.aujourdhui(); total integer := 0; n integer;
BEGIN
    -- Demandes : relance aux valideurs de l'étape en cours
    INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, donnees, created_at, updated_at)
    SELECT d.id, 'relance', automation.valideurs_etape(d.id), '[]'::jsonb, jsonb_build_object('etape', d.etape), now(), now()
      FROM public.demandes d
     WHERE d.statut = 'en_attente' AND d.relance_le <= j
    ON CONFLICT DO NOTHING;
    GET DIAGNOSTICS n = ROW_COUNT; total := total + n;

    -- Demandes : rappel la veille (ouvrée) de l'échéance de l'étape
    INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, donnees, created_at, updated_at)
    SELECT d.id, 'rappel_echeance', automation.valideurs_etape(d.id), '[]'::jsonb, jsonb_build_object('etape', d.etape), now(), now()
      FROM public.demandes d
     WHERE d.statut = 'en_attente' AND d.echeance_le > j
       AND automation.jour_ouvre_precedent(d.echeance_le) <= j AND d.relance_le < j
    ON CONFLICT DO NOTHING;
    GET DIAGNOSTICS n = ROW_COUNT; total := total + n;

    -- Demandes : escalade à l'échéance de l'étape
    --   étape « manager » : RH + N+2, manager en copie ; étape « service » : direction, service en copie
    INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, donnees, created_at, updated_at)
    SELECT d.id, 'escalade',
           CASE WHEN coalesce(ec.valideur, 'manager') = 'manager' THEN
                (SELECT coalesce(jsonb_agg(DISTINCT x.email), '[]'::jsonb) FROM (
                     SELECT jsonb_array_elements_text(automation.emails_role('rh'))
                     UNION SELECT n2.email FROM public.users m JOIN public.users n2 ON n2.id = m.manager_id WHERE m.id = d.manager_id
                 ) AS x(email))
                ELSE automation.emails_role('direction') END,
           automation.valideurs_etape(d.id),
           jsonb_build_object('etape', d.etape), now(), now()
      FROM public.demandes d
      LEFT JOIN public.etapes_circuit ec ON ec.type_code = d.type AND ec.ordre = d.etape
     WHERE d.statut = 'en_attente' AND d.echeance_le <= j
    ON CONFLICT DO NOTHING;
    GET DIAGNOSTICS n = ROW_COUNT; total := total + n;

    -- Tâches de projet sans deadline : relance du chef de projet (2 j ouvrés)
    INSERT INTO public.mails_sortants (tache_id, type, destinataires, copies, created_at, updated_at)
    SELECT t.id, 'tache_relance_deadline', jsonb_build_array(c.email), '[]'::jsonb, now(), now()
      FROM public.taches t JOIN public.projets p ON p.id = t.projet_id JOIN public.users c ON c.id = p.chef_projet_id
     WHERE t.deadline IS NULL AND t.statut IN ('a_faire', 'en_cours')
       AND automation.ajouter_jours_ouvres(automation.date_paris(t.created_at), 2) <= j
    ON CONFLICT DO NOTHING;
    GET DIAGNOSTICS n = ROW_COUNT; total := total + n;

    -- ... puis escalade au supérieur du chef de projet (5 j ouvrés), RH à défaut ; chef en copie
    INSERT INTO public.mails_sortants (tache_id, type, destinataires, copies, created_at, updated_at)
    SELECT t.id, 'tache_escalade_deadline',
           coalesce((SELECT jsonb_build_array(s.email) FROM public.users s WHERE s.id = c.manager_id), automation.emails_role('rh')),
           jsonb_build_array(c.email), now(), now()
      FROM public.taches t JOIN public.projets p ON p.id = t.projet_id JOIN public.users c ON c.id = p.chef_projet_id
     WHERE t.deadline IS NULL AND t.statut IN ('a_faire', 'en_cours')
       AND automation.ajouter_jours_ouvres(automation.date_paris(t.created_at), 5) <= j
    ON CONFLICT DO NOTHING;
    GET DIAGNOSTICS n = ROW_COUNT; total := total + n;

    -- Tâches : rappel la veille (ouvrée) de la deadline au responsable, chef de projet en copie
    INSERT INTO public.mails_sortants (tache_id, type, destinataires, copies, donnees, created_at, updated_at)
    SELECT t.id, 'tache_rappel', jsonb_build_array(r.email),
           CASE WHEN c.email IS NOT NULL AND c.email <> r.email THEN jsonb_build_array(c.email) ELSE '[]'::jsonb END,
           jsonb_build_object('deadline', t.deadline), now(), now()
      FROM public.taches t
      JOIN public.users r ON r.id = t.responsable_id
      LEFT JOIN public.projets p ON p.id = t.projet_id
      LEFT JOIN public.users c ON c.id = p.chef_projet_id
     WHERE t.statut IN ('a_faire', 'en_cours') AND t.deadline IS NOT NULL
       AND t.deadline >= j AND automation.jour_ouvre_precedent(t.deadline) <= j
    ON CONFLICT DO NOTHING;
    GET DIAGNOSTICS n = ROW_COUNT; total := total + n;

    RETURN total;
END;
$function$;

CREATE OR REPLACE FUNCTION automation.planifier_relances (
  x integer DEFAULT 2,
  y integer DEFAULT 5
)
  RETURNS integer
  LANGUAGE plpgsql
  SET search_path TO ''
  AS $function$
DECLARE
    total integer := 0;
    lignes integer;
BEGIN
    INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, created_at, updated_at)
    SELECT d.id, 'relance', jsonb_build_array(m.email), '[]'::jsonb, now(), now()
    FROM public.demandes d
    JOIN public.users m ON m.id = d.manager_id
    WHERE d.statut = 'en_attente'
      AND automation.jours_ouvres_depuis(coalesce(d.envoyee_at, d.created_at)) >= x
    ON CONFLICT DO NOTHING;
    GET DIAGNOSTICS lignes = ROW_COUNT;
    total := total + lignes;

    INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, created_at, updated_at)
    SELECT d.id, 'escalade',
        (SELECT coalesce(jsonb_agg(DISTINCT dest.email), '[]'::jsonb)
           FROM (SELECT u.email FROM public.users u
                   JOIN public.roles r ON r.id = u.role_id
                  WHERE r.slug = 'rh' AND u.actif
                 UNION
                 SELECT n2.email FROM public.users n2 WHERE n2.id = m.manager_id) AS dest),
        jsonb_build_array(m.email), now(), now()
    FROM public.demandes d
    JOIN public.users m ON m.id = d.manager_id
    WHERE d.statut = 'en_attente'
      AND automation.jours_ouvres_depuis(coalesce(d.envoyee_at, d.created_at)) >= y
    ON CONFLICT DO NOTHING;
    GET DIAGNOSTICS lignes = ROW_COUNT;
    total := total + lignes;

    RETURN total;
END;
$function$;

CREATE OR REPLACE FUNCTION automation.rafraichir_stats (
  p_jour date DEFAULT NULL::date
)
  RETURNS void
  LANGUAGE plpgsql
  SECURITY DEFINER
  SET search_path TO ''
  AS $function$
DECLARE v_jour date := coalesce(p_jour, automation.aujourdhui());
BEGIN
    INSERT INTO public.stats_quotidiennes AS s
           (jour, manager_id, demandes_creees, decisions, en_attente, en_retard, temps_moyen_jours, dans_les_temps_pct, detail, updated_at)
    SELECT v_jour, p.manager_id,
           coalesce((c.detail -> 'par_jour' -> -1 ->> 'creees')::integer, 0),
           coalesce((c.detail -> 'par_jour' -> -1 ->> 'decisions')::integer, 0),
           (c.detail -> 'en_cours' ->> 'en_attente')::integer,
           (c.detail -> 'en_cours' ->> 'en_retard')::integer,
           (c.detail -> 'decisions' ->> 'temps_moyen_jours_ouvres')::numeric,
           (c.detail -> 'decisions' ->> 'dans_les_temps_pct')::numeric,
           c.detail, now()
      FROM (SELECT NULL::bigint AS manager_id
            UNION SELECT u.id FROM public.users u JOIN public.roles r ON r.id = u.role_id WHERE r.slug = 'manager' AND u.actif
            UNION SELECT DISTINCT x.manager_id FROM public.demandes x WHERE x.manager_id IS NOT NULL) p
     CROSS JOIN LATERAL (SELECT automation.calculer_stats(p.manager_id, v_jour - 29, v_jour) AS detail) c
    ON CONFLICT (jour, (coalesce(manager_id, 0))) DO UPDATE
       SET demandes_creees = EXCLUDED.demandes_creees, decisions = EXCLUDED.decisions,
           en_attente = EXCLUDED.en_attente, en_retard = EXCLUDED.en_retard,
           temps_moyen_jours = EXCLUDED.temps_moyen_jours, dans_les_temps_pct = EXCLUDED.dans_les_temps_pct,
           detail = EXCLUDED.detail, updated_at = now()
     WHERE s.detail - 'calcule_le' IS DISTINCT FROM EXCLUDED.detail - 'calcule_le';  -- pas d'événement Realtime inutile
END;
$function$;

CREATE OR REPLACE FUNCTION automation.remplir_jours_feries (
  annee integer
)
  RETURNS integer
  LANGUAGE plpgsql
  SET search_path TO ''
  AS $function$
DECLARE p date := automation.paques(annee); n integer;
BEGIN
    INSERT INTO public.jours_feries (jour, libelle, created_at, updated_at)
    SELECT v.jour, v.libelle, now(), now() FROM (VALUES
        (make_date(annee, 1, 1),   'Jour de l''an'),
        (p + 1,                    'Lundi de Pâques'),
        (make_date(annee, 5, 1),   'Fête du travail'),
        (make_date(annee, 5, 8),   'Victoire 1945'),
        (p + 39,                   'Ascension'),
        (p + 50,                   'Lundi de Pentecôte'),
        (make_date(annee, 7, 14),  'Fête nationale'),
        (make_date(annee, 8, 15),  'Assomption'),
        (make_date(annee, 11, 1),  'Toussaint'),
        (make_date(annee, 11, 11), 'Armistice 1918'),
        (make_date(annee, 12, 25), 'Noël')) AS v(jour, libelle)
    ON CONFLICT (jour) DO NOTHING;
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n;
END;
$function$;

CREATE OR REPLACE FUNCTION automation.stats_apres_modification()
  RETURNS TRIGGER
  LANGUAGE plpgsql
  SECURITY DEFINER
  SET search_path TO ''
  AS $function$
BEGIN
    PERFORM automation.rafraichir_stats();
    RETURN NULL;
EXCEPTION WHEN others THEN
    RAISE WARNING 'stats non mises à jour : %', SQLERRM;   -- les statistiques ne bloquent jamais le métier
    RETURN NULL;
END;
$function$;

CREATE OR REPLACE FUNCTION automation.valideurs_etape (
  p_demande bigint
)
  RETURNS jsonb
  LANGUAGE sql
  STABLE
  SET search_path TO ''
  AS $function$
    WITH d AS (SELECT * FROM public.demandes WHERE id = p_demande),
         e AS (SELECT ec.valideur FROM public.etapes_circuit ec, d
                WHERE ec.type_code = d.type AND ec.ordre = d.etape)
    SELECT CASE
        WHEN coalesce((SELECT valideur FROM e), 'manager') = 'manager' THEN
            (SELECT coalesce(jsonb_agg(m.email), '[]'::jsonb) FROM public.users m, d WHERE m.id = d.manager_id)
        ELSE
            (SELECT coalesce(jsonb_agg(u.email ORDER BY u.id), '[]'::jsonb)
               FROM public.users u JOIN public.roles r ON r.id = u.role_id
              WHERE r.slug = (SELECT valideur FROM e) AND u.actif)
    END;
$function$;

CREATE OR REPLACE FUNCTION public.audit_attachments()
  RETURNS TRIGGER
  LANGUAGE plpgsql
  SECURITY DEFINER
  SET search_path TO ''
  AS $function$
begin
  insert into public.audit_logs (user_id, action, entity, entity_id, metadata)
  values (auth.uid(), 'upload_file', 'attachments', new.id::text,
          jsonb_build_object('request_id', new.request_id,
                             'type', new.type, 'file_url', new.file_url));
  return new;
end $function$;

REVOKE ALL ON FUNCTION "public"."audit_attachments"() FROM PUBLIC, "anon", "authenticated";

CREATE OR REPLACE FUNCTION public.audit_requests()
  RETURNS TRIGGER
  LANGUAGE plpgsql
  SECURITY DEFINER
  SET search_path TO ''
  AS $function$
begin
  if tg_op = 'INSERT' then
    insert into public.audit_logs (user_id, action, entity, entity_id, metadata)
    values (auth.uid(), 'create_request', 'requests', new.id::text,
            jsonb_build_object('type', new.type,
                               'manager_id', new.manager_id,
                               'deadline', new.deadline));
  else
    insert into public.audit_logs (user_id, action, entity, entity_id, metadata)
    values (auth.uid(),
            case when new.status is distinct from old.status then
              case new.status
                when 'validated' then 'validate_request'
                when 'refused'   then 'refuse_request'
                when 'expired'   then 'expire_request'
                else 'update_request' end
            else 'update_request' end,
            'requests', new.id::text,
            jsonb_build_object('status_old', old.status, 'status_new', new.status));
  end if;
  return new;
end $function$;

REVOKE ALL ON FUNCTION "public"."audit_requests"() FROM PUBLIC, "anon", "authenticated";

CREATE OR REPLACE FUNCTION public.expire_overdue_requests()
  RETURNS void
  LANGUAGE sql
  SECURITY DEFINER
  SET search_path TO ''
  AS $function$
  update public.requests
  set status = 'expired'
  where status = 'pending' and deadline < now();
$function$;

CREATE OR REPLACE FUNCTION public.handle_new_user()
  RETURNS TRIGGER
  LANGUAGE plpgsql
  SECURITY DEFINER
  SET search_path TO ''
  AS $function$
begin
  insert into public.employees (id, email, first_name, last_name, phone, role)
  values (new.id, new.email,
          new.raw_user_meta_data->>'first_name',
          new.raw_user_meta_data->>'last_name',
          new.raw_user_meta_data->>'phone',
          public.resolve_role(new.email));

  insert into public.audit_logs (user_id, action, entity, entity_id, metadata)
  values (new.id, 'signup', 'employees', new.id::text,
          jsonb_build_object('email', new.email));
  return new;
end $function$;

REVOKE ALL ON FUNCTION "public"."handle_new_user"() FROM PUBLIC, "anon", "authenticated";

CREATE OR REPLACE FUNCTION public.log_login()
  RETURNS TRIGGER
  LANGUAGE plpgsql
  SECURITY DEFINER
  SET search_path TO ''
  AS $function$
begin
  if new.last_sign_in_at is distinct from old.last_sign_in_at then
    insert into public.audit_logs (user_id, action, entity, entity_id)
    values (new.id, 'login', 'employees', new.id::text);
  end if;
  return new;
end $function$;

REVOKE ALL ON FUNCTION "public"."log_login"() FROM PUBLIC, "anon", "authenticated";

CREATE OR REPLACE FUNCTION public.my_role()
  RETURNS public.app_role
  LANGUAGE sql
  STABLE
  SECURITY DEFINER
  SET search_path TO ''
  AS $function$
  select role from public.employees where id = auth.uid();
$function$;

REVOKE ALL ON FUNCTION "public"."my_role"() FROM PUBLIC, "anon";

CREATE OR REPLACE FUNCTION public.novacorp_profil()
  RETURNS TABLE (
    user_id bigint,
    role    text
  )
  LANGUAGE sql
  STABLE
  SECURITY DEFINER
  SET search_path TO ''
  AS $function$
    SELECT u.id, r.slug::text
      FROM auth.users a
      JOIN public.users u ON lower(u.email) = lower(a.email) AND u.actif
      JOIN public.roles r ON r.id = u.role_id
     WHERE a.id = auth.uid()
       AND a.email_confirmed_at IS NOT NULL   -- pas de lien sur une adresse non vérifiée
     LIMIT 1;
$function$;

REVOKE ALL ON FUNCTION "public"."novacorp_profil"() FROM PUBLIC, "anon";

CREATE OR REPLACE FUNCTION public.requests_before_write()
  RETURNS TRIGGER
  LANGUAGE plpgsql
  SECURITY DEFINER
  SET search_path TO ''
  AS $function$
begin
  if tg_op = 'INSERT' then
    new.status := 'pending';
    if new.manager_id is null then
      select manager_id into new.manager_id
      from public.employees where id = new.employee_id;
    end if;
    if new.deadline is null then
      select now() + make_interval(days => default_days) into new.deadline
      from public.request_types where code = new.type;
    end if;
  end if;
  new.updated_at := now();
  return new;
end $function$;

CREATE OR REPLACE FUNCTION public.resolve_role (
  p_email text
)
  RETURNS public.app_role
  LANGUAGE sql
  STABLE
  SECURITY DEFINER
  SET search_path TO ''
  AS $function$
  select coalesce(
    (select role from public.role_rules
      where match_type='email' and lower(match_value)=lower(p_email) limit 1),
    (select role from public.role_rules
      where match_type='domain' and lower(match_value)=lower(split_part(p_email,'@',2)) limit 1),
    'employee'::public.app_role);
$function$;

REVOKE ALL ON FUNCTION "public"."resolve_role"(text) FROM PUBLIC, "anon", "authenticated";

CREATE OR REPLACE FUNCTION public.stats_workflow (
  p_debut date DEFAULT NULL::date,
  p_fin   date DEFAULT NULL::date
)
  RETURNS jsonb
  LANGUAGE plpgsql
  STABLE
  SECURITY DEFINER
  SET search_path TO ''
  AS $function$
DECLARE
    v_user bigint;
    v_role text;
    v_fin date := coalesce(p_fin, automation.aujourdhui());
    v_debut date := coalesce(p_debut, coalesce(p_fin, automation.aujourdhui()) - 29);
BEGIN
    SELECT p.user_id, p.role INTO v_user, v_role FROM public.novacorp_profil() p;
    IF v_user IS NULL THEN
        RAISE EXCEPTION 'Accès refusé : ce compte n''est relié à aucun employé NovaCorp (même e-mail, adresse confirmée)'
            USING ERRCODE = '42501';
    END IF;
    IF v_debut > v_fin OR v_fin - v_debut > 366 THEN
        RAISE EXCEPTION 'Période invalide (un an maximum)' USING ERRCODE = '22023';
    END IF;

    IF v_role IN ('rh', 'direction', 'admin') THEN
        RETURN automation.calculer_stats(NULL, v_debut, v_fin);
    ELSIF v_role = 'manager' THEN
        RETURN automation.calculer_stats(v_user, v_debut, v_fin);   -- son équipe uniquement
    END IF;
    RAISE EXCEPTION 'Accès refusé : statistiques réservées aux RH, à la direction et aux managers' USING ERRCODE = '42501';
END;
$function$;

REVOKE ALL ON FUNCTION "public"."stats_workflow"(date, date) FROM PUBLIC, "anon";

ALTER TABLE "public"."employees"
  ADD CONSTRAINT "employees_id_fkey" FOREIGN KEY (id) REFERENCES auth.users(id) ON DELETE CASCADE;

ALTER TABLE "public"."employees"
  ADD CONSTRAINT "employees_manager_id_fkey" FOREIGN KEY (manager_id) REFERENCES public.employees(id) ON DELETE SET NULL;

ALTER TABLE "public"."mails_sortants"
  ADD CONSTRAINT "mails_sortants_demande_id_foreign" FOREIGN KEY (demande_id) REFERENCES public.demandes(id) ON DELETE CASCADE;

ALTER TABLE "public"."pieces_jointes"
  ADD CONSTRAINT "pieces_jointes_demande_id_foreign" FOREIGN KEY (demande_id) REFERENCES public.demandes(id) ON DELETE CASCADE;

ALTER TABLE "public"."projects"
  ADD CONSTRAINT "projects_manager_id_fkey" FOREIGN KEY (manager_id) REFERENCES public.employees(id);

ALTER TABLE "public"."chiffres_affaires"
  ADD CONSTRAINT "chiffres_affaires_projet_id_foreign" FOREIGN KEY (projet_id) REFERENCES public.projets(id) ON DELETE SET NULL;

ALTER TABLE "public"."projet_user"
  ADD CONSTRAINT "projet_user_projet_id_foreign" FOREIGN KEY (projet_id) REFERENCES public.projets(id) ON DELETE CASCADE;

ALTER TABLE "public"."requests"
  ADD CONSTRAINT "requests_manager_id_fkey" FOREIGN KEY (manager_id) REFERENCES public.employees(id);

ALTER TABLE "public"."attachments"
  ADD CONSTRAINT "attachments_request_id_fkey" FOREIGN KEY (request_id) REFERENCES public.requests(id) ON DELETE CASCADE;

ALTER TABLE "public"."requests"
  ADD CONSTRAINT "requests_type_fkey" FOREIGN KEY (TYPE) REFERENCES public.request_types(code);

ALTER TABLE "public"."mails_sortants"
  ADD CONSTRAINT "mails_sortants_tache_id_foreign" FOREIGN KEY (tache_id) REFERENCES public.taches(id) ON DELETE CASCADE;

ALTER TABLE "public"."taches"
  ADD CONSTRAINT "taches_projet_id_foreign" FOREIGN KEY (projet_id) REFERENCES public.projets(id) ON DELETE CASCADE;

ALTER TABLE "public"."connexion_logs"
  ADD CONSTRAINT "connexion_logs_user_id_foreign" FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;

ALTER TABLE "public"."demandes"
  ADD CONSTRAINT "demandes_decision_par_foreign" FOREIGN KEY (decision_par) REFERENCES public.users(id) ON DELETE SET NULL;

ALTER TABLE "public"."demandes"
  ADD CONSTRAINT "demandes_demandeur_id_foreign" FOREIGN KEY (demandeur_id) REFERENCES public.users(id) ON DELETE CASCADE;

ALTER TABLE "public"."demandes"
  ADD CONSTRAINT "demandes_derniere_action_par_foreign" FOREIGN KEY (derniere_action_par) REFERENCES public.users(id) ON DELETE SET NULL;

ALTER TABLE "public"."demandes"
  ADD CONSTRAINT "demandes_manager_id_foreign" FOREIGN KEY (manager_id) REFERENCES public.users(id) ON DELETE SET NULL;

ALTER TABLE "public"."demandes"
  ADD CONSTRAINT "demandes_traite_par_foreign" FOREIGN KEY (traite_par) REFERENCES public.users(id) ON DELETE SET NULL;

ALTER TABLE "public"."historique"
  ADD CONSTRAINT "historique_auteur_id_foreign" FOREIGN KEY (auteur_id) REFERENCES public.users(id) ON DELETE SET NULL;

ALTER TABLE "public"."projet_user"
  ADD CONSTRAINT "projet_user_user_id_foreign" FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;

ALTER TABLE "public"."projets"
  ADD CONSTRAINT "projets_chef_projet_id_foreign" FOREIGN KEY (chef_projet_id) REFERENCES public.users(id) ON DELETE SET NULL;

ALTER TABLE "public"."stats_quotidiennes"
  ADD CONSTRAINT "stats_quotidiennes_manager_id_fkey" FOREIGN KEY (manager_id) REFERENCES public.users(id) ON DELETE CASCADE;

ALTER TABLE "public"."taches"
  ADD CONSTRAINT "taches_cree_par_foreign" FOREIGN KEY (cree_par) REFERENCES public.users(id) ON DELETE SET NULL;

ALTER TABLE "public"."taches"
  ADD CONSTRAINT "taches_deadline_fixee_par_foreign" FOREIGN KEY (deadline_fixee_par) REFERENCES public.users(id) ON DELETE SET NULL;

ALTER TABLE "public"."taches"
  ADD CONSTRAINT "taches_derniere_action_par_foreign" FOREIGN KEY (derniere_action_par) REFERENCES public.users(id) ON DELETE SET NULL;

ALTER TABLE "public"."taches"
  ADD CONSTRAINT "taches_responsable_id_foreign" FOREIGN KEY (responsable_id) REFERENCES public.users(id) ON DELETE CASCADE;

ALTER TABLE "public"."users"
  ADD CONSTRAINT "users_manager_id_foreign" FOREIGN KEY (manager_id) REFERENCES public.users(id) ON DELETE SET NULL;

ALTER TABLE "public"."users"
  ADD CONSTRAINT "users_role_id_foreign" FOREIGN KEY (role_id) REFERENCES public.roles(id) ON DELETE SET NULL;

CREATE INDEX audit_logs_entity_entity_id_idx ON public.audit_logs USING btree (entity, entity_id);

CREATE INDEX audit_logs_user_id_created_at_idx ON public.audit_logs USING btree (user_id, created_at DESC);

CREATE INDEX cache_expiration_index ON public.cache USING btree (expiration);

CREATE INDEX cache_locks_expiration_index ON public.cache_locks USING btree (expiration);

CREATE INDEX failed_jobs_connection_queue_failed_at_index ON public.failed_jobs USING btree (CONNECTION, queue, failed_at);

CREATE INDEX historique_objet_objet_id_index ON public.historique USING btree (objet, objet_id);

CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);

CREATE UNIQUE INDEX mails_sortants_palier_etape_unique ON public.mails_sortants USING btree (demande_id, TYPE, ((donnees ->> 'etape'::text)))
  WHERE ((TYPE)::text = ANY ((ARRAY['relance'::character varying, 'rappel_echeance'::character varying, 'escalade'::character varying])::text[]));

CREATE UNIQUE INDEX mails_sortants_palier_tache_unique ON public.mails_sortants USING btree (tache_id, TYPE)
  WHERE
    ((TYPE)::text = ANY ((ARRAY['tache_deadline_a_fixer'::character varying, 'tache_relance_deadline'::character varying, 'tache_escalade_deadline'::character varying])::text[]));

CREATE UNIQUE INDEX mails_sortants_palier_unique ON public.mails_sortants USING btree (demande_id, TYPE)
  WHERE ((TYPE)::text = ANY ((ARRAY['nouvelle_demande'::character varying, 'expiration'::character varying])::text[]));

CREATE UNIQUE INDEX mails_sortants_rappel_tache_unique ON public.mails_sortants USING btree (tache_id, TYPE, ((donnees ->> 'deadline'::text)))
  WHERE ((TYPE)::text = 'tache_rappel'::text);

CREATE INDEX mails_sortants_statut_prochain_essai_at_index ON public.mails_sortants USING btree (statut, prochain_essai_at);

CREATE INDEX requests_manager_status_idx ON public.requests USING btree (manager_id, status);

CREATE INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity);

CREATE INDEX sessions_user_id_index ON public.sessions USING btree (user_id);

CREATE UNIQUE INDEX stats_quotidiennes_jour_perimetre ON public.stats_quotidiennes USING btree (jour, COALESCE(manager_id, (0)::bigint));

CREATE INDEX taches_statut_deadline_index ON public.taches USING btree (statut, deadline);

CREATE TRIGGER on_auth_user_created
  AFTER INSERT ON auth.users
  FOR EACH ROW
  EXECUTE FUNCTION public.handle_new_user();

CREATE TRIGGER on_auth_user_login
  AFTER UPDATE OF last_sign_in_at ON auth.users
  FOR EACH ROW
  EXECUTE FUNCTION public.log_login();

CREATE TRIGGER audit_attachments
  AFTER INSERT ON public.attachments
  FOR EACH ROW
  EXECUTE FUNCTION public.audit_attachments();

CREATE TRIGGER demandes_dates
  BEFORE INSERT ON public.demandes
  FOR EACH ROW
  EXECUTE FUNCTION automation.calculer_dates_demande();

CREATE TRIGGER demandes_historique
  AFTER INSERT OR UPDATE ON public.demandes
  FOR EACH ROW
  EXECUTE FUNCTION automation.historiser('demande');

CREATE TRIGGER demandes_mail_creation
  AFTER INSERT ON public.demandes
  FOR EACH ROW
  EXECUTE FUNCTION automation.mail_nouvelle_demande();

CREATE TRIGGER demandes_mail_decision
  AFTER UPDATE OF statut, etape ON public.demandes
  FOR EACH ROW
  EXECUTE FUNCTION automation.mail_decision();

CREATE TRIGGER demandes_stats
  AFTER INSERT OR DELETE OR UPDATE OF statut, etape ON public.demandes
  FOR EACH STATEMENT
  EXECUTE FUNCTION automation.stats_apres_modification();

CREATE TRIGGER demandes_transition
  BEFORE UPDATE OF statut ON public.demandes
  FOR EACH ROW
  EXECUTE FUNCTION automation.controler_transition('demande');

CREATE TRIGGER audit_requests
  AFTER INSERT OR UPDATE ON public.requests
  FOR EACH ROW
  EXECUTE FUNCTION public.audit_requests();

CREATE TRIGGER requests_before_write
  BEFORE INSERT OR UPDATE ON public.requests
  FOR EACH ROW
  EXECUTE FUNCTION public.requests_before_write();

CREATE TRIGGER taches_controle
  BEFORE INSERT OR UPDATE ON public.taches
  FOR EACH ROW
  EXECUTE FUNCTION automation.controler_tache();

CREATE TRIGGER taches_historique
  AFTER INSERT OR UPDATE ON public.taches
  FOR EACH ROW
  EXECUTE FUNCTION automation.historiser('tache');

CREATE TRIGGER taches_mails
  AFTER INSERT OR UPDATE ON public.taches
  FOR EACH ROW
  EXECUTE FUNCTION automation.mail_tache();

CREATE TRIGGER taches_stats
  AFTER INSERT OR DELETE OR UPDATE OF statut ON public.taches
  FOR EACH STATEMENT
  EXECUTE FUNCTION automation.stats_apres_modification();

CREATE TRIGGER taches_transition
  BEFORE UPDATE OF statut ON public.taches
  FOR EACH ROW
  EXECUTE FUNCTION automation.controler_transition('tache');

CREATE POLICY "attachments select" ON "public"."attachments"
  FOR SELECT
  TO "authenticated"
  USING ((EXISTS ( SELECT 1
   FROM public.requests r
  WHERE (r.id = attachments.request_id))));

CREATE POLICY "audit read rh/admin" ON "public"."audit_logs"
  FOR SELECT
  TO "authenticated"
  USING ((public.my_role() = ANY (ARRAY['rh'::public.app_role, 'admin'::public.app_role])));

CREATE POLICY "employees read" ON "public"."employees"
  FOR SELECT
  TO "authenticated"
  USING (true);

CREATE POLICY "employees update self" ON "public"."employees"
  FOR UPDATE
  TO "authenticated"
  USING ((id = auth.uid()))
  WITH CHECK ((id = auth.uid()));

CREATE POLICY "projects select" ON "public"."projects"
  FOR SELECT
  TO "authenticated"
  USING ((public.my_role() = ANY (ARRAY['manager'::public.app_role, 'direction'::public.app_role, 'rh'::public.app_role, 'admin'::public.app_role])));

CREATE POLICY "types read" ON "public"."request_types"
  FOR SELECT
  TO "authenticated"
  USING (true);

CREATE POLICY "requests update manager" ON "public"."requests"
  FOR UPDATE
  TO "authenticated"
  USING (((manager_id = auth.uid()) OR (public.my_role() = ANY (ARRAY['rh'::public.app_role, 'admin'::public.app_role]))));

CREATE POLICY "stats_lecture" ON "public"."stats_quotidiennes"
  FOR SELECT
  TO "authenticated"
  USING ((EXISTS ( SELECT 1
   FROM public.novacorp_profil() p(user_id, ROLE)
  WHERE
    (((stats_quotidiennes.manager_id IS NULL) AND (p.role = ANY (ARRAY['rh'::text, 'direction'::text, 'admin'::text]))) OR ((stats_quotidiennes.manager_id = p.user_id) AND (p.role
    = 'manager'::text))))));

ALTER PUBLICATION "supabase_realtime" ADD TABLE "public"."stats_quotidiennes";

COMMENT ON EXTENSION "pg_cron" IS 'Job scheduler for PostgreSQL';

COMMENT ON EXTENSION "pg_net" IS 'Async HTTP';

REVOKE ALL ON FUNCTION "automation"."ajouter_jours_ouvres"(date, integer) FROM PUBLIC;

REVOKE ALL ON FUNCTION "automation"."appeler_envoyer_mails"() FROM PUBLIC;

REVOKE ALL ON FUNCTION "automation"."aujourdhui"() FROM PUBLIC;

REVOKE ALL ON FUNCTION "automation"."calculer_dates_demande"() FROM PUBLIC;

REVOKE ALL ON FUNCTION "automation"."controler_tache"() FROM PUBLIC;

REVOKE ALL ON FUNCTION "automation"."controler_transition"() FROM PUBLIC;

REVOKE ALL ON FUNCTION "automation"."date_paris"(timestamp WITHOUT time zone) FROM PUBLIC;

REVOKE ALL ON FUNCTION "automation"."emails_role"(text) FROM PUBLIC;

REVOKE ALL ON FUNCTION "automation"."est_jour_ouvre"(date) FROM PUBLIC;

REVOKE ALL ON FUNCTION "automation"."expirer"() FROM PUBLIC;

REVOKE ALL ON FUNCTION "automation"."historiser"() FROM PUBLIC;

REVOKE ALL ON FUNCTION "automation"."jour_ouvre_precedent"(date) FROM PUBLIC;

REVOKE ALL ON FUNCTION "automation"."jours_ouvres_depuis"(timestamp WITH time zone) FROM PUBLIC;

REVOKE ALL ON FUNCTION "automation"."jours_ouvres_periode"(date, date) FROM PUBLIC;

REVOKE ALL ON FUNCTION "automation"."mail_decision"() FROM PUBLIC;

REVOKE ALL ON FUNCTION "automation"."mail_nouvelle_demande"() FROM PUBLIC;

REVOKE ALL ON FUNCTION "automation"."mail_tache"() FROM PUBLIC;

REVOKE ALL ON FUNCTION "automation"."paques"(integer) FROM PUBLIC;

REVOKE ALL ON FUNCTION "automation"."planifier_rappels"() FROM PUBLIC;

REVOKE ALL ON FUNCTION "automation"."planifier_relances"(integer, integer) FROM PUBLIC;

REVOKE ALL ON FUNCTION "automation"."remplir_jours_feries"(integer) FROM PUBLIC;

REVOKE ALL ON FUNCTION "automation"."valideurs_etape"(bigint) FROM PUBLIC;

REVOKE ALL ON FUNCTION "public"."audit_attachments"() FROM "postgres";

GRANT EXECUTE ON FUNCTION "public"."audit_attachments"() TO "postgres";

GRANT EXECUTE ON FUNCTION "public"."audit_attachments"() TO "service_role";

REVOKE ALL ON FUNCTION "public"."audit_requests"() FROM "postgres";

GRANT EXECUTE ON FUNCTION "public"."audit_requests"() TO "postgres";

GRANT EXECUTE ON FUNCTION "public"."audit_requests"() TO "service_role";

GRANT EXECUTE ON FUNCTION "public"."expire_overdue_requests"() TO PUBLIC, "anon", "authenticated";

REVOKE ALL ON FUNCTION "public"."expire_overdue_requests"() FROM "postgres";

GRANT EXECUTE ON FUNCTION "public"."expire_overdue_requests"() TO "postgres";

GRANT EXECUTE ON FUNCTION "public"."expire_overdue_requests"() TO "service_role";

REVOKE ALL ON FUNCTION "public"."handle_new_user"() FROM "postgres";

GRANT EXECUTE ON FUNCTION "public"."handle_new_user"() TO "postgres";

GRANT EXECUTE ON FUNCTION "public"."handle_new_user"() TO "service_role";

REVOKE ALL ON FUNCTION "public"."log_login"() FROM "postgres";

GRANT EXECUTE ON FUNCTION "public"."log_login"() TO "postgres";

GRANT EXECUTE ON FUNCTION "public"."log_login"() TO "service_role";

GRANT EXECUTE ON FUNCTION "public"."my_role"() TO "authenticated";

REVOKE ALL ON FUNCTION "public"."my_role"() FROM "postgres";

GRANT EXECUTE ON FUNCTION "public"."my_role"() TO "postgres";

GRANT EXECUTE ON FUNCTION "public"."my_role"() TO "service_role";

GRANT EXECUTE ON FUNCTION "public"."novacorp_profil"() TO "authenticated";

REVOKE ALL ON FUNCTION "public"."novacorp_profil"() FROM "postgres";

GRANT EXECUTE ON FUNCTION "public"."novacorp_profil"() TO "postgres";

GRANT EXECUTE ON FUNCTION "public"."novacorp_profil"() TO "service_role";

GRANT EXECUTE ON FUNCTION "public"."requests_before_write"() TO PUBLIC, "anon", "authenticated";

REVOKE ALL ON FUNCTION "public"."requests_before_write"() FROM "postgres";

GRANT EXECUTE ON FUNCTION "public"."requests_before_write"() TO "postgres";

GRANT EXECUTE ON FUNCTION "public"."requests_before_write"() TO "service_role";

REVOKE ALL ON FUNCTION "public"."resolve_role"(text) FROM "postgres";

GRANT EXECUTE ON FUNCTION "public"."resolve_role"(text) TO "postgres";

GRANT EXECUTE ON FUNCTION "public"."resolve_role"(text) TO "service_role";

GRANT EXECUTE ON FUNCTION "public"."stats_workflow"(date, date) TO "authenticated";

REVOKE ALL ON FUNCTION "public"."stats_workflow"(date, date) FROM "postgres";

GRANT EXECUTE ON FUNCTION "public"."stats_workflow"(date, date) TO "postgres";

GRANT EXECUTE ON FUNCTION "public"."stats_workflow"(date, date) TO "service_role";

REVOKE ALL ON SEQUENCE "public"."audit_logs_id_seq" FROM "anon";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."audit_logs_id_seq" TO "anon";

REVOKE ALL ON SEQUENCE "public"."audit_logs_id_seq" FROM "authenticated";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."audit_logs_id_seq" TO "authenticated";

REVOKE ALL ON SEQUENCE "public"."audit_logs_id_seq" FROM "postgres";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."audit_logs_id_seq" TO "postgres";

REVOKE ALL ON SEQUENCE "public"."audit_logs_id_seq" FROM "service_role";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."audit_logs_id_seq" TO "service_role";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."chiffres_affaires_id_seq" TO "anon", "authenticated";

REVOKE ALL ON SEQUENCE "public"."chiffres_affaires_id_seq" FROM "postgres";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."chiffres_affaires_id_seq" TO "postgres";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."chiffres_affaires_id_seq" TO "service_role";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."connexion_logs_id_seq" TO "anon", "authenticated";

REVOKE ALL ON SEQUENCE "public"."connexion_logs_id_seq" FROM "postgres";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."connexion_logs_id_seq" TO "postgres";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."connexion_logs_id_seq" TO "service_role";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."demandes_id_seq" TO "anon", "authenticated";

REVOKE ALL ON SEQUENCE "public"."demandes_id_seq" FROM "postgres";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."demandes_id_seq" TO "postgres";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."demandes_id_seq" TO "service_role";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."etapes_circuit_id_seq" TO "anon", "authenticated";

REVOKE ALL ON SEQUENCE "public"."etapes_circuit_id_seq" FROM "postgres";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."etapes_circuit_id_seq" TO "postgres";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."etapes_circuit_id_seq" TO "service_role";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."failed_jobs_id_seq" TO "anon", "authenticated";

REVOKE ALL ON SEQUENCE "public"."failed_jobs_id_seq" FROM "postgres";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."failed_jobs_id_seq" TO "postgres";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."failed_jobs_id_seq" TO "service_role";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."historique_id_seq" TO "anon", "authenticated";

REVOKE ALL ON SEQUENCE "public"."historique_id_seq" FROM "postgres";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."historique_id_seq" TO "postgres";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."historique_id_seq" TO "service_role";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."jobs_id_seq" TO "anon", "authenticated";

REVOKE ALL ON SEQUENCE "public"."jobs_id_seq" FROM "postgres";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."jobs_id_seq" TO "postgres";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."jobs_id_seq" TO "service_role";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."mails_sortants_id_seq" TO "anon", "authenticated";

REVOKE ALL ON SEQUENCE "public"."mails_sortants_id_seq" FROM "postgres";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."mails_sortants_id_seq" TO "postgres";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."mails_sortants_id_seq" TO "service_role";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."migrations_id_seq" TO "anon", "authenticated";

REVOKE ALL ON SEQUENCE "public"."migrations_id_seq" FROM "postgres";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."migrations_id_seq" TO "postgres";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."migrations_id_seq" TO "service_role";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."pieces_jointes_id_seq" TO "anon", "authenticated";

REVOKE ALL ON SEQUENCE "public"."pieces_jointes_id_seq" FROM "postgres";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."pieces_jointes_id_seq" TO "postgres";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."pieces_jointes_id_seq" TO "service_role";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."projet_user_id_seq" TO "anon", "authenticated";

REVOKE ALL ON SEQUENCE "public"."projet_user_id_seq" FROM "postgres";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."projet_user_id_seq" TO "postgres";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."projet_user_id_seq" TO "service_role";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."projets_id_seq" TO "anon", "authenticated";

REVOKE ALL ON SEQUENCE "public"."projets_id_seq" FROM "postgres";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."projets_id_seq" TO "postgres";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."projets_id_seq" TO "service_role";

REVOKE ALL ON SEQUENCE "public"."role_rules_id_seq" FROM "anon";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."role_rules_id_seq" TO "anon";

REVOKE ALL ON SEQUENCE "public"."role_rules_id_seq" FROM "authenticated";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."role_rules_id_seq" TO "authenticated";

REVOKE ALL ON SEQUENCE "public"."role_rules_id_seq" FROM "postgres";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."role_rules_id_seq" TO "postgres";

REVOKE ALL ON SEQUENCE "public"."role_rules_id_seq" FROM "service_role";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."role_rules_id_seq" TO "service_role";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."roles_id_seq" TO "anon", "authenticated";

REVOKE ALL ON SEQUENCE "public"."roles_id_seq" FROM "postgres";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."roles_id_seq" TO "postgres";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."roles_id_seq" TO "service_role";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."stats_quotidiennes_id_seq" TO "anon", "authenticated";

REVOKE ALL ON SEQUENCE "public"."stats_quotidiennes_id_seq" FROM "postgres";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."stats_quotidiennes_id_seq" TO "postgres";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."stats_quotidiennes_id_seq" TO "service_role";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."taches_id_seq" TO "anon", "authenticated";

REVOKE ALL ON SEQUENCE "public"."taches_id_seq" FROM "postgres";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."taches_id_seq" TO "postgres";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."taches_id_seq" TO "service_role";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."transitions_id_seq" TO "anon", "authenticated";

REVOKE ALL ON SEQUENCE "public"."transitions_id_seq" FROM "postgres";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."transitions_id_seq" TO "postgres";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."transitions_id_seq" TO "service_role";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."types_demande_id_seq" TO "anon", "authenticated";

REVOKE ALL ON SEQUENCE "public"."types_demande_id_seq" FROM "postgres";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."types_demande_id_seq" TO "postgres";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."types_demande_id_seq" TO "service_role";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."users_id_seq" TO "anon", "authenticated";

REVOKE ALL ON SEQUENCE "public"."users_id_seq" FROM "postgres";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."users_id_seq" TO "postgres";

GRANT SELECT, UPDATE, USAGE ON SEQUENCE "public"."users_id_seq" TO "service_role";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."attachments" TO "anon", "authenticated";

REVOKE ALL ON TABLE "public"."attachments" FROM "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."attachments" TO "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."attachments" TO "service_role";

REVOKE ALL ON TABLE "public"."audit_logs" FROM "anon";

GRANT MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE ON TABLE "public"."audit_logs" TO "anon";

REVOKE ALL ON TABLE "public"."audit_logs" FROM "authenticated";

GRANT MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE ON TABLE "public"."audit_logs" TO "authenticated";

REVOKE ALL ON TABLE "public"."audit_logs" FROM "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."audit_logs" TO "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."audit_logs" TO "service_role";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."cache" TO "anon", "authenticated";

REVOKE ALL ON TABLE "public"."cache" FROM "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."cache" TO "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."cache" TO "service_role";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."cache_locks" TO "anon", "authenticated";

REVOKE ALL ON TABLE "public"."cache_locks" FROM "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."cache_locks" TO "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."cache_locks" TO "service_role";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."chiffres_affaires" TO "anon", "authenticated";

REVOKE ALL ON TABLE "public"."chiffres_affaires" FROM "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."chiffres_affaires" TO "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."chiffres_affaires" TO "service_role";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."connexion_logs" TO "anon", "authenticated";

REVOKE ALL ON TABLE "public"."connexion_logs" FROM "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."connexion_logs" TO "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."connexion_logs" TO "service_role";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."demandes" TO "anon", "authenticated";

REVOKE ALL ON TABLE "public"."demandes" FROM "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."demandes" TO "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."demandes" TO "service_role";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."employees" TO "anon";

REVOKE ALL ON TABLE "public"."employees" FROM "authenticated";

REVOKE ALL ("first_name") ON TABLE "public"."employees" FROM "authenticated";

GRANT UPDATE ("first_name") ON TABLE "public"."employees" TO "authenticated";

REVOKE ALL ("last_name") ON TABLE "public"."employees" FROM "authenticated";

GRANT UPDATE ("last_name") ON TABLE "public"."employees" TO "authenticated";

REVOKE ALL ("phone") ON TABLE "public"."employees" FROM "authenticated";

GRANT UPDATE ("phone") ON TABLE "public"."employees" TO "authenticated";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE ON TABLE "public"."employees" TO "authenticated";

REVOKE ALL ON TABLE "public"."employees" FROM "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."employees" TO "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."employees" TO "service_role";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."etapes_circuit" TO "anon", "authenticated";

REVOKE ALL ON TABLE "public"."etapes_circuit" FROM "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."etapes_circuit" TO "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."etapes_circuit" TO "service_role";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."failed_jobs" TO "anon", "authenticated";

REVOKE ALL ON TABLE "public"."failed_jobs" FROM "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."failed_jobs" TO "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."failed_jobs" TO "service_role";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."historique" TO "anon", "authenticated";

REVOKE ALL ON TABLE "public"."historique" FROM "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."historique" TO "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."historique" TO "service_role";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."job_batches" TO "anon", "authenticated";

REVOKE ALL ON TABLE "public"."job_batches" FROM "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."job_batches" TO "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."job_batches" TO "service_role";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."jobs" TO "anon", "authenticated";

REVOKE ALL ON TABLE "public"."jobs" FROM "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."jobs" TO "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."jobs" TO "service_role";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."jours_feries" TO "anon", "authenticated";

REVOKE ALL ON TABLE "public"."jours_feries" FROM "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."jours_feries" TO "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."jours_feries" TO "service_role";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."mails_sortants" TO "anon", "authenticated";

REVOKE ALL ON TABLE "public"."mails_sortants" FROM "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."mails_sortants" TO "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."mails_sortants" TO "service_role";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."migrations" TO "anon", "authenticated";

REVOKE ALL ON TABLE "public"."migrations" FROM "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."migrations" TO "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."migrations" TO "service_role";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."password_reset_tokens" TO "anon", "authenticated";

REVOKE ALL ON TABLE "public"."password_reset_tokens" FROM "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."password_reset_tokens" TO "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."password_reset_tokens" TO "service_role";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."pieces_jointes" TO "anon", "authenticated";

REVOKE ALL ON TABLE "public"."pieces_jointes" FROM "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."pieces_jointes" TO "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."pieces_jointes" TO "service_role";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."projects" TO "anon", "authenticated";

REVOKE ALL ON TABLE "public"."projects" FROM "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."projects" TO "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."projects" TO "service_role";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."projet_user" TO "anon", "authenticated";

REVOKE ALL ON TABLE "public"."projet_user" FROM "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."projet_user" TO "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."projet_user" TO "service_role";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."projets" TO "anon", "authenticated";

REVOKE ALL ON TABLE "public"."projets" FROM "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."projets" TO "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."projets" TO "service_role";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."request_types" TO "anon", "authenticated";

REVOKE ALL ON TABLE "public"."request_types" FROM "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."request_types" TO "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."request_types" TO "service_role";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."requests" TO "anon", "authenticated";

REVOKE ALL ON TABLE "public"."requests" FROM "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."requests" TO "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."requests" TO "service_role";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."role_rules" TO "anon", "authenticated";

REVOKE ALL ON TABLE "public"."role_rules" FROM "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."role_rules" TO "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."role_rules" TO "service_role";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."roles" TO "anon", "authenticated";

REVOKE ALL ON TABLE "public"."roles" FROM "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."roles" TO "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."roles" TO "service_role";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."sessions" TO "anon", "authenticated";

REVOKE ALL ON TABLE "public"."sessions" FROM "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."sessions" TO "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."sessions" TO "service_role";

REVOKE ALL ON TABLE "public"."stats_quotidiennes" FROM "authenticated";

GRANT MAINTAIN, REFERENCES, SELECT, TRIGGER ON TABLE "public"."stats_quotidiennes" TO "authenticated";

REVOKE ALL ON TABLE "public"."stats_quotidiennes" FROM "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."stats_quotidiennes" TO "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."stats_quotidiennes" TO "service_role";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."taches" TO "anon", "authenticated";

REVOKE ALL ON TABLE "public"."taches" FROM "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."taches" TO "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."taches" TO "service_role";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."transitions" TO "anon", "authenticated";

REVOKE ALL ON TABLE "public"."transitions" FROM "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."transitions" TO "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."transitions" TO "service_role";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."types_demande" TO "anon", "authenticated";

REVOKE ALL ON TABLE "public"."types_demande" FROM "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."types_demande" TO "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."types_demande" TO "service_role";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."users" TO "anon", "authenticated";

REVOKE ALL ON TABLE "public"."users" FROM "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."users" TO "postgres";

GRANT DELETE, INSERT, MAINTAIN, REFERENCES, SELECT, TRIGGER, TRUNCATE, UPDATE ON TABLE "public"."users" TO "service_role";

SELECT cron.schedule_in_database('novacorp-envoyer-mails', '* * * * *', ' select automation.appeler_envoyer_mails(); ', 'postgres', NULL, true);

SELECT cron.schedule_in_database('novacorp-expiration', '5 * * * *', ' select automation.expirer(); ', 'postgres', NULL, true);

SELECT cron.schedule_in_database('novacorp-jours-feries', '0 3 1 12 *', ' select automation.remplir_jours_feries(extract(year from now())::integer + 1); ', 'postgres', NULL, true);

SELECT
  cron.schedule_in_database('novacorp-purge-historique-cron', '0 3 * * 0', ' delete from cron.job_run_details where end_time < now() - interval ''7 days''; ', 'postgres', NULL,
  true);

SELECT cron.schedule_in_database('novacorp-relances', '0 7,8 * * 1-5', ' select automation.planifier_rappels()
               where extract(hour from now() at time zone ''Europe/Paris'') = 9
                 and automation.est_jour_ouvre(automation.aujourdhui()); ', 'postgres', NULL, true);

SELECT
  cron.schedule_in_database('novacorp-stats', '10 0 * * *', ' SELECT automation.rafraichir_stats(automation.aujourdhui() - 1); SELECT automation.rafraichir_stats(); ', 'postgres',
  NULL, true);

ALTER TABLE "public"."requests"
  ADD CONSTRAINT "requests_employee_id_fkey" FOREIGN KEY (employee_id) REFERENCES public.employees(id);

CREATE INDEX requests_employee_idx ON public.requests USING btree (employee_id);

CREATE POLICY "attachments insert own request" ON "public"."attachments"
  FOR INSERT
  TO "authenticated"
  WITH CHECK ((EXISTS ( SELECT 1
   FROM public.requests r
  WHERE ((r.id = attachments.request_id) AND (r.employee_id = auth.uid())))));

CREATE POLICY "requests insert own" ON "public"."requests"
  FOR INSERT
  TO "authenticated"
  WITH CHECK ((employee_id = auth.uid()));

CREATE POLICY "requests select" ON "public"."requests"
  FOR SELECT
  TO "authenticated"
  USING (((employee_id = auth.uid()) OR (manager_id = auth.uid()) OR (public.my_role() = ANY (ARRAY['rh'::public.app_role, 'admin'::public.app_role]))));

