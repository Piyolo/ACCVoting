-- ============================================================
-- VOTING SYSTEM - CLEAN DATABASE SCHEMA
-- ============================================================
-- Creates:
--   public.activity_logs
--   public.admins
--   public.candidates
--   public.departments
--   public.election_positions
--   public.elections
--   public.login_attempts
--   public.majors
--   public.positions
--   public.users
--   public.vote_drafts
--   public.votes
--   public.year_levels
--
-- NO DATA IS INSERTED.
--
-- neon_auth is intentionally NOT included.
-- Neon manages neon_auth separately.
-- ============================================================


-- ============================================================
-- SCHEMA
-- ============================================================

CREATE SCHEMA IF NOT EXISTS public;


-- ============================================================
-- TABLE: activity_logs
-- ============================================================

CREATE TABLE public.activity_logs (
    id serial PRIMARY KEY,
    admin_username varchar(50),
    action text NOT NULL,
    created_at timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL
);


-- ============================================================
-- TABLE: admins
-- ============================================================

CREATE TABLE public.admins (
    id serial PRIMARY KEY,
    username varchar(50) NOT NULL
        CONSTRAINT admins_username_key UNIQUE,
    password_hash varchar(255) NOT NULL,
    email varchar(100),
    role varchar(20) DEFAULT 'admin',
    created_at timestamp DEFAULT CURRENT_TIMESTAMP,
    last_login timestamp
);


-- ============================================================
-- TABLE: departments
-- ============================================================

CREATE TABLE public.departments (
    id serial PRIMARY KEY,
    code varchar(20) NOT NULL
        CONSTRAINT departments_code_key UNIQUE,
    name varchar(100) NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL
);


-- ============================================================
-- TABLE: positions
-- ============================================================

CREATE TABLE public.positions (
    id serial PRIMARY KEY,
    title varchar(50) NOT NULL
);


-- ============================================================
-- TABLE: elections
-- ============================================================

CREATE TABLE public.elections (
    id serial PRIMARY KEY,
    name varchar(100) NOT NULL,
    type varchar(10) NOT NULL,
    department varchar(50),
    status varchar(20) DEFAULT 'draft',
    start_date timestamp NOT NULL,
    end_date timestamp NOT NULL,
    results_visibility varchar(20) DEFAULT 'after',
    parties_enabled boolean DEFAULT false,
    created_at timestamp DEFAULT CURRENT_TIMESTAMP,
    parties jsonb DEFAULT '[]',

    CONSTRAINT elections_results_visibility_check
        CHECK (
            results_visibility IN (
                'always',
                'after',
                'never'
            )
        ),

    CONSTRAINT elections_status_check
        CHECK (
            status IN (
                'draft',
                'scheduled',
                'ongoing',
                'paused',
                'closed',
                'archived'
            )
        ),

    CONSTRAINT elections_type_check
        CHECK (
            type IN (
                'SSG',
                'DSG'
            )
        )
);


-- ============================================================
-- TABLE: candidates
-- ============================================================

CREATE TABLE public.candidates (
    id serial PRIMARY KEY,
    position_id integer NOT NULL,
    photo text,
    course varchar(100),
    candidate_year varchar(20),
    platform text,
    party varchar(100),
    name varchar(100) NOT NULL
);


-- ============================================================
-- TABLE: election_positions
-- ============================================================

CREATE TABLE public.election_positions (
    id serial PRIMARY KEY,
    election_id integer NOT NULL,
    position_id integer NOT NULL,
    winner_count integer DEFAULT 1,
    candidate_limit integer,
    year_restriction varchar(20),

    CONSTRAINT election_positions_election_id_position_id_key
        UNIQUE (election_id, position_id)
);


-- ============================================================
-- TABLE: login_attempts
-- ============================================================

CREATE TABLE public.login_attempts (
    id serial PRIMARY KEY,
    identifier varchar(150) NOT NULL,
    attempted_at timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL
);


-- ============================================================
-- TABLE: majors
-- ============================================================

CREATE TABLE public.majors (
    id serial PRIMARY KEY,
    department_code varchar(20) NOT NULL,
    name varchar(100) NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL
);


-- ============================================================
-- TABLE: users
-- ============================================================

