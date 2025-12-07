-- ============================================================================
-- ONBOARDING HR-TECH PLATFORM - COMPLETE DATABASE SCHEMA
-- ============================================================================
-- A production-ready, secure database for recruitment & talent management
-- Features: RLS policies, audit trails, NO AI components
-- ============================================================================

-- Enable required extensions
create extension if not exists "uuid-ossp";
create extension if not exists "pgcrypto";

-- ============================================================================
-- ENUMS & TYPES
-- ============================================================================

create type user_role as enum ('candidate', 'employer', 'recruiter', 'admin');
create type application_status as enum ('pending', 'screening', 'shortlisted', 'interview', 'offer', 'rejected', 'withdrawn', 'hired');
create type job_type as enum ('full_time', 'part_time', 'contract', 'temporary', 'internship');
create type job_status as enum ('draft', 'active', 'closed', 'filled');
create type outsourcing_status as enum ('requested', 'assigned', 'active', 'completed', 'cancelled');
create type employment_level as enum ('entry', 'mid', 'senior', 'executive', 'c_level');
create type notification_type as enum ('application', 'interview', 'message', 'system', 'job_alert');
create type document_status as enum ('pending', 'verified', 'rejected');

-- ============================================================================
-- CORE USER TABLES
-- ============================================================================

-- Main users table (extends Supabase auth.users)
create table public.users (
  id uuid references auth.users(id) on delete cascade primary key,
  email text unique not null,
  role user_role not null default 'candidate',
  is_active boolean default true,
  is_verified boolean default false,
  last_login timestamptz,
  created_at timestamptz default now(),
  updated_at timestamptz default now()
);

-- User profiles (candidates)
create table public.user_profiles (
  id uuid primary key default gen_random_uuid(),
  user_id uuid references public.users(id) on delete cascade unique not null,
  first_name text,
  last_name text,
  phone text,
  profile_image_url text,
  headline text,
  bio text,
  location text,
  years_experience integer default 0,
  current_position text,
  current_company text,
  linkedin_url text,
  portfolio_url text,
  desired_salary_min integer,
  desired_salary_max integer,
  preferred_job_types job_type[],
  skills text[],
  languages text[],
  availability_date date,
  is_open_to_opportunities boolean default true,
  created_at timestamptz default now(),
  updated_at timestamptz default now()
);

-- Education records
create table public.education (
  id uuid primary key default gen_random_uuid(),
  user_profile_id uuid references public.user_profiles(id) on delete cascade not null,
  institution text not null,
  degree text not null,
  field_of_study text,
  start_date date,
  end_date date,
  is_current boolean default false,
  grade text,
  description text,
  created_at timestamptz default now()
);

-- Work experience
create table public.work_experience (
  id uuid primary key default gen_random_uuid(),
  user_profile_id uuid references public.user_profiles(id) on delete cascade not null,
  company text not null,
  position text not null,
  employment_type job_type,
  location text,
  start_date date not null,
  end_date date,
  is_current boolean default false,
  description text,
  achievements text[],
  created_at timestamptz default now()
);

-- ============================================================================
-- EMPLOYER & COMPANY TABLES
-- ============================================================================

-- Employers/Companies
create table public.employers (
  id uuid primary key default gen_random_uuid(),
  user_id uuid references public.users(id) on delete set null,
  company_name text not null,
  company_email text unique not null,
  company_phone text,
  website text,
  logo_url text,
  banner_url text,
  industry text,
  company_size text,
  founded_year integer,
  description text,
  address text,
  city text,
  country text,
  linkedin_url text,
  is_verified boolean default false,
  is_approved boolean default false,
  approved_by uuid references public.users(id),
  approved_at timestamptz,
  created_at timestamptz default now(),
  updated_at timestamptz default now()
);

