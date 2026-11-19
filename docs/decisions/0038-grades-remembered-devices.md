# ADR-0038: Remembered devices for teachers and students

- Status: Accepted (owner's request and approval, 2026-11-19; phase G7)
- Amends: ADR-0034 ("there is no remember me" for teachers and students)

## Context

The owner asked that a teacher's or student's ID be kept on their device,
so a second visit goes straight in unless the ID has changed. Keeping the
code itself in the browser would let anyone at that computer read it, so
the owner approved remembering the device instead (2026-11-19).

## Decision

**Opt-in.** The code page shows "Remember this device for N days" when the
owner allows it (`grade_settings.remember_days`, default 30, 0 turns it
off, at most 365). It is never ticked by default, and the hint warns
against shared computers.

**Split token.** A remembered device gets a cookie holding
`selector.validator` (24 and 40 random characters), never the code. The
table `grade_remembered_devices` keeps the selector, a SHA-256 hash of the
validator, and an HMAC (application key) fingerprint of the holder's code:
the session guard's "password" (`Teacher` uses its lock version; `Student`
uses its code digest).

The cookie is `grades_teacher_device` on `/teacher` or
`grades_student_device` on `/student`. It is HttpOnly, SameSite=Lax, secure
like the session cookie, and encrypted like every application cookie.

**Use.** Opening the code page with a valid cookie signs the holder in,
counts the sign-in, and audits it with `remembered: true`. A device is
valid only while all of these hold:

- it has not expired;
- the fingerprint matches, so a new code, or a stopped or blocked teacher
  (which raises the lock version), ends it for good;
- the holder may sign in (`maySignIn`: active, not blocked, under the cap).

Each use replaces the validator. A known selector with a wrong validator,
such as a copied cookie used after the original or a guess, deletes that
device and records `grades.device_token_mismatch`.

**Ending it.**

- Signing out forgets that device.
- The owner can forget every device of a teacher (access dialog on
  `/admin`) or of a student (`/admin/students`), audited as
  `grades.devices_forgotten`.
- Expired rows are deleted when used, and when their holder remembers
  another device.

## Consequences

- Remembering a device bypasses the 30-minute idle sign-out: the next visit
  to the code page signs the holder in again. That is the purpose of the
  option, and it is why the option is opt-in.
- The attempt limits do not apply to the cookie path. It cannot be guessed
  through: the cookie is encrypted with the application key, and a wrong
  validator deletes the device.
- Rotating `APP_KEY` forgets every device.

## Reversal

Set `remember_days` to 0 (the option disappears and cookies stop working),
or roll back `2026_11_19_100000_remembered_devices`.
