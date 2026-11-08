# Final POS Engineering Review – Correction Changelog (Round 6, 8, 9 & 10 Addenda)

**Document ID:** POS-REV-2026-006
**Project:** Car-WashService
**Date:** November 8, 2026 (Updated - Round 10)
**Status:** Business Rules Harmonized – Technical Dependencies Documented – Awaiting Separate Executive Authorization

---

## Round 10 Addendum: Final Hardening, Reconciliations & Safety Controls (November 8, 2026)

Following the Round 9 GitHub architectural review, the POS documentation suite was updated to resolve six remaining technical design and operational safety issues:

### 1. Area A: UTD-1 Historical Financial Reporting & Realistic Scenarios
- **Correction of Fallback Omission:** Corrected reporting logic so that historical revenue is **never silently omitted** when an order has both historical payment information and subsequent post-cutover ledger payments.
- **Double-Counting & Omission Prevention:** The existence of a subsequent ledger payment does not automatically exclude the historical order amount, nor is it double-counted. Unreconstructable legacy split amounts are isolated as **Historical Split-Payment Reconciliation Exceptions** and reported under audit on the Exceptions tab.
- **Independent Refund Evaluation:** Historical and post-cutover refunds are evaluated completely independently from historical payments; a post-cutover refund never causes a historical payment to disappear.
- **Historical Cancellations:** Because [`init.php`](database/init.php#L242-L280) lacks `orders.refunded_amount`, pre-cutover cancellations with `payment_status = 'paid'` are classified as **Historical Unreconciled Cancellation Exceptions** rather than guessing unverified numbers.
- **Clear Metric Separation:** Reports strictly separate **Confirmed Net Settled Revenue** from **Unreconciled Historical Amounts (Under Audit)**.
- **Four Realistic Examples Added:**
  1. *Fully historical paid order:* Order #101 ($25.00) confirmed 100% in pre-cutover period without exceptions.
  2. *Historical order with subsequent ledger payment (split payment):* Order #102 ($50.00 historical total + $20.00 post-cutover ledger). $20.00 confirmed in ledger period; $50.00 isolated under audit on Exceptions tab.
  3. *Historical order with multiple post-cutover refunds:* Order #103 ($60.00 historical total; $10.00 + $15.00 post-cutover refunds). Payment confirmed in pre-cutover period; refunds recognized independently in post-cutover period.
  4. *Historical cancellation with no reconstructable refund:* Order #104 ($40.00 cancelled pre-cutover). Isolated as an unreconciled cancellation exception under audit.

### 2. Area B: UTD-4 KHQR Database Constraint Consistency (Two-Table Architecture)
- **Nullable Column Contradiction Resolved:** Documented that in MariaDB/MySQL InnoDB, `NULL != NULL` in composite unique indexes. If fields are nullable, rows containing `NULL` bypass duplicate protection.
- **Two-Table Schema Specification:**
  - `order_payments`: General tender ledger for cash and digital transactions. Cash payments carry zero digital reference requirements.
  - `order_digital_payments`: Dedicated digital ledger enforcing strictly `NOT NULL` on `provider`, `merchant_account_id`, and `payment_ref`, protected by `CONSTRAINT uq_digital_bank_ref UNIQUE (provider, merchant_account_id, payment_ref)`.
- **Reference Scoping & Unresolved Dependency:** Clarified that 4-to-8 digit reference codes are not globally unique; format, character set, and rollover cycle remain an **Unresolved Technical Dependency** pending merchant bank documentation.

### 3. Area C: UTD-3 Bank Reversal Finality & Replacement Cash Safety
- **Reversal Finality Reality:** Reaffirmed that local `cancelled` status is not proof of bank cancellation, and statement snapshots do not prove a delayed reversal won't settle later in clearing.
- **Replacement Cash Blockade:** On an offline local LAN counter without real-time bank APIs, **no automated bank finality mechanism exists**. The replacement cash payout is strictly **BLOCKED**.
- **Process Kept Unresolved:** The replacement cash refund workflow is classified as an **Unresolved Technical Dependency**, requiring an owner-approved operational procedure (e.g. formal bank clearance or mandatory dispute waiting period) before any implementation.
- **Archival Controls:** Maintained append-only audit logging (`order_refund_events`), Manager PIN authorization, atomic conditional locks, and `replaced_refund_id` linking if an operational procedure is established.

### 4. Area D: Phase 0 Strictly Read-Only Inspection Boundaries
- **Uncompromising Prohibitions:** Explicitly barred Phase 0 from starting stopped database services, repairing unstable databases, creating databases, running dumps, or performing restore operations.
- **Inspection Protocol:** If the database is stopped or down, Phase 0 inspects service status, configuration files (`my.ini`), and system event logs, reporting the status in the log without attempting to start or modify it.
- **Working Tree Preservation:** Explicitly barred cleaning, resetting, stashing, or overwriting existing user changes in the Git working tree.

### 5. Area E: Phase 5 Recovery and Rollback Safety
- **Separation of Concerns:** Strictly separated **Application Code Rollback** (reverting PHP/JS files via Git commit tag) from **Database Disaster Recovery** (restoring a data dump).
- **Transaction Loss Warning:** Documented that restoring a cold backup destroys all transactional data created after the backup timestamp.
- **Explicit Recovery Authorization:** Database recovery requires separate executive authorization, formal assessment of potential transaction loss, backup verification, and staging validation in an isolated database (`carwash_restore_test`).
- **No Direct Production Overwrite:** Directly restoring over the production database is strictly prohibited without explicit authorization.
- **Unverified Timelines Removed:** Removed all unverified claims of a "five-minute recovery or rollback time".

### 6. Area F: Git Governance & Report Accuracy
- **Activity Distinction:** Clearly distinguished local working-tree modifications from remote Git history.
- **Remote Baseline Recorded:** Acknowledged existing commit `37417efe889d18783eb3a217897eb3ae770b0113` on `origin/main`.
- **Zero Agent Commits/Pushes:** Confirmed that during this Round 10 session, zero Git commits and zero Git pushes were created by the agent. All updates exist strictly as uncommitted working-tree modifications.

---

## Round 9 Addendum Summary (November 8, 2026)
- Eliminated unsupported schema assumptions (`orders.refunded_amount`) and undefined flags (`is_initial_migration`).
- Formulated period-aware independent evaluation of payments and refunds within half-open intervals `[start_date, end_date)`.
- Replaced global `UNIQUE (payment_method, payment_ref)` with scoped digital uniqueness.
- Prohibited treating statement snapshots as proof of bank reversal non-execution.
- Renamed Phase 0 to strictly read-only inspection.

---

## Round 8 Addendum Summary (November 8, 2026)
- Removed all-or-nothing historical fallback logic.
- Defined `provider_event_at`, `recorded_at`, and `disbursed_at` / `settled_at` timestamps.
- Removed claim that local `'cancelled'` status guarantees bank non-execution.
- Standardized all cross-document links to relative repository paths.

---

## Round 7 Executive Confirmation Summary (November 8, 2026)
1. **Decision 1:** Lift First $\rightarrow$ Wash Second.
2. **Decision 2:** Advisory paper printing; electronic receipt issuance gates service.
3. **Decision 3:** Walk-in Guest defaulting; preserve `customer_name VARCHAR(100) NOT NULL`.
4. **Decision 4:** Historical records preserved intact; dedicated Exceptions tab in reports.
5. **Decision 5:** Merchant counter phone and soundbox on local LAN.
6. **Decision 6:** Net Settled Revenue $\ne$ Net Operating Profit; unrecorded COGS flagged.

---

## Inventory of Files Reviewed & Source Inspection
- Markdown reports: `POS-FINAL-BUSINESS-RULES.md`, `POS-BUSINESS-RULES-OPEN-QUESTIONS.md`, `POS-FINAL-IMPLEMENTATION-PLAN.md`, `POS-IMPLEMENTATION-TEST-MATRIX.md`.
- PHP source files: [OrderGate.php](core/OrderGate.php), [BayManager.php](core/BayManager.php), [CashierControl.php](core/CashierControl.php), [index.php](api/pos/index.php), [reports.php](views/owner/reports.php), [init.php](database/init.php).

---

## Read-Only Integrity Safeguards Confirmation
- **Zero Application Code Modified:** No PHP, JS, CSS, HTML, or configuration files were changed.
- **Zero Database Operations Executed:** No SQL migrations, queries, schema changes, backup dumps, or restore operations were run.
- **Zero Git Commits or Pushes:** No `git commit` or `git push` commands were executed during Round 10.
- **Permanent LAN Self-Sufficiency:** Counter operations maintain 0% external cloud dependencies.
- **Governance:** All 58 test cases remain planned specifications; system remains awaiting separate executive authorization.
