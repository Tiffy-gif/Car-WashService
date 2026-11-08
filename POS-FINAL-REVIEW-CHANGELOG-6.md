# Final POS Engineering Review – Correction Changelog (Round 6, 8, 9, 10, 11 & 12 Addenda)

**Document ID:** POS-REV-2026-006
**Project:** Car-WashService
**Date:** November 8, 2026 (Updated - Round 12 Closure)
**Status:** Business Rules Harmonized – Technical Dependencies Documented – Awaiting Separate Executive Authorization

---

## Round 12 Addendum: Documentation Closure, Relational Integrity & Accounting Realignment (November 8, 2026)

Following the Round 11 GitHub review, the POS documentation suite was finalized to resolve remaining technical inconsistencies across historical accounting pseudocode, database relational constraints, bank reversal safety, and reporting boundaries:

### 1. Area A: Historical Reporting & Elimination of Contradictory Logic
- **Elimination of `NOT EXISTS` Contradiction:** Removed the contradictory query clause that previously excluded an entire historical order from historical reports whenever any subsequent ledger payment existed.
- **Accounting Distinctions Codified:** Formally differentiated:
  * *Cash Received:* Physical currency or digital inflow at a specific point in time.
  * *Revenue Earned:* Value recognized upon completion of service.
  * *Accounting-Period Recognition:* Recognition of confirmed inflow/outflow strictly within `[:start_timestamp, :end_timestamp)`.
  * *Historical Order Total (`orders.total_usd`):* The static order price stored in the legacy table.
  * *Amount Historically Recognized as Paid:* Confirmed only for unconflicted pre-cutover orders where `payment_status = 'paid'`, `cancelled_at IS NULL`, and zero subsequent ledger rows exist.
  * *Subsequent Ledger Payments (`order_payments.amount_usd`):* Discrete post-cutover payments.
  * *Remaining Unreconciled Amount:* Ambiguous historical balances requiring manual audit.
- **Strict Accounting Principles:**
  - Never automatically add a subsequent ledger payment to the full historical order total.
  - Never silently exclude an entire historical order simply because a subsequent ledger payment exists.
  - Never assume the historical order total equals the amount actually paid when subsequent payments occur.
  - Do not invent an arbitrary allocation formula without supporting source data.
- **Exceptions Tab Double-Counting Defense:**
  - In Example 2 (Order #102: $50 historical total, $20 subsequent post-cutover payment), the source database cannot establish whether the $20 was part of the $50 (balance payoff) or additional to it (upsell/add-on).
  - The $20 is recognized as confirmed ledger revenue in the post-cutover period.
  - The historical $50 is **not** added to confirmed historical revenue (preventing double-counting) and **not** treated as zero. It is isolated on the **Historical Unreconciled Exceptions** tab as an unreconciled historical amount under audit for owner manual review.

### 2. Area B: Two-Table Relational Integrity, Audit Evidence & Restrictive Deletion
- **Order Alignment via Composite Foreign Keys:**
  - In `order_payments`, primary key is defined as `(id, order_id)`.
  - In `order_digital_payments`, composite foreign key `CONSTRAINT fk_digital_tender FOREIGN KEY (payment_id, order_id) REFERENCES order_payments(id, order_id) ON DELETE RESTRICT` guarantees that the digital detail's `order_id` **can never disagree with its parent tender's order**.
- **Prohibition of Cascading Deletion:**
  - Removed all `ON DELETE CASCADE` clauses from financial ledgers and audit tables (`order_payments`, `order_digital_payments`, `order_refunds`, `order_refund_events`).
  - Replaced with `ON DELETE RESTRICT`. Financial payment evidence and refund audit histories are strictly append-only and protected from accidental deletion.
- **Application Transaction Contract:**
  - Database declarative constraints alone cannot verify that every `khqr` tender has exactly one digital detail, nor that cash tenders have none.
  - Backend application transaction logic within `BEGIN ... COMMIT` enforces this conditional cardinality, executing an atomic rollback if digital validation fails.

### 3. Area C: Bank Reversal State Machine & Timeout Safety
- **Three Distinct Outcomes Clarified:**
  1. *Definitive Bank Rejection:* Bank clearinghouse explicitly rejects the reversal. Requires verifiable external confirmation (written bank statement / portal notice).
  2. *Timeout or Unknown Bank Outcome:* Network disconnect or gateway timeout occurs.
  3. *Confirmed Bank Settlement:* Reversal cleared and credited to the customer.
- **Reversal Safety Rules:**
  - A timeout must **never** be treated as a definitive bank rejection.
  - Unknown outcomes must **never be automatically retried**, as retrying in-flight requests risks duplicate debits on the banking network.
  - Unknown outcomes strictly **block replacement cash payouts**.
  - A retry is permitted ONLY when reliable evidence establishes that the original request was dropped, and an approved operational retry procedure exists.
  - Any mention of a "72-hour waiting period" is strictly an **unapproved operational example requiring bank and owner validation**. A 72-hour delay does NOT prove bank finality and does NOT permit automatic cash payouts.
  - Original reversal attempts are never deleted or overwritten; all transitions recorded in append-only `order_refund_events`.

### 4. Area D: Historical Exception Reporting Boundaries
- **Lifetime Registry vs. Period Metrics:**
  - Period financial reports measure confirmed financial activity occurring within `[:start_timestamp, :end_timestamp)`.
  - Future events never alter previously closed reporting periods.
  - Delayed notifications are assigned to reporting periods based on `recorded_at` (when registered locally), ensuring past periods remain immutable.
  - Lifetime historical exception sums are **never included in period revenue totals**; they are reported in an independent registry on the Exceptions tab for owner write-off or allocation.

### 5. Area E: Provisional KHQR Reference Specifications
- **Provisional Notice:** The description of bank references as "4 to 8 digits" is **provisional** and not a confirmed universal standard.
- **Scoped Uniqueness:** References are not globally unique; uniqueness is scoped strictly to `(provider, merchant_account_id, payment_ref)`.
- **Bank Specs Unverified:** Marked exact reference formats, lengths, character sets, and rollover cycles as an **Unresolved Technical Dependency** pending official merchant bank documentation.

### 6. Area F: Git Governance & Reporting Accuracy
- **Remote Baseline Recorded:** Acknowledged commit `9910fdac717151438b345081fada8e7e4eb709f5` as the remote base on `origin/main`.
- **Zero Agent Commits/Pushes:** Confirmed that during this Round 12 session, zero Git commits and zero Git pushes were created by the agent. All updates exist strictly as uncommitted working-tree modifications.

---

## Round 11 Addendum Summary (November 8, 2026)
- Differentiated historical order total, amount recognized as paid, subsequent ledger payments, remaining unreconciled amount, and refunds.
- Two-table schema defined with explicit foreign keys.
- Clarified bank reversal timeouts vs rejections; blocked automatic retries.
- 72-hour delay labeled as unapproved operational example.

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

## Read-Only Integrity Safeguards Confirmation
- **Zero Application Code Modified:** No PHP, JS, CSS, HTML, or configuration files were changed.
- **Zero Database Operations Executed:** No SQL migrations, queries, schema changes, backup dumps, or restore operations were run.
- **Zero Git Commits or Pushes:** No `git commit` or `git push` commands were executed during Round 12.
- **Permanent LAN Self-Sufficiency:** Counter operations maintain 0% external cloud dependencies.
- **Governance:** All 58 test cases remain planned specifications; system remains awaiting separate executive authorization.
