# Final POS Engineering Review – Correction Changelog (Round 6, 8 & 9 Addenda)

**Document ID:** POS-REV-2026-006
**Project:** Car-WashService
**Date:** November 8, 2026 (Updated - Round 9)
**Status:** Business Rules Harmonized – Technical Dependencies Documented – Awaiting Separate Executive Authorization

---

## Round 9 Addendum: Final Documentation Harmonization & Dependency Strengthening (November 8, 2026)

Following a comprehensive architectural review against the live codebase, the POS documentation was refined to eliminate unsupported schema assumptions, address database constraint vulnerabilities, strengthen bank reversal safety, and enforce strict read-only inspection boundaries:

### 1. UTD-1: Historical Financial Reporting & Reconciliation Exceptions
- **Unsupported Assumptions Eliminated:** Removed undefined flags (`is_initial_migration`) and unsupported schema assumptions (the live schema in [init.php](database/init.php#L242-L280) possesses `total_usd`, `paid_at`, and `cancelled_at`, but **does NOT contain `orders.refunded_amount`**).
- **Independent Period-Aware Evaluation:** Payment and refund recognition are evaluated independently within `[start_date, end_date)`. A post-cutover refund never causes a pre-cutover payment to disappear, and a post-cutover payment never suppresses historical payment recognition.
- **Double-Counting Defense & Reconciliation Exceptions:** Pre-cutover orders (`created_at < cutover_ts`) with `payment_status = 'paid'` that receive subsequent post-cutover ledger payments cannot have historical paid totals reliably inferred without risking double-counting. Rather than guessing an unverified formula, these orders are classified as **Historical Split-Payment Reconciliation Exceptions** on the Exceptions tab for manual audit.
- **Pre-Cutover Cancellations:** Orders cancelled prior to cutover with `payment_status = 'paid'` lack refund amount data in the schema. They are formally classified as **Historical Unreconciled Cancellation Exceptions** rather than assuming $0.00 or full refund.
- **Pseudocode Realignment:** Updated pseudocode to reference actual source fields and include explicit exception classification branches.

### 2. UTD-4: KHQR Uniqueness & Nullable Column Protection
- **Vulnerability Identified:** In MariaDB/MySQL ANSI SQL, `NULL != NULL` in unique constraints. If composite fields are nullable, multiple identical rows containing `NULL` bypass duplicate protection.
- **Mandatory Digital Fields:** Defined that for verified digital payments, `provider`, `merchant_account_id`, and `payment_ref` must be strictly `NOT NULL`.
- **Cash Separation:** Cash transactions do not possess bank references and are kept strictly separate from digital reference validation (e.g. dedicated digital ledger table or separate tender schemas).
- **No Global 4-8 Digit Uniqueness Assumption:** Clarified that short bank reference codes are not globally unique across banks, accounts, or rollover cycles. Marked official bank reference specifications as an **Unresolved Technical Dependency**.

### 3. UTD-3: Bank Reversal Finality & Operational Blockades
- **Snapshot Limitation Clarified:** Documented that inspecting a bank mobile app or bank statement snapshot at a single point in time **does not prove that a delayed reversal cannot settle later in clearing**.
- **No Automated Finality on Local LAN:** Explicitly recognized that on an offline LAN counter operating without direct bank gateway API integrations, **no automated mechanism exists to guarantee bank reversal finality**.
- **Mandatory Payout Blockade:** If the definitive external bank outcome cannot be verified through formal gateway rejection codes or written bank confirmation, cash replacement payout **must remain strictly blocked**.
- **Operational Policy Required:** The replacement cash refund workflow is classified as an **unresolved process requiring an approved operational procedure** before implementation.
- **Traceability & Concurrency:** Preserved append-only event logging (`order_refund_events`), Manager PIN authorization, atomic conditional locks, and explicit linking via `replaced_refund_id`.

### 4. Phase 0: Strictly Read-Only Inspection Boundaries
- **Bar on Modifying Operations:** Explicitly barred Phase 0 from starting stopped database services, repairing unstable databases, creating databases, running dumps, or performing restore operations.
- **Inspection Protocol:** If the database service is stopped or down, Phase 0 inspects service status, configuration files, and system logs and reports the status without attempting to start or modify it.

### 5. Corporate Receipt Policy & Verification Standards
- **Sole Gate Pass:** Confirmed payment (`payment_status = 'paid'`) and electronic receipt issuance (`receipt_issued_at`) are the sole service admission gate pass. Paper printing is advisory.
- **Unapproved Proposal:** Any proposal to introduce a physical-printing hard gate (`require_receipt_before_wash`) is classified as an **unapproved proposal requiring a separate owner decision**.
- **Planned Test Matrix:** Reaffirmed that all 58 test cases in [POS-IMPLEMENTATION-TEST-MATRIX.md](POS-IMPLEMENTATION-TEST-MATRIX.md) remain **planned test specifications**; no tests have been executed or passed.
- **Relative Linking:** Standardized all cross-document references to relative repository links.

---

## Round 8 Addendum: Engineering & Governance Harmonization (November 8, 2026)

Following in-depth architectural review, the POS documentation suite was refined to address key data integrity, financial reporting, and release governance requirements:
1. Removed all-or-nothing historical fallback logic; introduced period-aware independent evaluation.
2. Distinguished `provider_event_at`, `recorded_at`, and `disbursed_at` / `settled_at` timestamps with half-open interval boundaries.
3. Removed claim that local `'cancelled'` status guarantees bank non-execution.
4. Removed global `UNIQUE (payment_method, payment_ref)`.
5. Renamed Phase 0 to strictly read-only inspection.
6. Reaffirmed corporate receipt policy and marked 58 tests as planned specifications.

---

## Round 7 Executive Confirmation Summary (November 8, 2026)

On November 8, 2026, the business owner reviewed [POS-BUSINESS-RULES-OPEN-QUESTIONS.md](POS-BUSINESS-RULES-OPEN-QUESTIONS.md) and approved all six operational decisions:
1. **Decision 1 (Mixed Services Sequence):** Standardized as **Lift First $\rightarrow$ Wash Second** (Bay 4 Mechanical Lift work finishes before Exterior Wash & Dry). Earlier option lettering ambiguity resolved.
2. **Decision 2 (Receipt Printing Gate):** Physical printing is advisory; electronic receipt issuance (`receipt_issued_at`) and payment confirmation (`payment_status = 'paid'`) constitute the service gate pass.
3. **Decision 3 (Walk-In Customer Identity):** Preserves `customer_name VARCHAR(100) NOT NULL` in MariaDB schema; defaults to `'Walk-in Guest'` when no customer name is provided. Vehicle plate and type captured via existing flow.
4. **Decision 4 (Historical Orders Reconciliation):** Original historical records preserved intact; a dedicated **"Historical Unreconciled Exceptions"** report tab is provided for owner review.
5. **Decision 5 (KHQR Verification Channel):** Rely on store counter merchant smartphone notification and soundbox on local LAN. No public IP or commercial bank API/webhook onboarding in initial scope.
6. **Decision 6 (Financial Reporting Terminology):** Strict separation of **Net Settled Revenue** (`Gross Payments - Settled Refunds`) from **Net Operating Profit** (`Net Settled Revenue - (COGS + Commissions + Logged Expenses)`). Unrecorded COGS flagged rather than assumed $0.00.

All specifications were incorporated into [POS-FINAL-BUSINESS-RULES.md](POS-FINAL-BUSINESS-RULES.md) and the comprehensive [POS-FINAL-IMPLEMENTATION-PLAN.md](POS-FINAL-IMPLEMENTATION-PLAN.md).

---

## Executive Overview (Round 6 Baseline)

In Round 6 of the Car-WashService POS review, all business rules governing the cashier workflow, payment verification, refund lifecycle, financial reporting, receipt printing, and bay dispatching were comprehensively audited against the existing PHP source code and finalized into authoritative specifications.

This produced two primary operational reference documents:
1. [POS-FINAL-BUSINESS-RULES.md](POS-FINAL-BUSINESS-RULES.md): The consolidated business rules defining all operational policies, state machines, and concurrency controls.
2. [POS-BUSINESS-RULES-OPEN-QUESTIONS.md](POS-BUSINESS-RULES-OPEN-QUESTIONS.md): The executive registry of 6 open decisions and source-code contradictions requiring explicit business-owner sign-off.

---

## 1. Inventory of Files Reviewed

### Existing Markdown Reports Audited:
- `POS-FINAL-SOURCE-COMPATIBILITY-AUDIT.md` (Forensic compatibility audit)
- `POS-IMPLEMENTATION-DEPENDENCY-MAP.md` (Call chains, ER diagram, and table impact matrix)
- `POS-IMPLEMENTATION-TEST-MATRIX.md` (58 planned verification tests)
- `POS-FINAL-REVIEW-CHANGELOG-4.md` (Round 4 reconciliation)
- `POS-FINAL-REVIEW-CHANGELOG-5.md` (Round 5 decoupled Two-Tier query & refund lifecycle)
- `POS-CURRENT-VS-NEW-WORKFLOW.md` (Comparative workflow analysis)
- `POS-NEW-CHECKOUT-DESIGN.md` (Single-page checkout UI specification)
- `POS-PAYMENT-BUSINESS-RULES.md` (10 binding operational payment rules)

### Existing Application PHP Source Code Inspected:
- [OrderGate.php](core/OrderGate.php) (Lines 1–198: Inspection of `isReceiptRequired()`, `markReceiptPrinted()`, `assertReceiptPrinted()`, and manager PIN override).
- [BayManager.php](core/BayManager.php) (Lines 437–515, 832–980: Inspection of `ensureOrderTasks()`, `assignCarToBay()`, atomic bay locks, and task sequence rules).
- [CashierControl.php](core/CashierControl.php) (Shift boundaries, active drawer balancing, manager PIN verification).
- [index.php](api/pos/index.php) (Lines 1115–1205, 1350–1570, 5395–5635: Customer intake, order creation, walk-in defaulting, status validation).
- [reports.php](views/owner/reports.php) (Lines 20–170, 1490–2020: Revenue queries, commission aggregation, expense deduction, filter controls).
- [init.php](database/init.php) (Lines 240–310: Schema definitions for `orders`, `order_items`, `order_washers`, `bays`).

---

## 2. Key Forensic Findings & Code Contradictions Identified

### Finding 1: Multi-Stage Task Sequence Contradiction
* **Current PHP Source (`core/BayManager.php:503-509` & line 917):**
  The codebase strictly forces `Wash (Step 1) -> Oil Change (Step 2)`. Line 917 explicitly aborts if Step 1 is not finished with: `"Sequence Rule: Step 1 (Car Wash) must be finished before Step 2 (Oil Change) can begin. Nobody skips a step."`
* **Design Documents (`POS-PAYMENT-BUSINESS-RULES.md: Rule 5`):**
  Proposes `Mechanical Lift (Bay 4, Stage 1) -> Wash Bay (Stage 2)` so that dirty undercarriage oil draining happens before exterior cleaning.
* **Resolution:** Documented in [POS-BUSINESS-RULES-OPEN-QUESTIONS.md](POS-BUSINESS-RULES-OPEN-QUESTIONS.md) as **Decision 1**; confirmed by owner as **Lift First $\rightarrow$ Wash Second**.

### Finding 2: `OrderGate` Hard Blockade vs. Decoupled Presentation
* **Current PHP Source (`core/OrderGate.php:87-124`):**
  If `receipt_printed_at` is empty, vehicle intake is rejected with HTTP 422 `ReceiptRequiredException` unless a manager enters a PIN override.
* **Confirmed Policy:**
  Financial payment confirmation (`payment_status = 'paid'`) and electronic receipt issuance (`receipt_issued_at`) must be the mandatory gate pass. Physical thermal printing must be decoupled so hardware jams never block apron operations.

### Finding 3: Reports Querying Operational Status Instead of Payment Status
* **Current PHP Source (`views/owner/reports.php:75, 98, 108`):**
  The reporting queries in the live application still filter `WHERE o.status = 'paid'`.
* **Impact:**
  Decoupling operational status (`waiting`, `washing`, `finished`) from financial status would cause existing reports to drop to $0.00 unless the reporting query is updated to filter `WHERE o.payment_status = 'paid'`.

### Finding 4: Future Event Leakage Prevention in Date Calculations
* **Analysis:**
  Audited the Two-Tier Financial Query to ensure that subqueries offsetting historical sums cannot subtract future ledger transactions when querying past periods. Subqueries on `order_payments` and `order_refunds` must explicitly enforce half-open interval bounding `recorded_at >= :start_date AND recorded_at < :end_date`.

### Finding 5: Clarification of Net Settled Revenue vs. Business Profit
* **Analysis:**
  `Gross Payments - Settled Refunds` represents **Net Settled Cash Revenue (Tender Inflow)**, NOT accounting profit. True profit requires deducting chemical COGS, worker commissions, and facility overhead. All POS documentation has been standardized to use the term **Net Settled Revenue**.

---

## 3. Documentation Deliverables Maintained

1. [POS-FINAL-BUSINESS-RULES.md](POS-FINAL-BUSINESS-RULES.md):
   - Comprehensive rules across 7 core areas: System Architecture, Cashier Workflow, Payment Governance, Refund State Machine, Revenue/Accounting Definitions, OrderGate Policy, and Bay/Queue Management.
2. [POS-BUSINESS-RULES-OPEN-QUESTIONS.md](POS-BUSINESS-RULES-OPEN-QUESTIONS.md):
   - Executive registry of confirmed owner decisions and open technical design dependencies.
3. [POS-FINAL-IMPLEMENTATION-PLAN.md](POS-FINAL-IMPLEMENTATION-PLAN.md):
   - Six-phase engineering roadmap with strictly read-only Phase 0 inspection.
4. [POS-FINAL-REVIEW-CHANGELOG-6.md](POS-FINAL-REVIEW-CHANGELOG-6.md):
   - This comprehensive changelog registering all audit activities, forensic findings, and Round 8/9 harmonizations.

---

## 4. Read-Only Integrity Safeguards Confirmation

In strict compliance with instructions:
- **Zero Application Code Modified:** No PHP files, JavaScript files, CSS, HTML, or configuration settings were changed.
- **Zero Database Operations Executed:** No SQL migrations, queries, schema changes, backup dumps, or restore operations were run.
- **Zero Git Commits or Pushes:** No `git commit` or `git push` commands were executed.
- **Permanent LAN Self-Sufficiency:** Counter operations maintain 0% external cloud dependencies.
- **Governance:** All 58 test cases remain planned specifications; system remains awaiting separate executive authorization.
