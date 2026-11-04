# NovinPay release pipeline stabilization

Status as of 2026-11-04: **historical PR #12 release-specific tooling, preserved for review; not a deployable PR #13 package**.

The preparation notes below are historical, not current production findings. The owner later reported `RELEASE PASS` at `1f39bb476fd31880600564e58599c4488622c4c0`. This preservation review did not inspect production or validate current receipts. See `AI-PROJECT-HANDOFF.md` for current staging work.

The tracked source intentionally excludes private manifests, approvals, hosting proofs, credentials, keys, archives and prior server-only helpers (`guard-v1.php`, `backup-working-tree-v2.php`, fence/rollback health helpers). It is not a standalone runnable distribution. Hard-coded release-specific paths, 14-migration/five-trigger assumptions and old source identities are incompatible with the current 22-migration/13-trigger staging candidate. Do not run or upload these entry points for PR #13. Build and review a complete new package under separate release authorization before any host execution.

Publication hygiene moved the retained credential-file fingerprint out of source: `backup.php` now requires the exact `credentialsSha256` in the approved private manifest, as the inspection entry point already did. Missing/invalid/mismatched pins fail closed. Any future package must regenerate its tool integrity manifest; old checksums are not applicable.

Local preservation validation: focused `ReleasePipelineTest`, PHP lint, PowerShell AST parsing and scoped Pint. These are source/fixture checks, not evidence of an actual backup, restore, deployment or hosted rollback. No host commands were run. All sections below describe the original preparation snapshot.
This document does not authorize running any old updater. In particular, do not
run `release-redesign-v2.sh`: it targets the intermediate PR #11 design.

## Current release and failed attempt

