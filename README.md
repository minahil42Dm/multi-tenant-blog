# Multi-tenant blog

Laravel 11 blog with one central database and a separate database for each tenant.

## Demo data

The demo seeder creates two local tenants through the normal tenant provisioning flow. Each tenant gets its own domain, brand color, database, writer, and sample posts. It does not create cover images or logos, and it does not delete existing tenants, databases, users, posts, or central admin accounts.

Running it again skips tenants, domains, writers, and posts that already exist. It does not reset an existing demo writer's password or rewrite posts that were already seeded.

From the project directory:

```bash
php artisan db:seed --class=DemoTenantSeeder
```

These credentials are for local development only.

| Blog | Domain | Writer email | Password |
| --- | --- | --- | --- |
| Harbor Journal | harbor.blogs.test | writer@harbor.blogs.test | password |
| Pine and Paper | pine.blogs.test | writer@pine.blogs.test | password |

Writer login is `http://harbor.blogs.test/login` and `http://pine.blogs.test/login`.

On Laravel Herd, link the hostnames before opening them in a browser:

```bash
herd link harbor.blogs
herd link pine.blogs
```

Add `127.0.0.1 harbor.blogs.test` and `127.0.0.1 pine.blogs.test` to the hosts file if they are not already mapped.
