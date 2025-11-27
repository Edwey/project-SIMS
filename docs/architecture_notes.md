# Architecture Notes — project-SIMS

Location: `docs/architecture.mmd` (Mermaid source)

Overview
- This is a high-level system architecture for the Student Information Management System (SIMS).
- It shows clients (Admin/Instructor/Student browsers and CLI operator), the edge web server (Apache/XAMPP), the PHP app (project files), scheduled/CLI operations, and persistence/external services (MySQL, SMTP, file storage).

Key components (what to call out in a demo)
- `includes/functions.php` — core business rules: enrollment helpers, `auto_enroll_student_in_program_courses()`, advisor assignment, grading helpers, and security helpers (auth/CSRF).
- `database/setup.php` — seeder and maintenance helper (supports `--force` cleanup, seeds historical enrollments with grades).
- `database/assign_missing_advisors.php` — idempotent CLI to assign advisors to students missing one (dry-run by default).
- Database (MySQL) — schema defined in `database/schema.sql`; important tables: `users`, `students`, `instructors`, `course_sections`, `enrollments`, `grades`, `student_advisors`.

Render instructions (quick)
1. Install mermaid CLI (optional):
   - With npm (one-time):
     ```powershell
     npm i -g @mermaid-js/mermaid-cli
     ```
   - Or use `npx` without global install.

2. Render the Mermaid file to PNG (example):
   ```powershell
   cd c:\xampp\htdocs\project-SIMS
   npx @mermaid-js/mermaid-cli -i docs/architecture.mmd -o docs/architecture.png
   ```

3. Open `docs/architecture.png` for slides, or open `docs/architecture.mmd` in VS Code with the Mermaid preview extension.

Notes and tips
- For slide decks keep this diagram high-level; annotate with short strings like "enrollment rules: level & prerequisites" near the `includes/` box.
- For an engineering diagram, create another Mermaid file expanding `INC` into: `auth`, `enrollment`, `admissions`, `grading`, `notifications`, and show the `student_advisors` and `auto_enroll` flows explicitly.
- Always include the render command and a safety note when you commit PNGs to the repo (so non-technical team members can regenerate them).