- Production was independently read back at `33d4a52c2c2862493dec9dcf4a81b18263cc4b74`.
- Approved final target is `1f39bb476fd31880600564e58599c4488622c4c0` (PR #11 + #12).
- Their combined diff contains eight maintenance presentation/documentation/test
  files. It changes no dependency, migration, route, authorization or payment code.
- The failed `backup-maintenance-pr12-v2.php` left only `source-commit.txt` and
  an empty `private-process-error.txt` in `maintenance-pr12-v1`. No dump, encrypted
  archive or receipt was present during API inspection. Preserve both files.
- The helper unconditionally executes `ALTER DATABASE` on the disposable restore
  database. The API confirms the account has CREATE, INSERT, REFERENCES, SELECT,
  TRIGGER there, but not ALTER. The hosted read-only inspection established that
  both defaults already match `latin1 / latin1_swedish_ci`. The statement is both
  unauthorized and unnecessary. The original discarded exception prevents proving
  whether another exception occurred first.
- The release log contains only the known PHP startup `session.gc_divisor`
  warnings, including 2026-10-27 03:50:20 UTC. That does not establish the cause
  of the caught exception. Do not modify global PHP settings on this evidence.

## Implemented locally

`scripts/release/release.php inspect-backup MANIFEST SHA256` is the versioned,
native PHP entry point for the present diagnostic. It validates its manifest and
tool hashes, account, routing, existing failed-helper integrity, exact approved
working tree, partial artifacts, the existing production environment parser,
read-only connections, restore emptiness/defaults and current schema privileges.
It cannot execute backup, ALTER, import, deployment, maintenance toggles or cleanup.
Its parser module is extracted from the reviewed backup helper; it does not boot
Laravel or open sessions. Database inspection is inside read-only transactions.

`pipeline.php` now connects the reviewed backup algorithm, an exclusive durable
journal, independent off-host verifier, fresh authenticated API quota requests,
preservation checks, a deployment fence, source fast-forward, health checks and
code-only recovery. Completed backup phases are reused only after archive hash and
receipt verification. An ambiguous interrupted import or source update stops.
`lib.php` provides tested policies for restore defaults, structured failures,
quota, recovery and post-admission health results. Hash pins, ownership, identities
and independent evidence remain mandatory; unit tests alone are not release proof.
The candidate has not yet completed a real production backup, activation or
post-release acceptance; do not claim end-to-end success until the receipts exist.

Failure records identify phase, check, category, expected condition, controlled
non-sensitive result, production-modification state, retry safety and next action.
PDO failures retain only SQLSTATE and numeric driver error, never SQL, exception
messages, credentials, traces or native stderr. PHP fatal shutdowns report the
active check. Process termination such as SIGKILL cannot print a final record;
the eventual durable `started` receipt must make that interruption detectable.

## Findings and required integration

| Weakness | Required stable behavior |
| --- | --- |
| Commit/database/path constants copied into many scripts | One pinned release manifest; no script-generation substitutions for ordinary releases. |
| Grants provisioned independently of executed SQL | Verify the SQL privilege contract before allocating output. Matching defaults require no ALTER; mismatches require a reviewed correction on the empty disposable DB only. |
| One preflight label for many operations; discarded exceptions | Per-operation identifiers and safe structured errors; private durable failure receipts. |
| Approval, runtime proof and backup all expire after one hour | Separate durable approval/integrity evidence from observations whose validity depends on change or time. |
| Static quota evidence expires while waiting for Terminal | Request fresh authenticated API quota at consumption, before backup writes and immediately before the deployment fence. Keep the 120-second bound, physical-space check and 2 GiB reserve. |
| Unreliable local `uapi` command | Authenticated HTTPS API only, bounded timeout, certificate verification, no redirects or fallback to guessed free space. |
| Windows broker tied to an old snapshot's deadline | Bind requests to the release, phase and unique request ID. Missing broker is PENDING before mutation; resume with a new quota request, not a new backup. Never move the DPAPI token to the host implicitly. |
| Existing directory means an entirely new attempt | Reconcile exact artifacts and durable phase state. Reuse completed phases only after verifying their postconditions; never replay an ambiguous import or deployment. |
| Blanket optimization/cache rebuild on template-only changes | Derive operations from the reviewed diff. This release needs no dependencies, migrations or Vite build. Verify application-scoped web behavior rather than resetting global OPcache. |
| Health transport error treated as broken application | Genuine integrity/readiness failures remain fenced. Healthy local state plus external transport uncertainty is exit 75 / PENDING, never PASS. |
| Rollback assumes an old pristine tree | Restore only reviewed code/assets; retain dirty files, new enum support, state/audit, uploads and all current live database data. |
| Cleanup interleaved with failed release recovery | Cleanup only after acceptance, by exact proven-obsolete path; preserve unknown purpose/evidence. |

## Risk and recovery policy

1. **Presentation-only:** establish exact changed-path/content review and a verified
   code-only rollback to the current baseline. Preserve current configuration,
   uploads, sessions and private maintenance history. An independently verified
   older encrypted snapshot remains a historical disaster-recovery point; its age
   alone need not force another full restore rehearsal. Reuse requires an explicit
   release policy recording those safeguards and its recovery-point limitation.
   Never describe it as protection for newer transactions and never automatically
   restore it over the live DB. The current PR #12 recovery gate is not yet passed.
2. **Application/security changes:** identify affected data/invariants and require
   compatible rollback plus current recovery evidence. Reuse infrastructure tests
   only when the relevant runtime/configuration/implementation is unchanged.
3. **Data/schema changes:** require a separately approved migration/restore plan,
   consistent current snapshot, isolated restore, off-host recovery and an explicit
   maximum acceptable data-loss window. No blanket age extension.
4. **Infrastructure changes:** require distinct authorization and effective-host
   verification. They are not part of this presentation release.

## Standard release sequence to finish integrating

1. Prepare immutable manifest: source/target/tree, exact diff, checks, tool hashes,
   preservation inventory, risk policy, backup references and rollback identity.
2. Inspect through cPanel API: paths, modes, source refs, available receipts,
   dependencies and quota. Terminal-only checks are clearly separated.
3. Validate in Terminal under an exclusive release lock. Write/fsync/atomically
   publish a `started` journal entry **before** each mutating operation. Completed
   receipts are bound to the manifest and verified postconditions. An incomplete
   journal stops for explicit reconciliation; never infer success from existence.
4. Complete only missing recovery work. Restore exclusively into the authorized
   empty disposable DB. Retain all prior backup generations and keys. Transfer
   ciphertext, decrypt and validate off-host in memory, then pin that proof.
5. Refresh API quota immediately at the activation boundary, validate preservation
   and recovery, establish the reviewed 503 fence, fast-forward exact target,
   verify local readiness, then admit traffic. No initial installation or DDL.
6. Verify HTTPS, assets, standalone 503 semantics and authorized read-only preview.
   Do not enable global maintenance just to test controls. Report PENDING if an
   essential authenticated observation remains unavailable.
7. Accept, retain the rollback/recovery set, then clean only verified obsolete
   artifacts. Report actual deleted bytes, not hypothetical savings.

The existing API connection has no arbitrary shell execution facility. A reviewed
cPanel Terminal command remains necessary. `bridge.ps1` keeps the existing DPAPI
credential on the owner workstation, responds to nonce-bound authenticated quota
requests and independently transfers/verifies the ciphertext. The bridge must be
running; if it is unavailable the host exits PENDING without bypassing evidence.
A real nonce-bound quota request was verified through the authenticated API before
the candidate was offered. This test did not execute a backup or deployment.

## Validation

Run `./scripts/test.ps1 --filter=ReleasePipelineTest`, PHP syntax checks, scoped
Pint and `git diff --check`. Tests cover policy, redaction, durable journal,
interruption, activation sequencing, encryption and an isolated code-only Git
rollback fixture. The quota bridge also has an actual authenticated API proof.
These checks do not prove a real database restore, production activation, hosted
rollback or authenticated browser acceptance. Exact-head CI is separate evidence.
No Docker, live data test writes, new keys or secret printing are required.
