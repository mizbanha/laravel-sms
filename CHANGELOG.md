# Changelog

All notable changes to `amidesfahani/laravel-sms` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

First public release candidate. Nothing has been tagged yet.

### Added

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

### Notes

- No driver has yet made a verified request to a live Iranian provider account; all
  five Iranian and international implementations are built from current official
  documentation and tested against faked responses.
- Delivery is not exactly-once. See the Security section of the README.
- Round-robin distribution needs a cache store whose locks every sending process
  shares. On one that cannot lock, the package logs an error and routes by
  configured priority rather than keeping a per-process counter.

[Unreleased]: https://github.com/amidesfahani/laravel-sms
