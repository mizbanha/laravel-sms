# Changelog

All notable changes to `amidesfahani/laravel-sms` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

First public release candidate. Nothing has been tagged yet.

### Changed

- ⚠️ **The configuration namespace is `laravel-sms`, not `sms`.** The published file is
  `config/laravel-sms.php`, every setting is read as `config('laravel-sms.*')`, and the publish tags are
  `laravel-sms-config` and `laravel-sms-migrations`. `sms` is too generic a key for a package to claim, and
  the first application to install this one had owned `config/sms.php` for eleven stages: Laravel merges a
  package's config into the application's shallowly, application on top, so the two files silently fought
  over every key they shared — `drivers` was `name => [driver => class, …]` on one side and `name => class`
  on the other, and nothing threw. Keeping the generic name free lets an application's own SMS subsystem
  and this package coexist with no bridge and no precedence rule. There is **no fallback to `sms.*`** and no
  way to select the namespace from the environment. Corrected before the first tag, so nothing is owed
  backwards compatibility. ⚠️ **Environment variable names are unchanged** and still read `SMS_*`; they
  were never what collided.

### Added

- **Configurable table names.** All five tables this package creates can be renamed from
  `config('laravel-sms.tables')` before the first migration, for an application that already owns a table called
  `sms_messages` or `sms_templates`. The defaults are exactly the names this package has always used, so
  an installation that configures nothing is unaffected. Migrations, models, relations, foreign keys,
  index names and the router's one raw join all resolve through `Amid\Sms\Support\TableNames`; a
  management layer built on this package inherits the mapping through the models and needs no setting of
  its own. An invalid or duplicated name throws `InvalidTableConfiguration` rather than falling back —
  falling back would point the package at the application's own data. ⚠️ It is a schema mapping decided
  at installation, **not** a rename: changing it later moves no rows.
- **`PendingMessage::viaGateway()`** — pin one logical send to one gateway, for
  management layers that need to prove a gateway rather than route around it. An
  ordinary send with a narrower candidate list: every rule still applies, it never
  fails over, it never advances a round-robin or weighted round-robin cursor, and it
  never bypasses an open circuit. Synchronous only; `queue()` refuses a pinned send.
- **`GatewayRegistry::capabilitiesFor()`** — what a registered driver can do, asked
  by name rather than by gateway, so a management layer never has to keep its own
  copy of the capability table. Contacts nobody and reads no credential.
- **Provider-neutral send pipeline.** One logical message, recorded before anything
  is sent, delivered synchronously or through the queue, with a structured result
  rather than a boolean and a string.
- **Six drivers.** `log` (writes to a log channel, contacts nobody), Kavenegar,
  SMS.ir, IPPanel and Melipayamak for Iran, and Twilio for international
  destinations.
- **Runtime-configurable gateways.** Driver, sender, priority, enabled state,
  per-driver options and credentials live in the database and are editable without
  a deployment. Credentials are encrypted at rest and kept out of logs, dumps,
  exceptions and stored provider payloads.
- **Logical templates.** A template is a message, not a delivery mode: the same
  template can be a registered pattern at one gateway and free text at another.
- **Ordered per-gateway parameter mapping**, so positional providers (Kavenegar,
  Melipayamak) and named providers (SMS.ir, IPPanel) can be fed from one set of
  logical variables.
- **Messages and attempts as separate records**, so "we told this customer" and
  "the first gateway timed out and the second accepted it" are both answerable.
- **Safe failover** across gateways in priority order, guarded by a structured
  result: it stops on acceptance, stops on an uncertain result, and stops on a
  refusal another gateway would repeat.
- **Queue retry with a single owner**, an idempotency lock, and encrypted job
  payloads.
- **E.164 phone normalisation** over libphonenumber, behind the package's own
  contract.
- **Country-aware routing.** A gateway declares the countries it serves; an
  ineligible gateway is not called, records no attempt and consumes no failover
  budget.
- **Sensitive messages.** A template can be marked sensitive: no body, no
  variables, no provider payload and no provider prose is persisted, and the local
  log driver writes metadata only.
- **Package-owned OTP.** Codes are generated here, stored hashed, verified here and
  delivered through the ordinary pipeline — so one code can fail over between
  providers. Purpose-namespaced, single use, attempt-limited, with a race-safe
  resend cooldown. No result ever contains the code.
- **Delivery status**, on its own axis from the send outcome, refreshed explicitly
  from Twilio and IPPanel. No raw report is persisted.
- **Gateway circuit breaker.** After repeated transport failures a gateway is
  skipped for a cooldown and then given exactly one probe, so a provider outage
  does not cost every message a timeout. Cache-only, and it can never rescue the
  message that tripped it.
- **Routing strategies.** A template chooses how its messages pick a starting
  gateway: `priority` (the default and the previous behaviour), `round_robin`, or
  `weighted_round_robin` over per-binding weights that are ratios rather than
  percentages. Deterministic, never random; the cursor is shared through the cache
  so queue workers distribute together; and a gateway that is ineligible or
  circuit-open takes no share. Selection only — every failover rule is unchanged,
  and a queued retry keeps the gateway its message was first given.

### Fixed

- ⚠️ **Melipayamak: a text send refused for an unauthorised IP would not fail over.** The vendor documents
  `-111` — "IP درخواست کننده نامعتبر است" — for `SendSMS` as well as for `BaseServiceNumber`, and this
  driver listed it for the pattern endpoint only. A code the endpoint's own table does not carry falls
  through to the unknown-code path, which claims no meaning and, correctly for something genuinely
  unknown, never fails over. Here that caution was precisely wrong: an API allowlist belongs to the
  **account**, so the next gateway was the one route that would have carried the message, and it was the
  one route ruled out. The meaning was already in the driver's table; only the endpoint's membership list
  was missing it. Found by reading the vendor's own PDF guides against the driver.
- ⚠️ **Melipayamak: the text acknowledgement `1` was stored as a provider message id.** `SendSMS`
  documents `1` among its return values as "درخواست با موفقیت انجام شد" — the request succeeded. It is a
  success sentinel, not an identifier, and the driver accepts any positive number the endpoint does not
  document as an error, so it was accepted **as** a recId. The outcome was never wrong, which is why
  nothing caught it; the record was — a message filed against provider id `1`, which a later
  `GetDeliveries2` lookup will answer for, about somebody else's message or about nothing. Such a send is
  now accepted with no provider id, which is what the response actually contains. ⚠️ Text only:
  `BaseServiceNumber` publishes no such sentinel and a bare `1` there remains a refusal.

- **A driver could carry stale configuration within one request.** `GatewayRegistry`
  memoised driver instances keyed on the gateway's primary key, which does not change
  when its credentials, sender or options do — so a gateway edited and then resolved
  again inside the same request was handed a driver still holding the old settings.
  Instances are no longer cached: a driver in this package is a configuration wrapper
  with no connection and no mutable state, so building a fresh one costs nothing worth
  a class of bug that could only be avoided by every caller remembering to invalidate
  something.

### Notes

- No driver has yet made a verified request to a live Iranian provider account; all
  five Iranian and international implementations are built from current official
  documentation and tested against faked responses.
- Delivery is not exactly-once. See the Security section of the README.
- Round-robin distribution needs a cache store whose locks every sending process
  shares. On one that cannot lock, the package logs an error and routes by
  configured priority rather than keeping a per-process counter.

[Unreleased]: https://github.com/amidesfahani/laravel-sms