CREATE TABLE public.users (
    id serial PRIMARY KEY,
    student_id varchar(20) NOT NULL
        CONSTRAINT users_student_id_key UNIQUE,
    name varchar(100) NOT NULL,
    password varchar(255),
    department varchar(50),
    year_level varchar(20),
    has_voted integer DEFAULT 0 NOT NULL,
    registration_status varchar(20) DEFAULT 'unregistered',
    academic_year varchar(10),
    major varchar(100),
    section varchar(20),
    email varchar(100)
);


-- ============================================================
-- TABLE: vote_drafts
-- ============================================================

CREATE TABLE public.vote_drafts (
    id serial PRIMARY KEY,
    user_id integer NOT NULL,
    election_id integer NOT NULL,
    selections jsonb DEFAULT '{}' NOT NULL,
    updated_at timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL,

    CONSTRAINT vote_drafts_user_id_election_id_key
        UNIQUE (user_id, election_id)
);


-- ============================================================
-- TABLE: votes
-- ============================================================

CREATE TABLE public.votes (
    id serial PRIMARY KEY,
    user_id integer NOT NULL,
    candidate_id integer NOT NULL,
    position_id integer NOT NULL,
    voted_at timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL,

    CONSTRAINT votes_user_id_candidate_id_key
        UNIQUE (user_id, candidate_id)
);


-- ============================================================
-- TABLE: year_levels
-- ============================================================

CREATE TABLE public.year_levels (
    id serial PRIMARY KEY,
    name varchar(20) NOT NULL
        CONSTRAINT year_levels_name_key UNIQUE,
    sort_order integer DEFAULT 0 NOT NULL
);


-- ============================================================
-- INDEXES
-- ============================================================

CREATE INDEX idx_activity_logs_created
    ON public.activity_logs (created_at);


CREATE INDEX idx_candidates_position
    ON public.candidates (position_id);


CREATE INDEX idx_election_positions_position
    ON public.election_positions (position_id);


CREATE INDEX idx_login_attempts_identifier_time
    ON public.login_attempts (identifier, attempted_at);


CREATE INDEX idx_majors_department
    ON public.majors (department_code);


CREATE INDEX idx_votes_candidate
    ON public.votes (candidate_id);


-- ============================================================
-- FOREIGN KEYS
-- ============================================================

-- Candidates → Positions
ALTER TABLE public.candidates
    ADD CONSTRAINT candidates_position_id_fkey
    FOREIGN KEY (position_id)
    REFERENCES public.positions(id)
    ON DELETE CASCADE;


-- Election Positions → Elections
ALTER TABLE public.election_positions
    ADD CONSTRAINT election_positions_election_id_fkey
    FOREIGN KEY (election_id)
    REFERENCES public.elections(id)
    ON DELETE CASCADE;


-- Election Positions → Positions
ALTER TABLE public.election_positions
    ADD CONSTRAINT election_positions_position_id_fkey
    FOREIGN KEY (position_id)
    REFERENCES public.positions(id)
    ON DELETE CASCADE;


-- Majors → Departments
ALTER TABLE public.majors
    ADD CONSTRAINT majors_department_code_fkey
    FOREIGN KEY (department_code)
    REFERENCES public.departments(code)
    ON DELETE CASCADE
    ON UPDATE CASCADE;


-- Vote Drafts → Elections
ALTER TABLE public.vote_drafts
    ADD CONSTRAINT vote_drafts_election_id_fkey
    FOREIGN KEY (election_id)
    REFERENCES public.elections(id)
    ON DELETE CASCADE;


-- Vote Drafts → Users
ALTER TABLE public.vote_drafts
    ADD CONSTRAINT vote_drafts_user_id_fkey
    FOREIGN KEY (user_id)
    REFERENCES public.users(id)
    ON DELETE CASCADE;


-- Votes → Candidates
ALTER TABLE public.votes
    ADD CONSTRAINT votes_candidate_id_fkey
    FOREIGN KEY (candidate_id)
    REFERENCES public.candidates(id)
    ON DELETE CASCADE;


-- Votes → Positions
ALTER TABLE public.votes
    ADD CONSTRAINT votes_position_id_fkey
    FOREIGN KEY (position_id)
    REFERENCES public.positions(id)
    ON DELETE CASCADE;


-- Votes → Users
ALTER TABLE public.votes
    ADD CONSTRAINT votes_user_id_fkey
    FOREIGN KEY (user_id)
    REFERENCES public.users(id)
    ON DELETE CASCADE;


-- ============================================================
-- DONE
-- ============================================================
-- All tables are created EMPTY.
-- neon_auth is untouched.
-- ============================================================