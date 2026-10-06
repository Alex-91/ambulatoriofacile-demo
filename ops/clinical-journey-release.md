# Clinical journey release

The existing agenda links saved appointments to `/cartella-clinica/esame/{id}`.
`clinical_records` is required both in navigation and on every controller/service access.
The assigned doctor may start/finish the exam and author the report. Linked operational
staff may accept the patient; only doctors may view the report in this workflow.
Signature preferences are tenant-scoped; a master configures the space and each doctor
configures their personal preferences. No credentials or remote-signing adapter are stored.

## Schema and existing data

`2026-10-06-190001_CreateClinicalJourneys` creates only `clinical_journeys` and
`clinical_journeys_events`. It does not copy test data or update existing appointments,
reports, billing data, features or defaults. Patient/doctor bindings are captured on first
acceptance and checked on subsequent access. Repeated initialization is idempotent.

Container bootstrap runs `php rest/spark clinical:journey-migrate --apply` only for active
spaces with the clinical module and an existing clinical archive. Unprepared spaces are
skipped and must use the existing clinical setup action, which also installs this migration.
`php rest/spark clinical:journey-migrate` is a read-only readiness check.

## Test-only boundaries

Test fixtures, test tables and simulation actions require the existing isolated test host
and both test flags. Production uses different tables with no simulated-signature fields.
Simulation POSTs are rejected server-side before writes. The UI omits their controls.
No feature is enabled as part of the release. No email, SMS, or WhatsApp delivery is added.

Run in the dedicated test container as www-data:

```
php rest/spark clinical:check-journey-test --production-path
php rest/spark clinical:check-journey-test
```

The first command checks the real table/code path exclusively against the isolated test
database, including immutable PDF, role boundaries, revision conflicts, identity binding,
idempotent migration and rejection of simulations. Both commands reject other runtimes.

## Remaining integrations

Remote signing remains visibly not connected and disabled until a provider is integrated
and validated. External signature intake retains the existing validator and signer identity
requirements. Protected delivery and device/PACS configuration are separate integrations.

## Rollback

Redeploy the preceding main commit if runtime checks fail. The additive migration intentionally
retains workflow history; do not drop these tables or restore older databases over new records.
