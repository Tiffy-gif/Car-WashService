# Final POS Engineering Review – Correction Changelog (Round 6, 8, 9, 10 & 11 Addenda)

**Document ID:** POS-REV-2026-006
**Project:** Car-WashService
**Date:** November 8, 2026 (Updated - Round 11)
**Status:** Business Rules Harmonized – Technical Dependencies Documented – Awaiting Separate Executive Authorization

---

## Round 11 Addendum: Architectural Integrity, Split-Payment Accounting & Timeout Safety (November 8, 2026)

Following the Round 10 GitHub architectural review, the POS documentation suite was refined to resolve six technical consistency issues across historical accounting, database schema contracts, bank timeout safety, and reference specifications:

### 1. Area A: Historical Split-Payment Accounting & Allocation Clarity
- **Explicit Accounting Distinctions:** Formally differentiated:
  1. *Historical Order Total (`orders.total_usd`):* The static order price stored in the legacy table.
  2. *Amount Historically Recognized as Paid:* Verified only for unconflicted pre-cutover orders where `payment_status = 'paid'` and zero subsequent ledger rows exist.
  3. *Subsequent Ledger Payments (`order_payments.amount_usd`):* Discrete payments recorded after cutover.
  4. *Remaining Unreconciled Amount:* Ambiguous amounts requiring manual executive audit.
  5. *Subsequent Refunds (`order_refunds.amount_usd`):* Discrete refunds recorded post-cutover.
- **Strict Accounting Principles:**
  - Never automatically add a subsequent ledger payment to the full historical order total (prevents double-counting).
  - Never silently exclude an entire historical order simply because a subsequent ledger payment exists (prevents revenue loss).
  - Never assume the historical order total equals the amount actually paid when subsequent payments occur.
  - Do not invent an arbitrary allocation formula (such as `$50 - $20 = $30`) without supporting source data.
- **Exceptions Tab Double-Counting Defense:**
  - In Example 2 (Order #102: $50 historical total, $20 subsequent post-cutover payment), the source database cannot establish whether the $20 was part of the $50 (balance payoff) or additional to it (upsell/add-on).
  - The $20 is recognized as confirmed ledger revenue in the post-cutover period.
  - The historical $50 is **not** added to confirmed historical revenue (preventing double-counting) and **not** treated as zero. It is isolated on the **Historical Unreconciled Exceptions** tab as an unreconciled historical amount under audit for owner manual review.

### 2. Area B: Two-Table Digital Payment Architecture & Transaction Integrity
- **Normalized Schema Contract:** Replaced single-table proposals with a normalized two-table architecture:
  - `order_payments`: General tender ledger for cash and digital transactions. Cash payments carry zero digital reference requirements. Includes foreign key `fk_order_payments_order` to `orders(id)`.
  - `order_digital_payments`: Dedicated digital ledger enforcing mandatory `NOT NULL` for `provider`, `merchant_account_id`, and `payment_ref`, with `CONSTRAINT uq_digital_bank_ref UNIQUE (provider, merchant_account_id, payment_ref)` and foreign keys to `order_payments(id)` and `orders(id)`.
- **Application Transaction Contract:**
  - Database constraints alone cannot enforce that each digital tender has exactly one detail row, nor that cash tenders have none.
  - Backend application transaction logic enforces that every `khqr` tender must insert a valid detail record; cash tenders must never insert a detail record; and if digital insertion fails (duplicate reference or missing field), the entire database transaction is rolled back atomically.

### 3. Area C: Bank Reversal Outcomes, Timeout Safety & Unapproved Waiting Periods
- **Three Distinct Outcomes Clarified:**
  1. *Definitive Bank Rejection:* Bank clearinghouse explicitly rejects the reversal request.
  2. *Request Timeout with Unknown Bank Outcome:* Network disconnect or gateway timeout occurs.
  3. *Confirmed Bank Settlement:* Reversal cleared and credited to the customer.
- **Timeout Safety Rules:**
  - A timeout must **never** be treated as a definitive bank rejection.
  - An uncertain reversal must **never be automatically retried**, as retrying in-flight requests risks duplicate debits on the banking network.
  - When the bank outcome is uncertain, **replacement cash payout is strictly BLOCKED**.
  - A retry is permitted ONLY when reliable evidence establishes that the original request was dropped, and an approved operational retry procedure exists.
- **Unapproved 72-Hour Example Clarification:** Any mention of a "72-hour waiting period" is strictly an **unapproved operational example requiring bank and owner validation**. A 72-hour delay does NOT prove bank finality and does NOT permit automatic cash payouts.

### 4. Area D: Historical Exception Reporting Periods & Boundary Isolation
- **Lifetime Registry vs. Period Metrics:** Distinctly separated period-specific report totals (bounded strictly by `recorded_at >= :start_timestamp AND recorded_at < :end_timestamp`) from lifetime exception registries (which list all unresolved historical orders under audit).
- **Future Event Isolation:** Subqueries offsetting historical sums must enforce strict interval bounding, preventing future ledger adjustments from altering closed historical reporting periods.
- **Handling Delayed Notifications:** Late-recorded events fall into the period recorded/settled, preserving the immutability of closed past reports.

### 5. Area E: Provisional KHQR Reference Format
- **Provisional Notice:** Clarified that the description of bank references as "4 to 8 digits" is **provisional** and not a confirmed universal standard.
- **Scoped Uniqueness:** References are not globally unique; uniqueness is scoped strictly to `(provider, merchant_account_id, payment_ref)`.
- **Bank Specs Unverified:** Marked exact reference formats, lengths, character sets, and rollover cycles as an **Unresolved Technical Dependency** pending official merchant bank documentation.

### 6. Area F: Git Governance & Reporting Accuracy
- **Remote Baseline Recorded:** Acknowledged commit `c47d133571614f5bc75ae3561af93accd3d6adb0` as the remote base on `origin/main`.
- **Zero Agent Commits/Pushes:** Confirmed that during this Round 11 session, zero Git commits and zero Git pushes were created by the agent. All updates exist strictly as uncommitted working-tree modifications.

---

## Round 10 Addendum Summary (November 8, 2026)
- Eliminated fallback omission flaws; introduced four realistic historical scenarios.
- Resolved MariaDB nullable unique constraint vulnerabilities via Two-Table architecture.
- Enforced strict blockade on replacement cash payouts for uncertain bank reversals.
- Renamed Phase 0 to strictly read-only inspection; barred service start, table repairs, and database creation.
- Separated application code rollback from database disaster recovery; warned of transaction loss.

---

## Round 9 Addendum Summary (November 8, 2026)
- Eliminated unsupported schema assumptions (`orders.refunded_amount`) and undefined flags (`is_initial_migration`).
- Formulated period-aware independent evaluation of payments and refunds within half-open intervals `[start_date, end_date)`.
- Replaced global `UNIQUE (payment_method, payment_ref)` with scoped digital uniqueness.
- Prohibited treating statement snapshots as proof of bank reversal non-execution.

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
- **Zero Git Commits or Pushes:** No `git commit` or `git push` commands were executed during Round 11.
- **Permanent LAN Self-Sufficiency:** Counter operations maintain 0% external cloud dependencies.
- **Governance:** All 58 test cases remain planned specifications; system remains awaiting separate executive authorization.
