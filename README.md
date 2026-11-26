# Multi-tenant blog

A white-label, multi-tenant blogging platform built with Laravel 11 and stancl/tenancy ^3.8. A central administrator creates tenant blogs from `blogs.test`. Each tenant is served on its own subdomain, such as `acme.blogs.test`, with its own name, logo, brand color, and MySQL database. Verified central administrators create each tenant's writers. Writers sign in on their tenant's domain and publish posts that only that tenant's visitors can see.

## Features

- Central administration on `blogs.test`, with super admin and read-only support roles.
- One MySQL database per tenant, created and migrated automatically when the tenant is created.
- Separate authentication for central users and tenant writers.
- Writer accounts created by central administrators, with writer login on each tenant domain.
- Post management for writers: create, view, edit, and delete their own posts.
- Draft, scheduled, and published post statuses.
- Scheduled publishing through the Laravel scheduler and a queued job.
- Optional cover images (JPEG, PNG, or WebP, up to 2 MB), stored separately per tenant.
- A public blog on each tenant domain that lists published posts and shows each post at `/posts/{slug}`.
- Tenant branding (name, logo, and brand color) on the public blog.
- A tenant-specific homepage cache, cleared when that tenant's posts change.
- A demo seeder for two local tenants.

## Technology stack

| Component      | Version used                                             |
| -------------- | -------------------------------------------------------- |
| PHP            | 8.4 (the locked dependencies require PHP 8.4.1 or newer) |
| Laravel        | 11.x (locked at 11.57.1)                                 |
| stancl/tenancy | ^3.8 (locked at 3.10.1)                                  |
| Inertia.js     | inertiajs/inertia-laravel ^2.0 and @inertiajs/react ^2.0 |
| React          | 18                                                       |
| Vite           | 5                                                        |
| Tailwind CSS   | 3                                                        |
| Node.js        | 24 (used locally and in CI)                              |
| MySQL          | 8.0 or newer (local development verified on 9.7.1)       |
| PHPUnit        | 10.5 (tests use SQLite in memory)                        |

The writer and admin screens come from Laravel Breeze with React and Inertia.

## Prerequisites

- PHP 8.4 with the `pdo_mysql`, `pdo_sqlite`, `mbstring`, `fileinfo`, and `gd` extensions
- Composer 2
- Node.js and npm
- MySQL 8.0 or newer, with a user that can create databases, because each new tenant gets its own database. Local development was verified on MySQL 9.7.1.
- Laravel Herd, or another web server that can serve the central domain and each tenant subdomain from this project

## Installation

From the project directory:

```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
```

## Environment and database setup

`.env.example` defaults to SQLite. This project runs on MySQL locally, so set these values in `.env`:

```dotenv
APP_URL=http://blogs.test

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=multi_tenant_blog
DB_USERNAME=root
DB_PASSWORD=
```

Use your own MySQL username and password. Keep `SESSION_DRIVER=database`, `CACHE_STORE=database`, and `QUEUE_CONNECTION=database`. Sessions, cache, and queued jobs are stored in the central database.

Create the central database `multi_tenant_blog` in MySQL, then run the central migrations:

```bash
php artisan migrate
```

This creates the central tables, including users, tenants, domains, sessions, cache, jobs, impersonation tokens and the audit log. Tenant tables live in `database/migrations/tenant` and run automatically for each new tenant. If you add tenant migrations later, apply them to existing tenants with:

```bash
php artisan tenants:migrate
```

Upgrading an existing installation to the tenant lifecycle, domain management and impersonation features needs both commands, in this order:

```bash
php artisan migrate          # tenant status, primary domains, impersonation tokens, audit log
php artisan tenants:migrate  # adds the role column to every tenant's users table
```

The primary-domain migration marks each tenant's oldest domain as primary. Existing writers get the `writer` role; none become tenant admins.

Build the frontend assets:

```bash
npm run build
```

During development you can run `npm run dev` instead.

## Laravel Herd and domains

The central domains are configured in `config/tenancy.php`: `127.0.0.1`, `localhost`, `blogs.test` and `platform.test`. The central admin app runs on `http://blogs.test`. `platform.test` is reserved for platform subdomains such as `acme.platform.test`; to use it locally, run `herd link platform` and link each tenant subdomain the same way as below (`herd link acme.platform`). Central domains can never be assigned to a tenant.

From the project directory, link the central site:

```bash
herd link blogs
```

Herd has no wildcard subdomains here, so link each tenant domain to the same project directory. For a tenant at `acme.blogs.test`:

```bash
herd link acme.blogs
```

Tenant domains are full hostnames under `blogs.test`. `herd link acme.blogs` serves `acme.blogs.test`. Run `herd links` to see which hostnames are linked.

If a hostname does not resolve in the browser, add it to the Windows hosts file. Open Notepad as Administrator, open `C:\Windows\System32\drivers\etc\hosts`, and add one line per hostname:

```text
127.0.0.1 blogs.test
127.0.0.1 acme.blogs.test
```

Every new tenant domain needs its own `herd link` and, if needed, its own hosts entry.

Tenant logos are served from `/storage/...`. Herd serves that path from `storage/app/public` directly. On other web servers, run `php artisan storage:link` once. Cover images use `/tenancy/assets/...` and do not need the link.

## Central administrator

Central users can hold one of two roles, stored in the non-fillable `role` column of the central `users` table:

| Role | Access |
| --- | --- |
| `super_admin` | Full central access: create, edit, suspend, reactivate and delete tenants, manage domains and writers, and impersonate any tenant user. |
| `support` | Read-only: view tenants and their users, and impersonate regular writers (not tenant admins). Every create, edit, suspend, reactivate, delete and domain change returns 403. |

Central users without a role can sign in, but every `/admin` page returns 403.

1. Register a central account at `http://blogs.test/register`.
2. Grant it a role from the project directory. The default role is `super_admin`:

    ```bash
    php artisan admin:promote you@example.com
    php artisan admin:promote agent@example.com --role=support --force
    ```

    The command only changes an existing central user. If another user already has a central role, it refuses unless you add `--force`.

3. Verify the account's email address. Admin pages require a verified email, and unverified administrators are redirected to the verification page. Locally, mail uses the `log` driver, so the verification link is written to `storage/logs/laravel.log`. If you promote an account before verifying it, sign in and use the link from that log file.
4. Sign in at `http://blogs.test/login` and open `http://blogs.test/admin`.

Public registration never creates an administrator.

Authorization lives in `App\Support\CentralAbility`, which defines one Gate per central ability (`central.view-tenants`, `central.manage-tenants`, `central.view-tenant-users`, `central.manage-tenant-users`, `central.impersonate-tenant-users`). Admin routes use `can:` middleware, so a manually typed URL gets the same 403 as a hidden button. The same abilities are shared with React as `auth.can` to show or hide links.

Upgrading an existing installation: run `php artisan migrate`. The `replace_is_admin_with_role_on_users_table` migration turns every existing administrator into a `super_admin` and removes the old `is_admin` column.

## Creating tenants

As a super admin, open `http://blogs.test/admin/tenants/create`. The wizard has four steps, and each step is validated on the server before you can continue. You can go back to earlier steps without losing what you entered.

1. **Details**: the blog name shown on the public site.
2. **Domains**: a primary domain such as `acme.blogs.test` and up to five additional domains. Domains are lowercased, must be valid hostnames, must not be IP addresses or central domains, and must be unique across all tenants.
3. **Branding**: a hex brand color and a JPEG, PNG, or WebP logo up to 2 MB.
4. **Tenant admin**: the name, email and password of the tenant's first account.

Nothing is created until the last step is valid. Creating the tenant then:

- creates the central tenant record and its domains (the first one primary),
- creates and migrates a database named `tenant{tenant-id}` through the tenancy pipeline, and
- creates the first account in that tenant database with the `admin` role.

If any part fails, the tenant, its domains, its database, its storage folder and the uploaded logo are all removed and the wizard shows an error. The new tenant admin can sign in at `http://{primary-domain}/login` straight away.

The tenant list at `http://blogs.test/admin/tenants` shows each tenant's name, status, domains (primary first), user and post counts, and created date. It can be searched by name or domain, filtered by status and is paginated; the search and filter stay in the URL across pages.

Then link the domain in Herd (see above) and open the blog at `http://acme.blogs.test/`.

## Managing tenants

Super admins manage a tenant from its detail page:

- **Edit** changes the name, brand color and logo. Status is never changed by editing. A replaced logo is deleted only after the new one is saved, and only if it lives in `tenant-logos/`.
- **Suspend / Reactivate** takes the public site and writer login offline (visitors see "This site is suspended") or brings them back.
- **Delete** is available only for suspended tenants. A confirmation dialog shows the tenant name and ID and requires typing the primary domain. Deletion goes through the tenancy pipeline, which drops the tenant database, then removes the domains, logo and `storage/tenant{id}` folder. If the database cannot be dropped, the error says so and the failure is recorded in the audit log for manual cleanup.

### Domains

A tenant can have one primary domain and any number of additional domains: platform subdomains (`acme.platform.test`, `acme.blogs.test`) or custom domains (`blog.acme.com`). Every domain serves the same tenant. The primary domain is used for links, the writer login hint and impersonation.

From **Edit**, super admins can add a domain, make a domain primary, or remove a non-primary domain. A tenant always keeps at least one domain and exactly one primary. Primary changes and removals run in a database transaction with the tenant's domain rows locked.

For a domain to actually reach the platform:

- **Platform subdomains** need a wildcard DNS record (`*.platform.example A <server-ip>`) and a wildcard TLS certificate for `*.platform.example`. Locally, Herd needs one `herd link` per subdomain.
- **Custom domains** need the customer to point their DNS at the platform (a `CNAME` to the platform host, or an `A` record to its IP) before the domain is added, and a TLS certificate issued for that exact hostname (for example through Let's Encrypt with on-demand issuance). Until both exist the domain is stored but will not load in a browser.

## Impersonation

Super admins and support can sign in as a tenant user to see exactly what they see. Open a tenant's **Users** page and click **Impersonate**.

- A single-use token is created in `tenant_user_impersonation_tokens` (stancl's `ImpersonationToken` model). It expires after `UserImpersonation::$ttl` seconds (60).
- The token is posted to `http://{primary-domain}/impersonate` in a form body, so it never appears in a URL, browser history, server logs or client-side storage. The tenant consumes it atomically before trusting it.
- The tenant checks the token's tenant, guard, age, target user and the impersonator's current permissions, then starts a fresh session bound to that tenant.
- A banner shows who is being impersonated and by whom, with a **Stop impersonating** button. Stopping (or logging out) ends the tenant session and returns to the central writers page. The central admin session is never touched, because session cookies are host-only.
- Support cannot impersonate tenant admins. Nested impersonation is refused, suspended tenants cannot be impersonated, and email and password changes are blocked for the whole impersonated session.

Starting and stopping impersonation, tenant creation, edits, status changes, deletions and domain changes are written to the central `audit_logs` table with the acting user, tenant ID, IP address and details. Audit entries are kept when a tenant is deleted.

## Tenant writers

Writers belong to a single tenant and are stored in that tenant's database. There is no public writer registration. A verified central administrator creates each writer:

1. Open `http://blogs.test/admin/tenants`.
2. Find the tenant and click **Add writer**.
3. Enter the writer's name, email, and password, then confirm the password.

The writer is created in that tenant's database only. An email can be used once per tenant, and the same email can exist in different tenants. Share the password with the writer securely.

Writers then use their tenant's domain:

- Sign in at `http://{tenant-domain}/login`.
- The writer dashboard is at `http://{tenant-domain}/dashboard`.

A writer account works only on the tenant domain where it was created. Central accounts cannot sign in as writers, and writers cannot sign in to the central admin area.

## Posts

Writers manage posts at `http://{tenant-domain}/posts`.

- Writers can view their own posts in any status and other writers' posts only once they are publicly published. Only the author can edit or delete a post.
- Slugs are generated from the title when the post is created and are unique within the tenant. Editing the title later does not change the slug.
- New posts default to **draft**.

### Statuses

| Status    | Publish date                    | Public?                                   |
| --------- | ------------------------------- | ----------------------------------------- |
| Draft     | Cleared                         | No                                        |
| Scheduled | Required, must be in the future | Only after the scheduled job publishes it |
| Published | Required, any valid date        | Yes, once the publish date has arrived    |

### Cover images

Covers are optional, in JPEG, PNG, or WebP format, up to 2 MB. They are stored under `storage/tenant{tenant-id}/app/public/covers/{tenant-id}/` and served through `/tenancy/assets/...` on the tenant's own domain. Replacing or removing a cover deletes the old file.

### Public blog

- `http://{tenant-domain}/` lists published posts with an excerpt, and cover image and branding when available.
- `http://{tenant-domain}/posts/{slug}` shows a single published post.
- Drafts, scheduled posts, published posts dated in the future, and other tenants' posts return 404.

## Tenant branding and homepage cache

The name, logo path, and brand color entered when the tenant is created are stored in the central tenant record. The public blog layout, homepage, and post pages use them for the blog title, logo, and accent color. An invalid or missing color falls back to `#1d4ed8`, and a missing name falls back to `Blog`. A logo appears only if its file exists in `storage/app/public/tenant-logos/`.

The public homepage post list is cached for up to one hour under the key `blog.homepage`. If a published post is dated in the future, the cache expires when that date arrives. The tenant cache prefix (`tenant_{id}_`) gives each tenant its own copy. Creating, updating, or deleting a post clears that tenant's cache, and so does the scheduled publish job. Other tenants' caches are not affected.

## Scheduler and queue worker

The `posts:publish-due` command runs every minute (see `routes/console.php`). It checks each tenant for scheduled posts that are due and queues a publish job for each one, tagged with that tenant's id. Herd does not run the scheduler or queue workers. Run both from the project directory, in separate terminals:

```bash
php artisan schedule:work
```

```bash
php artisan queue:work
```

To check for due posts once without the scheduler, run:

```bash
php artisan posts:publish-due
```

## Demo data

The demo seeder creates two local tenants through the normal tenant provisioning flow. Each tenant gets its own domain, brand color, database, writer, and sample posts. It does not create cover images or logos. It does not delete existing tenants, databases, users, posts, or central admin accounts.

Running it again skips tenants, domains, writers, and posts that already exist. It does not reset an existing demo writer's password or rewrite posts that were already seeded.

From the project directory:

```bash
php artisan db:seed --class=DemoTenantSeeder
```

`php artisan db:seed` also runs it, after creating the `test@example.com` central user if that user does not exist yet.

These credentials are for local development only.

| Blog           | Domain            | Writer email             | Password |
| -------------- | ----------------- | ------------------------ | -------- |
| Harbor Journal | harbor.blogs.test | writer@harbor.blogs.test | password |
| Pine and Paper | pine.blogs.test   | writer@pine.blogs.test   | password |

Writer login is at `http://harbor.blogs.test/login` and `http://pine.blogs.test/login`.

On Laravel Herd, link the hostnames before opening them in a browser:

```bash
herd link harbor.blogs
herd link pine.blogs
```

Add `127.0.0.1 harbor.blogs.test` and `127.0.0.1 pine.blogs.test` to the hosts file if they are not already mapped.

Each tenant gets two published posts, one draft, and one scheduled post dated 7 or 14 days ahead. The demo seeder does not create a central administrator.

## Tests and frontend build

```bash
php artisan test
npm run build
```

The tests use SQLite in memory (see `phpunit.xml`). They do not need MySQL, Herd, or tenant domains, and they never touch the local MySQL database. Tests that create tenants make temporary SQLite tenant databases and delete them afterwards.

GitHub Actions runs the same steps in `.github/workflows/ci.yml` on every pull request and on pushes to `feature/**` and `feat/**` branches.

## Development conventions

- Commit messages follow Conventional Commits in the form `type(scope): description`, for example `feat(posts): add scheduled publishing job`.
- Project rules for the Cursor editor live in `.cursor/rules/`. They cover architecture, tenant isolation, authentication, tenant infrastructure, testing and security, and commit messages.

## Known limitations

`composer audit` reports five laravel/framework advisories. Their fixes are released only for Laravel 12 and 13, and 11.57.1 is the latest Laravel 11 release, so they remain open while the project stays on Laravel 11 as the assignment requires.

## Multi-tenant architecture

**Central database** (`multi_tenant_blog`):

- central users and their central `role` (`super_admin` or `support`)
- tenants, their status, and their domains (with an `is_primary` flag)
- tenant branding, stored in the tenant record's `data` JSON column
- impersonation tokens and the audit log
- sessions, cache entries, and queued jobs

**Tenant databases** (`tenant{tenant-id}`, one per tenant):

- that tenant's users (writers and tenant admins, by `role`), in its own `users` table
- that tenant's posts

**How a tenant request is isolated:**

1. `InitializeTenancyByDomain` finds the tenant from the request's domain. `PreventAccessFromCentralDomains` blocks tenant routes on central domains.
2. The database bootstrapper switches the default connection to that tenant's database. The writer and post models refuse to run when no tenant is active.
3. The central `User` and `Tenant` models always use the central connection.
4. `ScopeSessions` binds each session to one tenant. A session cookie reused on another tenant's domain gets a 403.
5. The `writer` guard authenticates tenant writers. The `web` guard authenticates central users.
6. Cache keys are prefixed with `tenant_{id}_`, so each tenant's homepage cache is separate.
7. The filesystem bootstrapper gives each tenant its own storage folder, `storage/tenant{tenant-id}`.
8. Queued jobs carry the tenant id, and the publish job runs only in that tenant's database.

Tenancy ends after every request and after every queued job.

## What I Learned

> **Note:** This section was drafted from the code in this repository. Review it, rewrite it in your own words, and fill in the personal reflection at the end before submitting.

### Central and tenant applications

I built this as two applications in one codebase. The central application on `blogs.test` (`routes/web.php`) handles administrators and tenant management. The tenant application (`routes/tenant.php`) serves each blog on its own subdomain. Keeping the routes apart, and having `PreventAccessFromCentralDomains` block tenant routes on central domains, made it clear which context every page runs in.

### Database per tenant and domain identification

Every tenant gets its own MySQL database (`tenant{id}`). It is created and migrated by the tenancy `TenantCreated` pipeline, never by hand. `InitializeTenancyByDomain` finds the tenant from the request host, and the database bootstrapper then switches the default connection. I learned that this switch affects every model that relies on the default connection. So I pinned the central `User` model to the central connection with `CentralConnection`, and I made `Writer` and `Post` throw an exception when no tenant is active instead of quietly querying the wrong database.

### Tenant context and bootstrappers

Tenancy is a context that starts and ends. Inside it, the database, cache prefix, filesystem, and queue payloads all change. Outside it, tenant models must not run. Commands like `posts:publish-due` loop over tenants in the central context and query posts only inside `$tenant->run(...)`.

### Sessions, cache, file storage, and queued jobs

- **Sessions** stay in the central database. `ScopeSessions` binds a session to one tenant, so a cookie reused on another tenant's domain gets a 403.
- **Cache** entries are prefixed with `tenant_{id}_` by a small custom bootstrapper, because the database cache store does not support tags. That is what keeps the `blog.homepage` cache separate per tenant.
- **File storage** is re-rooted per tenant (`storage/tenant{id}`). Cover images go under `covers/{tenantId}/` and are served through `/tenancy/assets/`. Central logos have to be read from the central path, because `storage_path()` changes inside a tenant.
- **Queued jobs** carry the tenant id. `PublishScheduledPost` stores its tenant id itself, re-initializes that tenant when it runs, refuses to run for a different tenant, and ends tenancy afterwards.

### Isolation and authorization

Authentication is split into two guards: `web` for central users and `writer` for tenant writers, each with its own provider and table. Administrator access started as a non-fillable `is_admin` flag; it is now a non-fillable `role` (`super_admin` or `support`), granted only through `php artisan admin:promote`, and checked through Gates in `App\Support\CentralAbility`. Inside a tenant, `PostPolicy` limits edit and delete to the post's author, and `user_id` and `cover_image` are set on the server, never taken from form input.

### Testing and debugging

- Tests run on SQLite in memory, with temporary SQLite databases per tenant. That lets me test cross-tenant isolation without MySQL, Herd, or real domains.
- A flaky test showed me that returning `PendingDispatch` from an arrow function passed to `$tenant->run()` dispatches the job after tenancy has ended, so the payload loses its tenant id. Using a braced closure fixed it.
- Writer routes such as `posts/{post}` and the public `posts/{slug}` route collided until I constrained the writer routes to numeric ids and made slug generation avoid numeric or reserved slugs.
- Code that runs after a request (the `terminating` callback) sees tenant configuration unless the central values are captured at boot.
- A fresh `composer install` failed because the lock file no longer satisfied the version constraint. That taught me to validate `composer.json` against `composer.lock` in CI.

### Personal reflection

Building this project helped me understand how multi-tenancy works in Laravel. I learned how domains identify tenants and how each tenant can have a separate database.

The most challenging part was maintaining tenant isolation, especially for posts, writers, uploaded images, caching and scheduled jobs.

If I built this project again, I would plan the architecture more carefully and write isolation tests earlier in development.

Next, I want to learn more about scaling multi-tenant applications, production deployment, background jobs and security.
