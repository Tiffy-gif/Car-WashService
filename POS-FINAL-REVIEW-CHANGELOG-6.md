# Final POS Engineering Review – Correction Changelog (Round 6, 8, 9, 10, 11, 12, 13, 14 & 15 Addenda)

**Document ID:** POS-REV-2026-006
**Project:** Car-WashService
**Date:** November 8, 2026 (Updated - Round 15 Final Closure)
**Status:** Business Rules Harmonized – Technical Dependencies Documented – Awaiting Separate Executive Authorization

---

## Round 15 Addendum: Final KHQR Safety, "Investigate First" Workflow, Exception Concurrency & Metadata Harmonization (November 8, 2026)

Following the Round 14 review, the four POS engineering documents were corrected and finalized to resolve remaining technical issues across KHQR expiration policies, late transfer workflows, exception registry concurrency protection, and cross-document metadata consistency:

### 1. Correction 1: KHQR Expiration Policy Reclassification
- **Removal of False Mandate Claims:**
  - Removed unverified claims that a 300-second validity window is mandated by NBC Bakong or EMVCo standards.
  - Clarified that EMVCo MPM standards (Tag 01 point-of-initiation method) and NBC Bakong specifications do not impose a universal 300-second hard cutoff for dynamic QR validity.
- **Proposed Local POS Operational Default:**
  - Defined 300 seconds strictly as a **proposed local POS operational default setting** (`khqr_intent_expiry_seconds`, default: 300s), fully configurable pending business owner approval and acquiring bank specifications.
- **Presentation Intent Expiration vs. Bank Transaction Status:**
  - Distinctly separated local UI intent expiration from bank transaction status: UI intent expiration is purely a local client presentation timeout designed to prevent stale counter cart sessions.
  - **Expiration of a local intent does NOT mean payment failed at the bank**, nor does it indicate that customer funds were rejected or returned.

### 2. Correction 2: Late KHQR Transfer Investigation ("Investigate First" Workflow)
- **Mandatory "Investigate First" Protocol:**
  - Defined an explicit operational protocol when a customer claims to have transferred after the local dynamic QR intent expired:
    1. *Do NOT Immediately Generate Another QR:* Cashiers are strictly forbidden from immediately generating a new dynamic QR code or requesting another payment, eliminating the risk of double-charging the customer.
    2. *Cashier Checks Merchant Counter Phone:* Cashier immediately inspects the merchant counter smartphone's push notification drawer, SMS inbox, and merchant banking app account statement.
    3. *5-Point Verification:* Cashier verifies: (a) exact transfer amount, (b) correct currency (USD vs. KHR), (c) merchant receiver account, (d) transaction status = `SUCCESS`/`COMPLETED`, and (e) customer sender name.
    4. *Locate Genuine Bank Reference:* Cashier extracts the authentic bank transaction reference (`payment_ref`) from the verified merchant notification or app statement.
    5. *Reconcile Without Collecting Second Payment:* Using a controlled late-transfer reconciliation interface, cashier matches the genuine `payment_ref` to confirm settlement and issue the electronic receipt for the existing order, without collecting a second payment.
    6. *Preserve Expired Intent:* The expired client intent metadata and timestamp are retained in local audit logs for transaction reconciliation.
    7. *Escalate to Manager if No Funds Appear:* If no incoming credit appears on the merchant counter phone or banking app, cashier must escalate to the manager to verify merchant bank statements. Only after manager confirmation that zero funds arrived may a fresh QR be generated or an alternate tender requested.
    8. *Strict Rejection of Non-Bank Proof:* Customer smartphone screens, customer-shown receipts, dynamic QR generation alone, soundbox announcements without phone statement verification, and temporary local UUIDs are strictly rejected as settlement proof.
    9. *Database Protection Against Duplicate References:* Composite uniqueness `CONSTRAINT uq_digital_bank_ref UNIQUE (provider, merchant_account_id, payment_ref)` rejects duplicate bank references and prevents a payment already matched to another order from being accepted.
    10. *Zero Invented Bank APIs:* LAN operations rely strictly on physical merchant counter device inspection; no direct bank webhooks, automated polling APIs, or simulated push protocols are assumed.

### 3. Correction 3: Exception Registry Optimistic Concurrency Protection
- **Prevention of Silent Overwrites:**
  - Concurrent administrative actions or background reconciliation syncs must never silently overwrite each other's status or newer ledger figures.
- **Dedicated Version Counter:**
  - Added `lock_version INT NOT NULL DEFAULT 1` to `historical_exception_registry`.