-- Employer team members (for multi-user company accounts)
create table public.employer_team_members (
  id uuid primary key default gen_random_uuid(),
  employer_id uuid references public.employers(id) on delete cascade not null,
  user_id uuid references public.users(id) on delete cascade not null,
  role text default 'member', -- admin, member, viewer
  invited_by uuid references public.users(id),
  joined_at timestamptz default now(),
  unique(employer_id, user_id),
  
  -- Constraints
  constraint valid_team_role check (role in ('admin', 'member', 'viewer'))
);

-- ============================================================================
-- JOB POSTING & MANAGEMENT
-- ============================================================================

-- Jobs
create table public.jobs (
  id uuid primary key default gen_random_uuid(),
  employer_id uuid references public.employers(id) on delete cascade not null,
  posted_by uuid references public.users(id) on delete set null,
  assigned_recruiter_id uuid references public.users(id) on delete set null,
  
  title text not null,
  slug text unique,
  description text not null,
  responsibilities text[],
  requirements text[],
  nice_to_have text[],
  
  job_type job_type not null,
  employment_level employment_level not null,
  status job_status default 'draft',
  
  location text,
  is_remote boolean default false,
  salary_min integer,
  salary_max integer,
  salary_currency text default 'GHS',
  is_salary_visible boolean default false,
  
  apply_url text, -- external apply URL if not using built-in ATS
  application_deadline date,
  positions_available integer default 1,
  
  view_count integer default 0,
  application_count integer default 0,
  
  created_at timestamptz default now(),
  updated_at timestamptz default now(),
  published_at timestamptz,
  closed_at timestamptz,
  
  -- Constraints
  constraint salary_range_check check (salary_min is null or salary_max is null or salary_min <= salary_max),
  constraint positions_positive check (positions_available > 0),
  constraint valid_deadline check (application_deadline is null or application_deadline >= created_at::date)
);

-- Job categories/tags
create table public.job_categories (
  id uuid primary key default gen_random_uuid(),
  name text unique not null,
  slug text unique not null,
  description text,
  parent_id uuid references public.job_categories(id) on delete set null
);

create table public.job_category_mappings (
  job_id uuid references public.jobs(id) on delete cascade,
  category_id uuid references public.job_categories(id) on delete cascade,
  primary key (job_id, category_id)
);

-- ============================================================================
-- APPLICATION TRACKING SYSTEM (ATS)
-- ============================================================================

-- Custom application stages per employer (must be created BEFORE applications table)
create table public.application_stages (
  id uuid primary key default gen_random_uuid(),
  employer_id uuid references public.employers(id) on delete cascade, -- nullable for system defaults
  name text not null,
  description text,
  order_index integer not null,
  color text default '#6366f1',
  is_default boolean default false,
  created_at timestamptz default now()
);

-- Applications
create table public.applications (
  id uuid primary key default gen_random_uuid(),
  job_id uuid references public.jobs(id) on delete cascade not null,
  user_id uuid references public.users(id) on delete cascade,
  
  -- Application details (stored here for non-registered applicants)
  applicant_name text not null,
  applicant_email text not null,
  applicant_phone text,
  resume_url text,
  cover_letter text,
  portfolio_url text,
  
  -- Status & tracking
  status application_status default 'pending',
  current_stage_id uuid references public.application_stages(id) on delete set null,
  
  -- Metadata
  source text, -- direct, referral, linkedin, indeed, etc.
  referral_code text,
  applied_via text, -- 'platform', 'external'
  
  -- Timeline
  applied_at timestamptz default now(),
  last_updated_at timestamptz default now(),
  viewed_at timestamptz,
  responded_at timestamptz,
  
  -- Constraints
  constraint valid_email check (applicant_email ~* '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$')
);

-- Stage transitions/history
create table public.application_stage_history (
  id uuid primary key default gen_random_uuid(),
  application_id uuid references public.applications(id) on delete cascade not null,
  from_stage_id uuid references public.application_stages(id),
  to_stage_id uuid references public.application_stages(id) not null,
  changed_by uuid references public.users(id) not null,
  notes text,
  created_at timestamptz default now()
);

