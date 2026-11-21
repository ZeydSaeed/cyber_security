# ADR-0047: Deployment on the shared EXAMIS server

- Status: Accepted
- Date: 2026-11-21

## Context

The owner chose not to buy a server: cyber_security runs on the existing
Hetzner server `examis-staging-1` (178.105.246.187, Ubuntu 24.04, arm64),
which also serves EXAMIS (External_Link) at `staff.` and `students.`
`mahani-iq.com`. CLAUDE.md forbids touching External_Link's servers without
a separate, explicit, narrowly scoped approval; the owner gave it on
2026-11-21 for: read-only inspection; creating the `cyber` user and
`/srv/cyber_security`; this application's PHP pool, PostgreSQL cluster,
services and a new Nginx site; installing missing packages only when no
version EXAMIS uses changes. Excluded: any EXAMIS file, database, service
or setting, the firewall, and existing DNS records.

## Decision

| Layer      | cyber_security                                                                                                                                                                      |
| ---------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Hosts      | `teachers.` (→ /teacher), `learn.` (→ /student), `admin.` (→ /admin) `mahani-iq.com`, proxied                                                                                       |
| User       | `cyber` (system, no shell, no sudo); `/srv/cyber_security` 750, www-data traverse ACL only                                                                                          |
| PHP        | 8.5 beside EXAMIS's 8.4; `php` alternative pinned to 8.4 (manual); pool `cyber_security`, ondemand, 8 children; generic `www` pool disabled                                         |
| PostgreSQL | own cluster `16/cyber` on 127.0.0.1:5433; roles `cyber_owner`/`cyber_app`/`cyber_backup` (ADR-0012)                                                                                 |
| Background | `cyber_security.target`: queue@fast, queue@long, outbox relay, scheduler timer; `cyber_security.slice` caps 1.5 GB / 200% CPU                                                       |
| Nginx      | `/etc/nginx/sites-available/cyber_security`, no `default_server` (EXAMIS owns it)                                                                                                   |
| TLS        | Cloudflare Origin certificate from a CSR; key made on the server, `/etc/ssl/cyber_security`, expires 2041-11-17                                                                     |
| Secrets    | `/srv/cyber_security/shared/.env` (cyber, 600); `/root/cyber_security/{db-roles,migrate}.env` (root, 600)                                                                           |
| Deploy     | root-owned `/srv/cyber_security/bin/scripts`; `APP=cyber BASE=/srv/cyber_security SMOKE_HOST=admin.mahani-iq.com MIGRATE_ENV=/root/cyber_security/migrate.env deploy.sh <artifact>` |
| Access     | SSH key `cyber_security_deploy` in root's authorized_keys (owner may remove it)                                                                                                     |

Changes the owner asked for on the same day:

- Second factor not required (`PLATFORM_REQUIRE_SECOND_FACTOR=false`):
  e-mail and password only. An accepted risk, the owner's decision.
- Portal links in the links tab and printed sheets come from
  `GRADES_TEACHER_LINK`, `GRADES_STUDENT_LINK`, `GRADES_ADMIN_LINK`.
- Backups: age recipients in `.env`; the three private identities live on
  the owner's PC only (`%USERPROFILE%\cyber_security-backup-keys`), never
  on the server. `pg_stat_statements` is not installed in this cluster.
  The pool allows `proc_open` (the web "copy of the database" runs
  pg_dump); `exec`, `system` and the rest stay disabled.
- Tunnel: SSH user `cyber_tunnel` (no shell; key restricted to
  `permitopen="127.0.0.1:5433"`) and role `cyber_readonly`
  (`pg_read_all_data`, read-only transactions, 3 connections). Opened with
  `%USERPROFILE%\cyber_security-tunnel\open-tunnel.ps1` → 127.0.0.1:55433.

PostgreSQL 16, not 18: adding the PGDG repository would let unattended
upgrades move EXAMIS's PostgreSQL 16 packages.

## Consequences

- Nginx is the one shared component; every change passes `nginx -t` first.
- The application is tested on PostgreSQL 18 in CI but runs on 16.
  Migrations pass on 16; `pg_dump --exclude-extension` (17+) is given
  only to pg_dump 17+ (BackupSettings::dumpExclusions); a backup set was
  made on the server and decrypted and read on the owner's PC.
- Baseline of EXAMIS before the change:
  `/root/cyber_security-baseline-20261121T090837Z.txt` on the server.

## Reversal

Disable `cyber_security.target` and `php8.5-fpm`, remove the Nginx site
link and reload, drop cluster `16/cyber`, remove `/srv/cyber_security`,
`/etc/ssl/cyber_security`, the users `cyber` and `cyber_tunnel`, the three DNS records, and the
SSH key line. EXAMIS is unaffected by each step.
