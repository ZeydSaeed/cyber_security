# ADR-0036: User management, direct permissions and visible passwords

- Status: Accepted (owner's request and decisions, 2026-11-18)
- Supersedes: the invitation part of ADR-0027
- Amends: ADR-0014 (permissions also granted directly)

## Context

The owner asked for `/admin/users` to list each person's username, email
address and password, with an eye to show or hide the password, and the
actions View, Edit and Delete. Administrators add a person with a password
they choose, change it later, and tick permissions from the full list.

A password is normally stored only as a one-way hash, which cannot be shown.

## Decision

**Visible passwords.** Besides the sign-in hash (`users.password`), the
password an administrator sets is kept encrypted with the application key
(`users.admin_password`, Laravel `encrypted` cast), so it can be decrypted
and shown.

- Only holders of `roles.manage` can read it, one person at a time, through
  `POST /admin/users/{user}/password` (`Cache-Control: no-store`, throttled
  to 60 a minute). Each showing is audited as `user.password_viewed`. The
  list page never carries passwords, only a state: `set`, `changed`,
  `unknown`.
- When the person changes their own password (settings, "forgot password"),
  the copy is cleared (owner's decision); the list shows "Changed by the
  user". A `User` saving hook does this for every path that changes
  `password` without setting `admin_password`.
- People created before this change show "Unknown" until an administrator
  sets a password for them.
- The password never enters the audit trail (`user.updated` records only
  that it changed).

**Direct permissions.** `permission_grants` holds permissions granted to one
user besides their roles. `HasRoles::hasPermission()` and the interface's
`auth.permissions` include them. Grants change only through
`SyncPermissions`: no change to one's own, the actor's right re-checked
under a lock, each grant and removal audited
(`access.permission_granted` / `access.permission_revoked`).

**Editing and deleting.** `UpdateUser` and `DeleteUser`. Roles still change
only through `AssignRole` / `RevokeRole`, so their rules hold. Deleting is
refused for oneself, for the last administrator, and for a person whose
records must stay attributed to them (for example marks they entered).

## Consequences

- Anyone with the application key and the database can read the passwords
  administrators set. Protecting `APP_KEY` and database backups is therefore
  as important as protecting the passwords themselves. Rotating `APP_KEY`
  makes earlier copies unreadable (they show as "Unknown").
- Administrators know the passwords they set. People should change their
  password after their first sign-in if that matters to them; doing so
  removes the visible copy.
- Every signed-in page runs one more indexed query (direct permissions);
  QueryBudgetTest was raised by one on purpose.