-- Recruiter notes on applications
create table public.recruiter_notes (
  id uuid primary key default gen_random_uuid(),
  application_id uuid references public.applications(id) on delete cascade not null,
  recruiter_id uuid references public.users(id) on delete cascade not null,
  note text not null,
  is_private boolean default false,
  created_at timestamptz default now(),
  updated_at timestamptz default now()
);

-- Interview scheduling
create table public.interviews (
  id uuid primary key default gen_random_uuid(),
  application_id uuid references public.applications(id) on delete cascade not null,
  job_id uuid references public.jobs(id) on delete cascade not null,
  
  interviewer_ids uuid[] not null, -- array of user IDs
  candidate_id uuid references public.users(id) not null,
  
  interview_type text, -- 'phone', 'video', 'in-person', 'technical'
  scheduled_at timestamptz not null,
  duration_minutes integer default 60,
  
  location text, -- for in-person
  meeting_link text, -- for video
  
  status text default 'scheduled', -- scheduled, completed, cancelled, rescheduled
  feedback text,
  rating integer, -- 1-5
  
  created_at timestamptz default now(),
  updated_at timestamptz default now(),
  
  -- Constraints
  constraint valid_rating check (rating is null or (rating >= 1 and rating <= 5)),
  constraint valid_duration check (duration_minutes > 0)
);

-- ============================================================================
-- CV STORAGE & DOCUMENTS
-- ============================================================================

-- CV uploads and documents
create table public.candidate_documents (
  id uuid primary key default gen_random_uuid(),
  user_id uuid references public.users(id) on delete cascade not null,
  application_id uuid references public.applications(id) on delete cascade,
  
  document_type text not null, -- 'resume', 'cover_letter', 'certificate', 'portfolio'
  file_url text not null,
  file_name text,
  file_size_kb integer,
  
  verification_status document_status default 'pending',
  verified_by uuid references public.users(id),
  verified_at timestamptz,
  rejection_reason text,
  
  is_primary boolean default false, -- primary resume
  
  uploaded_at timestamptz default now(),
  created_at timestamptz default now()
);

-- ============================================================================
-- LABOUR OUTSOURCING & WORKFORCE MANAGEMENT
-- ============================================================================

-- Outsourcing service requests
create table public.outsourcing_requests (
  id uuid primary key default gen_random_uuid(),
  employer_id uuid references public.employers(id) on delete cascade not null,
  requested_by uuid references public.users(id) not null,
  
  service_type text not null, -- 'permanent', 'contract', 'temporary', 'executive_search'
  job_title text not null,
  description text,
  number_of_positions integer default 1,
  
  required_skills text[],
  experience_level employment_level,
  duration_months integer, -- for contract/temp
  budget_min integer,
  budget_max integer,
  
  status outsourcing_status default 'requested',
  assigned_recruiter_id uuid references public.users(id),
  
  start_date date,
  end_date date,
  
  created_at timestamptz default now(),
  updated_at timestamptz default now(),
  
  -- Constraints
  constraint budget_range_check check (budget_min is null or budget_max is null or budget_min <= budget_max),
  constraint positions_positive_check check (number_of_positions > 0),
  constraint duration_positive check (duration_months is null or duration_months > 0)
);

-- Outsourced staff management
create table public.outsourced_staff (
  id uuid primary key default gen_random_uuid(),
  outsourcing_request_id uuid references public.outsourcing_requests(id) on delete set null,
  employer_id uuid references public.employers(id) on delete cascade not null,
  user_id uuid references public.users(id) on delete cascade not null,
  
  position text not null,
  employment_type job_type not null,
  
  start_date date not null,
  end_date date,
  is_active boolean default true,
  
  -- Payroll tracking
  hourly_rate numeric(10, 2),
  monthly_salary numeric(10, 2),
  currency text default 'GHS',
  
  -- Timesheet
  total_hours_worked numeric(10, 2) default 0,
  
  -- Manager/supervisor
  supervisor_id uuid references public.users(id),
  
  created_at timestamptz default now(),
  updated_at timestamptz default now(),
  terminated_at timestamptz
);

