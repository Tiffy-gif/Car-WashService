# Final POS Engineering Review – Correction Changelog (Round 6)

**Document ID:** POS-REV-2026-006  
**Project:** Car-WashService  
**Date:** November 7, 2026  
**Status:** Business Rules Finalization Complete – Executive Decisions Confirmed by Business Owner (Nov 8, 2026)  

---

## Round 7 Addendum: Executive Decisions Confirmed (November 8, 2026)

On November 8, 2026, the business owner reviewed [POS-BUSINESS-RULES-OPEN-QUESTIONS.md](file:///C:/xampp/htdocs/Car-WashService/POS-BUSINESS-RULES-OPEN-QUESTIONS.md) and approved all six operational decisions:
1. **Decision 1 (Mixed Services Sequence):** Standardized as **Lift First $\rightarrow$ Wash Second** (Bay 4 Mechanical Lift work finishes before Exterior Wash & Dry). Earlier option lettering ambiguity resolved.
2. **Decision 2 (Receipt Printing Gate):** Physical printing is advisory; electronic receipt issuance (`receipt_issued_at`) and payment confirmation (`payment_status = 'paid'`) constitute the service gate pass.
3. **Decision 3 (Walk-In Customer Identity):** Preserves `customer_name VARCHAR(100) NOT NULL` in MariaDB schema; defaults to `'Walk-in Guest'` when no customer name is provided. Vehicle plate and type captured via existing flow.
4. **Decision 4 (Historical Orders Reconciliation):** Original historical records preserved intact; a dedicated **"Historical Unreconciled Exceptions"** report tab is provided for owner review.
5. **Decision 5 (KHQR Verification Channel):** Rely on store counter merchant smartphone notification and soundbox on local LAN. No public IP or commercial bank API/webhook onboarding in initial scope.
6. **Decision 6 (Financial Reporting Terminology):** Strict separation of **Net Settled Revenue** (`Gross Payments - Settled Refunds`) from **Net Operating Profit** (`Net Settled Revenue - (COGS + Commissions + Logged Expenses)`). Unrecorded COGS flagged rather than assumed $0.00.

All specifications were incorporated into [POS-FINAL-BUSINESS-RULES.md](file:///C:/xampp/htdocs/Car-WashService/POS-FINAL-BUSINESS-RULES.md) and the comprehensive [POS-FINAL-IMPLEMENTATION-PLAN.md](file:///C:/xampp/htdocs/Car-WashService/POS-FINAL-IMPLEMENTATION-PLAN.md).

---

## Executive Overview

In Round 6 of the Car-WashService POS review, all business rules governing the cashier workflow, payment verification, refund lifecycle, financial reporting, receipt printing, and bay dispatching were comprehensively audited against the existing PHP source code and finalized into authoritative specifications.

This round produced two primary operational reference documents:
1. [POS-FINAL-BUSINESS-RULES.md](file:///C:/xampp/htdocs/Car-WashService/POS-FINAL-BUSINESS-RULES.md): The consolidated, final business rules defining all operational policies, state machines, and concurrency controls.
2. [POS-BUSINESS-RULES-OPEN-QUESTIONS.md](file:///C:/xampp/htdocs/Car-WashService/POS-BUSINESS-RULES-OPEN-QUESTIONS.md): The executive registry of 6 open decisions and source-code contradictions requiring explicit business-owner sign-off.

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
- `core/OrderGate.php` (Lines 1–198: Inspection of `isReceiptRequired()`, `markReceiptPrinted()`, `assertReceiptPrinted()`, and manager PIN override).
- `core/BayManager.php` (Lines 437–515, 832–980: Inspection of `ensureOrderTasks()`, `assignCarToBay()`, atomic bay locks, and task sequence rules).
- `core/CashierControl.php` (Shift boundaries, active drawer balancing, manager PIN verification).
- `api/pos/index.php` (Lines 1115–1205, 1350–1570, 5395–5635: Customer intake, order creation, walk-in defaulting, status validation).
- `views/owner/reports.php` (Lines 20–170, 1490–2020: Revenue queries, commission aggregation, expense deduction, filter controls).
- `database/init.php` (Lines 240–310: Schema definitions for `orders`, `order_items`, `order_washers`, `bays`).

---

## 2. Key Forensic Findings & Code Contradictions Identified

### Finding 1: Multi-Stage Task Sequence Contradiction
* **Current PHP Source (`core/BayManager.php:503-509` & line 917):**  
  The codebase strictly forces `Wash (Step 1) -> Oil Change (Step 2)`. Line 917 explicitly aborts if Step 1 is not finished with: `"Sequence Rule: Step 1 (Car Wash) must be finished before Step 2 (Oil Change) can begin. Nobody skips a step."`
* **Design Documents (`POS-PAYMENT-BUSINESS-RULES.md: Rule 5`):**  
  Proposes `Mechanical Lift (Bay 4, Stage 1) -> Wash Bay (Stage 2)` so that dirty undercarriage oil draining happens before exterior cleaning.
* **Resolution:** Documented in [POS-BUSINESS-RULES-OPEN-QUESTIONS.md](file:///C:/xampp/htdocs/Car-WashService/POS-BUSINESS-RULES-OPEN-QUESTIONS.md) as **Decision 1** for business-owner confirmation.

### Finding 2: `OrderGate` Hard Blockade vs. Decoupled Presentation
* **Current PHP Source (`core/OrderGate.php:87-124`):**  
  If `receipt_printed_at` is empty, vehicle intake is rejected with HTTP 422 `ReceiptRequiredException` unless a manager enters a PIN override.
* **Confirmed Policy:**  
  Financial payment confirmation (`payment_status = 'paid'`) and electronic receipt issuance (`receipt_issued_at`) must be the mandatory gate pass. Physical thermal printing must be decoupled so hardware jams never block apron operations.

### Finding 3: Reports Querying Operational Status Instead of Payment Status
* **Current PHP Source (`views/owner/reports.php:75, 98, 108`):**  
  The reporting queries in the live application still filter `WHERE o.status = 'paid'`.
* **Impact:**  
  Decoupling operational status (`waiting`, `washing`, `finished`) from financial status would cause existing reports to drop to $0.00 unless the reporting query is updated to filter `WHERE o.payment_status = 'paid'`. This reinforces the necessity of the Two-Tier certified query.

### Finding 4: Future Event Leakage Prevention in Date Calculations
* **Analysis:**  
  Audited the Two-Tier Financial Query to ensure that subqueries offsetting historical sums cannot subtract future ledger transactions when querying past periods. Subqueries on `order_payments` and `order_refunds` must explicitly enforce `recorded_at <= :end_date`.

### Finding 5: Clarification of Net Settled Revenue vs. Business Profit
* **Analysis:**  
  `Gross Payments - Settled Refunds` represents **Net Settled Cash Revenue (Tender Inflow)**, NOT accounting profit. True profit requires deducting chemical COGS, worker commissions, and facility overhead. All POS documentation has been standardized to use the term **Net Settled Revenue**.

---

## 3. Documentation Deliverables Created

1. [POS-FINAL-BUSINESS-RULES.md](file:///C:/xampp/htdocs/Car-WashService/POS-FINAL-BUSINESS-RULES.md):
   - Comprehensive rules across 7 core areas: System Architecture, Cashier Workflow, Payment Governance, Refund State Machine, Revenue/Accounting Definitions, OrderGate Policy, and Bay/Queue Management.
2. [POS-BUSINESS-RULES-OPEN-QUESTIONS.md](file:///C:/xampp/htdocs/Car-WashService/POS-BUSINESS-RULES-OPEN-QUESTIONS.md):
   - Detailed breakdown of the 6 decisions requiring business-owner approval, including trade-offs, source code contradictions, and operational risks.
3. [POS-FINAL-REVIEW-CHANGELOG-6.md](file:///C:/xampp/htdocs/Car-WashService/POS-FINAL-REVIEW-CHANGELOG-6.md):
   - This comprehensive changelog registering all audit activities and findings.

---

## 4. Read-Only Integrity Safeguards Confirmation

In strict compliance with instructions:
- **Zero Application Code Modified:** No PHP files, JavaScript files, CSS, HTML, or configuration settings were changed.
- **Zero Database Operations Executed:** No SQL migrations, queries, or schema changes were run.
- **Zero Git Commits or Pushes:** No `git commit` or `git push` commands were executed.
- **Permanent LAN Self-Sufficiency:** Counter operations maintain 0% external cloud dependencies.