- **Atomic Conditional Updates:**
  - Resolution submissions require the client to submit the expected `lock_version` and execute:
    ```sql
    UPDATE historical_exception_registry
    SET resolution_status = :to_status,
        resolution_decision = :resolution_decision,
        resolution_explanation = :resolution_explanation,
        authorized_manager_id = :authorized_manager_id,
        resolved_at = NOW(),
        supporting_evidence_ref = :supporting_evidence_ref,
        lock_version = lock_version + 1
    WHERE id = :id AND lock_version = :expected_lock_version;
    ```
- **Stale Record Detection & Mandatory Reload:**
  - If another manager or background sync updated the record in the interim, `rowCount === 0`. The API aborts the transaction and returns `HTTP 409 Conflict` (`EXCEPTION_STALE_RECORD`).
  - The UI prompts the manager to reload the record, inspect updated ledger tallies and event history, and re-confirm their resolution decision.
  - Complete transition history is permanently preserved in `historical_exception_events`.

### 4. Correction 4: Document Metadata Harmonization
- **Metadata Synchronization:**
  - Synchronized all four permitted Markdown files (`POS-BUSINESS-RULES-OPEN-QUESTIONS.md`, `POS-FINAL-BUSINESS-RULES.md`, `POS-FINAL-IMPLEMENTATION-PLAN.md`, `POS-FINAL-REVIEW-CHANGELOG-6.md`) to `November 8, 2026 (Updated - Round 15 Final Closure)`.
- **Preservation of Historical Integrity:**
  - Preserved all Round 6, 8, 9, 10, 11, 12, 13, and 14 historical records and changelog entries without rewriting or obscuring historical facts.
- **Preservation of Architectural Guardrails:**
  - Retained all six approved business decisions, Phase 0 read-only inspection safety boundaries, and the prohibition of premature implementation.

---

## Round 14 Addendum: Final Documentation Closure, Exception Idempotency, Unpaid Order Discovery & KHQR Intent Lifecycle (November 8, 2026)

Following the Round 13 review, the four POS engineering documents were corrected and closed to resolve remaining technical issues across historical unpaid order discovery, exception registry idempotency and reopening rules, KHQR pending-intent expiration and recovery, and Git reporting accuracy:

### 1. Correction A: Historical Unpaid Orders & Exception Discovery Realignment
- **Exception Discovery NOT Restricted to `payment_status = 'paid'`:**
  - Removed the filtering restriction `AND o.payment_status = 'paid'` from historical exception queries and pseudocode.
  - Ensured finished but unpaid historical orders (`status = 'finished'` AND `payment_status != 'paid'`) are systematically discovered and surfaced as reconciliation exceptions.
- **Strict Classification Across Historical States:**
  - Partitioned historical pre-cutover orders into distinct categories without guessing allocations or fabricating cash:
    1. *Unconflicted Historical Paid:* `payment_status = 'paid'`, `cancelled_at IS NULL`, zero post-cutover payments. Confirmed revenue = `total_usd`.
    2. *Historical Finished but Unpaid:* `status = 'finished'`, `payment_status != 'paid'`. Surfaced as `Historical Finished but Unpaid (Uncollected / Unverified Tender)`.
    3. *Historical Cancellation:* `cancelled_at IS NOT NULL`. Surfaced as `Historical Cancellation (Refund Amount Indeterminate)` due to missing `orders.refunded_amount`.
    4. *Historical Split Payment:* Pre-cutover orders with subsequent post-cutover ledger payments. Surfaced as `Historical Split Payment (Allocation Indeterminate)`.
    5. *Historical Incomplete / Ambiguous Tickets:* Tickets lacking payment status or unfinished across cutover.
