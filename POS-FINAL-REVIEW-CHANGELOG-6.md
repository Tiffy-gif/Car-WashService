# Final POS Engineering Review – Correction Changelog (Round 6, 8, 9, 10, 11, 12 & 13 Addenda)

**Document ID:** POS-REV-2026-006
**Project:** Car-WashService
**Date:** November 8, 2026 (Updated - Round 13 Review)
**Status:** Business Rules Harmonized – Technical Dependencies Documented – Awaiting Separate Executive Authorization

---

## Round 13 Addendum: Historical Accounting Pseudocode, Exception Lifecycle & KHQR Pending Reference Harmonization (November 8, 2026)

Following the Round 12 GitHub review, the four POS engineering documents were corrected to resolve remaining technical inconsistencies across historical accounting queries, exception resolution lifecycles, and pending digital payment lifecycles:

### 1. Correction A: Historical Accounting Pseudocode & Query Realignment
- **Elimination of Contradictory `NOT EXISTS` Query:**
  - Removed the contradictory `AND NOT EXISTS (SELECT 1 FROM order_payments op WHERE op.order_id = o.id)` clause that previously silently dropped historical pre-cutover orders from historical reports whenever any subsequent ledger payment existed.
  - No historical order is ever silently omitted from financial visibility because a subsequent payment or refund occurred.
- **Strict Separation of Accounting Concepts:**
  - Differentiated:
    1. *Historical Order Total (`orders.total_usd`):* The static order price stored in the legacy table.
    2. *Historically Verified Payment Amount:* Verifiable only for unconflicted pre-cutover orders where `payment_status = 'paid'`, `cancelled_at IS NULL`, and zero subsequent ledger rows exist.
    3. *Post-Cutover Ledger Payments (`order_payments.amount_usd`):* Discrete, immutable payments recorded in the new transaction ledger with known event timestamps.
    4. *Refunds & Disbursement Periods:* Outflows recognized strictly in the period when currency physically departed (`disbursed_at`) or bank settlement cleared (`settled_at`).
    5. *Unreconciled Historical Amount:* Ambiguous historical balances requiring manual audit.
- **Anti-Double-Counting & Anti-Omission Rules:**
  - A subsequent ledger payment is **never automatically added** to the full historical order total.
  - In period financial reports, historical orders falling within the reporting window are partitioned into:
    * *Confirmed Historical Payments:* Unconflicted orders where full payment is verified.
    * *Period Historical Reconciliation Exceptions:* Orders with subsequent ledger payments or unverified cancellations, displayed visibly under audit on the report rather than guessing an allocation formula.
  - Post-cutover ledger payments are recognized in their respective post-cutover periods under Confirmed Ledger Payments. Neither transaction is omitted and neither is double-counted.
- **Four Realistic Scenarios Harmonized:**
  1. *Historical order with no later payment (unconflicted):* Recognized as Confirmed Historical Payment in historical period; zero exceptions.
  2. *Historical order with one later partial payment (split payment):* Historical total reported under Period Reconciliation Exceptions ($50 under audit); subsequent payment ($20) confirmed in post-cutover period.
  3. *Historical order with multiple later payments:* Historical total ($100) reported under Period Reconciliation Exceptions; subsequent payments ($30 + $40) confirmed in post-cutover period.
  4. *Historical order with a cancellation or unverified refund:* Historical total ($40) reported under Period Reconciliation Exceptions (missing `orders.refunded_amount` in schema); never fabricated as revenue or zero.

### 2. Correction B: Historical Exception Resolution Lifecycle & Governance
- **Three-State Resolution Lifecycle:**
  $$\text{Open} \longrightarrow \text{Under Review} \longrightarrow \text{Resolved}$$
  * `open`: Exception detected automatically by historical queries; presented in active open backlog.
  * `under_review`: Authorized manager has opened the ticket, reviewing physical receipts, cash sheets, or bank statements.
  * `resolved`: Authorized manager has recorded an administrative disposition, explanation, and supporting evidence references.
- **Mandatory Audit Attributes per Exception:**
  Captures `exception_id`, `order_id`, `order_number`, `exception_type`, `historical_order_total`, `subsequent_ledger_payments`, `subsequent_refunds`, `resolution_status`, `resolution_decision`, `resolution_explanation`, `authorized_manager_id`, `resolved_at`, and `supporting_evidence_ref`.