-- Timesheet entries
create table public.timesheets (
  id uuid primary key default gen_random_uuid(),
  outsourced_staff_id uuid references public.outsourced_staff(id) on delete cascade not null,
  
  work_date date not null,
  hours_worked numeric(5, 2) not null,
  description text,
  
  submitted_at timestamptz,
  approved_by uuid references public.users(id),
  approved_at timestamptz,
  status text default 'pending', -- pending, approved, rejected
  
  created_at timestamptz default now(),
  
  -- Constraints
  constraint hours_positive check (hours_worked > 0),
  constraint hours_reasonable check (hours_worked <= 24),
  constraint unique_staff_date unique (outsourced_staff_id, work_date)
);

-- ============================================================================
-- NOTIFICATIONS & MESSAGING
-- ============================================================================

-- Notifications
create table public.notifications (
  id uuid primary key default gen_random_uuid(),
  user_id uuid references public.users(id) on delete cascade not null,
  
  type notification_type not null,
  title text not null,
  message text,
  
  link text, -- URL to relevant page
  metadata jsonb, -- additional data
  
  is_read boolean default false,
  read_at timestamptz,
  
  created_at timestamptz default now()
);

-- Internal messaging between users
create table public.messages (
  id uuid primary key default gen_random_uuid(),
  sender_id uuid references public.users(id) on delete cascade not null,
  recipient_id uuid references public.users(id) on delete cascade not null,
  application_id uuid references public.applications(id) on delete set null,
  
  subject text,
  body text not null,
  
  is_read boolean default false,
  read_at timestamptz,
  
  created_at timestamptz default now()
);

-- ============================================================================
-- CONTENT MANAGEMENT (BLOG, RESOURCES)
-- ============================================================================

-- Blog posts and resources
create table public.content_posts (
  id uuid primary key default gen_random_uuid(),
  author_id uuid references public.users(id) on delete set null,
  
  title text not null,
  slug text unique not null,
  excerpt text,
  content text not null,
  featured_image_url text,
  
  category text, -- 'blog', 'career_advice', 'industry_news', 'resource'
  tags text[],
  
  is_published boolean default false,
  view_count integer default 0,
  
  published_at timestamptz,
  created_at timestamptz default now(),
  updated_at timestamptz default now()
);

-- ============================================================================
-- SAVED ITEMS & USER INTERACTIONS
-- ============================================================================

-- Saved/bookmarked jobs
create table public.saved_jobs (
  id uuid primary key default gen_random_uuid(),
  user_id uuid references public.users(id) on delete cascade not null,
  job_id uuid references public.jobs(id) on delete cascade not null,
  created_at timestamptz default now(),
  unique(user_id, job_id)
);

-- Job alerts/subscriptions
create table public.job_alerts (
  id uuid primary key default gen_random_uuid(),
  user_id uuid references public.users(id) on delete cascade not null,
  
  alert_name text not null,
  keywords text[],
  location text,
  job_type job_type,
  salary_min integer,
  
  is_active boolean default true,
  frequency text default 'daily', -- instant, daily, weekly
  
  last_sent_at timestamptz,
  created_at timestamptz default now()
);

-- ============================================================================
-- CONTACT & LEADS
-- ============================================================================

-- Contact form submissions / leads
create table public.leads (
  id uuid primary key default gen_random_uuid(),
  name text not null,
  email text not null,
  phone text,
  company text,
  message text,
  lead_type text, -- 'contact', 'employer_inquiry', 'partnership', 'support'
  source text, -- 'website', 'referral', 'campaign'
  
  is_contacted boolean default false,
  contacted_by uuid references public.users(id),
  contacted_at timestamptz,
  
  created_at timestamptz default now()
);

-- ============================================================================
-- SYSTEM & AUDIT TABLES
-- ============================================================================

