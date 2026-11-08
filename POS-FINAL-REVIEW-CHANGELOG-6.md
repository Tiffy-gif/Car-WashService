# Final POS Engineering Review – Correction Changelog (Round 6 & Round 8 Addenda)

**Document ID:** POS-REV-2026-006
**Project:** Car-WashService
**Date:** November 8, 2026 (Updated - Round 8)
**Status:** Business Rules Harmonized – Technical Dependencies Documented – Awaiting Separate Executive Authorization

---

## Round 8 Addendum: Engineering & Governance Harmonization (November 8, 2026)

Following in-depth architectural review, the POS documentation suite was refined to address key data integrity, financial reporting, and release governance requirements:

### 1. Removal of All-or-Nothing Historical Fallback Logic (Area 1)
- **Problem Resolved:** Earlier drafts applied coupled, all-or-nothing fallback logic that risked dropping historical payments if a post-cutover refund occurred, or omitting historical payments when a post-cutover split payment was recorded.
- **Harmonization:**
  - Fallback logic is now decoupled and period-aware: payment recognition and refund recognition are evaluated independently within `[start_date, end_date)`.
  - Defined explicit handling for pre-cutover orders, historical payments, post-cutover payments, split payments, multiple partial refunds, post-cutover refunds against pre-cutover orders, and events outside reporting boundaries.
  - Documented that the existing MariaDB schema lacks `orders.refunded_amount` and lacks a pre-cutover payment ledger; marked the exact historical split-reconciliation query as an **Unresolved Technical Design Item (UTD-1)** pending Phase 2 schema design.

### 2. Definition of Reporting Timestamps & Period Bounding (Area 2)
- **Timestamps Distinguished:**
  - `provider_event_at`: External provider/bank event timestamp.
  - `recorded_at`: Local POS ledger recording timestamp.
  - `disbursed_at` / `settled_at`: Currency disbursement or bank settlement confirmation timestamp.
- **Recognition Standards:**
  - Cash payments and manually verified KHQR payments recognized at `recorded_at`.
  - Cash refunds recognized at `disbursed_at`.
  - Settled digital refunds recognized at `settled_at`.
- **Interval Bounding:** Strictly half-open interval `[:start_timestamp, :end_timestamp)`. Delayed provider notifications are recorded in the period received/settled, preserving the immutability of closed past periods.
- **Design Dependency:** Highlighted that these discrete columns do not exist in the current source tables ([init.php](database/init.php)) and require introduction in Phase 2.

### 3. Verification-Gated Bank Reversal Safety & Dual-Payout Prevention (Area 3)
- **Problem Resolved:** Removed the erroneous claim that updating a failed reversal row to `'cancelled'` eliminates all risk of a delayed bank settlement.
- **Harmonization:**
  - A local database status update does not guarantee bank non-execution.
  - Preserved original reversal attempts in an append-only audit trail (`order_refund_events`).
  - Cash replacement refunds are strictly blocked until the manager verifies the definitive external bank outcome (e.g. reviewing merchant bank statement).
  - If the bank outcome remains ambiguous or pending, cash replacement payout remains blocked.
  - Authorized cash replacements require Manager PIN, an atomic conditional lock, and maintain an explicit foreign key link (`replaced_refund_id`).

### 4. Scoped KHQR Reference Uniqueness (Area 4)
- **Problem Resolved:** Removed the unworkable global constraint `UNIQUE (payment_method, payment_ref)` which broke cash transactions and falsely assumed 4-to-8 digit bank codes were globally unique.
- **Harmonization:**
  - Defined uniqueness scoped to provider and merchant account: `UNIQUE (provider, merchant_account_id, payment_ref)`.
  - Cash transactions carry no digital reference requirement.
  - Marked official bank reference format, character set, length, and uniqueness rules as an **Unresolved Technical Dependency (UTD-4)** pending official merchant bank documentation.

### 5. Strictly Read-Only Phase 0 (Area 5)
- **Problem Resolved:** Removed active database dumps (`mysqldump`), database creation, database restoration, and directory copying commands from Phase 0.
- **Harmonization:**
  - Renamed Phase 0 to **"Phase 0: Read-Only Source, Runtime & Configuration Inspection"**.
  - Limited activities strictly to inspecting source code, Git status, runtime versions, configuration files, service status, and reviewing existing backup documentation.
  - Backup and restore verification was reclassified as a future prerequisite under authorized staging conditions, requiring an isolated environment and zero changes to production MariaDB.

### 6. Corporate Receipt Policy Clarification (Area 6)
- **Problem Resolved:** Clarified that an optional physical-printing hard gate (`require_receipt_before_wash`) is **not approved policy**.
- **Harmonization:**
  - Confirmed payment (`payment_status = 'paid'`) and electronic receipt issuance (`receipt_issued_at`) are the sole service gate pass.
  - Physical paper printing is strictly advisory; hardware printer failure never blocks vehicle admission into service apron.
  - Re-stated that any physical printing gate proposal is an unapproved item requiring a separate owner decision.

### 7. Governance, Relative Linking & Planned Tests (Area 7)
- Replaced all local `file:///` URLs with valid relative repository links.
- Confirmed that the 58 test cases in [POS-IMPLEMENTATION-TEST-MATRIX.md](POS-IMPLEMENTATION-TEST-MATRIX.md) remain **planned test specifications**; no tests have been executed or passed.
- Standardized document dates to November 8, 2026.
- Maintained status: **Engineering Proposal – Awaiting Separate Executive Authorization (NOT Production-Ready Until Released)**.

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
   - This comprehensive changelog registering all audit activities, forensic findings, and Round 8 harmonizations.

---

## 4. Read-Only Integrity Safeguards Confirmation

In strict compliance with instructions:
- **Zero Application Code Modified:** No PHP files, JavaScript files, CSS, HTML, or configuration settings were changed.
- **Zero Database Operations Executed:** No SQL migrations, queries, schema changes, backup dumps, or restore operations were run.
- **Zero Git Commits or Pushes:** No `git commit` or `git push` commands were executed.
- **Permanent LAN Self-Sufficiency:** Counter operations maintain 0% external cloud dependencies.
- **Governance:** All 58 test cases remain planned specifications; system remains awaiting separate executive authorization.