- **Append-Only History:** Recorded in `historical_exception_events` (`id`, `exception_id`, `from_status`, `to_status`, `actor_id`, `notes`, `created_at`).
- **Operational & Accounting Rules:**
  * Resolving an exception must **never** delete the original exception, historical order, or payment records (`ON DELETE RESTRICT` enforced).
  * Resolved exceptions remain permanently stored and queryable in `view_historical_exception_audit`.
  * The active open backlog excludes resolved exceptions (`WHERE resolution_status IN ('open', 'under_review')`).
  * Lifetime exception totals are **never** injected into periodic net revenue totals.
  * Manager PIN required for status transitions; cashiers cannot resolve exceptions.
  * Financial write-off decisions **require explicit CPA or owner approval**.
  * **Administrative Resolution $\ne$ Revenue Recognition:** Administrative resolution documents operational disposition. It does **not** retroactively rewrite closed historical revenue reports. Any formal revenue recognition or accounting write-off adjustments must be booked as separate general ledger adjustments in the current accounting period with CPA sign-off.

### 3. Correction C: KHQR Pending Reference Lifecycle & Architecture Boundary
- **Four-Stage Digital Payment Lifecycle:**
  1. *Stage 1 (Payment Initiated / Staged):* Cashier selects "KHQR"; counter displays dynamic EMVCo QR code. Vehicle is held at counter apron; wash bay entry is blocked. Dynamic QR generation is merely an invitation to pay, **never proof of payment**. Customer screenshots are strictly rejected.
  2. *Stage 2 (Temporary Local Pending Intent):* An ephemeral client session identifier (UUID v4 `client_pending_intent_id`) tracks the active checkout intent in browser memory / IndexedDB (`PosStorage`). A temporary local identifier is **NOT a bank transaction reference**, must **never be treated as proof of payment**, must **never satisfy verified digital payment requirements**, and must **never be inserted into `order_digital_payments.payment_ref`**.
  3. *Stage 3 (Authentic Bank Reference Capture):* Cashier reads the genuine bank transaction reference code (`payment_ref`) directly from the merchant smartphone notification or SMS alert. Soundbox chime alerts cashier, but matching on merchant phone is mandatory if amounts collide. Generation of synthetic reference strings (`TRX-` via `uniqid()`) or provisional tokens (`PROV-...`) is prohibited.
  4. *Stage 4 (Verified & Settled Tender):* Cashier enters genuine `payment_ref`; backend executes atomic insert into `order_payments` and `order_digital_payments`. Electronic receipt is issued (`receipt_issued_at = NOW()`), and vehicle is dispatched to apron.
- **Two-Table Schema Limitation on Pending Records:**
  - In the approved Two-Table schema, `order_payments` is an append-only ledger of confirmed collected tenders, and `order_digital_payments.payment_ref` is `NOT NULL` with a unique constraint.
  - Inserting an unconfirmed payment into `order_digital_payments` with a provisional fake reference (like `PROV-...`) would corrupt the ledger with uncollected revenue and violate unique constraints.
  - *Standard Local Counter Policy (Approved Scope):* In-flight pending KHQR payments remain staged in browser memory / IndexedDB (`PosStorage`). Zero rows are written to `order_payments` or `order_digital_payments` until positive merchant confirmation is obtained and the genuine `payment_ref` is entered.
  - *Future Server-Side Staging (Pending Approval):* If server-side persistence of unverified payment intents is required, it requires either adding a nullable `payment_ref` with `settlement_status` to `order_digital_payments` or creating a dedicated non-financial staging table `pos_payment_intents`. This limitation is logged as **Unresolved Technical Dependency (UTD-4 Addendum)**.
- **Provisional Format & Scoped Uniqueness:**
  - Describing references as "4 to 8 digits" is provisional and not an assumed universal standard.
  - Scoped strictly to `(provider, merchant_account_id, payment_ref)`.
  - Format specifications, character sets, and rollover cycles remain an Unresolved Technical Dependency pending official bank API documentation.

### 4. Git & Operational Governance Safeguards
- **Remote Baseline Recorded:** Commit `a304f8a1ce909b118f9b80d701ce84a55c2798be` on `origin/main`.
- **Zero Agent Commits/Pushes:** Confirmed zero Git commits and zero Git pushes performed.
- **Zero Source Code / DB Modifications:** Zero application source files, database migrations, or server configurations altered.
- **Zero Tests Executed:** All 58 test matrix specifications remain planned specifications only.
- **Phase 0 Status:** Phase 0 was NOT started.
- **Readiness:** The POS system remains strictly NOT production-ready and awaits separate executive authorization.

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