-- Audit log for critical actions
create table public.audit_logs (
  id uuid primary key default gen_random_uuid(),
  user_id uuid references public.users(id) on delete set null,
  
  action text not null, -- 'create', 'update', 'delete', 'approve', 'reject'
  table_name text not null,
  record_id uuid,
  
  old_data jsonb,
  new_data jsonb,
  
  ip_address inet,
  user_agent text,
  
  created_at timestamptz default now()
);

-- System settings
create table public.system_settings (
  key text primary key,
  value jsonb not null,
  description text,
  updated_at timestamptz default now(),
  updated_by uuid references public.users(id)
);

-- ============================================================================
-- INDEXES FOR PERFORMANCE
-- ============================================================================

-- User indexes
create index idx_users_role on public.users(role);
create index idx_users_email on public.users(email);

-- Job indexes
create index idx_jobs_employer on public.jobs(employer_id);
create index idx_jobs_status on public.jobs(status);
create index idx_jobs_published_at on public.jobs(published_at desc);
create index idx_jobs_location on public.jobs(location);
create index idx_jobs_job_type on public.jobs(job_type);
create index idx_jobs_slug on public.jobs(slug);

-- Composite indexes for common queries
create index idx_jobs_active_location on public.jobs(status, location) where status = 'active';
create index idx_jobs_active_type on public.jobs(status, job_type) where status = 'active';
create index idx_jobs_search on public.jobs(status, job_type, location) where status = 'active';
create index idx_jobs_employer_status on public.jobs(employer_id, status);
create index idx_jobs_search_composite on public.jobs(status, job_type, employment_level, location) where status = 'active';

-- Application indexes
create index idx_applications_job on public.applications(job_id);
create index idx_applications_user on public.applications(user_id);
create index idx_applications_status on public.applications(status);
create index idx_applications_applied_at on public.applications(applied_at desc);

-- Composite indexes for filtering
create index idx_applications_job_status on public.applications(job_id, status);
create index idx_applications_user_status on public.applications(user_id, status);
create index idx_applications_employer_dashboard on public.applications(job_id, status, applied_at desc);
create index idx_user_applications on public.applications(user_id, status, applied_at desc);

-- Notification indexes
create index idx_notifications_user_unread on public.notifications(user_id, is_read);
create index idx_notifications_created on public.notifications(created_at desc);
create index idx_notifications_user_type on public.notifications(user_id, type);

-- Timesheet indexes
create index idx_timesheets_staff on public.timesheets(outsourced_staff_id);
create index idx_timesheets_status on public.timesheets(status);
create index idx_timesheets_date on public.timesheets(work_date desc);

-- Document indexes
create index idx_candidate_documents_user on public.candidate_documents(user_id);
create index idx_candidate_documents_type on public.candidate_documents(document_type);

-- Interview indexes
create index idx_interviews_candidate on public.interviews(candidate_id);
create index idx_interviews_scheduled on public.interviews(scheduled_at);
create index idx_interviews_application on public.interviews(application_id);

-- ============================================================================
-- ROW LEVEL SECURITY (RLS) POLICIES
-- ============================================================================

-- Enable RLS on all tables
alter table public.users enable row level security;
alter table public.user_profiles enable row level security;
alter table public.education enable row level security;
alter table public.work_experience enable row level security;
alter table public.employers enable row level security;
alter table public.employer_team_members enable row level security;
alter table public.jobs enable row level security;
alter table public.applications enable row level security;
alter table public.application_stages enable row level security;
alter table public.application_stage_history enable row level security;
alter table public.recruiter_notes enable row level security;
alter table public.interviews enable row level security;
alter table public.candidate_documents enable row level security;
alter table public.outsourcing_requests enable row level security;
alter table public.outsourced_staff enable row level security;
alter table public.timesheets enable row level security;
alter table public.notifications enable row level security;
alter table public.messages enable row level security;
alter table public.saved_jobs enable row level security;
alter table public.job_alerts enable row level security;
alter table public.audit_logs enable row level security;

-- Users: can only read their own record
create policy "Users can view own record"
  on public.users for select
  using (auth.uid() = id);

