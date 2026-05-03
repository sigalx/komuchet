<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260518010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create baseline KomUchet schema.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql(<<<'SQL_0001'
SET statement_timeout = 0;
SQL_0001);

        $this->addSql(<<<'SQL_0002'
SET lock_timeout = 0;
SQL_0002);

        $this->addSql(<<<'SQL_0003'
SET idle_in_transaction_session_timeout = 0;
SQL_0003);

        $this->addSql(<<<'SQL_0004'
SET transaction_timeout = 0;
SQL_0004);

        $this->addSql(<<<'SQL_0005'
SET client_encoding = 'UTF8';
SQL_0005);

        $this->addSql(<<<'SQL_0006'
SET standard_conforming_strings = on;
SQL_0006);

        $this->addSql(<<<'SQL_0007'
SELECT pg_catalog.set_config('search_path', '', false);
SQL_0007);

        $this->addSql(<<<'SQL_0008'
SET check_function_bodies = false;
SQL_0008);

        $this->addSql(<<<'SQL_0009'
SET xmloption = content;
SQL_0009);

        $this->addSql(<<<'SQL_0010'
SET client_min_messages = warning;
SQL_0010);

        $this->addSql(<<<'SQL_0011'
SET row_security = off;
SQL_0011);

        $this->addSql(<<<'SQL_0012'
CREATE COLLATION public.unicode_search_ci_ai (provider = icu, deterministic = false, locale = 'und-u-ks-level1');
SQL_0012);

        $this->addSql(<<<'SQL_0013'
CREATE EXTENSION IF NOT EXISTS btree_gist WITH SCHEMA public;
SQL_0013);

        $this->addSql(<<<'SQL_0014'
COMMENT ON EXTENSION btree_gist IS 'support for indexing common datatypes in GiST';
SQL_0014);

        $this->addSql(<<<'SQL_0015'
CREATE TYPE public.account_statement_delivery_channel AS ENUM (
    'email'
);
SQL_0015);

        $this->addSql(<<<'SQL_0016'
CREATE TYPE public.accrual_type AS ENUM (
    'electricity',
    'membership_fee',
    'water',
    'other'
);
SQL_0016);

        $this->addSql(<<<'SQL_0017'
CREATE TYPE public.audit_log_source AS ENUM (
    'app',
    'db',
    'import',
    'system'
);
SQL_0017);

        $this->addSql(<<<'SQL_0018'
CREATE TYPE public.billing_run_account_issue_close_reason AS ENUM (
    'resolved',
    'ignored',
    'cancelled_run',
    'obsolete'
);
SQL_0018);

        $this->addSql(<<<'SQL_0019'
CREATE TYPE public.billing_run_account_issue_type AS ENUM (
    'missing_reading',
    'stale_reading',
    'invalid_reading',
    'missing_tariff',
    'missing_consumption_band_rule',
    'calculation_error'
);
SQL_0019);

        $this->addSql(<<<'SQL_0020'
CREATE TYPE public.billing_run_kind AS ENUM (
    'electricity'
);
SQL_0020);

        $this->addSql(<<<'SQL_0021'
CREATE TYPE public.electricity_consumption_band_allocation_method AS ENUM (
    'total_proportional',
    'per_tariff_zone'
);
SQL_0021);

        $this->addSql(<<<'SQL_0022'
CREATE TYPE public.electricity_consumption_band_rule_scope_mode AS ENUM (
    'include',
    'exclude'
);
SQL_0022);

        $this->addSql(<<<'SQL_0023'
CREATE TYPE public.electricity_meter_reading_source AS ENUM (
    'subscriber',
    'admin',
    'import'
);
SQL_0023);

        $this->addSql(<<<'SQL_0024'
CREATE TYPE public.payment_source AS ENUM (
    'manual',
    'import'
);
SQL_0024);

        $this->addSql(<<<'SQL_0025'
CREATE TYPE public.subscriber_account_access_role AS ENUM (
    'owner',
    'representative',
    'viewer'
);
SQL_0025);

        $this->addSql(<<<'SQL_0026'
CREATE TYPE public.workspace_user_role_code AS ENUM (
    'admin',
    'operator'
);
SQL_0026);

        $this->addSql(<<<'SQL_0027'
CREATE TYPE public.zavety_michurina_statement_import_file_status AS ENUM (
    'pending',
    'parsed',
    'failed',
    'applied',
    'cancelled'
);
SQL_0027);

        $this->addSql(<<<'SQL_0028'
CREATE FUNCTION public.prevent_immutable_table_changes() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    RAISE EXCEPTION 'Table % is immutable', TG_TABLE_NAME;
END;
$$;
SQL_0028);

        $this->addSql(<<<'SQL_0029'
CREATE FUNCTION public.set_row_timestamps() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF tg_op = 'INSERT' THEN
        NEW.created_at = COALESCE(NEW.created_at, clock_timestamp());
        NEW.updated_at = COALESCE(NEW.updated_at, NEW.created_at);
    ELSIF tg_op = 'UPDATE' THEN
        NEW.updated_at = clock_timestamp();
    END IF;

    RETURN NEW;
END;
$$;
SQL_0029);

        $this->addSql(<<<'SQL_0030'
SET default_tablespace = '';
SQL_0030);

        $this->addSql(<<<'SQL_0031'
SET default_table_access_method = heap;
SQL_0031);

        $this->addSql(<<<'SQL_0032'
CREATE TABLE public.account_electricity_tariff_profile_assignments (
    workspace_uuid uuid CONSTRAINT account_electricity_tariff_profile_assi_workspace_uuid_not_null NOT NULL,
    account_uuid uuid CONSTRAINT account_electricity_tariff_profile_assign_account_uuid_not_null NOT NULL,
    tariff_profile_uuid uuid CONSTRAINT account_electricity_tariff_profile_tariff_profile_uuid_not_null NOT NULL,
    valid_from date CONSTRAINT account_electricity_tariff_profile_assignme_valid_from_not_null NOT NULL,
    valid_to date,
    assigned_at timestamp with time zone DEFAULT clock_timestamp() CONSTRAINT account_electricity_tariff_profile_assignm_assigned_at_not_null NOT NULL,
    assigned_by uuid,
    notes text,
    CONSTRAINT chk_account_electricity_tariff_profile_assignments_valid_period CHECK (((valid_to IS NULL) OR (valid_to > valid_from)))
);
SQL_0032);

        $this->addSql(<<<'SQL_0033'
CREATE TABLE public.account_group_members (
    workspace_uuid uuid NOT NULL,
    account_group_uuid uuid NOT NULL,
    account_uuid uuid NOT NULL,
    valid_from date NOT NULL,
    valid_to date,
    created_by uuid,
    CONSTRAINT chk_account_group_members_valid_period CHECK (((valid_to IS NULL) OR (valid_to > valid_from)))
);
SQL_0033);

        $this->addSql(<<<'SQL_0034'
CREATE TABLE public.account_groups (
    uuid uuid DEFAULT uuidv7() NOT NULL,
    workspace_uuid uuid NOT NULL,
    code text NOT NULL,
    name text NOT NULL,
    description text,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    updated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    deleted_at timestamp with time zone,
    created_by uuid,
    updated_by uuid,
    deleted_by uuid
);
SQL_0034);

        $this->addSql(<<<'SQL_0035'
CREATE TABLE public.account_statement_accruals (
    workspace_uuid uuid NOT NULL,
    account_statement_uuid uuid NOT NULL,
    accrual_uuid uuid NOT NULL,
    type public.accrual_type NOT NULL,
    period_start date NOT NULL,
    period_end date NOT NULL,
    amount numeric(14,2) NOT NULL,
    notes text,
    sort_order integer NOT NULL,
    CONSTRAINT chk_account_statement_accruals_amount_nonnegative CHECK ((amount >= (0)::numeric)),
    CONSTRAINT chk_account_statement_accruals_period CHECK ((period_end > period_start)),
    CONSTRAINT chk_account_statement_accruals_sort_order_positive CHECK ((sort_order > 0))
);
SQL_0035);

        $this->addSql(<<<'SQL_0036'
CREATE TABLE public.account_statement_deliveries (
    uuid uuid DEFAULT uuidv7() NOT NULL,
    workspace_uuid uuid NOT NULL,
    account_statement_uuid uuid NOT NULL,
    recipient_subscriber_uuid uuid,
    channel public.account_statement_delivery_channel DEFAULT 'email'::public.account_statement_delivery_channel NOT NULL,
    recipient_email text NOT NULL,
    recipient_email_normalized text CONSTRAINT account_statement_deliverie_recipient_email_normalized_not_null NOT NULL,
    recipient_name text,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    created_by uuid,
    cancelled_at timestamp with time zone,
    cancelled_by uuid,
    cancellation_reason text,
    CONSTRAINT chk_account_statement_deliveries_cancellation_complete CHECK ((((cancelled_at IS NULL) AND (cancellation_reason IS NULL)) OR ((cancelled_at IS NOT NULL) AND (cancellation_reason IS NOT NULL)))),
    CONSTRAINT chk_account_statement_deliveries_recipient_email_normalized_not CHECK ((recipient_email_normalized <> ''::text)),
    CONSTRAINT chk_account_statement_deliveries_recipient_email_not_empty CHECK ((recipient_email <> ''::text)),
    CONSTRAINT chk_account_statement_deliveries_recipient_name_not_empty CHECK (((recipient_name IS NULL) OR (recipient_name <> ''::text)))
);
SQL_0036);

        $this->addSql(<<<'SQL_0037'
CREATE TABLE public.account_statement_delivery_attempts (
    workspace_uuid uuid NOT NULL,
    delivery_uuid uuid NOT NULL,
    attempt_number integer NOT NULL,
    queued_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    queued_by uuid,
    started_at timestamp with time zone,
    succeeded_at timestamp with time zone,
    failed_at timestamp with time zone,
    failure_reason text,
    provider_message_id text,
    CONSTRAINT chk_account_statement_delivery_attempts_attempt_positive CHECK ((attempt_number > 0)),
    CONSTRAINT chk_account_statement_delivery_attempts_failure_reason CHECK (((failed_at IS NULL) OR ((failure_reason IS NOT NULL) AND (failure_reason <> ''::text)))),
    CONSTRAINT chk_account_statement_delivery_attempts_provider_message_id_not CHECK (((provider_message_id IS NULL) OR (provider_message_id <> ''::text))),
    CONSTRAINT chk_account_statement_delivery_attempts_terminal_state CHECK (((succeeded_at IS NULL) OR (failed_at IS NULL)))
);
SQL_0037);

        $this->addSql(<<<'SQL_0038'
CREATE TABLE public.account_statement_electricity_lines (
    workspace_uuid uuid NOT NULL,
    account_statement_uuid uuid CONSTRAINT account_statement_electricity_l_account_statement_uuid_not_null NOT NULL,
    accrual_uuid uuid NOT NULL,
    tariff_zone_uuid uuid NOT NULL,
    consumption_band_uuid uuid CONSTRAINT account_statement_electricity_li_consumption_band_uuid_not_null NOT NULL,
    tariff_zone_code text NOT NULL,
    tariff_zone_name text NOT NULL,
    consumption_band_code text CONSTRAINT account_statement_electricity_li_consumption_band_code_not_null NOT NULL,
    consumption_band_name text CONSTRAINT account_statement_electricity_li_consumption_band_name_not_null NOT NULL,
    consumption_kwh numeric(14,3) NOT NULL,
    rate numeric(12,6) NOT NULL,
    amount numeric(14,2) NOT NULL,
    sort_order integer NOT NULL,
    CONSTRAINT chk_account_statement_electricity_lines_amount_nonnegative CHECK ((amount >= (0)::numeric)),
    CONSTRAINT chk_account_statement_electricity_lines_band_code_not_empty CHECK ((consumption_band_code <> ''::text)),
    CONSTRAINT chk_account_statement_electricity_lines_band_name_not_empty CHECK ((consumption_band_name <> ''::text)),
    CONSTRAINT chk_account_statement_electricity_lines_consumption_nonnegative CHECK ((consumption_kwh >= (0)::numeric)),
    CONSTRAINT chk_account_statement_electricity_lines_rate_nonnegative CHECK ((rate >= (0)::numeric)),
    CONSTRAINT chk_account_statement_electricity_lines_sort_order_positive CHECK ((sort_order > 0)),
    CONSTRAINT chk_account_statement_electricity_lines_zone_code_not_empty CHECK ((tariff_zone_code <> ''::text)),
    CONSTRAINT chk_account_statement_electricity_lines_zone_name_not_empty CHECK ((tariff_zone_name <> ''::text))
);
SQL_0038);

        $this->addSql(<<<'SQL_0039'
CREATE TABLE public.account_statement_electricity_registers (
    workspace_uuid uuid NOT NULL,
    account_statement_uuid uuid CONSTRAINT account_statement_electricity_r_account_statement_uuid_not_null NOT NULL,
    accrual_uuid uuid NOT NULL,
    electricity_meter_uuid uuid CONSTRAINT account_statement_electricity_r_electricity_meter_uuid_not_null NOT NULL,
    tariff_zone_uuid uuid CONSTRAINT account_statement_electricity_registe_tariff_zone_uuid_not_null NOT NULL,
    tariff_zone_code text CONSTRAINT account_statement_electricity_registe_tariff_zone_code_not_null NOT NULL,
    tariff_zone_name text CONSTRAINT account_statement_electricity_registe_tariff_zone_name_not_null NOT NULL,
    electricity_meter_serial_number text,
    electricity_meter_model text,
    previous_reading_uuid uuid,
    previous_reading_value numeric(14,3) DEFAULT NULL::numeric,
    previous_reading_taken_on date,
    current_reading_uuid uuid CONSTRAINT account_statement_electricity_reg_current_reading_uuid_not_null NOT NULL,
    current_reading_value numeric(14,3) CONSTRAINT account_statement_electricity_re_current_reading_value_not_null NOT NULL,
    current_reading_taken_on date CONSTRAINT account_statement_electricity_current_reading_taken_on_not_null NOT NULL,
    sort_order integer NOT NULL,
    CONSTRAINT chk_account_statement_electricity_registers_current_nonnegative CHECK ((current_reading_value >= (0)::numeric)),
    CONSTRAINT chk_account_statement_electricity_registers_previous_complete CHECK ((((previous_reading_uuid IS NULL) AND (previous_reading_value IS NULL) AND (previous_reading_taken_on IS NULL)) OR ((previous_reading_uuid IS NOT NULL) AND (previous_reading_value IS NOT NULL) AND (previous_reading_taken_on IS NOT NULL)))),
    CONSTRAINT chk_account_statement_electricity_registers_previous_nonnegativ CHECK (((previous_reading_value IS NULL) OR (previous_reading_value >= (0)::numeric))),
    CONSTRAINT chk_account_statement_electricity_registers_sort_order_positive CHECK ((sort_order > 0)),
    CONSTRAINT chk_account_statement_electricity_registers_zone_code_not_empty CHECK ((tariff_zone_code <> ''::text)),
    CONSTRAINT chk_account_statement_electricity_registers_zone_name_not_empty CHECK ((tariff_zone_name <> ''::text))
);
SQL_0039);

        $this->addSql(<<<'SQL_0040'
CREATE TABLE public.account_statement_payments (
    workspace_uuid uuid NOT NULL,
    account_statement_uuid uuid NOT NULL,
    payment_uuid uuid NOT NULL,
    amount numeric(14,2) NOT NULL,
    paid_on date NOT NULL,
    source public.payment_source NOT NULL,
    payer_name text,
    purpose text,
    sort_order integer NOT NULL,
    CONSTRAINT chk_account_statement_payments_amount_positive CHECK ((amount > (0)::numeric)),
    CONSTRAINT chk_account_statement_payments_sort_order_positive CHECK ((sort_order > 0))
);
SQL_0040);

        $this->addSql(<<<'SQL_0041'
CREATE TABLE public.account_statements (
    uuid uuid DEFAULT uuidv7() NOT NULL,
    workspace_uuid uuid NOT NULL,
    account_uuid uuid NOT NULL,
    number text NOT NULL,
    workspace_name text NOT NULL,
    account_number text NOT NULL,
    statement_date date NOT NULL,
    generated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    generated_by uuid,
    cancelled_at timestamp with time zone,
    cancelled_by uuid,
    cancellation_reason text,
    active_accrual_total numeric(14,2) NOT NULL,
    active_payment_total numeric(14,2) NOT NULL,
    balance_amount numeric(14,2) NOT NULL,
    amount_to_pay numeric(14,2) NOT NULL,
    overpayment_amount numeric(14,2) NOT NULL,
    payment_requisite_profile_uuid uuid,
    payment_recipient_name text,
    payment_recipient_inn text,
    payment_recipient_kpp text,
    payment_bank_name text,
    payment_bank_bik text,
    payment_bank_correspondent_account text,
    payment_bank_account text,
    payment_purpose text,
    billing_run_uuid uuid,
    CONSTRAINT chk_account_statements_account_number_not_empty CHECK ((account_number <> ''::text)),
    CONSTRAINT chk_account_statements_active_accrual_total_nonnegative CHECK ((active_accrual_total >= (0)::numeric)),
    CONSTRAINT chk_account_statements_active_payment_total_nonnegative CHECK ((active_payment_total >= (0)::numeric)),
    CONSTRAINT chk_account_statements_amount_to_pay_nonnegative CHECK ((amount_to_pay >= (0)::numeric)),
    CONSTRAINT chk_account_statements_cancellation_complete CHECK (((cancelled_at IS NULL) OR (cancellation_reason IS NOT NULL))),
    CONSTRAINT chk_account_statements_number_not_empty CHECK ((number <> ''::text)),
    CONSTRAINT chk_account_statements_overpayment_amount_nonnegative CHECK ((overpayment_amount >= (0)::numeric)),
    CONSTRAINT chk_account_statements_payment_requisite_fields_not_empty CHECK ((((payment_recipient_name IS NULL) OR (payment_recipient_name <> ''::text)) AND ((payment_recipient_inn IS NULL) OR (payment_recipient_inn <> ''::text)) AND ((payment_recipient_kpp IS NULL) OR (payment_recipient_kpp <> ''::text)) AND ((payment_bank_name IS NULL) OR (payment_bank_name <> ''::text)) AND ((payment_bank_bik IS NULL) OR (payment_bank_bik <> ''::text)) AND ((payment_bank_correspondent_account IS NULL) OR (payment_bank_correspondent_account <> ''::text)) AND ((payment_bank_account IS NULL) OR (payment_bank_account <> ''::text)) AND ((payment_purpose IS NULL) OR (payment_purpose <> ''::text)))),
    CONSTRAINT chk_account_statements_workspace_name_not_empty CHECK ((workspace_name <> ''::text))
);
SQL_0041);

        $this->addSql(<<<'SQL_0042'
CREATE TABLE public.accounts (
    uuid uuid DEFAULT uuidv7() NOT NULL,
    workspace_uuid uuid NOT NULL,
    number text NOT NULL,
    notes text,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    updated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    deleted_at timestamp with time zone,
    created_by uuid,
    updated_by uuid,
    deleted_by uuid
);
SQL_0042);

        $this->addSql(<<<'SQL_0043'
CREATE TABLE public.accruals (
    uuid uuid DEFAULT uuidv7() NOT NULL,
    workspace_uuid uuid NOT NULL,
    account_uuid uuid NOT NULL,
    billing_run_uuid uuid,
    type public.accrual_type NOT NULL,
    period_start date NOT NULL,
    period_end date NOT NULL,
    amount numeric(14,2) NOT NULL,
    posted_at timestamp with time zone,
    posted_by uuid,
    replacing_accrual_uuid uuid,
    replaced_at timestamp with time zone,
    replaced_by uuid,
    replacement_reason text,
    cancelled_at timestamp with time zone,
    cancelled_by uuid,
    cancellation_reason text,
    calculated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    calculation_version text,
    notes text,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    updated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    created_by uuid,
    updated_by uuid,
    CONSTRAINT chk_accruals_amount_nonnegative CHECK ((amount >= (0)::numeric)),
    CONSTRAINT chk_accruals_cancellation_complete CHECK (((cancelled_at IS NULL) OR (cancellation_reason IS NOT NULL))),
    CONSTRAINT chk_accruals_cancelled_not_replaced CHECK ((NOT ((cancelled_at IS NOT NULL) AND (replacing_accrual_uuid IS NOT NULL)))),
    CONSTRAINT chk_accruals_period CHECK ((period_end > period_start)),
    CONSTRAINT chk_accruals_posted_after_calculated CHECK (((posted_at IS NULL) OR (posted_at >= calculated_at))),
    CONSTRAINT chk_accruals_replacement_complete CHECK (((replacing_accrual_uuid IS NULL) OR ((replaced_at IS NOT NULL) AND (replacement_reason IS NOT NULL)))),
    CONSTRAINT chk_accruals_replacing_not_self CHECK (((replacing_accrual_uuid IS NULL) OR (replacing_accrual_uuid <> uuid)))
);
SQL_0043);

        $this->addSql(<<<'SQL_0044'
CREATE TABLE public.audit_logs (
    uuid uuid DEFAULT uuidv7() NOT NULL,
    workspace_uuid uuid,
    occurred_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    actor_user_uuid uuid,
    source public.audit_log_source DEFAULT 'app'::public.audit_log_source NOT NULL,
    db_user text,
    action text NOT NULL,
    entity_table text,
    entity_uuid uuid,
    entity_pk jsonb,
    old_values jsonb,
    new_values jsonb,
    changed_fields text[],
    reason text,
    request_id text,
    ip_address inet,
    user_agent text,
    CONSTRAINT chk_audit_logs_entity_reference CHECK (((entity_uuid IS NULL) OR (entity_pk IS NULL)))
);
SQL_0044);

        $this->addSql(<<<'SQL_0045'
CREATE TABLE public.billing_run_account_issues (
    uuid uuid DEFAULT uuidv7() NOT NULL,
    workspace_uuid uuid NOT NULL,
    billing_run_uuid uuid NOT NULL,
    account_uuid uuid NOT NULL,
    issue_type public.billing_run_account_issue_type NOT NULL,
    message text NOT NULL,
    closed_at timestamp with time zone,
    closed_by uuid,
    close_reason public.billing_run_account_issue_close_reason,
    close_comment text,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    updated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    created_by uuid,
    updated_by uuid,
    CONSTRAINT chk_billing_run_account_issues_close_complete CHECK ((((closed_at IS NULL) AND (closed_by IS NULL) AND (close_reason IS NULL)) OR ((closed_at IS NOT NULL) AND (close_reason IS NOT NULL))))
);
SQL_0045);

        $this->addSql(<<<'SQL_0046'
CREATE TABLE public.billing_runs (
    uuid uuid DEFAULT uuidv7() NOT NULL,
    workspace_uuid uuid NOT NULL,
    kind public.billing_run_kind NOT NULL,
    period_start date NOT NULL,
    period_end date NOT NULL,
    generated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    generated_by uuid,
    posted_at timestamp with time zone,
    posted_by uuid,
    cancelled_at timestamp with time zone,
    cancelled_by uuid,
    cancellation_reason text,
    accruals_generated_at timestamp with time zone,
    accruals_generated_by uuid,
    CONSTRAINT chk_billing_runs_accruals_generated_after_generated CHECK (((accruals_generated_at IS NULL) OR (accruals_generated_at >= generated_at))),
    CONSTRAINT chk_billing_runs_cancellation_complete CHECK (((cancelled_at IS NULL) OR (cancellation_reason IS NOT NULL))),
    CONSTRAINT chk_billing_runs_cancelled_after_generated CHECK (((cancelled_at IS NULL) OR (cancelled_at >= generated_at))),
    CONSTRAINT chk_billing_runs_not_posted_and_cancelled CHECK ((NOT ((posted_at IS NOT NULL) AND (cancelled_at IS NOT NULL)))),
    CONSTRAINT chk_billing_runs_period CHECK ((period_end > period_start)),
    CONSTRAINT chk_billing_runs_posted_after_accruals_generated CHECK (((posted_at IS NULL) OR ((accruals_generated_at IS NOT NULL) AND (posted_at >= accruals_generated_at)))),
    CONSTRAINT chk_billing_runs_posted_after_generated CHECK (((posted_at IS NULL) OR (posted_at >= generated_at)))
);
SQL_0046);

        $this->addSql(<<<'SQL_0047'
CREATE TABLE public.billing_settings (
    workspace_uuid uuid NOT NULL,
    association_name text NOT NULL,
    invoice_generation_day smallint DEFAULT 5 NOT NULL,
    reading_freshness_window_days integer DEFAULT 15 NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    updated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    created_by uuid,
    updated_by uuid,
    CONSTRAINT chk_billing_settings_association_name_not_empty CHECK ((association_name <> ''::text)),
    CONSTRAINT chk_billing_settings_invoice_generation_day CHECK (((invoice_generation_day >= 1) AND (invoice_generation_day <= 28))),
    CONSTRAINT chk_billing_settings_reading_freshness_window_days CHECK (((reading_freshness_window_days >= 1) AND (reading_freshness_window_days <= 60)))
);
SQL_0047);

        $this->addSql(<<<'SQL_0048'
CREATE TABLE public.electricity_accrual_contexts (
    workspace_uuid uuid NOT NULL,
    accrual_uuid uuid NOT NULL,
    electricity_meter_uuid uuid NOT NULL,
    tariff_profile_uuid uuid NOT NULL,
    tariff_period_uuid uuid NOT NULL,
    consumption_band_rule_uuid uuid CONSTRAINT electricity_accrual_context_consumption_band_rule_uuid_not_null NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL
);
SQL_0048);

        $this->addSql(<<<'SQL_0049'
CREATE TABLE public.electricity_accrual_lines (
    workspace_uuid uuid NOT NULL,
    accrual_uuid uuid NOT NULL,
    tariff_zone_uuid uuid NOT NULL,
    consumption_band_uuid uuid NOT NULL,
    consumption_kwh numeric(14,3) NOT NULL,
    rate numeric(12,6) NOT NULL,
    amount numeric(14,2) NOT NULL,
    CONSTRAINT chk_electricity_accrual_lines_amount_nonnegative CHECK ((amount >= (0)::numeric)),
    CONSTRAINT chk_electricity_accrual_lines_consumption_nonnegative CHECK ((consumption_kwh >= (0)::numeric)),
    CONSTRAINT chk_electricity_accrual_lines_rate_nonnegative CHECK ((rate >= (0)::numeric))
);
SQL_0049);

        $this->addSql(<<<'SQL_0050'
CREATE TABLE public.electricity_accrual_registers (
    workspace_uuid uuid NOT NULL,
    accrual_uuid uuid NOT NULL,
    electricity_meter_uuid uuid NOT NULL,
    tariff_zone_uuid uuid NOT NULL,
    previous_reading_uuid uuid,
    current_reading_uuid uuid NOT NULL,
    CONSTRAINT chk_electricity_accrual_registers_different_readings CHECK (((previous_reading_uuid IS NULL) OR (previous_reading_uuid <> current_reading_uuid)))
);
SQL_0050);

        $this->addSql(<<<'SQL_0051'
CREATE TABLE public.electricity_consumption_band_rule_account_scopes (
    workspace_uuid uuid CONSTRAINT electricity_consumption_band_rule_accou_workspace_uuid_not_null NOT NULL,
    rule_uuid uuid CONSTRAINT electricity_consumption_band_rule_account_sc_rule_uuid_not_null NOT NULL,
    account_uuid uuid CONSTRAINT electricity_consumption_band_rule_account_account_uuid_not_null NOT NULL,
    mode public.electricity_consumption_band_rule_scope_mode DEFAULT 'include'::public.electricity_consumption_band_rule_scope_mode NOT NULL
);
SQL_0051);

        $this->addSql(<<<'SQL_0052'
CREATE TABLE public.electricity_consumption_band_rule_all_scopes (
    workspace_uuid uuid CONSTRAINT electricity_consumption_band_rule_all_s_workspace_uuid_not_null NOT NULL,
    rule_uuid uuid NOT NULL,
    mode public.electricity_consumption_band_rule_scope_mode DEFAULT 'include'::public.electricity_consumption_band_rule_scope_mode NOT NULL
);
SQL_0052);

        $this->addSql(<<<'SQL_0053'
CREATE TABLE public.electricity_consumption_band_rule_group_scopes (
    workspace_uuid uuid CONSTRAINT electricity_consumption_band_rule_group_workspace_uuid_not_null NOT NULL,
    rule_uuid uuid CONSTRAINT electricity_consumption_band_rule_group_scop_rule_uuid_not_null NOT NULL,
    account_group_uuid uuid CONSTRAINT electricity_consumption_band_rule_g_account_group_uuid_not_null NOT NULL,
    mode public.electricity_consumption_band_rule_scope_mode DEFAULT 'include'::public.electricity_consumption_band_rule_scope_mode NOT NULL
);
SQL_0053);

        $this->addSql(<<<'SQL_0054'
CREATE TABLE public.electricity_consumption_band_rule_ranges (
    workspace_uuid uuid CONSTRAINT electricity_consumption_band_rule_range_workspace_uuid_not_null NOT NULL,
    rule_uuid uuid NOT NULL,
    consumption_band_uuid uuid CONSTRAINT electricity_consumption_band_rul_consumption_band_uuid_not_null NOT NULL,
    lower_bound_kwh numeric(14,3) CONSTRAINT electricity_consumption_band_rule_rang_lower_bound_kwh_not_null NOT NULL,
    upper_bound_kwh numeric(14,3) DEFAULT NULL::numeric,
    CONSTRAINT chk_electricity_consumption_band_rule_ranges_lower_nonnegative CHECK ((lower_bound_kwh >= (0)::numeric)),
    CONSTRAINT chk_electricity_consumption_band_rule_ranges_upper CHECK (((upper_bound_kwh IS NULL) OR (upper_bound_kwh > lower_bound_kwh)))
);
SQL_0054);

        $this->addSql(<<<'SQL_0055'
CREATE TABLE public.electricity_consumption_band_rules (
    uuid uuid DEFAULT uuidv7() NOT NULL,
    workspace_uuid uuid NOT NULL,
    tariff_profile_uuid uuid NOT NULL,
    valid_from date NOT NULL,
    valid_to date,
    month smallint NOT NULL,
    allocation_method public.electricity_consumption_band_allocation_method DEFAULT 'total_proportional'::public.electricity_consumption_band_allocation_method NOT NULL,
    priority integer DEFAULT 100 NOT NULL,
    source_document text,
    notes text,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    updated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    deleted_at timestamp with time zone,
    created_by uuid,
    updated_by uuid,
    deleted_by uuid,
    CONSTRAINT chk_electricity_consumption_band_rules_month CHECK (((month >= 1) AND (month <= 12))),
    CONSTRAINT chk_electricity_consumption_band_rules_valid_period CHECK (((valid_to IS NULL) OR (valid_to > valid_from)))
);
SQL_0055);

        $this->addSql(<<<'SQL_0056'
CREATE TABLE public.electricity_consumption_bands (
    uuid uuid DEFAULT uuidv7() NOT NULL,
    workspace_uuid uuid NOT NULL,
    code text NOT NULL,
    name text NOT NULL,
    description text,
    sort_order integer DEFAULT 100 NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    updated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    deleted_at timestamp with time zone,
    created_by uuid,
    updated_by uuid,
    deleted_by uuid
);
SQL_0056);

        $this->addSql(<<<'SQL_0057'
CREATE TABLE public.electricity_meter_readings (
    uuid uuid DEFAULT uuidv7() NOT NULL,
    workspace_uuid uuid NOT NULL,
    electricity_meter_uuid uuid NOT NULL,
    tariff_zone_uuid uuid NOT NULL,
    reading_value numeric(14,3) NOT NULL,
    taken_on date NOT NULL,
    submitted_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    source public.electricity_meter_reading_source NOT NULL,
    submitted_by uuid,
    provided_by_subscriber_uuid uuid,
    replacing_reading_uuid uuid,
    replaced_at timestamp with time zone,
    replaced_by uuid,
    replacement_reason text,
    cancelled_at timestamp with time zone,
    cancelled_by uuid,
    cancellation_reason text,
    notes text,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    updated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    created_by uuid,
    updated_by uuid,
    CONSTRAINT chk_electricity_meter_readings_cancellation_complete CHECK (((cancelled_at IS NULL) OR (cancellation_reason IS NOT NULL))),
    CONSTRAINT chk_electricity_meter_readings_cancelled_not_replaced CHECK ((NOT ((cancelled_at IS NOT NULL) AND (replacing_reading_uuid IS NOT NULL)))),
    CONSTRAINT chk_electricity_meter_readings_replacement_complete CHECK (((replacing_reading_uuid IS NULL) OR ((replaced_at IS NOT NULL) AND (replacement_reason IS NOT NULL)))),
    CONSTRAINT chk_electricity_meter_readings_replacing_not_self CHECK (((replacing_reading_uuid IS NULL) OR (replacing_reading_uuid <> uuid))),
    CONSTRAINT chk_electricity_meter_readings_value_nonnegative CHECK ((reading_value >= (0)::numeric))
);
SQL_0057);

        $this->addSql(<<<'SQL_0058'
CREATE TABLE public.electricity_meter_registers (
    workspace_uuid uuid NOT NULL,
    electricity_meter_uuid uuid NOT NULL,
    tariff_zone_uuid uuid NOT NULL
);
SQL_0058);

        $this->addSql(<<<'SQL_0059'
CREATE TABLE public.electricity_meters (
    uuid uuid DEFAULT uuidv7() NOT NULL,
    workspace_uuid uuid NOT NULL,
    account_uuid uuid NOT NULL,
    serial_number text,
    installed_on date NOT NULL,
    removed_on date,
    verified_on date,
    verification_valid_until date,
    notes text,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    updated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    deleted_at timestamp with time zone,
    created_by uuid,
    updated_by uuid,
    deleted_by uuid,
    model text,
    CONSTRAINT chk_electricity_meters_removed_after_installed CHECK (((removed_on IS NULL) OR (removed_on >= installed_on))),
    CONSTRAINT chk_electricity_meters_verification_period CHECK (((verification_valid_until IS NULL) OR (verified_on IS NULL) OR (verification_valid_until >= verified_on)))
);
SQL_0059);

        $this->addSql(<<<'SQL_0060'
CREATE TABLE public.electricity_tariff_periods (
    uuid uuid DEFAULT uuidv7() NOT NULL,
    workspace_uuid uuid NOT NULL,
    tariff_profile_uuid uuid NOT NULL,
    valid_from date NOT NULL,
    valid_to date,
    source_document text,
    notes text,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    updated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    deleted_at timestamp with time zone,
    created_by uuid,
    updated_by uuid,
    deleted_by uuid,
    CONSTRAINT chk_electricity_tariff_periods_valid_period CHECK (((valid_to IS NULL) OR (valid_to > valid_from)))
);
SQL_0060);

        $this->addSql(<<<'SQL_0061'
CREATE TABLE public.electricity_tariff_profiles (
    uuid uuid DEFAULT uuidv7() NOT NULL,
    workspace_uuid uuid NOT NULL,
    code text NOT NULL,
    name text NOT NULL,
    description text,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    updated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    deleted_at timestamp with time zone,
    created_by uuid,
    updated_by uuid,
    deleted_by uuid
);
SQL_0061);

        $this->addSql(<<<'SQL_0062'
CREATE TABLE public.electricity_tariff_rates (
    workspace_uuid uuid NOT NULL,
    tariff_period_uuid uuid NOT NULL,
    tariff_zone_uuid uuid NOT NULL,
    consumption_band_uuid uuid NOT NULL,
    rate numeric(12,6) NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    updated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    created_by uuid,
    updated_by uuid,
    CONSTRAINT chk_electricity_tariff_rates_rate_nonnegative CHECK ((rate >= (0)::numeric))
);
SQL_0062);

        $this->addSql(<<<'SQL_0063'
CREATE TABLE public.electricity_tariff_zones (
    uuid uuid DEFAULT uuidv7() NOT NULL,
    workspace_uuid uuid NOT NULL,
    code text NOT NULL,
    name text NOT NULL,
    description text,
    sort_order integer DEFAULT 100 NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    updated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    deleted_at timestamp with time zone,
    created_by uuid,
    updated_by uuid,
    deleted_by uuid
);
SQL_0063);

        $this->addSql(<<<'SQL_0064'
CREATE TABLE public.payment_requisite_assignments (
    uuid uuid DEFAULT uuidv7() NOT NULL,
    workspace_uuid uuid NOT NULL,
    payment_requisite_profile_uuid uuid CONSTRAINT payment_requisite_assignmen_payment_requisite_profile__not_null NOT NULL,
    accrual_type public.accrual_type,
    valid_from date NOT NULL,
    valid_to date,
    assigned_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    assigned_by uuid,
    closed_at timestamp with time zone,
    closed_by uuid,
    close_reason text,
    CONSTRAINT chk_payment_requisite_assignments_close_complete CHECK ((((closed_at IS NULL) AND (close_reason IS NULL)) OR ((closed_at IS NOT NULL) AND (close_reason IS NOT NULL)))),
    CONSTRAINT chk_payment_requisite_assignments_validity CHECK (((valid_to IS NULL) OR (valid_to > valid_from)))
);
SQL_0064);

        $this->addSql(<<<'SQL_0065'
CREATE TABLE public.payment_requisite_profiles (
    uuid uuid DEFAULT uuidv7() NOT NULL,
    workspace_uuid uuid NOT NULL,
    code text NOT NULL,
    name text NOT NULL,
    recipient_name text NOT NULL,
    recipient_inn text,
    recipient_kpp text,
    bank_name text NOT NULL,
    bank_bik text NOT NULL,
    bank_correspondent_account text,
    bank_account text NOT NULL,
    payment_purpose_template text,
    valid_from date NOT NULL,
    valid_to date,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    updated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    deleted_at timestamp with time zone,
    created_by uuid,
    updated_by uuid,
    deleted_by uuid,
    CONSTRAINT chk_payment_requisite_profiles_bank_account_not_empty CHECK ((bank_account <> ''::text)),
    CONSTRAINT chk_payment_requisite_profiles_bank_bik_not_empty CHECK ((bank_bik <> ''::text)),
    CONSTRAINT chk_payment_requisite_profiles_bank_name_not_empty CHECK ((bank_name <> ''::text)),
    CONSTRAINT chk_payment_requisite_profiles_code_not_empty CHECK ((code <> ''::text)),
    CONSTRAINT chk_payment_requisite_profiles_name_not_empty CHECK ((name <> ''::text)),
    CONSTRAINT chk_payment_requisite_profiles_optional_fields_not_empty CHECK ((((recipient_inn IS NULL) OR (recipient_inn <> ''::text)) AND ((recipient_kpp IS NULL) OR (recipient_kpp <> ''::text)) AND ((bank_correspondent_account IS NULL) OR (bank_correspondent_account <> ''::text)) AND ((payment_purpose_template IS NULL) OR (payment_purpose_template <> ''::text)))),
    CONSTRAINT chk_payment_requisite_profiles_recipient_name_not_empty CHECK ((recipient_name <> ''::text)),
    CONSTRAINT chk_payment_requisite_profiles_validity CHECK (((valid_to IS NULL) OR (valid_to > valid_from)))
);
SQL_0065);

        $this->addSql(<<<'SQL_0066'
CREATE TABLE public.payments (
    uuid uuid DEFAULT uuidv7() NOT NULL,
    workspace_uuid uuid NOT NULL,
    account_uuid uuid NOT NULL,
    amount numeric(14,2) NOT NULL,
    paid_on date NOT NULL,
    paid_at timestamp with time zone,
    source public.payment_source DEFAULT 'manual'::public.payment_source NOT NULL,
    payer_name text,
    purpose text,
    external_reference text,
    replacing_payment_uuid uuid,
    replaced_at timestamp with time zone,
    replaced_by uuid,
    replacement_reason text,
    cancelled_at timestamp with time zone,
    cancelled_by uuid,
    cancellation_reason text,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    updated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    created_by uuid,
    updated_by uuid,
    CONSTRAINT chk_payments_amount_positive CHECK ((amount > (0)::numeric)),
    CONSTRAINT chk_payments_cancellation_complete CHECK (((cancelled_at IS NULL) OR (cancellation_reason IS NOT NULL))),
    CONSTRAINT chk_payments_cancelled_not_replaced CHECK ((NOT ((cancelled_at IS NOT NULL) AND (replacing_payment_uuid IS NOT NULL)))),
    CONSTRAINT chk_payments_paid_at_not_before_paid_on CHECK (((paid_at IS NULL) OR ((paid_at)::date >= paid_on))),
    CONSTRAINT chk_payments_replacement_complete CHECK (((replacing_payment_uuid IS NULL) OR ((replaced_at IS NOT NULL) AND (replacement_reason IS NOT NULL)))),
    CONSTRAINT chk_payments_replacing_not_self CHECK (((replacing_payment_uuid IS NULL) OR (replacing_payment_uuid <> uuid)))
);
SQL_0066);

        $this->addSql(<<<'SQL_0067'
CREATE TABLE public.subscriber_account_accesses (
    workspace_uuid uuid NOT NULL,
    subscriber_uuid uuid NOT NULL,
    account_uuid uuid NOT NULL,
    access_role public.subscriber_account_access_role DEFAULT 'owner'::public.subscriber_account_access_role NOT NULL,
    granted_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    granted_by uuid,
    revoked_at timestamp with time zone,
    revoked_by uuid,
    revoked_reason text,
    notes text,
    CONSTRAINT chk_subscriber_account_accesses_revoked_after_granted CHECK (((revoked_at IS NULL) OR (revoked_at >= granted_at)))
);
SQL_0067);

        $this->addSql(<<<'SQL_0068'
CREATE TABLE public.subscribers (
    uuid uuid DEFAULT uuidv7() NOT NULL,
    workspace_uuid uuid NOT NULL,
    user_uuid uuid,
    last_name text NOT NULL,
    first_name text NOT NULL,
    second_name text,
    contact_email text,
    contact_phone text,
    notes text,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    updated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    deleted_at timestamp with time zone,
    created_by uuid,
    updated_by uuid,
    deleted_by uuid
);
SQL_0068);

        $this->addSql(<<<'SQL_0069'
CREATE TABLE public.user_email_identities (
    user_uuid uuid NOT NULL,
    email text NOT NULL,
    email_normalized text NOT NULL,
    verified_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    deleted_at timestamp with time zone,
    created_by uuid,
    deleted_by uuid,
    CONSTRAINT chk_user_email_identities_email_normalized_not_empty CHECK ((email_normalized <> ''::text)),
    CONSTRAINT chk_user_email_identities_email_not_empty CHECK ((email <> ''::text))
);
SQL_0069);

        $this->addSql(<<<'SQL_0070'
CREATE TABLE public.user_password_credentials (
    user_uuid uuid NOT NULL,
    password_hash text NOT NULL,
    changed_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    expires_at timestamp with time zone,
    CONSTRAINT chk_user_password_credentials_password_hash_not_empty CHECK ((password_hash <> ''::text))
);
SQL_0070);

        $this->addSql(<<<'SQL_0071'
CREATE TABLE public.user_password_history (
    user_uuid uuid NOT NULL,
    password_hash text NOT NULL,
    changed_at timestamp with time zone NOT NULL,
    changed_by uuid,
    CONSTRAINT chk_user_password_history_password_hash_not_empty CHECK ((password_hash <> ''::text))
);
SQL_0071);

        $this->addSql(<<<'SQL_0072'
CREATE TABLE public.users (
    uuid uuid DEFAULT uuidv7() NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    updated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    approved_at timestamp with time zone,
    approved_by uuid,
    blocked_at timestamp with time zone,
    blocked_reason text,
    blocked_by uuid,
    deleted_at timestamp with time zone,
    deleted_by uuid,
    created_by uuid,
    updated_by uuid,
    admin_granted_at timestamp with time zone,
    admin_granted_by uuid,
    admin_revoked_at timestamp with time zone,
    admin_revoked_by uuid,
    admin_revoked_reason text,
    CONSTRAINT chk_users_admin_revoked_after_granted CHECK (((admin_revoked_at IS NULL) OR ((admin_granted_at IS NOT NULL) AND (admin_revoked_at >= admin_granted_at))))
);
SQL_0072);

        $this->addSql(<<<'SQL_0073'
CREATE TABLE public.workspace_user_role_assignments (
    uuid uuid DEFAULT uuidv7() NOT NULL,
    workspace_uuid uuid NOT NULL,
    user_uuid uuid NOT NULL,
    role_code public.workspace_user_role_code NOT NULL,
    granted_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    granted_by uuid,
    revoked_at timestamp with time zone,
    revoked_by uuid,
    revoked_reason text,
    CONSTRAINT chk_workspace_user_role_assignments_revoked_after_granted CHECK (((revoked_at IS NULL) OR (revoked_at >= granted_at)))
);
SQL_0073);

        $this->addSql(<<<'SQL_0074'
CREATE TABLE public.workspaces (
    uuid uuid DEFAULT uuidv7() NOT NULL,
    code text NOT NULL,
    name text NOT NULL,
    description text,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    updated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    created_by uuid,
    updated_by uuid,
    timezone text DEFAULT 'Europe/Moscow'::text NOT NULL,
    CONSTRAINT chk_workspaces_code_not_empty CHECK ((code <> ''::text)),
    CONSTRAINT chk_workspaces_name_not_empty CHECK ((name <> ''::text)),
    CONSTRAINT chk_workspaces_timezone_not_empty CHECK ((timezone <> ''::text))
);
SQL_0074);

        $this->addSql(<<<'SQL_0075'
CREATE TABLE public.zavety_michurina_statement_import_batches (
    uuid uuid DEFAULT uuidv7() NOT NULL,
    workspace_uuid uuid CONSTRAINT zavety_michurina_statement_import_batch_workspace_uuid_not_null NOT NULL,
    name text,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    updated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    created_by uuid,
    updated_by uuid,
    CONSTRAINT chk_zm_statement_import_batches_name_not_empty CHECK (((name IS NULL) OR (name <> ''::text)))
);
SQL_0075);

        $this->addSql(<<<'SQL_0076'
CREATE TABLE public.zavety_michurina_statement_import_files (
    uuid uuid DEFAULT uuidv7() NOT NULL,
    workspace_uuid uuid NOT NULL,
    batch_uuid uuid NOT NULL,
    original_filename text CONSTRAINT zavety_michurina_statement_import_fi_original_filename_not_null NOT NULL,
    storage_key text,
    source_sha256 text,
    file_size_bytes integer,
    parser_version text DEFAULT 'zavety_michurina_pdf_v1'::text NOT NULL,
    status public.zavety_michurina_statement_import_file_status DEFAULT 'pending'::public.zavety_michurina_statement_import_file_status NOT NULL,
    parsed_result jsonb,
    parse_error text,
    detected_account_number text,
    detected_subscriber_full_name text,
    parsed_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    updated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    created_by uuid,
    updated_by uuid,
    CONSTRAINT chk_zm_statement_import_files_failed_shape CHECK (((status <> 'failed'::public.zavety_michurina_statement_import_file_status) OR (parse_error IS NOT NULL))),
    CONSTRAINT chk_zm_statement_import_files_file_size CHECK (((file_size_bytes IS NULL) OR (file_size_bytes >= 0))),
    CONSTRAINT chk_zm_statement_import_files_original_filename_not_empty CHECK ((original_filename <> ''::text)),
    CONSTRAINT chk_zm_statement_import_files_parsed_shape CHECK (((status <> 'parsed'::public.zavety_michurina_statement_import_file_status) OR ((parsed_result IS NOT NULL) AND (parsed_at IS NOT NULL) AND (parse_error IS NULL)))),
    CONSTRAINT chk_zm_statement_import_files_parser_version_not_empty CHECK ((parser_version <> ''::text)),
    CONSTRAINT chk_zm_statement_import_files_source_sha256 CHECK (((source_sha256 IS NULL) OR (source_sha256 ~ '^[a-f0-9]{64}$'::text))),
    CONSTRAINT chk_zm_statement_import_files_storage_key_not_empty CHECK (((storage_key IS NULL) OR (storage_key <> ''::text)))
);
SQL_0076);

        $this->addSql(<<<'SQL_0077'
ALTER TABLE ONLY public.account_electricity_tariff_profile_assignments
    ADD CONSTRAINT account_electricity_tariff_profile_assignments_pkey PRIMARY KEY (workspace_uuid, account_uuid, valid_from);
SQL_0077);

        $this->addSql(<<<'SQL_0078'
ALTER TABLE ONLY public.account_group_members
    ADD CONSTRAINT account_group_members_pkey PRIMARY KEY (workspace_uuid, account_group_uuid, account_uuid, valid_from);
SQL_0078);

        $this->addSql(<<<'SQL_0079'
ALTER TABLE ONLY public.account_groups
    ADD CONSTRAINT account_groups_pkey PRIMARY KEY (uuid);
SQL_0079);

        $this->addSql(<<<'SQL_0080'
ALTER TABLE ONLY public.account_statement_accruals
    ADD CONSTRAINT account_statement_accruals_pkey PRIMARY KEY (workspace_uuid, account_statement_uuid, accrual_uuid);
SQL_0080);

        $this->addSql(<<<'SQL_0081'
ALTER TABLE ONLY public.account_statement_deliveries
    ADD CONSTRAINT account_statement_deliveries_pkey PRIMARY KEY (uuid);
SQL_0081);

        $this->addSql(<<<'SQL_0082'
ALTER TABLE ONLY public.account_statement_delivery_attempts
    ADD CONSTRAINT account_statement_delivery_attempts_pkey PRIMARY KEY (workspace_uuid, delivery_uuid, attempt_number);
SQL_0082);

        $this->addSql(<<<'SQL_0083'
ALTER TABLE ONLY public.account_statement_electricity_lines
    ADD CONSTRAINT account_statement_electricity_lines_pkey PRIMARY KEY (workspace_uuid, account_statement_uuid, accrual_uuid, tariff_zone_uuid, consumption_band_uuid);
SQL_0083);

        $this->addSql(<<<'SQL_0084'
ALTER TABLE ONLY public.account_statement_electricity_registers
    ADD CONSTRAINT account_statement_electricity_registers_pkey PRIMARY KEY (workspace_uuid, account_statement_uuid, accrual_uuid, electricity_meter_uuid, tariff_zone_uuid);
SQL_0084);

        $this->addSql(<<<'SQL_0085'
ALTER TABLE ONLY public.account_statement_payments
    ADD CONSTRAINT account_statement_payments_pkey PRIMARY KEY (workspace_uuid, account_statement_uuid, payment_uuid);
SQL_0085);

        $this->addSql(<<<'SQL_0086'
ALTER TABLE ONLY public.account_statements
    ADD CONSTRAINT account_statements_pkey PRIMARY KEY (uuid);
SQL_0086);

        $this->addSql(<<<'SQL_0087'
ALTER TABLE ONLY public.accounts
    ADD CONSTRAINT accounts_pkey PRIMARY KEY (uuid);
SQL_0087);

        $this->addSql(<<<'SQL_0088'
ALTER TABLE ONLY public.accruals
    ADD CONSTRAINT accruals_pkey PRIMARY KEY (uuid);
SQL_0088);

        $this->addSql(<<<'SQL_0089'
ALTER TABLE ONLY public.audit_logs
    ADD CONSTRAINT audit_logs_pkey PRIMARY KEY (uuid);
SQL_0089);

        $this->addSql(<<<'SQL_0090'
ALTER TABLE ONLY public.billing_run_account_issues
    ADD CONSTRAINT billing_run_account_issues_pkey PRIMARY KEY (uuid);
SQL_0090);

        $this->addSql(<<<'SQL_0091'
ALTER TABLE ONLY public.billing_runs
    ADD CONSTRAINT billing_runs_pkey PRIMARY KEY (uuid);
SQL_0091);

        $this->addSql(<<<'SQL_0092'
ALTER TABLE ONLY public.billing_settings
    ADD CONSTRAINT billing_settings_pkey PRIMARY KEY (workspace_uuid);
SQL_0092);

        $this->addSql(<<<'SQL_0093'
ALTER TABLE ONLY public.electricity_accrual_contexts
    ADD CONSTRAINT electricity_accrual_contexts_pkey PRIMARY KEY (workspace_uuid, accrual_uuid);
SQL_0093);

        $this->addSql(<<<'SQL_0094'
ALTER TABLE ONLY public.electricity_accrual_lines
    ADD CONSTRAINT electricity_accrual_lines_pkey PRIMARY KEY (workspace_uuid, accrual_uuid, tariff_zone_uuid, consumption_band_uuid);
SQL_0094);

        $this->addSql(<<<'SQL_0095'
ALTER TABLE ONLY public.electricity_accrual_registers
    ADD CONSTRAINT electricity_accrual_registers_pkey PRIMARY KEY (workspace_uuid, accrual_uuid, electricity_meter_uuid, tariff_zone_uuid);
SQL_0095);

        $this->addSql(<<<'SQL_0096'
ALTER TABLE ONLY public.electricity_consumption_band_rule_account_scopes
    ADD CONSTRAINT electricity_consumption_band_rule_account_scopes_pkey PRIMARY KEY (workspace_uuid, rule_uuid, account_uuid);
SQL_0096);

        $this->addSql(<<<'SQL_0097'
ALTER TABLE ONLY public.electricity_consumption_band_rule_all_scopes
    ADD CONSTRAINT electricity_consumption_band_rule_all_scopes_pkey PRIMARY KEY (workspace_uuid, rule_uuid);
SQL_0097);

        $this->addSql(<<<'SQL_0098'
ALTER TABLE ONLY public.electricity_consumption_band_rule_group_scopes
    ADD CONSTRAINT electricity_consumption_band_rule_group_scopes_pkey PRIMARY KEY (workspace_uuid, rule_uuid, account_group_uuid);
SQL_0098);

        $this->addSql(<<<'SQL_0099'
ALTER TABLE ONLY public.electricity_consumption_band_rule_ranges
    ADD CONSTRAINT electricity_consumption_band_rule_ranges_pkey PRIMARY KEY (workspace_uuid, rule_uuid, consumption_band_uuid);
SQL_0099);

        $this->addSql(<<<'SQL_0100'
ALTER TABLE ONLY public.electricity_consumption_band_rules
    ADD CONSTRAINT electricity_consumption_band_rules_pkey PRIMARY KEY (uuid);
SQL_0100);

        $this->addSql(<<<'SQL_0101'
ALTER TABLE ONLY public.electricity_consumption_bands
    ADD CONSTRAINT electricity_consumption_bands_pkey PRIMARY KEY (uuid);
SQL_0101);

        $this->addSql(<<<'SQL_0102'
ALTER TABLE ONLY public.electricity_meter_readings
    ADD CONSTRAINT electricity_meter_readings_pkey PRIMARY KEY (uuid);
SQL_0102);

        $this->addSql(<<<'SQL_0103'
ALTER TABLE ONLY public.electricity_meter_registers
    ADD CONSTRAINT electricity_meter_registers_pkey PRIMARY KEY (workspace_uuid, electricity_meter_uuid, tariff_zone_uuid);
SQL_0103);

        $this->addSql(<<<'SQL_0104'
ALTER TABLE ONLY public.electricity_meters
    ADD CONSTRAINT electricity_meters_pkey PRIMARY KEY (uuid);
SQL_0104);

        $this->addSql(<<<'SQL_0105'
ALTER TABLE ONLY public.electricity_tariff_periods
    ADD CONSTRAINT electricity_tariff_periods_pkey PRIMARY KEY (uuid);
SQL_0105);

        $this->addSql(<<<'SQL_0106'
ALTER TABLE ONLY public.electricity_tariff_profiles
    ADD CONSTRAINT electricity_tariff_profiles_pkey PRIMARY KEY (uuid);
SQL_0106);

        $this->addSql(<<<'SQL_0107'
ALTER TABLE ONLY public.electricity_tariff_rates
    ADD CONSTRAINT electricity_tariff_rates_pkey PRIMARY KEY (workspace_uuid, tariff_period_uuid, tariff_zone_uuid, consumption_band_uuid);
SQL_0107);

        $this->addSql(<<<'SQL_0108'
ALTER TABLE ONLY public.electricity_tariff_zones
    ADD CONSTRAINT electricity_tariff_zones_pkey PRIMARY KEY (uuid);
SQL_0108);

        $this->addSql(<<<'SQL_0109'
ALTER TABLE ONLY public.account_electricity_tariff_profile_assignments
    ADD CONSTRAINT ex_account_electricity_tariff_profile_assignments_no_overlap EXCLUDE USING gist (workspace_uuid WITH =, account_uuid WITH =, daterange(valid_from, COALESCE(valid_to, 'infinity'::date), '[)'::text) WITH &&);
SQL_0109);

        $this->addSql(<<<'SQL_0110'
ALTER TABLE ONLY public.electricity_consumption_band_rule_ranges
    ADD CONSTRAINT ex_electricity_consumption_band_rule_ranges_no_overlap EXCLUDE USING gist (workspace_uuid WITH =, rule_uuid WITH =, numrange(lower_bound_kwh, upper_bound_kwh, '[)'::text) WITH &&);
SQL_0110);

        $this->addSql(<<<'SQL_0111'
ALTER TABLE ONLY public.electricity_tariff_periods
    ADD CONSTRAINT ex_electricity_tariff_periods_no_overlap EXCLUDE USING gist (workspace_uuid WITH =, tariff_profile_uuid WITH =, daterange(valid_from, COALESCE(valid_to, 'infinity'::date), '[)'::text) WITH &&) WHERE ((deleted_at IS NULL));
SQL_0111);

        $this->addSql(<<<'SQL_0112'
ALTER TABLE ONLY public.payment_requisite_assignments
    ADD CONSTRAINT payment_requisite_assignments_pkey PRIMARY KEY (uuid);
SQL_0112);

        $this->addSql(<<<'SQL_0113'
ALTER TABLE ONLY public.payment_requisite_profiles
    ADD CONSTRAINT payment_requisite_profiles_pkey PRIMARY KEY (uuid);
SQL_0113);

        $this->addSql(<<<'SQL_0114'
ALTER TABLE ONLY public.payments
    ADD CONSTRAINT payments_pkey PRIMARY KEY (uuid);
SQL_0114);

        $this->addSql(<<<'SQL_0115'
ALTER TABLE ONLY public.subscriber_account_accesses
    ADD CONSTRAINT subscriber_account_accesses_pkey PRIMARY KEY (workspace_uuid, subscriber_uuid, account_uuid, granted_at);
SQL_0115);

        $this->addSql(<<<'SQL_0116'
ALTER TABLE ONLY public.subscribers
    ADD CONSTRAINT subscribers_pkey PRIMARY KEY (uuid);
SQL_0116);

        $this->addSql(<<<'SQL_0117'
ALTER TABLE ONLY public.account_groups
    ADD CONSTRAINT uq_account_groups_workspace_uuid UNIQUE (workspace_uuid, uuid);
SQL_0117);

        $this->addSql(<<<'SQL_0118'
ALTER TABLE ONLY public.account_statement_deliveries
    ADD CONSTRAINT uq_account_statement_deliveries_workspace_uuid UNIQUE (workspace_uuid, uuid);
SQL_0118);

        $this->addSql(<<<'SQL_0119'
ALTER TABLE ONLY public.account_statements
    ADD CONSTRAINT uq_account_statements_workspace_uuid UNIQUE (workspace_uuid, uuid);
SQL_0119);

        $this->addSql(<<<'SQL_0120'
ALTER TABLE ONLY public.accounts
    ADD CONSTRAINT uq_accounts_workspace_uuid UNIQUE (workspace_uuid, uuid);
SQL_0120);

        $this->addSql(<<<'SQL_0121'
ALTER TABLE ONLY public.accruals
    ADD CONSTRAINT uq_accruals_workspace_uuid UNIQUE (workspace_uuid, uuid);
SQL_0121);

        $this->addSql(<<<'SQL_0122'
ALTER TABLE ONLY public.billing_run_account_issues
    ADD CONSTRAINT uq_billing_run_account_issues_workspace_uuid UNIQUE (workspace_uuid, uuid);
SQL_0122);

        $this->addSql(<<<'SQL_0123'
ALTER TABLE ONLY public.billing_runs
    ADD CONSTRAINT uq_billing_runs_workspace_uuid UNIQUE (workspace_uuid, uuid);
SQL_0123);

        $this->addSql(<<<'SQL_0124'
ALTER TABLE ONLY public.electricity_consumption_band_rules
    ADD CONSTRAINT uq_electricity_consumption_band_rules_workspace_uuid UNIQUE (workspace_uuid, uuid);
SQL_0124);

        $this->addSql(<<<'SQL_0125'
ALTER TABLE ONLY public.electricity_consumption_bands
    ADD CONSTRAINT uq_electricity_consumption_bands_workspace_uuid UNIQUE (workspace_uuid, uuid);
SQL_0125);

        $this->addSql(<<<'SQL_0126'
ALTER TABLE ONLY public.electricity_meter_readings
    ADD CONSTRAINT uq_electricity_meter_readings_uuid_meter_zone UNIQUE (workspace_uuid, uuid, electricity_meter_uuid, tariff_zone_uuid);
SQL_0126);

        $this->addSql(<<<'SQL_0127'
ALTER TABLE ONLY public.electricity_meter_readings
    ADD CONSTRAINT uq_electricity_meter_readings_workspace_uuid UNIQUE (workspace_uuid, uuid);
SQL_0127);

        $this->addSql(<<<'SQL_0128'
ALTER TABLE ONLY public.electricity_meters
    ADD CONSTRAINT uq_electricity_meters_workspace_uuid UNIQUE (workspace_uuid, uuid);
SQL_0128);

        $this->addSql(<<<'SQL_0129'
ALTER TABLE ONLY public.electricity_tariff_periods
    ADD CONSTRAINT uq_electricity_tariff_periods_workspace_uuid UNIQUE (workspace_uuid, uuid);
SQL_0129);

        $this->addSql(<<<'SQL_0130'
ALTER TABLE ONLY public.electricity_tariff_profiles
    ADD CONSTRAINT uq_electricity_tariff_profiles_workspace_uuid UNIQUE (workspace_uuid, uuid);
SQL_0130);

        $this->addSql(<<<'SQL_0131'
ALTER TABLE ONLY public.electricity_tariff_zones
    ADD CONSTRAINT uq_electricity_tariff_zones_workspace_uuid UNIQUE (workspace_uuid, uuid);
SQL_0131);

        $this->addSql(<<<'SQL_0132'
ALTER TABLE ONLY public.payment_requisite_assignments
    ADD CONSTRAINT uq_payment_requisite_assignments_workspace_uuid UNIQUE (workspace_uuid, uuid);
SQL_0132);

        $this->addSql(<<<'SQL_0133'
ALTER TABLE ONLY public.payment_requisite_profiles
    ADD CONSTRAINT uq_payment_requisite_profiles_workspace_uuid UNIQUE (workspace_uuid, uuid);
SQL_0133);

        $this->addSql(<<<'SQL_0134'
ALTER TABLE ONLY public.payments
    ADD CONSTRAINT uq_payments_workspace_uuid UNIQUE (workspace_uuid, uuid);
SQL_0134);

        $this->addSql(<<<'SQL_0135'
ALTER TABLE ONLY public.subscribers
    ADD CONSTRAINT uq_subscribers_workspace_uuid UNIQUE (workspace_uuid, uuid);
SQL_0135);

        $this->addSql(<<<'SQL_0136'
ALTER TABLE ONLY public.user_email_identities
    ADD CONSTRAINT user_email_identities_pkey PRIMARY KEY (user_uuid, email_normalized);
SQL_0136);

        $this->addSql(<<<'SQL_0137'
ALTER TABLE ONLY public.user_password_credentials
    ADD CONSTRAINT user_password_credentials_pkey PRIMARY KEY (user_uuid);
SQL_0137);

        $this->addSql(<<<'SQL_0138'
ALTER TABLE ONLY public.user_password_history
    ADD CONSTRAINT user_password_history_pkey PRIMARY KEY (user_uuid, changed_at);
SQL_0138);

        $this->addSql(<<<'SQL_0139'
ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (uuid);
SQL_0139);

        $this->addSql(<<<'SQL_0140'
ALTER TABLE ONLY public.workspace_user_role_assignments
    ADD CONSTRAINT workspace_user_role_assignments_pkey PRIMARY KEY (uuid);
SQL_0140);

        $this->addSql(<<<'SQL_0141'
ALTER TABLE ONLY public.workspaces
    ADD CONSTRAINT workspaces_pkey PRIMARY KEY (uuid);
SQL_0141);

        $this->addSql(<<<'SQL_0142'
ALTER TABLE ONLY public.zavety_michurina_statement_import_batches
    ADD CONSTRAINT zavety_michurina_statement_import_batches_pkey PRIMARY KEY (uuid);
SQL_0142);

        $this->addSql(<<<'SQL_0143'
ALTER TABLE ONLY public.zavety_michurina_statement_import_files
    ADD CONSTRAINT zavety_michurina_statement_import_files_pkey PRIMARY KEY (uuid);
SQL_0143);

        $this->addSql(<<<'SQL_0144'
CREATE INDEX ix_account_electricity_tariff_profile_assignments_profile ON public.account_electricity_tariff_profile_assignments USING btree (workspace_uuid, tariff_profile_uuid, valid_from, valid_to);
SQL_0144);

        $this->addSql(<<<'SQL_0145'
CREATE INDEX ix_account_group_members_account ON public.account_group_members USING btree (workspace_uuid, account_uuid, valid_from, valid_to);
SQL_0145);

        $this->addSql(<<<'SQL_0146'
CREATE INDEX ix_account_statement_accruals_accrual ON public.account_statement_accruals USING btree (workspace_uuid, accrual_uuid);
SQL_0146);

        $this->addSql(<<<'SQL_0147'
CREATE INDEX ix_account_statement_deliveries_statement ON public.account_statement_deliveries USING btree (workspace_uuid, account_statement_uuid, created_at);
SQL_0147);

        $this->addSql(<<<'SQL_0148'
CREATE INDEX ix_account_statement_deliveries_subscriber ON public.account_statement_deliveries USING btree (workspace_uuid, recipient_subscriber_uuid) WHERE (recipient_subscriber_uuid IS NOT NULL);
SQL_0148);

        $this->addSql(<<<'SQL_0149'
CREATE INDEX ix_account_statement_delivery_attempts_finished ON public.account_statement_delivery_attempts USING btree (workspace_uuid, succeeded_at, failed_at);
SQL_0149);

        $this->addSql(<<<'SQL_0150'
CREATE INDEX ix_account_statement_delivery_attempts_queued ON public.account_statement_delivery_attempts USING btree (workspace_uuid, queued_at) WHERE ((started_at IS NULL) AND (succeeded_at IS NULL) AND (failed_at IS NULL));
SQL_0150);

        $this->addSql(<<<'SQL_0151'
CREATE INDEX ix_account_statement_electricity_registers_readings ON public.account_statement_electricity_registers USING btree (workspace_uuid, previous_reading_uuid, current_reading_uuid);
SQL_0151);

        $this->addSql(<<<'SQL_0152'
CREATE INDEX ix_account_statement_payments_payment ON public.account_statement_payments USING btree (workspace_uuid, payment_uuid);
SQL_0152);

        $this->addSql(<<<'SQL_0153'
CREATE INDEX ix_account_statements_account_generated ON public.account_statements USING btree (workspace_uuid, account_uuid, generated_at);
SQL_0153);

        $this->addSql(<<<'SQL_0154'
CREATE INDEX ix_account_statements_billing_run ON public.account_statements USING btree (workspace_uuid, billing_run_uuid, generated_at);
SQL_0154);

        $this->addSql(<<<'SQL_0155'
CREATE INDEX ix_accruals_account_period ON public.accruals USING btree (workspace_uuid, account_uuid, period_start, period_end);
SQL_0155);

        $this->addSql(<<<'SQL_0156'
CREATE INDEX ix_audit_logs_actor_user ON public.audit_logs USING btree (actor_user_uuid, occurred_at) WHERE (actor_user_uuid IS NOT NULL);
SQL_0156);

        $this->addSql(<<<'SQL_0157'
CREATE INDEX ix_audit_logs_entity_uuid ON public.audit_logs USING btree (entity_table, entity_uuid, occurred_at) WHERE (entity_uuid IS NOT NULL);
SQL_0157);

        $this->addSql(<<<'SQL_0158'
CREATE INDEX ix_audit_logs_request ON public.audit_logs USING btree (request_id) WHERE (request_id IS NOT NULL);
SQL_0158);

        $this->addSql(<<<'SQL_0159'
CREATE INDEX ix_audit_logs_workspace_occurred ON public.audit_logs USING btree (workspace_uuid, occurred_at) WHERE (workspace_uuid IS NOT NULL);
SQL_0159);

        $this->addSql(<<<'SQL_0160'
CREATE INDEX ix_billing_run_account_issues_account ON public.billing_run_account_issues USING btree (workspace_uuid, account_uuid, created_at);
SQL_0160);

        $this->addSql(<<<'SQL_0161'
CREATE INDEX ix_billing_run_account_issues_run ON public.billing_run_account_issues USING btree (workspace_uuid, billing_run_uuid);
SQL_0161);

        $this->addSql(<<<'SQL_0162'
CREATE INDEX ix_billing_runs_accruals_generated_by ON public.billing_runs USING btree (accruals_generated_by) WHERE (accruals_generated_by IS NOT NULL);
SQL_0162);

        $this->addSql(<<<'SQL_0163'
CREATE INDEX ix_billing_runs_kind_period ON public.billing_runs USING btree (workspace_uuid, kind, period_start, period_end);
SQL_0163);

        $this->addSql(<<<'SQL_0164'
CREATE INDEX ix_electricity_consumption_band_rule_account_scopes_account ON public.electricity_consumption_band_rule_account_scopes USING btree (workspace_uuid, account_uuid);
SQL_0164);

        $this->addSql(<<<'SQL_0165'
CREATE INDEX ix_electricity_consumption_band_rule_group_scopes_group ON public.electricity_consumption_band_rule_group_scopes USING btree (workspace_uuid, account_group_uuid);
SQL_0165);

        $this->addSql(<<<'SQL_0166'
CREATE INDEX ix_electricity_consumption_band_rules_profile_month_period ON public.electricity_consumption_band_rules USING btree (workspace_uuid, tariff_profile_uuid, month, valid_from, valid_to) WHERE (deleted_at IS NULL);
SQL_0166);

        $this->addSql(<<<'SQL_0167'
CREATE INDEX ix_electricity_meter_readings_active_meter_zone_taken ON public.electricity_meter_readings USING btree (workspace_uuid, electricity_meter_uuid, tariff_zone_uuid, taken_on, submitted_at) WHERE ((cancelled_at IS NULL) AND (replacing_reading_uuid IS NULL));
SQL_0167);

        $this->addSql(<<<'SQL_0168'
CREATE INDEX ix_electricity_meter_readings_provider ON public.electricity_meter_readings USING btree (workspace_uuid, provided_by_subscriber_uuid, submitted_at) WHERE (provided_by_subscriber_uuid IS NOT NULL);
SQL_0168);

        $this->addSql(<<<'SQL_0169'
CREATE INDEX ix_electricity_meter_registers_zone ON public.electricity_meter_registers USING btree (workspace_uuid, tariff_zone_uuid);
SQL_0169);

        $this->addSql(<<<'SQL_0170'
CREATE INDEX ix_electricity_meters_account ON public.electricity_meters USING btree (workspace_uuid, account_uuid, installed_on);
SQL_0170);

        $this->addSql(<<<'SQL_0171'
CREATE INDEX ix_electricity_tariff_periods_profile_period ON public.electricity_tariff_periods USING btree (workspace_uuid, tariff_profile_uuid, valid_from, valid_to) WHERE (deleted_at IS NULL);
SQL_0171);

        $this->addSql(<<<'SQL_0172'
CREATE INDEX ix_electricity_tariff_rates_zone_band ON public.electricity_tariff_rates USING btree (workspace_uuid, tariff_zone_uuid, consumption_band_uuid);
SQL_0172);

        $this->addSql(<<<'SQL_0173'
CREATE INDEX ix_payment_requisite_assignments_profile ON public.payment_requisite_assignments USING btree (workspace_uuid, payment_requisite_profile_uuid);
SQL_0173);

        $this->addSql(<<<'SQL_0174'
CREATE INDEX ix_payment_requisite_assignments_scope_validity ON public.payment_requisite_assignments USING btree (workspace_uuid, accrual_type, valid_from, valid_to);
SQL_0174);

        $this->addSql(<<<'SQL_0175'
CREATE INDEX ix_payment_requisite_profiles_validity ON public.payment_requisite_profiles USING btree (workspace_uuid, valid_from, valid_to);
SQL_0175);

        $this->addSql(<<<'SQL_0176'
CREATE INDEX ix_payments_account_paid_on ON public.payments USING btree (workspace_uuid, account_uuid, paid_on);
SQL_0176);

        $this->addSql(<<<'SQL_0177'
CREATE INDEX ix_payments_external_reference ON public.payments USING btree (workspace_uuid, external_reference) WHERE (external_reference IS NOT NULL);
SQL_0177);

        $this->addSql(<<<'SQL_0178'
CREATE INDEX ix_subscriber_account_accesses_account ON public.subscriber_account_accesses USING btree (workspace_uuid, account_uuid) WHERE (revoked_at IS NULL);
SQL_0178);

        $this->addSql(<<<'SQL_0179'
CREATE INDEX ix_subscribers_name ON public.subscribers USING btree (workspace_uuid, last_name, first_name, second_name) WHERE (deleted_at IS NULL);
SQL_0179);

        $this->addSql(<<<'SQL_0180'
CREATE INDEX ix_user_email_identities_user ON public.user_email_identities USING btree (user_uuid) WHERE (deleted_at IS NULL);
SQL_0180);

        $this->addSql(<<<'SQL_0181'
CREATE INDEX ix_user_password_history_changed_by ON public.user_password_history USING btree (changed_by, changed_at) WHERE (changed_by IS NOT NULL);
SQL_0181);

        $this->addSql(<<<'SQL_0182'
CREATE INDEX ix_users_admin_active ON public.users USING btree (admin_granted_at) WHERE ((admin_granted_at IS NOT NULL) AND (admin_revoked_at IS NULL));
SQL_0182);

        $this->addSql(<<<'SQL_0183'
CREATE INDEX ix_users_lifecycle ON public.users USING btree (approved_at, blocked_at, deleted_at);
SQL_0183);

        $this->addSql(<<<'SQL_0184'
CREATE INDEX ix_workspace_user_role_assignments_role ON public.workspace_user_role_assignments USING btree (workspace_uuid, role_code) WHERE (revoked_at IS NULL);
SQL_0184);

        $this->addSql(<<<'SQL_0185'
CREATE INDEX ix_workspace_user_role_assignments_user ON public.workspace_user_role_assignments USING btree (user_uuid, workspace_uuid) WHERE (revoked_at IS NULL);
SQL_0185);

        $this->addSql(<<<'SQL_0186'
CREATE INDEX ix_zm_statement_import_batches_created ON public.zavety_michurina_statement_import_batches USING btree (workspace_uuid, created_at);
SQL_0186);

        $this->addSql(<<<'SQL_0187'
CREATE INDEX ix_zm_statement_import_files_batch_status ON public.zavety_michurina_statement_import_files USING btree (workspace_uuid, batch_uuid, status);
SQL_0187);

        $this->addSql(<<<'SQL_0188'
CREATE INDEX ix_zm_statement_import_files_created ON public.zavety_michurina_statement_import_files USING btree (workspace_uuid, created_at);
SQL_0188);

        $this->addSql(<<<'SQL_0189'
CREATE INDEX ix_zm_statement_import_files_detected_account ON public.zavety_michurina_statement_import_files USING btree (workspace_uuid, detected_account_number) WHERE (detected_account_number IS NOT NULL);
SQL_0189);

        $this->addSql(<<<'SQL_0190'
CREATE UNIQUE INDEX ux_account_group_members_active ON public.account_group_members USING btree (workspace_uuid, account_group_uuid, account_uuid) WHERE (valid_to IS NULL);
SQL_0190);

        $this->addSql(<<<'SQL_0191'
CREATE UNIQUE INDEX ux_account_groups_code_active ON public.account_groups USING btree (workspace_uuid, code) WHERE (deleted_at IS NULL);
SQL_0191);

        $this->addSql(<<<'SQL_0192'
CREATE UNIQUE INDEX ux_account_statement_deliveries_active_recipient ON public.account_statement_deliveries USING btree (workspace_uuid, account_statement_uuid, channel, recipient_email_normalized) WHERE (cancelled_at IS NULL);
SQL_0192);

        $this->addSql(<<<'SQL_0193'
CREATE UNIQUE INDEX ux_account_statements_active_billing_run_account ON public.account_statements USING btree (workspace_uuid, billing_run_uuid, account_uuid) WHERE ((billing_run_uuid IS NOT NULL) AND (cancelled_at IS NULL));
SQL_0193);

        $this->addSql(<<<'SQL_0194'
CREATE UNIQUE INDEX ux_account_statements_number ON public.account_statements USING btree (workspace_uuid, number);
SQL_0194);

        $this->addSql(<<<'SQL_0195'
CREATE UNIQUE INDEX ux_accounts_number_active ON public.accounts USING btree (workspace_uuid, number) WHERE (deleted_at IS NULL);
SQL_0195);

        $this->addSql(<<<'SQL_0196'
CREATE UNIQUE INDEX ux_accruals_one_posted_per_period ON public.accruals USING btree (workspace_uuid, account_uuid, type, period_start, period_end) WHERE ((posted_at IS NOT NULL) AND (cancelled_at IS NULL) AND (replacing_accrual_uuid IS NULL));
SQL_0196);

        $this->addSql(<<<'SQL_0197'
CREATE UNIQUE INDEX ux_accruals_replacing ON public.accruals USING btree (workspace_uuid, replacing_accrual_uuid) WHERE (replacing_accrual_uuid IS NOT NULL);
SQL_0197);

        $this->addSql(<<<'SQL_0198'
CREATE UNIQUE INDEX ux_billing_run_account_issues_open ON public.billing_run_account_issues USING btree (workspace_uuid, billing_run_uuid, account_uuid, issue_type) WHERE (closed_at IS NULL);
SQL_0198);

        $this->addSql(<<<'SQL_0199'
CREATE UNIQUE INDEX ux_billing_runs_active_kind_period ON public.billing_runs USING btree (workspace_uuid, kind, period_start, period_end) WHERE (cancelled_at IS NULL);
SQL_0199);

        $this->addSql(<<<'SQL_0200'
CREATE UNIQUE INDEX ux_electricity_consumption_bands_code_active ON public.electricity_consumption_bands USING btree (workspace_uuid, code) WHERE (deleted_at IS NULL);
SQL_0200);

        $this->addSql(<<<'SQL_0201'
CREATE UNIQUE INDEX ux_electricity_meter_readings_replacing ON public.electricity_meter_readings USING btree (workspace_uuid, replacing_reading_uuid) WHERE (replacing_reading_uuid IS NOT NULL);
SQL_0201);

        $this->addSql(<<<'SQL_0202'
CREATE UNIQUE INDEX ux_electricity_meters_one_active_per_account ON public.electricity_meters USING btree (workspace_uuid, account_uuid) WHERE ((removed_on IS NULL) AND (deleted_at IS NULL));
SQL_0202);

        $this->addSql(<<<'SQL_0203'
CREATE UNIQUE INDEX ux_electricity_tariff_periods_profile_from_active ON public.electricity_tariff_periods USING btree (workspace_uuid, tariff_profile_uuid, valid_from) WHERE (deleted_at IS NULL);
SQL_0203);

        $this->addSql(<<<'SQL_0204'
CREATE UNIQUE INDEX ux_electricity_tariff_profiles_code_active ON public.electricity_tariff_profiles USING btree (workspace_uuid, code) WHERE (deleted_at IS NULL);
SQL_0204);

        $this->addSql(<<<'SQL_0205'
CREATE UNIQUE INDEX ux_electricity_tariff_zones_code_active ON public.electricity_tariff_zones USING btree (workspace_uuid, code) WHERE (deleted_at IS NULL);
SQL_0205);

        $this->addSql(<<<'SQL_0206'
CREATE UNIQUE INDEX ux_payment_requisite_assignments_default_open ON public.payment_requisite_assignments USING btree (workspace_uuid) WHERE ((accrual_type IS NULL) AND (valid_to IS NULL) AND (closed_at IS NULL));
SQL_0206);

        $this->addSql(<<<'SQL_0207'
CREATE UNIQUE INDEX ux_payment_requisite_assignments_type_open ON public.payment_requisite_assignments USING btree (workspace_uuid, accrual_type) WHERE ((accrual_type IS NOT NULL) AND (valid_to IS NULL) AND (closed_at IS NULL));
SQL_0207);

        $this->addSql(<<<'SQL_0208'
CREATE UNIQUE INDEX ux_payment_requisite_profiles_code_active ON public.payment_requisite_profiles USING btree (workspace_uuid, code) WHERE (deleted_at IS NULL);
SQL_0208);

        $this->addSql(<<<'SQL_0209'
CREATE UNIQUE INDEX ux_payments_replacing ON public.payments USING btree (workspace_uuid, replacing_payment_uuid) WHERE (replacing_payment_uuid IS NOT NULL);
SQL_0209);

        $this->addSql(<<<'SQL_0210'
CREATE UNIQUE INDEX ux_subscriber_account_accesses_active ON public.subscriber_account_accesses USING btree (workspace_uuid, subscriber_uuid, account_uuid) WHERE (revoked_at IS NULL);
SQL_0210);

        $this->addSql(<<<'SQL_0211'
CREATE UNIQUE INDEX ux_subscribers_user_active ON public.subscribers USING btree (workspace_uuid, user_uuid) WHERE ((user_uuid IS NOT NULL) AND (deleted_at IS NULL));
SQL_0211);

        $this->addSql(<<<'SQL_0212'
CREATE UNIQUE INDEX ux_user_email_identities_active_email ON public.user_email_identities USING btree (email_normalized) WHERE (deleted_at IS NULL);
SQL_0212);

        $this->addSql(<<<'SQL_0213'
CREATE UNIQUE INDEX ux_workspace_user_role_assignments_active ON public.workspace_user_role_assignments USING btree (workspace_uuid, user_uuid, role_code) WHERE (revoked_at IS NULL);
SQL_0213);

        $this->addSql(<<<'SQL_0214'
CREATE UNIQUE INDEX ux_workspaces_code ON public.workspaces USING btree (code);
SQL_0214);

        $this->addSql(<<<'SQL_0215'
CREATE UNIQUE INDEX ux_zm_statement_import_batches_workspace_uuid ON public.zavety_michurina_statement_import_batches USING btree (workspace_uuid, uuid);
SQL_0215);

        $this->addSql(<<<'SQL_0216'
CREATE UNIQUE INDEX ux_zm_statement_import_files_batch_hash ON public.zavety_michurina_statement_import_files USING btree (workspace_uuid, batch_uuid, source_sha256);
SQL_0216);

        $this->addSql(<<<'SQL_0217'
CREATE UNIQUE INDEX ux_zm_statement_import_files_workspace_uuid ON public.zavety_michurina_statement_import_files USING btree (workspace_uuid, uuid);
SQL_0217);

        $this->addSql(<<<'SQL_0218'
CREATE TRIGGER trg_account_groups_timestamps BEFORE INSERT OR UPDATE ON public.account_groups FOR EACH ROW EXECUTE FUNCTION public.set_row_timestamps();
SQL_0218);

        $this->addSql(<<<'SQL_0219'
CREATE TRIGGER trg_accounts_timestamps BEFORE INSERT OR UPDATE ON public.accounts FOR EACH ROW EXECUTE FUNCTION public.set_row_timestamps();
SQL_0219);

        $this->addSql(<<<'SQL_0220'
CREATE TRIGGER trg_accruals_timestamps BEFORE INSERT OR UPDATE ON public.accruals FOR EACH ROW EXECUTE FUNCTION public.set_row_timestamps();
SQL_0220);

        $this->addSql(<<<'SQL_0221'
CREATE TRIGGER trg_audit_logs_immutable_delete BEFORE DELETE ON public.audit_logs FOR EACH ROW EXECUTE FUNCTION public.prevent_immutable_table_changes();
SQL_0221);

        $this->addSql(<<<'SQL_0222'
CREATE TRIGGER trg_audit_logs_immutable_update BEFORE UPDATE ON public.audit_logs FOR EACH ROW EXECUTE FUNCTION public.prevent_immutable_table_changes();
SQL_0222);

        $this->addSql(<<<'SQL_0223'
CREATE TRIGGER trg_billing_run_account_issues_timestamps BEFORE INSERT OR UPDATE ON public.billing_run_account_issues FOR EACH ROW EXECUTE FUNCTION public.set_row_timestamps();
SQL_0223);

        $this->addSql(<<<'SQL_0224'
CREATE TRIGGER trg_billing_settings_timestamps BEFORE INSERT OR UPDATE ON public.billing_settings FOR EACH ROW EXECUTE FUNCTION public.set_row_timestamps();
SQL_0224);

        $this->addSql(<<<'SQL_0225'
CREATE TRIGGER trg_electricity_consumption_band_rules_timestamps BEFORE INSERT OR UPDATE ON public.electricity_consumption_band_rules FOR EACH ROW EXECUTE FUNCTION public.set_row_timestamps();
SQL_0225);

        $this->addSql(<<<'SQL_0226'
CREATE TRIGGER trg_electricity_consumption_bands_timestamps BEFORE INSERT OR UPDATE ON public.electricity_consumption_bands FOR EACH ROW EXECUTE FUNCTION public.set_row_timestamps();
SQL_0226);

        $this->addSql(<<<'SQL_0227'
CREATE TRIGGER trg_electricity_meter_readings_timestamps BEFORE INSERT OR UPDATE ON public.electricity_meter_readings FOR EACH ROW EXECUTE FUNCTION public.set_row_timestamps();
SQL_0227);

        $this->addSql(<<<'SQL_0228'
CREATE TRIGGER trg_electricity_meter_registers_immutable_delete BEFORE DELETE ON public.electricity_meter_registers FOR EACH ROW EXECUTE FUNCTION public.prevent_immutable_table_changes();
SQL_0228);

        $this->addSql(<<<'SQL_0229'
CREATE TRIGGER trg_electricity_meter_registers_immutable_update BEFORE UPDATE ON public.electricity_meter_registers FOR EACH ROW EXECUTE FUNCTION public.prevent_immutable_table_changes();
SQL_0229);

        $this->addSql(<<<'SQL_0230'
CREATE TRIGGER trg_electricity_meters_timestamps BEFORE INSERT OR UPDATE ON public.electricity_meters FOR EACH ROW EXECUTE FUNCTION public.set_row_timestamps();
SQL_0230);

        $this->addSql(<<<'SQL_0231'
CREATE TRIGGER trg_electricity_tariff_periods_timestamps BEFORE INSERT OR UPDATE ON public.electricity_tariff_periods FOR EACH ROW EXECUTE FUNCTION public.set_row_timestamps();
SQL_0231);

        $this->addSql(<<<'SQL_0232'
CREATE TRIGGER trg_electricity_tariff_profiles_timestamps BEFORE INSERT OR UPDATE ON public.electricity_tariff_profiles FOR EACH ROW EXECUTE FUNCTION public.set_row_timestamps();
SQL_0232);

        $this->addSql(<<<'SQL_0233'
CREATE TRIGGER trg_electricity_tariff_rates_timestamps BEFORE INSERT OR UPDATE ON public.electricity_tariff_rates FOR EACH ROW EXECUTE FUNCTION public.set_row_timestamps();
SQL_0233);

        $this->addSql(<<<'SQL_0234'
CREATE TRIGGER trg_electricity_tariff_zones_timestamps BEFORE INSERT OR UPDATE ON public.electricity_tariff_zones FOR EACH ROW EXECUTE FUNCTION public.set_row_timestamps();
SQL_0234);

        $this->addSql(<<<'SQL_0235'
CREATE TRIGGER trg_payment_requisite_profiles_timestamps BEFORE INSERT OR UPDATE ON public.payment_requisite_profiles FOR EACH ROW EXECUTE FUNCTION public.set_row_timestamps();
SQL_0235);

        $this->addSql(<<<'SQL_0236'
CREATE TRIGGER trg_payments_timestamps BEFORE INSERT OR UPDATE ON public.payments FOR EACH ROW EXECUTE FUNCTION public.set_row_timestamps();
SQL_0236);

        $this->addSql(<<<'SQL_0237'
CREATE TRIGGER trg_subscribers_timestamps BEFORE INSERT OR UPDATE ON public.subscribers FOR EACH ROW EXECUTE FUNCTION public.set_row_timestamps();
SQL_0237);

        $this->addSql(<<<'SQL_0238'
CREATE TRIGGER trg_users_timestamps BEFORE INSERT OR UPDATE ON public.users FOR EACH ROW EXECUTE FUNCTION public.set_row_timestamps();
SQL_0238);

        $this->addSql(<<<'SQL_0239'
CREATE TRIGGER trg_workspaces_timestamps BEFORE INSERT OR UPDATE ON public.workspaces FOR EACH ROW EXECUTE FUNCTION public.set_row_timestamps();
SQL_0239);

        $this->addSql(<<<'SQL_0240'
CREATE TRIGGER trg_zm_statement_import_batches_timestamps BEFORE INSERT OR UPDATE ON public.zavety_michurina_statement_import_batches FOR EACH ROW EXECUTE FUNCTION public.set_row_timestamps();
SQL_0240);

        $this->addSql(<<<'SQL_0241'
CREATE TRIGGER trg_zm_statement_import_files_timestamps BEFORE INSERT OR UPDATE ON public.zavety_michurina_statement_import_files FOR EACH ROW EXECUTE FUNCTION public.set_row_timestamps();
SQL_0241);

        $this->addSql(<<<'SQL_0242'
ALTER TABLE ONLY public.account_electricity_tariff_profile_assignments
    ADD CONSTRAINT fk_account_electricity_tariff_profile_assignments_account FOREIGN KEY (workspace_uuid, account_uuid) REFERENCES public.accounts(workspace_uuid, uuid);
SQL_0242);

        $this->addSql(<<<'SQL_0243'
ALTER TABLE ONLY public.account_electricity_tariff_profile_assignments
    ADD CONSTRAINT fk_account_electricity_tariff_profile_assignments_assigned_by FOREIGN KEY (assigned_by) REFERENCES public.users(uuid);
SQL_0243);

        $this->addSql(<<<'SQL_0244'
ALTER TABLE ONLY public.account_electricity_tariff_profile_assignments
    ADD CONSTRAINT fk_account_electricity_tariff_profile_assignments_profile FOREIGN KEY (workspace_uuid, tariff_profile_uuid) REFERENCES public.electricity_tariff_profiles(workspace_uuid, uuid);
SQL_0244);

        $this->addSql(<<<'SQL_0245'
ALTER TABLE ONLY public.account_electricity_tariff_profile_assignments
    ADD CONSTRAINT fk_account_electricity_tariff_profile_assignments_workspace FOREIGN KEY (workspace_uuid) REFERENCES public.workspaces(uuid);
SQL_0245);

        $this->addSql(<<<'SQL_0246'
ALTER TABLE ONLY public.account_group_members
    ADD CONSTRAINT fk_account_group_members_account FOREIGN KEY (workspace_uuid, account_uuid) REFERENCES public.accounts(workspace_uuid, uuid);
SQL_0246);

        $this->addSql(<<<'SQL_0247'
ALTER TABLE ONLY public.account_group_members
    ADD CONSTRAINT fk_account_group_members_created_by FOREIGN KEY (created_by) REFERENCES public.users(uuid);
SQL_0247);

        $this->addSql(<<<'SQL_0248'
ALTER TABLE ONLY public.account_group_members
    ADD CONSTRAINT fk_account_group_members_group FOREIGN KEY (workspace_uuid, account_group_uuid) REFERENCES public.account_groups(workspace_uuid, uuid);
SQL_0248);

        $this->addSql(<<<'SQL_0249'
ALTER TABLE ONLY public.account_group_members
    ADD CONSTRAINT fk_account_group_members_workspace FOREIGN KEY (workspace_uuid) REFERENCES public.workspaces(uuid);
SQL_0249);

        $this->addSql(<<<'SQL_0250'
ALTER TABLE ONLY public.account_groups
    ADD CONSTRAINT fk_account_groups_created_by FOREIGN KEY (created_by) REFERENCES public.users(uuid);
SQL_0250);

        $this->addSql(<<<'SQL_0251'
ALTER TABLE ONLY public.account_groups
    ADD CONSTRAINT fk_account_groups_deleted_by FOREIGN KEY (deleted_by) REFERENCES public.users(uuid);
SQL_0251);

        $this->addSql(<<<'SQL_0252'
ALTER TABLE ONLY public.account_groups
    ADD CONSTRAINT fk_account_groups_updated_by FOREIGN KEY (updated_by) REFERENCES public.users(uuid);
SQL_0252);

        $this->addSql(<<<'SQL_0253'
ALTER TABLE ONLY public.account_groups
    ADD CONSTRAINT fk_account_groups_workspace FOREIGN KEY (workspace_uuid) REFERENCES public.workspaces(uuid);
SQL_0253);

        $this->addSql(<<<'SQL_0254'
ALTER TABLE ONLY public.account_statement_accruals
    ADD CONSTRAINT fk_account_statement_accruals_accrual FOREIGN KEY (workspace_uuid, accrual_uuid) REFERENCES public.accruals(workspace_uuid, uuid);
SQL_0254);

        $this->addSql(<<<'SQL_0255'
ALTER TABLE ONLY public.account_statement_accruals
    ADD CONSTRAINT fk_account_statement_accruals_statement FOREIGN KEY (workspace_uuid, account_statement_uuid) REFERENCES public.account_statements(workspace_uuid, uuid);
SQL_0255);

        $this->addSql(<<<'SQL_0256'
ALTER TABLE ONLY public.account_statement_accruals
    ADD CONSTRAINT fk_account_statement_accruals_workspace FOREIGN KEY (workspace_uuid) REFERENCES public.workspaces(uuid);
SQL_0256);

        $this->addSql(<<<'SQL_0257'
ALTER TABLE ONLY public.account_statement_deliveries
    ADD CONSTRAINT fk_account_statement_deliveries_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES public.users(uuid);
SQL_0257);

        $this->addSql(<<<'SQL_0258'
ALTER TABLE ONLY public.account_statement_deliveries
    ADD CONSTRAINT fk_account_statement_deliveries_created_by FOREIGN KEY (created_by) REFERENCES public.users(uuid);
SQL_0258);

        $this->addSql(<<<'SQL_0259'
ALTER TABLE ONLY public.account_statement_deliveries
    ADD CONSTRAINT fk_account_statement_deliveries_statement FOREIGN KEY (workspace_uuid, account_statement_uuid) REFERENCES public.account_statements(workspace_uuid, uuid);
SQL_0259);

        $this->addSql(<<<'SQL_0260'
ALTER TABLE ONLY public.account_statement_deliveries
    ADD CONSTRAINT fk_account_statement_deliveries_subscriber FOREIGN KEY (workspace_uuid, recipient_subscriber_uuid) REFERENCES public.subscribers(workspace_uuid, uuid);
SQL_0260);

        $this->addSql(<<<'SQL_0261'
ALTER TABLE ONLY public.account_statement_deliveries
    ADD CONSTRAINT fk_account_statement_deliveries_workspace FOREIGN KEY (workspace_uuid) REFERENCES public.workspaces(uuid);
SQL_0261);

        $this->addSql(<<<'SQL_0262'
ALTER TABLE ONLY public.account_statement_delivery_attempts
    ADD CONSTRAINT fk_account_statement_delivery_attempts_delivery FOREIGN KEY (workspace_uuid, delivery_uuid) REFERENCES public.account_statement_deliveries(workspace_uuid, uuid);
SQL_0262);

        $this->addSql(<<<'SQL_0263'
ALTER TABLE ONLY public.account_statement_delivery_attempts
    ADD CONSTRAINT fk_account_statement_delivery_attempts_queued_by FOREIGN KEY (queued_by) REFERENCES public.users(uuid);
SQL_0263);

        $this->addSql(<<<'SQL_0264'
ALTER TABLE ONLY public.account_statement_delivery_attempts
    ADD CONSTRAINT fk_account_statement_delivery_attempts_workspace FOREIGN KEY (workspace_uuid) REFERENCES public.workspaces(uuid);
SQL_0264);

        $this->addSql(<<<'SQL_0265'
ALTER TABLE ONLY public.account_statement_electricity_lines
    ADD CONSTRAINT fk_account_statement_electricity_lines_accrual FOREIGN KEY (workspace_uuid, accrual_uuid) REFERENCES public.accruals(workspace_uuid, uuid);
SQL_0265);

        $this->addSql(<<<'SQL_0266'
ALTER TABLE ONLY public.account_statement_electricity_lines
    ADD CONSTRAINT fk_account_statement_electricity_lines_band FOREIGN KEY (workspace_uuid, consumption_band_uuid) REFERENCES public.electricity_consumption_bands(workspace_uuid, uuid);
SQL_0266);

        $this->addSql(<<<'SQL_0267'
ALTER TABLE ONLY public.account_statement_electricity_lines
    ADD CONSTRAINT fk_account_statement_electricity_lines_statement FOREIGN KEY (workspace_uuid, account_statement_uuid) REFERENCES public.account_statements(workspace_uuid, uuid);
SQL_0267);

        $this->addSql(<<<'SQL_0268'
ALTER TABLE ONLY public.account_statement_electricity_lines
    ADD CONSTRAINT fk_account_statement_electricity_lines_workspace FOREIGN KEY (workspace_uuid) REFERENCES public.workspaces(uuid);
SQL_0268);

        $this->addSql(<<<'SQL_0269'
ALTER TABLE ONLY public.account_statement_electricity_lines
    ADD CONSTRAINT fk_account_statement_electricity_lines_zone FOREIGN KEY (workspace_uuid, tariff_zone_uuid) REFERENCES public.electricity_tariff_zones(workspace_uuid, uuid);
SQL_0269);

        $this->addSql(<<<'SQL_0270'
ALTER TABLE ONLY public.account_statement_electricity_registers
    ADD CONSTRAINT fk_account_statement_electricity_registers_accrual FOREIGN KEY (workspace_uuid, accrual_uuid) REFERENCES public.accruals(workspace_uuid, uuid);
SQL_0270);

        $this->addSql(<<<'SQL_0271'
ALTER TABLE ONLY public.account_statement_electricity_registers
    ADD CONSTRAINT fk_account_statement_electricity_registers_current_reading FOREIGN KEY (workspace_uuid, current_reading_uuid) REFERENCES public.electricity_meter_readings(workspace_uuid, uuid);
SQL_0271);

        $this->addSql(<<<'SQL_0272'
ALTER TABLE ONLY public.account_statement_electricity_registers
    ADD CONSTRAINT fk_account_statement_electricity_registers_meter FOREIGN KEY (workspace_uuid, electricity_meter_uuid) REFERENCES public.electricity_meters(workspace_uuid, uuid);
SQL_0272);

        $this->addSql(<<<'SQL_0273'
ALTER TABLE ONLY public.account_statement_electricity_registers
    ADD CONSTRAINT fk_account_statement_electricity_registers_previous_reading FOREIGN KEY (workspace_uuid, previous_reading_uuid) REFERENCES public.electricity_meter_readings(workspace_uuid, uuid);
SQL_0273);

        $this->addSql(<<<'SQL_0274'
ALTER TABLE ONLY public.account_statement_electricity_registers
    ADD CONSTRAINT fk_account_statement_electricity_registers_statement FOREIGN KEY (workspace_uuid, account_statement_uuid) REFERENCES public.account_statements(workspace_uuid, uuid);
SQL_0274);

        $this->addSql(<<<'SQL_0275'
ALTER TABLE ONLY public.account_statement_electricity_registers
    ADD CONSTRAINT fk_account_statement_electricity_registers_workspace FOREIGN KEY (workspace_uuid) REFERENCES public.workspaces(uuid);
SQL_0275);

        $this->addSql(<<<'SQL_0276'
ALTER TABLE ONLY public.account_statement_electricity_registers
    ADD CONSTRAINT fk_account_statement_electricity_registers_zone FOREIGN KEY (workspace_uuid, tariff_zone_uuid) REFERENCES public.electricity_tariff_zones(workspace_uuid, uuid);
SQL_0276);

        $this->addSql(<<<'SQL_0277'
ALTER TABLE ONLY public.account_statement_payments
    ADD CONSTRAINT fk_account_statement_payments_payment FOREIGN KEY (workspace_uuid, payment_uuid) REFERENCES public.payments(workspace_uuid, uuid);
SQL_0277);

        $this->addSql(<<<'SQL_0278'
ALTER TABLE ONLY public.account_statement_payments
    ADD CONSTRAINT fk_account_statement_payments_statement FOREIGN KEY (workspace_uuid, account_statement_uuid) REFERENCES public.account_statements(workspace_uuid, uuid);
SQL_0278);

        $this->addSql(<<<'SQL_0279'
ALTER TABLE ONLY public.account_statement_payments
    ADD CONSTRAINT fk_account_statement_payments_workspace FOREIGN KEY (workspace_uuid) REFERENCES public.workspaces(uuid);
SQL_0279);

        $this->addSql(<<<'SQL_0280'
ALTER TABLE ONLY public.account_statements
    ADD CONSTRAINT fk_account_statements_account FOREIGN KEY (workspace_uuid, account_uuid) REFERENCES public.accounts(workspace_uuid, uuid);
SQL_0280);

        $this->addSql(<<<'SQL_0281'
ALTER TABLE ONLY public.account_statements
    ADD CONSTRAINT fk_account_statements_billing_run FOREIGN KEY (workspace_uuid, billing_run_uuid) REFERENCES public.billing_runs(workspace_uuid, uuid);
SQL_0281);

        $this->addSql(<<<'SQL_0282'
ALTER TABLE ONLY public.account_statements
    ADD CONSTRAINT fk_account_statements_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES public.users(uuid);
SQL_0282);

        $this->addSql(<<<'SQL_0283'
ALTER TABLE ONLY public.account_statements
    ADD CONSTRAINT fk_account_statements_generated_by FOREIGN KEY (generated_by) REFERENCES public.users(uuid);
SQL_0283);

        $this->addSql(<<<'SQL_0284'
ALTER TABLE ONLY public.account_statements
    ADD CONSTRAINT fk_account_statements_payment_requisite_profile FOREIGN KEY (workspace_uuid, payment_requisite_profile_uuid) REFERENCES public.payment_requisite_profiles(workspace_uuid, uuid);
SQL_0284);

        $this->addSql(<<<'SQL_0285'
ALTER TABLE ONLY public.account_statements
    ADD CONSTRAINT fk_account_statements_workspace FOREIGN KEY (workspace_uuid) REFERENCES public.workspaces(uuid);
SQL_0285);

        $this->addSql(<<<'SQL_0286'
ALTER TABLE ONLY public.accounts
    ADD CONSTRAINT fk_accounts_created_by FOREIGN KEY (created_by) REFERENCES public.users(uuid);
SQL_0286);

        $this->addSql(<<<'SQL_0287'
ALTER TABLE ONLY public.accounts
    ADD CONSTRAINT fk_accounts_deleted_by FOREIGN KEY (deleted_by) REFERENCES public.users(uuid);
SQL_0287);

        $this->addSql(<<<'SQL_0288'
ALTER TABLE ONLY public.accounts
    ADD CONSTRAINT fk_accounts_updated_by FOREIGN KEY (updated_by) REFERENCES public.users(uuid);
SQL_0288);

        $this->addSql(<<<'SQL_0289'
ALTER TABLE ONLY public.accounts
    ADD CONSTRAINT fk_accounts_workspace FOREIGN KEY (workspace_uuid) REFERENCES public.workspaces(uuid);
SQL_0289);

        $this->addSql(<<<'SQL_0290'
ALTER TABLE ONLY public.accruals
    ADD CONSTRAINT fk_accruals_account FOREIGN KEY (workspace_uuid, account_uuid) REFERENCES public.accounts(workspace_uuid, uuid);
SQL_0290);

        $this->addSql(<<<'SQL_0291'
ALTER TABLE ONLY public.accruals
    ADD CONSTRAINT fk_accruals_billing_run FOREIGN KEY (workspace_uuid, billing_run_uuid) REFERENCES public.billing_runs(workspace_uuid, uuid);
SQL_0291);

        $this->addSql(<<<'SQL_0292'
ALTER TABLE ONLY public.accruals
    ADD CONSTRAINT fk_accruals_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES public.users(uuid);
SQL_0292);

        $this->addSql(<<<'SQL_0293'
ALTER TABLE ONLY public.accruals
    ADD CONSTRAINT fk_accruals_created_by FOREIGN KEY (created_by) REFERENCES public.users(uuid);
SQL_0293);

        $this->addSql(<<<'SQL_0294'
ALTER TABLE ONLY public.accruals
    ADD CONSTRAINT fk_accruals_posted_by FOREIGN KEY (posted_by) REFERENCES public.users(uuid);
SQL_0294);

        $this->addSql(<<<'SQL_0295'
ALTER TABLE ONLY public.accruals
    ADD CONSTRAINT fk_accruals_replaced_by FOREIGN KEY (replaced_by) REFERENCES public.users(uuid);
SQL_0295);

        $this->addSql(<<<'SQL_0296'
ALTER TABLE ONLY public.accruals
    ADD CONSTRAINT fk_accruals_replacing FOREIGN KEY (workspace_uuid, replacing_accrual_uuid) REFERENCES public.accruals(workspace_uuid, uuid);
SQL_0296);

        $this->addSql(<<<'SQL_0297'
ALTER TABLE ONLY public.accruals
    ADD CONSTRAINT fk_accruals_updated_by FOREIGN KEY (updated_by) REFERENCES public.users(uuid);
SQL_0297);

        $this->addSql(<<<'SQL_0298'
ALTER TABLE ONLY public.accruals
    ADD CONSTRAINT fk_accruals_workspace FOREIGN KEY (workspace_uuid) REFERENCES public.workspaces(uuid);
SQL_0298);

        $this->addSql(<<<'SQL_0299'
ALTER TABLE ONLY public.audit_logs
    ADD CONSTRAINT fk_audit_logs_actor_user FOREIGN KEY (actor_user_uuid) REFERENCES public.users(uuid);
SQL_0299);

        $this->addSql(<<<'SQL_0300'
ALTER TABLE ONLY public.audit_logs
    ADD CONSTRAINT fk_audit_logs_workspace FOREIGN KEY (workspace_uuid) REFERENCES public.workspaces(uuid);
SQL_0300);

        $this->addSql(<<<'SQL_0301'
ALTER TABLE ONLY public.billing_run_account_issues
    ADD CONSTRAINT fk_billing_run_account_issues_account FOREIGN KEY (workspace_uuid, account_uuid) REFERENCES public.accounts(workspace_uuid, uuid);
SQL_0301);

        $this->addSql(<<<'SQL_0302'
ALTER TABLE ONLY public.billing_run_account_issues
    ADD CONSTRAINT fk_billing_run_account_issues_closed_by FOREIGN KEY (closed_by) REFERENCES public.users(uuid);
SQL_0302);

        $this->addSql(<<<'SQL_0303'
ALTER TABLE ONLY public.billing_run_account_issues
    ADD CONSTRAINT fk_billing_run_account_issues_created_by FOREIGN KEY (created_by) REFERENCES public.users(uuid);
SQL_0303);

        $this->addSql(<<<'SQL_0304'
ALTER TABLE ONLY public.billing_run_account_issues
    ADD CONSTRAINT fk_billing_run_account_issues_run FOREIGN KEY (workspace_uuid, billing_run_uuid) REFERENCES public.billing_runs(workspace_uuid, uuid);
SQL_0304);

        $this->addSql(<<<'SQL_0305'
ALTER TABLE ONLY public.billing_run_account_issues
    ADD CONSTRAINT fk_billing_run_account_issues_updated_by FOREIGN KEY (updated_by) REFERENCES public.users(uuid);
SQL_0305);

        $this->addSql(<<<'SQL_0306'
ALTER TABLE ONLY public.billing_run_account_issues
    ADD CONSTRAINT fk_billing_run_account_issues_workspace FOREIGN KEY (workspace_uuid) REFERENCES public.workspaces(uuid);
SQL_0306);

        $this->addSql(<<<'SQL_0307'
ALTER TABLE ONLY public.billing_runs
    ADD CONSTRAINT fk_billing_runs_accruals_generated_by FOREIGN KEY (accruals_generated_by) REFERENCES public.users(uuid);
SQL_0307);

        $this->addSql(<<<'SQL_0308'
ALTER TABLE ONLY public.billing_runs
    ADD CONSTRAINT fk_billing_runs_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES public.users(uuid);
SQL_0308);

        $this->addSql(<<<'SQL_0309'
ALTER TABLE ONLY public.billing_runs
    ADD CONSTRAINT fk_billing_runs_generated_by FOREIGN KEY (generated_by) REFERENCES public.users(uuid);
SQL_0309);

        $this->addSql(<<<'SQL_0310'
ALTER TABLE ONLY public.billing_runs
    ADD CONSTRAINT fk_billing_runs_posted_by FOREIGN KEY (posted_by) REFERENCES public.users(uuid);
SQL_0310);

        $this->addSql(<<<'SQL_0311'
ALTER TABLE ONLY public.billing_runs
    ADD CONSTRAINT fk_billing_runs_workspace FOREIGN KEY (workspace_uuid) REFERENCES public.workspaces(uuid);
SQL_0311);

        $this->addSql(<<<'SQL_0312'
ALTER TABLE ONLY public.billing_settings
    ADD CONSTRAINT fk_billing_settings_created_by FOREIGN KEY (created_by) REFERENCES public.users(uuid);
SQL_0312);

        $this->addSql(<<<'SQL_0313'
ALTER TABLE ONLY public.billing_settings
    ADD CONSTRAINT fk_billing_settings_updated_by FOREIGN KEY (updated_by) REFERENCES public.users(uuid);
SQL_0313);

        $this->addSql(<<<'SQL_0314'
ALTER TABLE ONLY public.billing_settings
    ADD CONSTRAINT fk_billing_settings_workspace FOREIGN KEY (workspace_uuid) REFERENCES public.workspaces(uuid);
SQL_0314);

        $this->addSql(<<<'SQL_0315'
ALTER TABLE ONLY public.electricity_accrual_contexts
    ADD CONSTRAINT fk_electricity_accrual_contexts_accrual FOREIGN KEY (workspace_uuid, accrual_uuid) REFERENCES public.accruals(workspace_uuid, uuid);
SQL_0315);

        $this->addSql(<<<'SQL_0316'
ALTER TABLE ONLY public.electricity_accrual_contexts
    ADD CONSTRAINT fk_electricity_accrual_contexts_band_rule FOREIGN KEY (workspace_uuid, consumption_band_rule_uuid) REFERENCES public.electricity_consumption_band_rules(workspace_uuid, uuid);
SQL_0316);

        $this->addSql(<<<'SQL_0317'
ALTER TABLE ONLY public.electricity_accrual_contexts
    ADD CONSTRAINT fk_electricity_accrual_contexts_meter FOREIGN KEY (workspace_uuid, electricity_meter_uuid) REFERENCES public.electricity_meters(workspace_uuid, uuid);
SQL_0317);

        $this->addSql(<<<'SQL_0318'
ALTER TABLE ONLY public.electricity_accrual_contexts
    ADD CONSTRAINT fk_electricity_accrual_contexts_tariff_period FOREIGN KEY (workspace_uuid, tariff_period_uuid) REFERENCES public.electricity_tariff_periods(workspace_uuid, uuid);
SQL_0318);

        $this->addSql(<<<'SQL_0319'
ALTER TABLE ONLY public.electricity_accrual_contexts
    ADD CONSTRAINT fk_electricity_accrual_contexts_tariff_profile FOREIGN KEY (workspace_uuid, tariff_profile_uuid) REFERENCES public.electricity_tariff_profiles(workspace_uuid, uuid);
SQL_0319);

        $this->addSql(<<<'SQL_0320'
ALTER TABLE ONLY public.electricity_accrual_contexts
    ADD CONSTRAINT fk_electricity_accrual_contexts_workspace FOREIGN KEY (workspace_uuid) REFERENCES public.workspaces(uuid);
SQL_0320);

        $this->addSql(<<<'SQL_0321'
ALTER TABLE ONLY public.electricity_accrual_lines
    ADD CONSTRAINT fk_electricity_accrual_lines_accrual FOREIGN KEY (workspace_uuid, accrual_uuid) REFERENCES public.accruals(workspace_uuid, uuid);
SQL_0321);

        $this->addSql(<<<'SQL_0322'
ALTER TABLE ONLY public.electricity_accrual_lines
    ADD CONSTRAINT fk_electricity_accrual_lines_consumption_band FOREIGN KEY (workspace_uuid, consumption_band_uuid) REFERENCES public.electricity_consumption_bands(workspace_uuid, uuid);
SQL_0322);

        $this->addSql(<<<'SQL_0323'
ALTER TABLE ONLY public.electricity_accrual_lines
    ADD CONSTRAINT fk_electricity_accrual_lines_tariff_zone FOREIGN KEY (workspace_uuid, tariff_zone_uuid) REFERENCES public.electricity_tariff_zones(workspace_uuid, uuid);
SQL_0323);

        $this->addSql(<<<'SQL_0324'
ALTER TABLE ONLY public.electricity_accrual_lines
    ADD CONSTRAINT fk_electricity_accrual_lines_workspace FOREIGN KEY (workspace_uuid) REFERENCES public.workspaces(uuid);
SQL_0324);

        $this->addSql(<<<'SQL_0325'
ALTER TABLE ONLY public.electricity_accrual_registers
    ADD CONSTRAINT fk_electricity_accrual_registers_accrual FOREIGN KEY (workspace_uuid, accrual_uuid) REFERENCES public.accruals(workspace_uuid, uuid);
SQL_0325);

        $this->addSql(<<<'SQL_0326'
ALTER TABLE ONLY public.electricity_accrual_registers
    ADD CONSTRAINT fk_electricity_accrual_registers_current_reading FOREIGN KEY (workspace_uuid, current_reading_uuid, electricity_meter_uuid, tariff_zone_uuid) REFERENCES public.electricity_meter_readings(workspace_uuid, uuid, electricity_meter_uuid, tariff_zone_uuid);
SQL_0326);

        $this->addSql(<<<'SQL_0327'
ALTER TABLE ONLY public.electricity_accrual_registers
    ADD CONSTRAINT fk_electricity_accrual_registers_previous_reading FOREIGN KEY (workspace_uuid, previous_reading_uuid, electricity_meter_uuid, tariff_zone_uuid) REFERENCES public.electricity_meter_readings(workspace_uuid, uuid, electricity_meter_uuid, tariff_zone_uuid);
SQL_0327);

        $this->addSql(<<<'SQL_0328'
ALTER TABLE ONLY public.electricity_accrual_registers
    ADD CONSTRAINT fk_electricity_accrual_registers_register FOREIGN KEY (workspace_uuid, electricity_meter_uuid, tariff_zone_uuid) REFERENCES public.electricity_meter_registers(workspace_uuid, electricity_meter_uuid, tariff_zone_uuid);
SQL_0328);

        $this->addSql(<<<'SQL_0329'
ALTER TABLE ONLY public.electricity_accrual_registers
    ADD CONSTRAINT fk_electricity_accrual_registers_workspace FOREIGN KEY (workspace_uuid) REFERENCES public.workspaces(uuid);
SQL_0329);

        $this->addSql(<<<'SQL_0330'
ALTER TABLE ONLY public.electricity_consumption_band_rule_account_scopes
    ADD CONSTRAINT fk_electricity_consumption_band_rule_account_scopes_account FOREIGN KEY (workspace_uuid, account_uuid) REFERENCES public.accounts(workspace_uuid, uuid);
SQL_0330);

        $this->addSql(<<<'SQL_0331'
ALTER TABLE ONLY public.electricity_consumption_band_rule_account_scopes
    ADD CONSTRAINT fk_electricity_consumption_band_rule_account_scopes_rule FOREIGN KEY (workspace_uuid, rule_uuid) REFERENCES public.electricity_consumption_band_rules(workspace_uuid, uuid);
SQL_0331);

        $this->addSql(<<<'SQL_0332'
ALTER TABLE ONLY public.electricity_consumption_band_rule_account_scopes
    ADD CONSTRAINT fk_electricity_consumption_band_rule_account_scopes_workspace FOREIGN KEY (workspace_uuid) REFERENCES public.workspaces(uuid);
SQL_0332);

        $this->addSql(<<<'SQL_0333'
ALTER TABLE ONLY public.electricity_consumption_band_rule_all_scopes
    ADD CONSTRAINT fk_electricity_consumption_band_rule_all_scopes_rule FOREIGN KEY (workspace_uuid, rule_uuid) REFERENCES public.electricity_consumption_band_rules(workspace_uuid, uuid);
SQL_0333);

        $this->addSql(<<<'SQL_0334'
ALTER TABLE ONLY public.electricity_consumption_band_rule_all_scopes
    ADD CONSTRAINT fk_electricity_consumption_band_rule_all_scopes_workspace FOREIGN KEY (workspace_uuid) REFERENCES public.workspaces(uuid);
SQL_0334);

        $this->addSql(<<<'SQL_0335'
ALTER TABLE ONLY public.electricity_consumption_band_rule_group_scopes
    ADD CONSTRAINT fk_electricity_consumption_band_rule_group_scopes_group FOREIGN KEY (workspace_uuid, account_group_uuid) REFERENCES public.account_groups(workspace_uuid, uuid);
SQL_0335);

        $this->addSql(<<<'SQL_0336'
ALTER TABLE ONLY public.electricity_consumption_band_rule_group_scopes
    ADD CONSTRAINT fk_electricity_consumption_band_rule_group_scopes_rule FOREIGN KEY (workspace_uuid, rule_uuid) REFERENCES public.electricity_consumption_band_rules(workspace_uuid, uuid);
SQL_0336);

        $this->addSql(<<<'SQL_0337'
ALTER TABLE ONLY public.electricity_consumption_band_rule_group_scopes
    ADD CONSTRAINT fk_electricity_consumption_band_rule_group_scopes_workspace FOREIGN KEY (workspace_uuid) REFERENCES public.workspaces(uuid);
SQL_0337);

        $this->addSql(<<<'SQL_0338'
ALTER TABLE ONLY public.electricity_consumption_band_rule_ranges
    ADD CONSTRAINT fk_electricity_consumption_band_rule_ranges_band FOREIGN KEY (workspace_uuid, consumption_band_uuid) REFERENCES public.electricity_consumption_bands(workspace_uuid, uuid);
SQL_0338);

        $this->addSql(<<<'SQL_0339'
ALTER TABLE ONLY public.electricity_consumption_band_rule_ranges
    ADD CONSTRAINT fk_electricity_consumption_band_rule_ranges_rule FOREIGN KEY (workspace_uuid, rule_uuid) REFERENCES public.electricity_consumption_band_rules(workspace_uuid, uuid);
SQL_0339);

        $this->addSql(<<<'SQL_0340'
ALTER TABLE ONLY public.electricity_consumption_band_rule_ranges
    ADD CONSTRAINT fk_electricity_consumption_band_rule_ranges_workspace FOREIGN KEY (workspace_uuid) REFERENCES public.workspaces(uuid);
SQL_0340);

        $this->addSql(<<<'SQL_0341'
ALTER TABLE ONLY public.electricity_consumption_band_rules
    ADD CONSTRAINT fk_electricity_consumption_band_rules_created_by FOREIGN KEY (created_by) REFERENCES public.users(uuid);
SQL_0341);

        $this->addSql(<<<'SQL_0342'
ALTER TABLE ONLY public.electricity_consumption_band_rules
    ADD CONSTRAINT fk_electricity_consumption_band_rules_deleted_by FOREIGN KEY (deleted_by) REFERENCES public.users(uuid);
SQL_0342);

        $this->addSql(<<<'SQL_0343'
ALTER TABLE ONLY public.electricity_consumption_band_rules
    ADD CONSTRAINT fk_electricity_consumption_band_rules_profile FOREIGN KEY (workspace_uuid, tariff_profile_uuid) REFERENCES public.electricity_tariff_profiles(workspace_uuid, uuid);
SQL_0343);

        $this->addSql(<<<'SQL_0344'
ALTER TABLE ONLY public.electricity_consumption_band_rules
    ADD CONSTRAINT fk_electricity_consumption_band_rules_updated_by FOREIGN KEY (updated_by) REFERENCES public.users(uuid);
SQL_0344);

        $this->addSql(<<<'SQL_0345'
ALTER TABLE ONLY public.electricity_consumption_band_rules
    ADD CONSTRAINT fk_electricity_consumption_band_rules_workspace FOREIGN KEY (workspace_uuid) REFERENCES public.workspaces(uuid);
SQL_0345);

        $this->addSql(<<<'SQL_0346'
ALTER TABLE ONLY public.electricity_consumption_bands
    ADD CONSTRAINT fk_electricity_consumption_bands_created_by FOREIGN KEY (created_by) REFERENCES public.users(uuid);
SQL_0346);

        $this->addSql(<<<'SQL_0347'
ALTER TABLE ONLY public.electricity_consumption_bands
    ADD CONSTRAINT fk_electricity_consumption_bands_deleted_by FOREIGN KEY (deleted_by) REFERENCES public.users(uuid);
SQL_0347);

        $this->addSql(<<<'SQL_0348'
ALTER TABLE ONLY public.electricity_consumption_bands
    ADD CONSTRAINT fk_electricity_consumption_bands_updated_by FOREIGN KEY (updated_by) REFERENCES public.users(uuid);
SQL_0348);

        $this->addSql(<<<'SQL_0349'
ALTER TABLE ONLY public.electricity_consumption_bands
    ADD CONSTRAINT fk_electricity_consumption_bands_workspace FOREIGN KEY (workspace_uuid) REFERENCES public.workspaces(uuid);
SQL_0349);

        $this->addSql(<<<'SQL_0350'
ALTER TABLE ONLY public.electricity_meter_readings
    ADD CONSTRAINT fk_electricity_meter_readings_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES public.users(uuid);
SQL_0350);

        $this->addSql(<<<'SQL_0351'
ALTER TABLE ONLY public.electricity_meter_readings
    ADD CONSTRAINT fk_electricity_meter_readings_created_by FOREIGN KEY (created_by) REFERENCES public.users(uuid);
SQL_0351);

        $this->addSql(<<<'SQL_0352'
ALTER TABLE ONLY public.electricity_meter_readings
    ADD CONSTRAINT fk_electricity_meter_readings_provider FOREIGN KEY (workspace_uuid, provided_by_subscriber_uuid) REFERENCES public.subscribers(workspace_uuid, uuid);
SQL_0352);

        $this->addSql(<<<'SQL_0353'
ALTER TABLE ONLY public.electricity_meter_readings
    ADD CONSTRAINT fk_electricity_meter_readings_register FOREIGN KEY (workspace_uuid, electricity_meter_uuid, tariff_zone_uuid) REFERENCES public.electricity_meter_registers(workspace_uuid, electricity_meter_uuid, tariff_zone_uuid);
SQL_0353);

        $this->addSql(<<<'SQL_0354'
ALTER TABLE ONLY public.electricity_meter_readings
    ADD CONSTRAINT fk_electricity_meter_readings_replaced_by FOREIGN KEY (replaced_by) REFERENCES public.users(uuid);
SQL_0354);

        $this->addSql(<<<'SQL_0355'
ALTER TABLE ONLY public.electricity_meter_readings
    ADD CONSTRAINT fk_electricity_meter_readings_replacing FOREIGN KEY (workspace_uuid, replacing_reading_uuid) REFERENCES public.electricity_meter_readings(workspace_uuid, uuid);
SQL_0355);

        $this->addSql(<<<'SQL_0356'
ALTER TABLE ONLY public.electricity_meter_readings
    ADD CONSTRAINT fk_electricity_meter_readings_submitted_by FOREIGN KEY (submitted_by) REFERENCES public.users(uuid);
SQL_0356);

        $this->addSql(<<<'SQL_0357'
ALTER TABLE ONLY public.electricity_meter_readings
    ADD CONSTRAINT fk_electricity_meter_readings_updated_by FOREIGN KEY (updated_by) REFERENCES public.users(uuid);
SQL_0357);

        $this->addSql(<<<'SQL_0358'
ALTER TABLE ONLY public.electricity_meter_readings
    ADD CONSTRAINT fk_electricity_meter_readings_workspace FOREIGN KEY (workspace_uuid) REFERENCES public.workspaces(uuid);
SQL_0358);

        $this->addSql(<<<'SQL_0359'
ALTER TABLE ONLY public.electricity_meter_registers
    ADD CONSTRAINT fk_electricity_meter_registers_meter FOREIGN KEY (workspace_uuid, electricity_meter_uuid) REFERENCES public.electricity_meters(workspace_uuid, uuid);
SQL_0359);

        $this->addSql(<<<'SQL_0360'
ALTER TABLE ONLY public.electricity_meter_registers
    ADD CONSTRAINT fk_electricity_meter_registers_tariff_zone FOREIGN KEY (workspace_uuid, tariff_zone_uuid) REFERENCES public.electricity_tariff_zones(workspace_uuid, uuid);
SQL_0360);

        $this->addSql(<<<'SQL_0361'
ALTER TABLE ONLY public.electricity_meter_registers
    ADD CONSTRAINT fk_electricity_meter_registers_workspace FOREIGN KEY (workspace_uuid) REFERENCES public.workspaces(uuid);
SQL_0361);

        $this->addSql(<<<'SQL_0362'
ALTER TABLE ONLY public.electricity_meters
    ADD CONSTRAINT fk_electricity_meters_account FOREIGN KEY (workspace_uuid, account_uuid) REFERENCES public.accounts(workspace_uuid, uuid);
SQL_0362);

        $this->addSql(<<<'SQL_0363'
ALTER TABLE ONLY public.electricity_meters
    ADD CONSTRAINT fk_electricity_meters_created_by FOREIGN KEY (created_by) REFERENCES public.users(uuid);
SQL_0363);

        $this->addSql(<<<'SQL_0364'
ALTER TABLE ONLY public.electricity_meters
    ADD CONSTRAINT fk_electricity_meters_deleted_by FOREIGN KEY (deleted_by) REFERENCES public.users(uuid);
SQL_0364);

        $this->addSql(<<<'SQL_0365'
ALTER TABLE ONLY public.electricity_meters
    ADD CONSTRAINT fk_electricity_meters_updated_by FOREIGN KEY (updated_by) REFERENCES public.users(uuid);
SQL_0365);

        $this->addSql(<<<'SQL_0366'
ALTER TABLE ONLY public.electricity_meters
    ADD CONSTRAINT fk_electricity_meters_workspace FOREIGN KEY (workspace_uuid) REFERENCES public.workspaces(uuid);
SQL_0366);

        $this->addSql(<<<'SQL_0367'
ALTER TABLE ONLY public.electricity_tariff_periods
    ADD CONSTRAINT fk_electricity_tariff_periods_created_by FOREIGN KEY (created_by) REFERENCES public.users(uuid);
SQL_0367);

        $this->addSql(<<<'SQL_0368'
ALTER TABLE ONLY public.electricity_tariff_periods
    ADD CONSTRAINT fk_electricity_tariff_periods_deleted_by FOREIGN KEY (deleted_by) REFERENCES public.users(uuid);
SQL_0368);

        $this->addSql(<<<'SQL_0369'
ALTER TABLE ONLY public.electricity_tariff_periods
    ADD CONSTRAINT fk_electricity_tariff_periods_profile FOREIGN KEY (workspace_uuid, tariff_profile_uuid) REFERENCES public.electricity_tariff_profiles(workspace_uuid, uuid);
SQL_0369);

        $this->addSql(<<<'SQL_0370'
ALTER TABLE ONLY public.electricity_tariff_periods
    ADD CONSTRAINT fk_electricity_tariff_periods_updated_by FOREIGN KEY (updated_by) REFERENCES public.users(uuid);
SQL_0370);

        $this->addSql(<<<'SQL_0371'
ALTER TABLE ONLY public.electricity_tariff_periods
    ADD CONSTRAINT fk_electricity_tariff_periods_workspace FOREIGN KEY (workspace_uuid) REFERENCES public.workspaces(uuid);
SQL_0371);

        $this->addSql(<<<'SQL_0372'
ALTER TABLE ONLY public.electricity_tariff_profiles
    ADD CONSTRAINT fk_electricity_tariff_profiles_created_by FOREIGN KEY (created_by) REFERENCES public.users(uuid);
SQL_0372);

        $this->addSql(<<<'SQL_0373'
ALTER TABLE ONLY public.electricity_tariff_profiles
    ADD CONSTRAINT fk_electricity_tariff_profiles_deleted_by FOREIGN KEY (deleted_by) REFERENCES public.users(uuid);
SQL_0373);

        $this->addSql(<<<'SQL_0374'
ALTER TABLE ONLY public.electricity_tariff_profiles
    ADD CONSTRAINT fk_electricity_tariff_profiles_updated_by FOREIGN KEY (updated_by) REFERENCES public.users(uuid);
SQL_0374);

        $this->addSql(<<<'SQL_0375'
ALTER TABLE ONLY public.electricity_tariff_profiles
    ADD CONSTRAINT fk_electricity_tariff_profiles_workspace FOREIGN KEY (workspace_uuid) REFERENCES public.workspaces(uuid);
SQL_0375);

        $this->addSql(<<<'SQL_0376'
ALTER TABLE ONLY public.electricity_tariff_rates
    ADD CONSTRAINT fk_electricity_tariff_rates_band FOREIGN KEY (workspace_uuid, consumption_band_uuid) REFERENCES public.electricity_consumption_bands(workspace_uuid, uuid);
SQL_0376);

        $this->addSql(<<<'SQL_0377'
ALTER TABLE ONLY public.electricity_tariff_rates
    ADD CONSTRAINT fk_electricity_tariff_rates_created_by FOREIGN KEY (created_by) REFERENCES public.users(uuid);
SQL_0377);

        $this->addSql(<<<'SQL_0378'
ALTER TABLE ONLY public.electricity_tariff_rates
    ADD CONSTRAINT fk_electricity_tariff_rates_period FOREIGN KEY (workspace_uuid, tariff_period_uuid) REFERENCES public.electricity_tariff_periods(workspace_uuid, uuid);
SQL_0378);

        $this->addSql(<<<'SQL_0379'
ALTER TABLE ONLY public.electricity_tariff_rates
    ADD CONSTRAINT fk_electricity_tariff_rates_updated_by FOREIGN KEY (updated_by) REFERENCES public.users(uuid);
SQL_0379);

        $this->addSql(<<<'SQL_0380'
ALTER TABLE ONLY public.electricity_tariff_rates
    ADD CONSTRAINT fk_electricity_tariff_rates_workspace FOREIGN KEY (workspace_uuid) REFERENCES public.workspaces(uuid);
SQL_0380);

        $this->addSql(<<<'SQL_0381'
ALTER TABLE ONLY public.electricity_tariff_rates
    ADD CONSTRAINT fk_electricity_tariff_rates_zone FOREIGN KEY (workspace_uuid, tariff_zone_uuid) REFERENCES public.electricity_tariff_zones(workspace_uuid, uuid);
SQL_0381);

        $this->addSql(<<<'SQL_0382'
ALTER TABLE ONLY public.electricity_tariff_zones
    ADD CONSTRAINT fk_electricity_tariff_zones_created_by FOREIGN KEY (created_by) REFERENCES public.users(uuid);
SQL_0382);

        $this->addSql(<<<'SQL_0383'
ALTER TABLE ONLY public.electricity_tariff_zones
    ADD CONSTRAINT fk_electricity_tariff_zones_deleted_by FOREIGN KEY (deleted_by) REFERENCES public.users(uuid);
SQL_0383);

        $this->addSql(<<<'SQL_0384'
ALTER TABLE ONLY public.electricity_tariff_zones
    ADD CONSTRAINT fk_electricity_tariff_zones_updated_by FOREIGN KEY (updated_by) REFERENCES public.users(uuid);
SQL_0384);

        $this->addSql(<<<'SQL_0385'
ALTER TABLE ONLY public.electricity_tariff_zones
    ADD CONSTRAINT fk_electricity_tariff_zones_workspace FOREIGN KEY (workspace_uuid) REFERENCES public.workspaces(uuid);
SQL_0385);

        $this->addSql(<<<'SQL_0386'
ALTER TABLE ONLY public.payment_requisite_assignments
    ADD CONSTRAINT fk_payment_requisite_assignments_assigned_by FOREIGN KEY (assigned_by) REFERENCES public.users(uuid);
SQL_0386);

        $this->addSql(<<<'SQL_0387'
ALTER TABLE ONLY public.payment_requisite_assignments
    ADD CONSTRAINT fk_payment_requisite_assignments_closed_by FOREIGN KEY (closed_by) REFERENCES public.users(uuid);
SQL_0387);

        $this->addSql(<<<'SQL_0388'
ALTER TABLE ONLY public.payment_requisite_assignments
    ADD CONSTRAINT fk_payment_requisite_assignments_profile FOREIGN KEY (workspace_uuid, payment_requisite_profile_uuid) REFERENCES public.payment_requisite_profiles(workspace_uuid, uuid);
SQL_0388);

        $this->addSql(<<<'SQL_0389'
ALTER TABLE ONLY public.payment_requisite_assignments
    ADD CONSTRAINT fk_payment_requisite_assignments_workspace FOREIGN KEY (workspace_uuid) REFERENCES public.workspaces(uuid);
SQL_0389);

        $this->addSql(<<<'SQL_0390'
ALTER TABLE ONLY public.payment_requisite_profiles
    ADD CONSTRAINT fk_payment_requisite_profiles_created_by FOREIGN KEY (created_by) REFERENCES public.users(uuid);
SQL_0390);

        $this->addSql(<<<'SQL_0391'
ALTER TABLE ONLY public.payment_requisite_profiles
    ADD CONSTRAINT fk_payment_requisite_profiles_deleted_by FOREIGN KEY (deleted_by) REFERENCES public.users(uuid);
SQL_0391);

        $this->addSql(<<<'SQL_0392'
ALTER TABLE ONLY public.payment_requisite_profiles
    ADD CONSTRAINT fk_payment_requisite_profiles_updated_by FOREIGN KEY (updated_by) REFERENCES public.users(uuid);
SQL_0392);

        $this->addSql(<<<'SQL_0393'
ALTER TABLE ONLY public.payment_requisite_profiles
    ADD CONSTRAINT fk_payment_requisite_profiles_workspace FOREIGN KEY (workspace_uuid) REFERENCES public.workspaces(uuid);
SQL_0393);

        $this->addSql(<<<'SQL_0394'
ALTER TABLE ONLY public.payments
    ADD CONSTRAINT fk_payments_account FOREIGN KEY (workspace_uuid, account_uuid) REFERENCES public.accounts(workspace_uuid, uuid);
SQL_0394);

        $this->addSql(<<<'SQL_0395'
ALTER TABLE ONLY public.payments
    ADD CONSTRAINT fk_payments_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES public.users(uuid);
SQL_0395);

        $this->addSql(<<<'SQL_0396'
ALTER TABLE ONLY public.payments
    ADD CONSTRAINT fk_payments_created_by FOREIGN KEY (created_by) REFERENCES public.users(uuid);
SQL_0396);

        $this->addSql(<<<'SQL_0397'
ALTER TABLE ONLY public.payments
    ADD CONSTRAINT fk_payments_replaced_by FOREIGN KEY (replaced_by) REFERENCES public.users(uuid);
SQL_0397);

        $this->addSql(<<<'SQL_0398'
ALTER TABLE ONLY public.payments
    ADD CONSTRAINT fk_payments_replacing FOREIGN KEY (workspace_uuid, replacing_payment_uuid) REFERENCES public.payments(workspace_uuid, uuid);
SQL_0398);

        $this->addSql(<<<'SQL_0399'
ALTER TABLE ONLY public.payments
    ADD CONSTRAINT fk_payments_updated_by FOREIGN KEY (updated_by) REFERENCES public.users(uuid);
SQL_0399);

        $this->addSql(<<<'SQL_0400'
ALTER TABLE ONLY public.payments
    ADD CONSTRAINT fk_payments_workspace FOREIGN KEY (workspace_uuid) REFERENCES public.workspaces(uuid);
SQL_0400);

        $this->addSql(<<<'SQL_0401'
ALTER TABLE ONLY public.subscriber_account_accesses
    ADD CONSTRAINT fk_subscriber_account_accesses_account FOREIGN KEY (workspace_uuid, account_uuid) REFERENCES public.accounts(workspace_uuid, uuid);
SQL_0401);

        $this->addSql(<<<'SQL_0402'
ALTER TABLE ONLY public.subscriber_account_accesses
    ADD CONSTRAINT fk_subscriber_account_accesses_granted_by FOREIGN KEY (granted_by) REFERENCES public.users(uuid);
SQL_0402);

        $this->addSql(<<<'SQL_0403'
ALTER TABLE ONLY public.subscriber_account_accesses
    ADD CONSTRAINT fk_subscriber_account_accesses_revoked_by FOREIGN KEY (revoked_by) REFERENCES public.users(uuid);
SQL_0403);

        $this->addSql(<<<'SQL_0404'
ALTER TABLE ONLY public.subscriber_account_accesses
    ADD CONSTRAINT fk_subscriber_account_accesses_subscriber FOREIGN KEY (workspace_uuid, subscriber_uuid) REFERENCES public.subscribers(workspace_uuid, uuid);
SQL_0404);

        $this->addSql(<<<'SQL_0405'
ALTER TABLE ONLY public.subscriber_account_accesses
    ADD CONSTRAINT fk_subscriber_account_accesses_workspace FOREIGN KEY (workspace_uuid) REFERENCES public.workspaces(uuid);
SQL_0405);

        $this->addSql(<<<'SQL_0406'
ALTER TABLE ONLY public.subscribers
    ADD CONSTRAINT fk_subscribers_created_by FOREIGN KEY (created_by) REFERENCES public.users(uuid);
SQL_0406);

        $this->addSql(<<<'SQL_0407'
ALTER TABLE ONLY public.subscribers
    ADD CONSTRAINT fk_subscribers_deleted_by FOREIGN KEY (deleted_by) REFERENCES public.users(uuid);
SQL_0407);

        $this->addSql(<<<'SQL_0408'
ALTER TABLE ONLY public.subscribers
    ADD CONSTRAINT fk_subscribers_updated_by FOREIGN KEY (updated_by) REFERENCES public.users(uuid);
SQL_0408);

        $this->addSql(<<<'SQL_0409'
ALTER TABLE ONLY public.subscribers
    ADD CONSTRAINT fk_subscribers_user FOREIGN KEY (user_uuid) REFERENCES public.users(uuid);
SQL_0409);

        $this->addSql(<<<'SQL_0410'
ALTER TABLE ONLY public.subscribers
    ADD CONSTRAINT fk_subscribers_workspace FOREIGN KEY (workspace_uuid) REFERENCES public.workspaces(uuid);
SQL_0410);

        $this->addSql(<<<'SQL_0411'
ALTER TABLE ONLY public.user_email_identities
    ADD CONSTRAINT fk_user_email_identities_created_by FOREIGN KEY (created_by) REFERENCES public.users(uuid);
SQL_0411);

        $this->addSql(<<<'SQL_0412'
ALTER TABLE ONLY public.user_email_identities
    ADD CONSTRAINT fk_user_email_identities_deleted_by FOREIGN KEY (deleted_by) REFERENCES public.users(uuid);
SQL_0412);

        $this->addSql(<<<'SQL_0413'
ALTER TABLE ONLY public.user_email_identities
    ADD CONSTRAINT fk_user_email_identities_user FOREIGN KEY (user_uuid) REFERENCES public.users(uuid) ON DELETE CASCADE;
SQL_0413);

        $this->addSql(<<<'SQL_0414'
ALTER TABLE ONLY public.user_password_credentials
    ADD CONSTRAINT fk_user_password_credentials_user FOREIGN KEY (user_uuid) REFERENCES public.users(uuid) ON DELETE CASCADE;
SQL_0414);

        $this->addSql(<<<'SQL_0415'
ALTER TABLE ONLY public.user_password_history
    ADD CONSTRAINT fk_user_password_history_changed_by FOREIGN KEY (changed_by) REFERENCES public.users(uuid);
SQL_0415);

        $this->addSql(<<<'SQL_0416'
ALTER TABLE ONLY public.user_password_history
    ADD CONSTRAINT fk_user_password_history_user FOREIGN KEY (user_uuid) REFERENCES public.users(uuid) ON DELETE CASCADE;
SQL_0416);

        $this->addSql(<<<'SQL_0417'
ALTER TABLE ONLY public.users
    ADD CONSTRAINT fk_users_admin_granted_by FOREIGN KEY (admin_granted_by) REFERENCES public.users(uuid);
SQL_0417);

        $this->addSql(<<<'SQL_0418'
ALTER TABLE ONLY public.users
    ADD CONSTRAINT fk_users_admin_revoked_by FOREIGN KEY (admin_revoked_by) REFERENCES public.users(uuid);
SQL_0418);

        $this->addSql(<<<'SQL_0419'
ALTER TABLE ONLY public.users
    ADD CONSTRAINT fk_users_approved_by FOREIGN KEY (approved_by) REFERENCES public.users(uuid);
SQL_0419);

        $this->addSql(<<<'SQL_0420'
ALTER TABLE ONLY public.users
    ADD CONSTRAINT fk_users_blocked_by FOREIGN KEY (blocked_by) REFERENCES public.users(uuid);
SQL_0420);

        $this->addSql(<<<'SQL_0421'
ALTER TABLE ONLY public.users
    ADD CONSTRAINT fk_users_created_by FOREIGN KEY (created_by) REFERENCES public.users(uuid);
SQL_0421);

        $this->addSql(<<<'SQL_0422'
ALTER TABLE ONLY public.users
    ADD CONSTRAINT fk_users_deleted_by FOREIGN KEY (deleted_by) REFERENCES public.users(uuid);
SQL_0422);

        $this->addSql(<<<'SQL_0423'
ALTER TABLE ONLY public.users
    ADD CONSTRAINT fk_users_updated_by FOREIGN KEY (updated_by) REFERENCES public.users(uuid);
SQL_0423);

        $this->addSql(<<<'SQL_0424'
ALTER TABLE ONLY public.workspace_user_role_assignments
    ADD CONSTRAINT fk_workspace_user_role_assignments_granted_by FOREIGN KEY (granted_by) REFERENCES public.users(uuid);
SQL_0424);

        $this->addSql(<<<'SQL_0425'
ALTER TABLE ONLY public.workspace_user_role_assignments
    ADD CONSTRAINT fk_workspace_user_role_assignments_revoked_by FOREIGN KEY (revoked_by) REFERENCES public.users(uuid);
SQL_0425);

        $this->addSql(<<<'SQL_0426'
ALTER TABLE ONLY public.workspace_user_role_assignments
    ADD CONSTRAINT fk_workspace_user_role_assignments_user FOREIGN KEY (user_uuid) REFERENCES public.users(uuid) ON DELETE CASCADE;
SQL_0426);

        $this->addSql(<<<'SQL_0427'
ALTER TABLE ONLY public.workspace_user_role_assignments
    ADD CONSTRAINT fk_workspace_user_role_assignments_workspace FOREIGN KEY (workspace_uuid) REFERENCES public.workspaces(uuid);
SQL_0427);

        $this->addSql(<<<'SQL_0428'
ALTER TABLE ONLY public.workspaces
    ADD CONSTRAINT fk_workspaces_created_by FOREIGN KEY (created_by) REFERENCES public.users(uuid);
SQL_0428);

        $this->addSql(<<<'SQL_0429'
ALTER TABLE ONLY public.workspaces
    ADD CONSTRAINT fk_workspaces_updated_by FOREIGN KEY (updated_by) REFERENCES public.users(uuid);
SQL_0429);

        $this->addSql(<<<'SQL_0430'
ALTER TABLE ONLY public.zavety_michurina_statement_import_batches
    ADD CONSTRAINT fk_zm_statement_import_batches_created_by FOREIGN KEY (created_by) REFERENCES public.users(uuid);
SQL_0430);

        $this->addSql(<<<'SQL_0431'
ALTER TABLE ONLY public.zavety_michurina_statement_import_batches
    ADD CONSTRAINT fk_zm_statement_import_batches_updated_by FOREIGN KEY (updated_by) REFERENCES public.users(uuid);
SQL_0431);

        $this->addSql(<<<'SQL_0432'
ALTER TABLE ONLY public.zavety_michurina_statement_import_batches
    ADD CONSTRAINT fk_zm_statement_import_batches_workspace FOREIGN KEY (workspace_uuid) REFERENCES public.workspaces(uuid);
SQL_0432);

        $this->addSql(<<<'SQL_0433'
ALTER TABLE ONLY public.zavety_michurina_statement_import_files
    ADD CONSTRAINT fk_zm_statement_import_files_batch FOREIGN KEY (batch_uuid) REFERENCES public.zavety_michurina_statement_import_batches(uuid) ON DELETE CASCADE;
SQL_0433);

        $this->addSql(<<<'SQL_0434'
ALTER TABLE ONLY public.zavety_michurina_statement_import_files
    ADD CONSTRAINT fk_zm_statement_import_files_created_by FOREIGN KEY (created_by) REFERENCES public.users(uuid);
SQL_0434);

        $this->addSql(<<<'SQL_0435'
ALTER TABLE ONLY public.zavety_michurina_statement_import_files
    ADD CONSTRAINT fk_zm_statement_import_files_updated_by FOREIGN KEY (updated_by) REFERENCES public.users(uuid);
SQL_0435);

        $this->addSql(<<<'SQL_0436'
ALTER TABLE ONLY public.zavety_michurina_statement_import_files
    ADD CONSTRAINT fk_zm_statement_import_files_workspace FOREIGN KEY (workspace_uuid) REFERENCES public.workspaces(uuid);
SQL_0436);

        $this->addSql(<<<'SQL_0437'
SET search_path = public;
SQL_0437);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql(<<<'SQL_0001'
DROP TABLE IF EXISTS public.zavety_michurina_statement_import_files CASCADE;
SQL_0001);

        $this->addSql(<<<'SQL_0002'
DROP TABLE IF EXISTS public.zavety_michurina_statement_import_batches CASCADE;
SQL_0002);

        $this->addSql(<<<'SQL_0003'
DROP TABLE IF EXISTS public.workspaces CASCADE;
SQL_0003);

        $this->addSql(<<<'SQL_0004'
DROP TABLE IF EXISTS public.workspace_user_role_assignments CASCADE;
SQL_0004);

        $this->addSql(<<<'SQL_0005'
DROP TABLE IF EXISTS public.users CASCADE;
SQL_0005);

        $this->addSql(<<<'SQL_0006'
DROP TABLE IF EXISTS public.user_password_history CASCADE;
SQL_0006);

        $this->addSql(<<<'SQL_0007'
DROP TABLE IF EXISTS public.user_password_credentials CASCADE;
SQL_0007);

        $this->addSql(<<<'SQL_0008'
DROP TABLE IF EXISTS public.user_email_identities CASCADE;
SQL_0008);

        $this->addSql(<<<'SQL_0009'
DROP TABLE IF EXISTS public.subscribers CASCADE;
SQL_0009);

        $this->addSql(<<<'SQL_0010'
DROP TABLE IF EXISTS public.subscriber_account_accesses CASCADE;
SQL_0010);

        $this->addSql(<<<'SQL_0011'
DROP TABLE IF EXISTS public.payments CASCADE;
SQL_0011);

        $this->addSql(<<<'SQL_0012'
DROP TABLE IF EXISTS public.payment_requisite_profiles CASCADE;
SQL_0012);

        $this->addSql(<<<'SQL_0013'
DROP TABLE IF EXISTS public.payment_requisite_assignments CASCADE;
SQL_0013);

        $this->addSql(<<<'SQL_0014'
DROP TABLE IF EXISTS public.electricity_tariff_zones CASCADE;
SQL_0014);

        $this->addSql(<<<'SQL_0015'
DROP TABLE IF EXISTS public.electricity_tariff_rates CASCADE;
SQL_0015);

        $this->addSql(<<<'SQL_0016'
DROP TABLE IF EXISTS public.electricity_tariff_profiles CASCADE;
SQL_0016);

        $this->addSql(<<<'SQL_0017'
DROP TABLE IF EXISTS public.electricity_tariff_periods CASCADE;
SQL_0017);

        $this->addSql(<<<'SQL_0018'
DROP TABLE IF EXISTS public.electricity_meters CASCADE;
SQL_0018);

        $this->addSql(<<<'SQL_0019'
DROP TABLE IF EXISTS public.electricity_meter_registers CASCADE;
SQL_0019);

        $this->addSql(<<<'SQL_0020'
DROP TABLE IF EXISTS public.electricity_meter_readings CASCADE;
SQL_0020);

        $this->addSql(<<<'SQL_0021'
DROP TABLE IF EXISTS public.electricity_consumption_bands CASCADE;
SQL_0021);

        $this->addSql(<<<'SQL_0022'
DROP TABLE IF EXISTS public.electricity_consumption_band_rules CASCADE;
SQL_0022);

        $this->addSql(<<<'SQL_0023'
DROP TABLE IF EXISTS public.electricity_consumption_band_rule_ranges CASCADE;
SQL_0023);

        $this->addSql(<<<'SQL_0024'
DROP TABLE IF EXISTS public.electricity_consumption_band_rule_group_scopes CASCADE;
SQL_0024);

        $this->addSql(<<<'SQL_0025'
DROP TABLE IF EXISTS public.electricity_consumption_band_rule_all_scopes CASCADE;
SQL_0025);

        $this->addSql(<<<'SQL_0026'
DROP TABLE IF EXISTS public.electricity_consumption_band_rule_account_scopes CASCADE;
SQL_0026);

        $this->addSql(<<<'SQL_0027'
DROP TABLE IF EXISTS public.electricity_accrual_registers CASCADE;
SQL_0027);

        $this->addSql(<<<'SQL_0028'
DROP TABLE IF EXISTS public.electricity_accrual_lines CASCADE;
SQL_0028);

        $this->addSql(<<<'SQL_0029'
DROP TABLE IF EXISTS public.electricity_accrual_contexts CASCADE;
SQL_0029);

        $this->addSql(<<<'SQL_0030'
DROP TABLE IF EXISTS public.billing_settings CASCADE;
SQL_0030);

        $this->addSql(<<<'SQL_0031'
DROP TABLE IF EXISTS public.billing_runs CASCADE;
SQL_0031);

        $this->addSql(<<<'SQL_0032'
DROP TABLE IF EXISTS public.billing_run_account_issues CASCADE;
SQL_0032);

        $this->addSql(<<<'SQL_0033'
DROP TABLE IF EXISTS public.audit_logs CASCADE;
SQL_0033);

        $this->addSql(<<<'SQL_0034'
DROP TABLE IF EXISTS public.accruals CASCADE;
SQL_0034);

        $this->addSql(<<<'SQL_0035'
DROP TABLE IF EXISTS public.accounts CASCADE;
SQL_0035);

        $this->addSql(<<<'SQL_0036'
DROP TABLE IF EXISTS public.account_statements CASCADE;
SQL_0036);

        $this->addSql(<<<'SQL_0037'
DROP TABLE IF EXISTS public.account_statement_payments CASCADE;
SQL_0037);

        $this->addSql(<<<'SQL_0038'
DROP TABLE IF EXISTS public.account_statement_electricity_registers CASCADE;
SQL_0038);

        $this->addSql(<<<'SQL_0039'
DROP TABLE IF EXISTS public.account_statement_electricity_lines CASCADE;
SQL_0039);

        $this->addSql(<<<'SQL_0040'
DROP TABLE IF EXISTS public.account_statement_delivery_attempts CASCADE;
SQL_0040);

        $this->addSql(<<<'SQL_0041'
DROP TABLE IF EXISTS public.account_statement_deliveries CASCADE;
SQL_0041);

        $this->addSql(<<<'SQL_0042'
DROP TABLE IF EXISTS public.account_statement_accruals CASCADE;
SQL_0042);

        $this->addSql(<<<'SQL_0043'
DROP TABLE IF EXISTS public.account_groups CASCADE;
SQL_0043);

        $this->addSql(<<<'SQL_0044'
DROP TABLE IF EXISTS public.account_group_members CASCADE;
SQL_0044);

        $this->addSql(<<<'SQL_0045'
DROP TABLE IF EXISTS public.account_electricity_tariff_profile_assignments CASCADE;
SQL_0045);

        $this->addSql(<<<'SQL_0046'
DROP FUNCTION IF EXISTS public.set_row_timestamps() CASCADE;
SQL_0046);

        $this->addSql(<<<'SQL_0047'
DROP FUNCTION IF EXISTS public.prevent_immutable_table_changes() CASCADE;
SQL_0047);

        $this->addSql(<<<'SQL_0048'
DROP COLLATION IF EXISTS public.unicode_search_ci_ai;
SQL_0048);

        $this->addSql(<<<'SQL_0049'
DROP TYPE IF EXISTS public.zavety_michurina_statement_import_file_status;
SQL_0049);

        $this->addSql(<<<'SQL_0050'
DROP TYPE IF EXISTS public.workspace_user_role_code;
SQL_0050);

        $this->addSql(<<<'SQL_0051'
DROP TYPE IF EXISTS public.subscriber_account_access_role;
SQL_0051);

        $this->addSql(<<<'SQL_0052'
DROP TYPE IF EXISTS public.payment_source;
SQL_0052);

        $this->addSql(<<<'SQL_0053'
DROP TYPE IF EXISTS public.electricity_meter_reading_source;
SQL_0053);

        $this->addSql(<<<'SQL_0054'
DROP TYPE IF EXISTS public.electricity_consumption_band_rule_scope_mode;
SQL_0054);

        $this->addSql(<<<'SQL_0055'
DROP TYPE IF EXISTS public.electricity_consumption_band_allocation_method;
SQL_0055);

        $this->addSql(<<<'SQL_0056'
DROP TYPE IF EXISTS public.billing_run_kind;
SQL_0056);

        $this->addSql(<<<'SQL_0057'
DROP TYPE IF EXISTS public.billing_run_account_issue_type;
SQL_0057);

        $this->addSql(<<<'SQL_0058'
DROP TYPE IF EXISTS public.billing_run_account_issue_close_reason;
SQL_0058);

        $this->addSql(<<<'SQL_0059'
DROP TYPE IF EXISTS public.audit_log_source;
SQL_0059);

        $this->addSql(<<<'SQL_0060'
DROP TYPE IF EXISTS public.accrual_type;
SQL_0060);

        $this->addSql(<<<'SQL_0061'
DROP TYPE IF EXISTS public.account_statement_delivery_channel;
SQL_0061);
    }
}
