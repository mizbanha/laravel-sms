# Security Policy

## Reporting a vulnerability

Please **do not open a public issue** for a security problem.

Report it privately through GitHub's private vulnerability reporting on this
repository — the **Security** tab → **Report a vulnerability**. If that is not
available to you, open a public issue that says only that you have found a
security problem and asks for a private channel, with no details in it.

Please include what you can: the affected version or commit, what an attacker
can do, and the smallest reproduction you have.

⚠️ **Never put real secrets in a report.** No API keys, no auth tokens, no
account credentials, no one-time codes, no real recipients' phone numbers, and
no unredacted provider responses — those responses can echo the request back,
including the credential that made it. Redact them, or describe the shape
instead. A report that leaks a live credential creates a second incident.

## Supported versions

No version has been released yet. Until a first tagged release exists, only the
current `main` branch is supported.

## Scope

This package stores gateway credentials encrypted at rest, deliberately does not
persist the content of messages marked sensitive, and stores one-time codes only
as hashes. Anything that defeats one of those — a credential, a message body, a
provider's echo of one, or an OTP reaching a database row, a log line or a queue
payload in the clear — is in scope and worth reporting.

Two behaviours are known and documented rather than defects:

- delivery is not exactly-once. If the process dies between a provider accepting
  a message and the attempt row being written, a later run may hand the message
  over again. See the Security section of the README.
- a message whose provider result is uncertain is settled as `unknown` and never
  automatically re-sent, which means some genuinely undelivered messages are not
  retried.