create policy "Users can update own record"
  on public.users for update
  using (auth.uid() = id);

-- User Profiles: candidates can manage their own
create policy "Users can view own profile"
  on public.user_profiles for select
  using (auth.uid() = user_id);

create policy "Users can update own profile"
  on public.user_profiles for all
  using (auth.uid() = user_id);

-- Employers: team members can view/update their employer
create policy "Employers viewable by team members"
  on public.employers for select
  using (
    exists (
      select 1 from public.employer_team_members
      where employer_id = employers.id
      and user_id = auth.uid()
    )
  );

-- Jobs: public can view active jobs
create policy "Anyone can view active jobs"
  on public.jobs for select
  using (status = 'active' and published_at is not null);

create policy "Employers can manage own jobs"
  on public.jobs for all
  using (
    exists (
      select 1 from public.employer_team_members
      where employer_id = jobs.employer_id
      and user_id = auth.uid()
      and role in ('admin', 'member') -- viewers cannot edit
    )
  );

-- Separate policy for job updates (only admins)
create policy "Employer admins can update jobs"
  on public.jobs for update
  using (
    exists (
      select 1 from public.employer_team_members
      where employer_id = jobs.employer_id
      and user_id = auth.uid()
      and role = 'admin'
    )
  );

-- Applications: candidates see own, employers see for their jobs
create policy "Candidates can view own applications"
  on public.applications for select
  using (auth.uid() = user_id);

create policy "Employers can view applications for their jobs"
  on public.applications for select
  using (
    exists (
      select 1 from public.jobs j
      join public.employer_team_members etm on j.employer_id = etm.employer_id
      where j.id = applications.job_id
      and etm.user_id = auth.uid()
    )
  );

create policy "Candidates can create applications"
  on public.applications for insert
  with check (auth.uid() = user_id);

-- Notifications: users see only their own
create policy "Users can view own notifications"
  on public.notifications for select
  using (auth.uid() = user_id);

create policy "Users can update own notifications"
  on public.notifications for update
  using (auth.uid() = user_id);

-- Saved Jobs: users manage their own
create policy "Users can manage saved jobs"
  on public.saved_jobs for all
  using (auth.uid() = user_id);

-- Interviews: visible to participants
create policy "Interviews visible to participants"
  on public.interviews for select
  using (
    auth.uid() = candidate_id 
    or auth.uid() = any(interviewer_ids)
    or exists (
      select 1 from public.employer_team_members etm
      join public.jobs j on j.employer_id = etm.employer_id
      where j.id = interviews.job_id
      and etm.user_id = auth.uid()
      and etm.role in ('admin', 'member')
    )
  );

-- ============================================================================
-- FUNCTIONS & TRIGGERS
-- ============================================================================

-- Function: Update updated_at timestamp
create or replace function public.update_updated_at_column()
returns trigger as $$
begin
  new.updated_at = now();
  return new;
end;
$$ language plpgsql;

-- Apply updated_at trigger to relevant tables
create trigger update_users_updated_at before update on public.users
  for each row execute function public.update_updated_at_column();

create trigger update_user_profiles_updated_at before update on public.user_profiles
  for each row execute function public.update_updated_at_column();

create trigger update_employers_updated_at before update on public.employers
  for each row execute function public.update_updated_at_column();

create trigger update_jobs_updated_at before update on public.jobs
  for each row execute function public.update_updated_at_column();

create trigger update_applications_updated_at before update on public.applications
  for each row execute function public.update_updated_at_column();

create trigger update_interviews_updated_at before update on public.interviews
  for each row execute function public.update_updated_at_column();

create trigger update_outsourced_staff_updated_at before update on public.outsourced_staff
  for each row execute function public.update_updated_at_column();

create trigger update_outsourcing_requests_updated_at before update on public.outsourcing_requests
  for each row execute function public.update_updated_at_column();

create trigger update_content_posts_updated_at before update on public.content_posts
  for each row execute function public.update_updated_at_column();