- **Worked Realistic Scenario Added:**
  - Added *Example 6 (Order #106: $35 total, status = 'finished', payment_status = 'unpaid')*. Excluded from Confirmed Revenue to prevent artificial income inflation; visibly isolated on Exceptions tab for manager investigation or CPA write-off authorization.

### 2. Correction B: Exception Registry Integrity, Idempotency & Reopening Governance
- **Idempotent Ingestion Rule:**
  - Added unique constraint `CONSTRAINT uq_hex_order_type UNIQUE (order_id, exception_type)` on `historical_exception_registry`.
  - Defined report discovery queries and background sync sweeps to use `INSERT ... ON DUPLICATE KEY UPDATE`, updating calculated tallies (`subsequent_ledger_payments`, `subsequent_refunds`) without creating duplicate exception rows or resetting manager status. Repeated execution of reports never duplicates records.
- **Updating Existing Exceptions with New Ledger Evidence:**
  - When post-cutover ledger payments or refunds occur against an order with an active exception, calculated fields update idempotently and an audit event is logged in `historical_exception_events` (`event_type = 'ledger_activity_detected'`). Static historical fields (`historical_order_total`, `order_number`) remain immutable.
- **Reopening Governance for Resolved Exceptions:**
  - A resolved exception may transition from `resolved` back to `under_review` ONLY when:
    * *Trigger A:* New conflicting post-cutover transactions (chargebacks, unlinked refunds, installments) are posted.
    * *Trigger B:* Manager discovers physical paper daybook evidence or external banking discrepancies.
  - *Reopening Procedure:* Gated by Manager PIN; state transitions `resolved` $\longrightarrow$ `under_review`; mandatory justification note logged in `historical_exception_events`; exception immediately returns to active open backlog (`WHERE resolution_status IN ('open', 'under_review')`).
- **Preservation of Evidence & Prohibition of Automatic Write-Offs:**
  - Resolving or reopening never deletes any records (`ON DELETE RESTRICT` enforced).
  - The system **never automatically writes off an outstanding balance** or mutates historical financial records. All write-offs, bad debt classifications, and tax adjustments strictly require explicit CPA or owner approval.
  - Administrative resolution documents operational disposition; it does **not** retroactively rewrite closed historical revenue reports.

### 3. Correction C: KHQR Pending-Intent Lifecycle, Expiry & Recovery
- **Expiration TTL (300 Seconds / 5 Minutes):**
  - Dynamic KHQR checkout intents expire strictly at 300 seconds (matching EMVCo dynamic validity).
  - Live countdown timer displayed. At $t > 300\text{s}$, intent automatically transitions to `expired`, disabling the "Confirm Payment" button. If customer transfers late, cashier must click "Generate Fresh QR" or use manager override.
- **Refresh & Restart Recovery via `PosStorage`:**
  - In-flight intents persisted in client IndexedDB (`PosStorage`) with timestamp, plate, cart, and `client_pending_intent_id`.
  - On page refresh or tablet reboot:
    * If $t < \text{created_at} + 300\text{s}$, restored with remaining countdown and QR display.
    * If $t \ge \text{created_at} + 300\text{s}$, restored in `expired` state, returning cart to editable draft mode.
    * *Crucial Rule:* Recovery does **NOT** constitute proof of payment. Recovered intents remain strictly unverified. Cashier MUST verify authentic push/SMS evidence on the merchant counter phone and input authentic bank `payment_ref`.
- **Local Cleanup Sweep:**
  - Expired intents older than 15 minutes are purged from `PosStorage` by a periodic cleanup sweep, releasing local browser storage.
- **Duplicate Payment Prevention:**
  - Immutable `idempotency_key` per intent; backend rejects payment submissions for already-paid orders with HTTP 409 Conflict (`ORDER_ALREADY_PAID`).
  - Composite uniqueness `CONSTRAINT uq_digital_bank_ref UNIQUE (provider, merchant_account_id, payment_ref)` strictly prevents recording identical bank references twice.
- **Zero Invented Bank API Behavior:**
  - Counter operations rely strictly on merchant counter phone notifications and soundbox announcements. No direct bank gateway webhooks, polling APIs, or automated push integrations are assumed.

### 4. Correction D: Git Reporting Accuracy & Verification Standards
- **Correction of Round 13 Baseline Statements:**
  - Corrected previous changelog statements that claimed the remote branch remained permanently static at commit `a304f8a1ce909b118f9b80d701ce84a55c2798be`.
  - Confirmed that Round 13 documentation updates were committed and pushed to `main` at commit `529e5c8b640bb1261267a93ceb4ea4cf7e854e8e`, which serves as the verified base commit for Round 14.
- **Strict Distinction of Git States:**
  - *Local Working-Tree Status:* Verified via `git status --short`. Only the four permitted Markdown files are modified in working tree.
  - *Local Commit History:* Verified via `git rev-parse HEAD` and `git log -1`.
  - *Remote Tracking Status:* Verified via `git branch -vv` against `origin/main`.
  - Prohibited claiming "zero commits or pushes occurred" without live execution and verification of git commands.

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
- **Remote Baseline Recorded:** Commit `a304f8a1ce909b118f9b80d701ce84a55c2798be` on `origin/main` was the initial base for Round 13; following Round 13 completion, changes were integrated upstream at commit `529e5c8b640bb1261267a93ceb4ea4cf7e854e8e`.
- **Session Commit/Push Isolation:** During the Round 13 editing session, zero agent commits or pushes were created. Subsequent upstream commit and push activity was verified prior to Round 14.
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
