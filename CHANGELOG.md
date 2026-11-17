# Release Notes

## [Unreleased](https://github.com/gunner42/laravel-eet-module/compare/v1.0.0...main)

## [v1.0.0](https://github.com/gunner42/laravel-eet-module/commits/v1.0.0) - 2026-11-17

First release: the client side of EET 2.0 (interface v4) for Laravel 12 and 13: taxpayers, registration units, cash registers and cash register certificates, signed data messages sent to the EET service, verified confirmations (POK), automatic resubmission, reversals and a test double.

### Added

- Automated renewal of the cash register certificate via the CA EET API (JWT RS256): `Eet::renewCertificate()`, `Actions\RenewCertificate` and the `eet:certificate:renew {--taxpayer=*} {--certificate=*} {--dry-run}` command (schedule it daily in the host application). Only production certificates in the renewal window are renewed; the successor is verified against the stored certificate chain and the cash registers are switched to it in the same transaction.
- New `eet_certificate_renewals` table, `CertificateRenewal` model, `RenewalStatus` / `RenewalProblem` enums, `CertificateNotRenewable` exception and `CertificateRenewed` / `CertificateRenewalFailed` events.
- New `renewal.*` config keys (endpoint, allowed hosts, `days_before_expiry` 21, `max_wait_seconds` 120, `timeout_ms` 10000).
- `eet:status` reports certificates in the renewal window without a successor and failed renewals.

### Changed

- Importing a PKCS#12 file without a private key now reports `MissingPrivateKey` instead of `Unreadable`.
- A failed database write of a certificate (import or renewal) throws a `QueryException` without query bindings, so the message no longer contains the certificate PEM or the encrypted private key.