-- Function: Increment job application count
create or replace function public.increment_application_count()
returns trigger as $$
begin
  update public.jobs
  set application_count = application_count + 1
  where id = new.job_id;
  return new;
end;
$$ language plpgsql;

create trigger increment_job_application_count
  after insert on public.applications
  for each row execute function public.increment_application_count();

-- Function: Create audit log entry
create or replace function public.create_audit_log()
returns trigger as $$
begin
  insert into public.audit_logs (
    user_id,
    action,
    table_name,
    record_id,
    old_data,
    new_data
  ) values (
    auth.uid(),
    tg_op,
    tg_table_name,
    coalesce(new.id, old.id),
    case when tg_op = 'DELETE' then row_to_json(old) else null end,
    case when tg_op in ('INSERT', 'UPDATE') then row_to_json(new) else null end
  );
  return coalesce(new, old);
end;
$$ language plpgsql security definer;

-- Apply audit triggers to critical tables
create trigger audit_jobs after insert or update or delete on public.jobs
  for each row execute function public.create_audit_log();

create trigger audit_applications after insert or update or delete on public.applications
  for each row execute function public.create_audit_log();

create trigger audit_employers after insert or update or delete on public.employers
  for each row execute function public.create_audit_log();

create trigger audit_outsourced_staff after insert or update or delete on public.outsourced_staff
  for each row execute function public.create_audit_log();

-- Function: Auto-update job published_at when status changes to active
create or replace function public.update_job_published_date()
returns trigger as $$
begin
  if new.status = 'active' and old.status != 'active' then
    new.published_at = now();
  elsif new.status != 'active' and old.status = 'active' then
    new.closed_at = now();
  end if;
  return new;
end;
$$ language plpgsql;

create trigger trigger_update_job_published_date
  before update on public.jobs
  for each row execute function public.update_job_published_date();

-- ============================================================================
-- UTILITY FUNCTIONS
-- ============================================================================

-- Email validation function (FIXED)
create or replace function public.validate_email(email text)
returns boolean
language sql
immutable
returns null on null input
as $$
  select email ~* '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$'
$$;

-- Phone formatting function (for Ghana)
create or replace function public.format_phone(phone text)
returns text
language sql
immutable
as $$
  select regexp_replace(phone, '[^\d+]', '', 'g')
$$;

-- ============================================================================
-- MATERIALIZED VIEWS FOR ANALYTICS
-- ============================================================================

-- Employer dashboard statistics
create materialized view public.employer_dashboard_stats as
select 
  e.id as employer_id,
  e.company_name,
  count(distinct j.id) as total_jobs,
  count(distinct case when j.status = 'active' then j.id end) as active_jobs,
  count(distinct a.id) as total_applications,
  count(distinct case when a.status = 'hired' then a.id end) as total_hires,
  count(distinct case when a.status = 'interview' then a.id end) as pending_interviews,
  avg(extract(epoch from (a.responded_at - a.applied_at))/3600)::integer as avg_response_hours
from public.employers e
left join public.jobs j on j.employer_id = e.id
left join public.applications a on a.job_id = j.id
group by e.id, e.company_name;

-- Create unique index for concurrent refresh
create unique index idx_employer_dashboard_stats_employer on public.employer_dashboard_stats(employer_id);

-- Refresh function
create or replace function public.refresh_dashboard_stats()
returns void
language plpgsql
as $$
begin
  refresh materialized view concurrently public.employer_dashboard_stats;
end;
$$;

-- ============================================================================
-- SYSTEM HEALTH & MONITORING
-- ============================================================================

-- Health check table for monitoring
create table public.system_health (
  id uuid primary key default gen_random_uuid(),
  check_name text not null,
  status text not null, -- 'healthy', 'degraded', 'down'
  message text,
  response_time_ms integer,
  checked_at timestamptz default now()
);

create index idx_system_health_checked on public.system_health(checked_at desc);

-- ============================================================================
-- INITIAL DATA SEEDING
-- ============================================================================

-- Default application stages (run AFTER tables are created)
do $$
begin
  insert into public.application_stages (employer_id, name, order_index, color, is_default) values
  (null, 'Applied', 1, '#6366f1', true),
  (null, 'Screening', 2, '#f59e0b', true),
  (null, 'Interview', 3, '#8b5cf6', true),
  (null, 'Offer', 4, '#10b981', true),
  (null, 'Hired', 5, '#059669', true),
  (null, 'Rejected', 6, '#ef4444', true)
  on conflict do nothing;

  -- Default job categories
  insert into public.job_categories (name, slug, description) values
  ('Technology', 'technology', 'Software development, IT, and tech roles'),
  ('Sales & Marketing', 'sales-marketing', 'Sales, marketing, and business development'),
  ('Finance & Accounting', 'finance-accounting', 'Accounting, finance, and audit roles'),
  ('Human Resources', 'human-resources', 'HR, recruitment, and people operations'),
  ('Operations', 'operations', 'Operations, logistics, and supply chain'),
  ('Customer Service', 'customer-service', 'Customer support and service roles'),
  ('Healthcare', 'healthcare', 'Medical and healthcare positions'),
  ('Education', 'education', 'Teaching and educational roles'),
  ('Engineering', 'engineering', 'Engineering and technical positions'),
  ('Administration', 'administration', 'Administrative and office support')
  on conflict do nothing;
end $$;

-- ============================================================================
-- GRANT PERMISSIONS
-- ============================================================================

-- Grant authenticated users appropriate access
grant usage on schema public to authenticated;
grant all on all tables in schema public to authenticated;
grant all on all sequences in schema public to authenticated;

-- ============================================================================
-- COMMENTS FOR DOCUMENTATION
-- ============================================================================

comment on table public.users is 'Core users table extending Supabase auth';
comment on table public.jobs is 'Job postings with comprehensive metadata';
comment on table public.applications is 'Job applications with ATS tracking';
comment on table public.candidate_documents is 'CV and document storage for candidates';
comment on table public.outsourced_staff is 'Workforce management for outsourced employees';
comment on table public.audit_logs is 'Comprehensive audit trail for compliance';
comment on table public.employer_dashboard_stats is 'Materialized view for fast dashboard loading';

-- Important column documentation
comment on column public.jobs.slug is 'URL-friendly job title for SEO';
comment on column public.applications.source is 'Traffic source for analytics (direct, linkedin, indeed, etc)';
comment on column public.outsourced_staff.total_hours_worked is 'Cumulative hours worked for payroll calculation';
comment on column public.notifications.metadata is 'Flexible JSONB storage for notification-specific data';
comment on column public.applications.applicant_name is 'Snapshot of name at time of application (may differ from current profile)';
comment on column public.applications.applicant_email is 'Snapshot of email at time of application (may differ from current profile)';
comment on column public.employer_team_members.role is 'Team member role: admin (full control), member (edit), viewer (read-only)';

-- ============================================================================
-- DATA RETENTION & GDPR COMPLIANCE NOTES
-- ============================================================================

comment on table public.audit_logs is 'Audit logs should be retained for 7 years for compliance. Consider partitioning by month.';
comment on table public.applications is 'Consider archiving applications older than 2 years. Implement right-to-be-forgotten for GDPR.';

-- ============================================================================
-- BACKUP & MAINTENANCE RECOMMENDATIONS
-- ============================================================================

-- RECOMMENDED BACKUP SCHEDULE:
-- - Full backup: Daily
-- - Point-in-time recovery: Enabled (Supabase default)
-- - Archive old audit_logs: Monthly
-- - Refresh materialized views: Daily or on-demand

-- RECOMMENDED MAINTENANCE:
-- - VACUUM ANALYZE: Weekly on large tables (jobs, applications, audit_logs)
-- - Reindex: Monthly
-- - Monitor slow queries: Use pg_stat_statements

-- ============================================================================
-- END OF SCHEMA
-- ============================================================================