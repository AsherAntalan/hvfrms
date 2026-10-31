# HIDDEN VIETNAM FOOD RESTAURANT FRANCHISE MANAGEMENT SYSTEM

Undergraduate Capstone Project

Submitted to the Faculty of the Department of Computer Studies, Cavite State University–CCAT Campus, Rosario, Cavite

In partial fulfillment of the requirements for the degree of Bachelor of Science in Information Technology

NICOLE JINKY J. CASTROMERO

ASHER G. ANTALAN

ARVIN P. BLANCAFLOR

Submission month and year: to be confirmed by the researchers.

Prepared under the supervision of Ms. Yvana Jardine R. Nocon and Mr. Aaron S. Tinambacan, as identified in the supplied manuscript.

Contribution No.: to be assigned by the institution.

Revision basis: the HVFRMS source tree reviewed on October 24, 2026, with report, navigation, and test-evidence updates checked on October 25, 2026, Asia/Manila. This working draft describes implemented behavior and explicitly identifies proposed activities and missing research evidence. It is not a declaration of completed deployment, adviser approval, ISO certification, or successful user evaluation.

# INTRODUCTION

Restaurant franchise operations require consistent records of products, branch requests, supply preparation, delivery, receiving, and inventory use. When these activities are recorded separately, a quantity requested by a branch can be confused with a quantity prepared, dispatched, or received. Expired and damaged goods also require separate handling so they are not treated as usable stock. A management system must therefore record both the quantity and the operational event that caused a change.

Research on restaurant systems identifies inventory information and coordinated transactions as relevant areas for digital support. Dio et al. (2024) describe an integrated restaurant application covering order processing and inventory, among other functions. Bonggolto et al. (2024) examine inventory practices and operational performance among selected restaurants in Pagsanjan, Laguna. These studies provide context for organized records; they do not establish that every restaurant needs the same modules or that HVFRMS has already produced measurable business improvements.

The researchers' original project account identifies manual records and informal branch communication as concerns at Hidden Vietnam Food Restaurant. It also reports inconsistent sourcing and preparation practices among franchise locations. These statements are retained as researcher-supplied context. Interview records, dates, respondent details, and supporting observations must accompany them in the final research submission.

The Hidden Vietnam Food Restaurant Franchise Management System (HVFRMS) implements a centralized web application for authorized business personnel. Its current scope emphasizes the Commissary product catalog, branch supply requests, order-bound preparation and dispatch, branch receiving, inventory movements, disposal records, reports, and decision support. It separates the Commissary production source from the Main Store and franchise branches. The system is an internal operations platform rather than a public food-ordering website or customer point-of-sale application.

## Project Context

HVFRMS supports a coordinated supply workflow. A Branch Manager requests catalog products. Authorized Owner, Admin, or Commissary Manager accounts review the request and record the quantities that can actually be prepared or picked. The system records shortages and reasons instead of assuming that every requested quantity will be supplied. Labels identify the relevant order and released goods. Dispatch and destination receipt then update the associated records and inventory under the applicable workflow.

The Commissary is treated as a production and dispatch location. Internal implementation names containing “warehouse” remain in some routes and classes for compatibility, but these names do not justify describing a separate Warehouse module in the business narrative. Order preparation can still create or reserve source batches for traceability. Thus, describing the application as having no inventory records at its production source would also be inaccurate.

Product configuration and stock balances have different purposes. Commissary Products maintains product details, categories, units, prices, reorder levels, shelf life, and manual catalog availability. All Branches Inventory provides authorized monitoring of branch stock, expired batches, pending supply activity, and disposal records. A catalog product marked available is enabled for ordering; that setting does not prove that an existing stock balance is positive.

## Objectives of the Study

The general objective is to develop and assess a centralized web-based franchise operations and inventory management system for Hidden Vietnam Food Restaurant.

The specific objectives are to:

1. Provide role-based access for business administrators, branch personnel, and Commissary personnel, with server-side controls on permitted actions and branch scope.
2. Maintain a centralized Commissary product catalog without confusing product configuration with physical inventory quantities.
3. Support branch supply requests, review, preparation or picking, dispatch, receiving, discrepancy recording, and retained order history.
4. Record stock movements using product, batch, and order-label QR workflows, with expiry checks, damaged-stock restrictions, and first-expire-first-out allocation where implemented.
5. Monitor active and expired stock, record disposal and adjustments, and distinguish archiving from physical stock removal.
6. Provide date-filtered operational reports, CSV exports, printable views, and traceable transaction and audit records.
7. Generate stock-movement-based forecasts and restocking recommendations using the implemented weighted moving average and trend rules.
8. Provide in-system alerts and a scheduled email-digest capability for supported inventory and operational conditions.
9. Evaluate functionality through documented automated and manual tests, and assess selected software product quality characteristics using a validated, task-specific evaluation instrument.
10. Prepare and evaluate a deployment and training plan without claiming implementation outcomes before supporting records are available.

The implementation uses Laravel 11 on PHP 8.2, Blade templates, HTML, CSS, JavaScript, Bootstrap, asynchronous HTTP requests, and a MySQL-compatible relational database. The local development arrangement uses the project's XAMPP workspace.

## Purpose and Description

HVFRMS provides authorized personnel with a shared record of franchise supply operations. The Owner and Admin can oversee organizational records. Branch Managers manage transactions within their permitted branch scope. Commissary personnel handle the preparation and release activities assigned to their roles. Branch Staff primarily access scanning and stock operations rather than a general management dashboard.

The application records the distinction between requested, fulfilled, dispatched, and received quantities. It supports GCash payment-proof submission and controlled access to supporting files and proforma documents. These features document submitted evidence; they do not automatically establish that payment has been verified.

The current dashboard includes a monthly Commissary Sales presentation for authorized management accounts, but verified-payment data remains unavailable. In Reports, Commissary Sales replaces the former Order Summary tab and displays an unavailable state instead of treating order amounts as collected revenue. Submitted payment evidence and operational order closure do not establish payment verification. The sales calculation and sales CSV export are unavailable until an authoritative verified-payment source exists.

## Time and Place of the Study

The original manuscript identifies Hidden Vietnam Food Restaurant in Tanza, Cavite, and selected franchise branches as the intended study setting, with a period from February to November 2026. As of the latest documentation review on October 25, 2026, November activities remain scheduled rather than completed. The researchers must confirm the actual start, completion, evaluation, and deployment dates. The original May 2026 cover date should be reconciled with that study period before submission.

## Scope and Limitations

The system covers internal franchise supply and inventory operations. Implemented functions include account authentication, role and branch access, product catalog management, branch requests, GCash proof upload, order preparation, labels, dispatch and receiving, stock movements, disposal, order and expired-batch archives, reports, forecasts, notifications, branch administration, user administration, profiles, settings, audit logging, and database backup initiation.

The active role identifiers are Super Admin, Owner, Admin, Branch Manager, Branch Staff, Commissary Manager, and Commissary Staff. Permissions are enforced by routes, middleware, role helpers, and action-level checks. Not every role can access every module. In particular, Branch Staff are directed to the QR scanner and do not receive unrestricted dashboard, reporting, or user-management access.

The following limitations apply:

1. HVFRMS does not implement public customer ordering, table reservations, payroll, employee scheduling, or a restaurant POS checkout workflow.
2. A payment reference and proof image are submitted evidence. No authoritative verification amount, verification timestamp, or verified-payment ledger was identified. The monthly dashboard tile and the Commissary Sales report do not calculate verified sales. The report displays an unavailable state and does not offer a sales CSV download.
3. Forecasts use historical stock movement, including expiry, damage, and adjustments, as an operational proxy. They are not machine-learning predictions or a clean measure of customer purchases. Recommendations require managerial review.
4. The forecast stock-on-hand query sums positive batch balances without applying all of the expiry and damage exclusions used by stock-issue services. This can affect the recommendation and must be stated as an implementation limitation.
5. Notifications use asynchronous requests and periodic polling. The application does not establish a universal continuous push or WebSocket-based monitoring service. Some screens still require refresh or navigation to obtain new data.
6. Hourly alert generation, archive pruning, and email delivery depend on a configured scheduler, running application environment, and working mail transport. Their presence in code does not establish successful production execution.
7. Database backup is implemented through a MySQL dump command. A user-facing automated restore and disaster-recovery workflow was not identified.
8. The application requires network access to its server. Camera scanning generally requires compatible browser permissions and an appropriate secure context. An offline synchronization mode and a native mobile application are not implemented.
9. Public registration and a mandatory first-login password-change workflow were not identified. Administrators provision accounts. The development password-reset implementation requires security review before public deployment because a generated reset link can be returned to the requesting session.
10. Performance improvement, forecast accuracy, user satisfaction, and production reliability cannot be claimed from source inspection alone. They require measurements and evaluation records.
11. Stock Summary date filters select batches by received date and display their current remaining quantities. They do not reconstruct inventory as it stood on a past date and exclude older receipts outside the selected period even when those batches still have stock. Expiry Report filters by expiry date and evaluates current balances and expiry status.

## Conceptual Framework

The Input–Process–Output model organizes the implemented scope.

| Input | Process | Output |
|---|---|---|
| Authorized accounts, roles, and branch assignments | Authentication, authorization, branch scoping, and session checks | Permitted interfaces and attributable actions |
| Catalog products, units, prices, reorder levels, and shelf life | Product configuration and availability management | Centralized Commissary catalog |
| Branch requests, submitted payment evidence, and preparation quantities | Request review, production or picking, label generation, dispatch, and receiving | Order stages, fulfillment records, and receiving discrepancies |
| Batch quantities, QR identifiers, conditions, and expiry dates | Validated stock movements, FEFO allocation, adjustments, and disposal | Current balances, stock ledger, and traceability |
| Historical movements and configured thresholds | Report aggregation, forecasting, and alert generation | Reports, recommendations, alerts, and conditional email digests |

Figure 1. Input–Process–Output framework of HVFRMS. This table supplies the content for the diagram; an approved diagram image must be inserted in the final formatted manuscript.

## Definition of Terms

**Archive** refers to a retained historical presentation state. Archiving does not itself dispose goods, reverse a transaction, or verify payment. Order archives and expired-batch archives have different rules.

**Branch scope** refers to the server-enforced set of locations and records an account may access. It is separate from merely hiding a menu item.

**Catalog availability** refers to the manual setting that enables or disables a product for catalog ordering. It is not a stock quantity.

**Commissary** refers to the configured production and dispatch source for branch supply orders, distinct from the Main Store.

**Disposal** refers to a recorded removal of expired or otherwise ineligible goods under authorized business rules, with a stock transaction and attributable history.

**FEFO** means first-expire-first-out. Eligible dated batches with earlier expiry are prioritized; expired and damaged stock is excluded from normal issue.

**Fulfilled quantity** refers to the actual quantity prepared or picked for an order line, which can differ from the requested quantity.

**Operational closure** refers to a received or closed shipment state. The application's settled status must not be treated as evidence of financial settlement.

**Payment proof** refers to the uploaded payment image and related reference submitted with an order. It is distinct from verified payment.

**Physical stock** refers to recorded remaining quantities, which may include expired or damaged goods. **Usable stock** excludes disallowed conditions and past-expiry goods; allocation can additionally exclude reserved quantities.

**QR label** refers to a machine-readable product, batch, or order-unit identifier used by the relevant scanning workflow. Printing a label alone does not release stock.

**Weighted moving average** refers to the forecast calculation assigning increasing weights to recent weekly movement values. Its output is advisory.

# REVIEW OF RELATED LITERATURE

## Related Literature and Studies

This review retains sources whose relevant content could be checked against publisher or institutional records. It distinguishes related systems from functions actually implemented in HVFRMS. A study describing POS, IoT, or customer ordering does not establish that those features exist in this project.

### Integrated restaurant operations

Dio et al. (2024) describe restaurant transaction modules that connect ordering and inventory with other restaurant functions. This supports examining related operational records together. HVFRMS applies that principle to branch-to-Commissary requests and inventory traceability, while excluding the study's POS and kitchen-costing scope from its implementation claims. The publisher identifies the issue as volume 3, number 1B, correcting the original manuscript's issue reference.

Oghenekaro and Okafor (2023) describe a web-based restaurant application built with HTML/CSS, PHP, and MySQL. Their account connects ordering and stored operational information. HVFRMS similarly uses a web interface and relational records, but serves authorized franchise personnel rather than public customers. The similarity concerns information organization, not identical requirements or proven equivalent outcomes.

### Inventory control and restaurant performance

Bonggolto et al. (2024) examine inventory practices and operational performance in selected Pagsanjan restaurants. Their discussion links purchasing, storage, and waste-related practices with operational concerns. This provides local context for inventory monitoring. It should not be generalized into a claim that all Philippine franchises use the same manual practices or that HVFRMS has already reduced waste.

Barto et al. (2024) analyze food-waste implications of online and offline retail inventory policies. The work illustrates that expiry-related outcomes depend on inventory control and fulfillment arrangements, rather than a single rule. Its relevance to HVFRMS is the need to retain expiry and movement information. It does not directly validate this application's forecast, FEFO implementation, or restaurant performance.

### Relational information management

Xie (2024) presents a restaurant database design using entity relationships and MySQL to organize operational queries. This supports the use of linked records for orders, products, and personnel. HVFRMS implements its own relationships among users, branches, products, batches, orders, and transactions. The publisher identifies the author as Ziteng Xie; the original text's “Zhiwei, Xie” attribution has been corrected.

### Software quality evaluation

ISO/IEC 25010:2023 provides a product-quality model with nine characteristics for specifying and evaluating ICT product quality. This draft uses those categories to organize a project-specific evaluation plan. The questions below are an adaptation for HVFRMS, not an official ISO questionnaire, a separate data-quality standard, or proof of certification (ISO/IEC, 2023).

## System Technical Background

The reviewed application uses Laravel 11.57.0 and PHP 8.2. Blade templates render the interface; HTML and CSS define content and presentation. Bootstrap 5.3.3 provides responsive interface components. JavaScript uses fetch-based requests for selected workflows, including partial inventory and catalog refreshes. The QR interfaces load html5-qrcode 2.3.8 for camera scanning. These mechanisms support interaction but do not imply that every page updates automatically.

Controllers process HTTP requests, models represent relational records, and service classes implement important stock and order rules. The StockService handles eligible stock, expiry policy, FEFO issue, and idempotency. OrderLabelService and related workflow services coordinate preparation, labeling, release, and receiving. ForecastService implements deterministic statistical recommendations. Middleware applies authentication-related controls, role restrictions, and branch scoping.

The operational database is MySQL-compatible. The test configuration uses in-memory SQLite for many automated scenarios. Some dashboard and alert queries contain MySQL-specific functions; success in SQLite tests does not by itself prove full compatibility with a production MySQL or MariaDB environment. Deployment must include testing against the actual operational database engine.

Composer manages PHP packages. PHPUnit 11.5.56 is present for automated testing, and Laravel Pint is available for formatting. The local workspace is hosted under XAMPP. The application can be deployed to a suitable PHP web server; production hosting, TLS configuration, scheduled jobs, backup destinations, and mail delivery remain environment-specific decisions requiring deployment evidence.

## Synthesis

The reviewed sources support organizing operational information, retaining linked transaction records, and evaluating quality through explicit tasks and evidence. HVFRMS applies these ideas to an internal franchise supply workflow. Its distinguishing scope is the connection among catalog configuration, branch requests, actual fulfillment, QR-based handling, receiving, and branch stock history.

The system's contribution should be assessed through traceability, correctness of stock changes, role-appropriate access, and usefulness of operational records. Claims about increased sales, reduced food waste, improved forecast accuracy, or faster work must follow measured results rather than being inferred from the existence of modules. The implemented forecast and unverified-payment limitations are therefore part of the evaluation, not features to omit from the manuscript.

# METHODOLOGY

## Research and Development Approach

The study combines software development with functional validation and a planned user evaluation. The supplied manuscript identifies an iterative Agile approach. The final study should substantiate that history with dated requirements, revisions, stakeholder feedback, and iteration records. Source files alone cannot confirm that interviews, sprint reviews, or training sessions occurred.

Development activities are organized into requirements analysis, design, implementation, testing, stakeholder review, and deployment preparation. Evaluation outcomes are reported separately from implementation descriptions. A planned activity is written in the future tense until execution records are available.

## Requirements Analysis

The operational requirements are derived from the researchers' reported business concerns and the implemented workflow. They distinguish actor permissions, product definitions, inventory records, and fulfillment events. The researchers must retain consent and interview documentation, describe the selected branches, and explain how stakeholder statements were translated into requirements.

| Requirement | Implemented interpretation | Evidence to retain |
|---|---|---|
| Centralized operations | Linked catalog, branch, account, order, batch, and transaction records | Requirements and schema review |
| Controlled access | Seven role identifiers with route and action restrictions | Role matrix and unauthorized-request tests |
| Supply coordination | Review, preparation or picking, release, and destination receiving | Workflow tests and observed scenarios |
| Stock accountability | Batch eligibility, FEFO issue, disposal, adjustments, and history | Before/after balances and ledger entries |
| Decision support | Weighted averages, trend rules, and recommendations | Known-input forecast calculations |
| Monitoring | Alerts, polling, reports, and scheduler-dependent digests | Browser observations and job/mail logs |
| Financial transparency | Submitted proof and proforma, with verified sales unavailable | Document access tests and payment-source review |

### Actor and Access Model

| Actor | Principal permitted responsibilities | Important boundary |
|---|---|---|
| Super Admin | Owner-level administrative access and technical oversight | Distinct account attribution; not a generic IT Staff role |
| Owner | Cross-branch oversight, catalog and organization administration | Actions remain authenticated and attributable |
| Admin | Business administration with Owner-level helpers in the current code | Does not automatically gain permission to assign Super Admin |
| Commissary Manager | Authorized production, preparation, delivery, inventory/report access, and availability management | Does not have unrestricted catalog or user administration |
| Commissary Staff | Assigned preparation/release and scanning operations | Not a general Reports, Settings, or Users role |
| Branch Manager | Own-branch ordering, receiving, inventory, and authorized reports | Cannot modify another branch by changing request parameters |
| Branch Staff | Own-branch QR and stock operations | Root landing page is QR Scanner; management dashboard access is restricted |

## System Design

The architecture follows a web presentation layer, Laravel application layer, and relational data layer. The presentation layer consists of Blade templates, CSS, Bootstrap components, and JavaScript. The application layer contains controllers, middleware, services, scheduled commands, validation, and authorization. The data layer stores catalog, organizational, stock, order, archive, notification, and audit information. Submitted payment images are kept in protected file storage rather than being treated as publicly accessible proof URLs.

The administrative sidebar orders its principal entries as Dashboard, Inventory, Orders, Reports, Insights, and Management. Reports is a standalone navigation entry before Insights. System Setting and My Profile remain separate existing entries. Navigation visibility and submenu contents depend on the account's role; the sequence does not grant every role access to every module.

Principal relationships include roles to users, branches to users and transactions, products to categories and batches, orders to order lines, preparation records to orders, and movements to products, branches, batches, and responsible users. This structure makes an order request distinct from the stock movements generated during release and receipt.

### Functional Modules

**Authentication.** Authorized users sign in using a supported username or email and password. The reviewed code locks an existing account after three failed attempts for fifteen minutes, regenerates the session on login, and uses role-aware landing pages. Administrators provision accounts. No public account-approval request process or mandatory first-login password-change enforcement was found.

**Dashboard.** Permitted management and Commissary roles receive operational summaries, scoped recent orders, stock warnings, and alerts. The current monthly Commissary Sales tile explicitly reports unavailable verified-payment data. Branch Staff instead use the QR landing page. Dashboard figures are not evidence of financial verification.

**Commissary Products.** The catalog maintains product names, categories, units, prices, reorder levels, shelf life, and availability. Catalog editing is an administrative function; authorized Commissary management can control availability as allowed by the code. Physical and usable quantities are not catalog columns.

**All Branches Inventory.** Authorized oversight accounts can inspect grouped branch records. Branch-limited users retain their assigned scope. The monitor separates active stock, expired stock, pending orders, and disposal records. The configured Commissary is excluded from the displayed branch-monitor rows; related production and delivery records remain traceable.

**Stock and QR Operations.** Users scan supported product or batch codes, record permitted movements, and review the resulting history. The stock service rejects expired or damaged goods from normal receiving/issue paths and uses FEFO for eligible issue. Camera access and manual entry depend on the particular screen and permissions. Generic product scanning must not be confused with scanning order-unit labels.

**Branch Orders and Payment Evidence.** Branch Managers submit requests using the catalog. The application validates submitted payment references and uploaded proof images for the relevant ordering workflow. Proof access is restricted. A proforma becomes available under the existing review/approval conditions. These records do not automatically create verified revenue or a POS transaction.

**Preparation, Dispatch, and Receiving.** Authorized users review requests and enter actual preparation quantities, production/picking sources, and shortage reasons. Order-linked labels and reservation records support release. Printing alone does not deduct stock. Dispatch and receiving update records through the relevant workflow and guard repeated handling. Healthy full receipts may close an order; exception receipts retain information for review.

**Disposal and Archives.** Authorized disposal records remove eligible quantities and preserve the movement and actor. Expired-batch archiving affects reporting visibility, not physical quantity. Its current visible retention window is thirty days, with scheduled pruning. Order archiving is a different retained-history function and can support restore; the expired-batch retention rule must not be assigned to order archives.

**Reports.** Reports provide Stock Summary, Stock Movements, Commissary Sales, and Expiry Report views. Commissary Sales replaces Order Summary and is restricted to Owner-level accounts, including Admin and Super Admin, and the Commissary Manager. Branch Managers retain their authorized branch reports without access to the sales tab. Selecting Commissary Sales opens its report content and preserves the selected period when filters are applied; it does not calculate revenue from unverified order amounts.

The shared filter panel contains Period presets (Today, Last 7 Days, This Month, and Custom Range), Date From, Date To, Branch, and Apply Filters. Valid ranges include both endpoint dates and default to the current month through the current Asia/Manila business date. Reversed dates are swapped with a notice. Branch-limited accounts remain pinned to their assigned branch; broader authorized branch options exclude the Commissary production source.

Each report explains which date its filter uses:

| Report | Date basis | Meaning of displayed results |
|---|---|---|
| Stock Summary | Batch received date | Current remaining quantities of positive-balance batches received within the selected period; not a historical inventory reconstruction |
| Stock Movements | Movement creation date | Recorded stock-in, stock-out, and net changes within the selected period |
| Commissary Sales | Selected reporting period; verified-payment date source not yet implemented | Unavailable verified sales; submitted proofs and received/closed orders are not counted as verified payment |
| Expiry Report | Batch expiry date | Current remaining batches with expiry dates within the selected period, classified using current expiry status |

CSV exports for Stock Summary, Stock Movements, and Expiry Report use the applied date range and branch scope. Unsaved edits to the filter inputs do not change the export's applied scope. Printable views retain the selected report's result and period label. Commissary Sales shows an explicit unavailable message, disables its CSV control, and rejects direct sales-export requests while verified payment data is absent. Neither an unavailable sales state nor an empty filtered result should be interpreted as measured zero revenue.

**Forecast and Decision Support.** Historical movement records are organized into completed weeks. The default window is eight weeks, with a minimum of four. The most recent four weeks receive weights of one, two, three, and four. Trend classification compares the two halves of the window; the forecast is adjusted by the implemented rising/falling rules. A safety factor then determines a target against which stock is compared. Insufficient history yields an insufficient-data result, not a validated prediction.

**Alerts and Notifications.** Supported conditions include low stock, expiring or expired batches, unusually high recorded consumption, idle branches, and order-linked operational events. The internal unusual_sales name does not establish a retail-sales anomaly detector. The scheduled generator can email newly generated warning/critical alerts to configured eligible recipients. SMTP and scheduler execution must be verified independently. No general overstock detector was established in this review.

**Branch and User Administration.** Authorized administrators maintain branch details, account records, roles, assignments, activation status, and password-reset actions. A Main Store and a Commissary are separate location types. Active branch and role validation constrain changes. The project does not define only four roles or a standalone IT Staff role.

**Profile, Settings, Audit, and Backup.** Profile functions include viewing account details, photo upload, and password change using the current password. Settings control supported operational parameters and the GCash receiving QR configuration. Audit records identify selected attributable actions. The backup command creates a MySQL dump when its environment supports it. No graphical database restore function or configurable personal notification-preference workflow was established.

### Principal Business Rules

1. A batch is usable through its expiry date and becomes expired when the date is before the current business date in Asia/Manila.
2. Normal stock issue excludes expired and damaged goods and respects eligible quantities and reservations.
3. FEFO prioritizes earlier expiry, rather than simply the oldest receipt date.
4. Requesting an order, approving it, and printing labels are distinct from dispatch and receiving. The current service workflow governs when quantities change.
5. The fulfilled amount may differ from the requested order amount. A received/closed status does not verify payment.
6. Repeated requests and scans must not create duplicate stock changes. Relevant services use transaction locks and idempotency or already-processed checks.
7. Archiving preserves underlying business history. Disposal changes quantities; the two actions are not interchangeable.
8. Inactive catalog availability is independent of inventory quantity and affects ordering under the existing rules.

### Forecast Calculation and Interpretation

For four recent weekly totals W1 through W4, ordered oldest to newest, the weighted moving average is (W1 + 2W2 + 3W3 + 4W4) / 10. The current service rounds and applies its trend adjustment before calculating a safety-stock target. The recommendation is the nonnegative difference between that target and the service's recorded stock-on-hand value.

The input method aggregates signed stock movements of types out, expired, damaged, and adjustment by date, takes the absolute daily sum, and groups those values into weeks. It therefore incorporates non-sales stock changes and can be affected by adjustments. The manuscript must describe it as movement-based decision support. Forecast accuracy should be tested against an explicitly defined operational target and held-out periods, rather than assumed from the formula.

### Figure Specifications and Corrected Captions

The supplied attachment contained captions but not the actual diagram or screenshot images. The following register replaces inaccurate descriptions and resolves the duplicate architecture numbering. Insert current screenshots or verified diagrams before final submission; captions alone do not constitute figures.

| Figure | Corrected subject and required content |
|---|---|
| 1 | IPO framework: accounts, catalog, orders, batches, movement processing, and operational outputs |
| 2 | HIPO: Dashboard; Inventory/catalog/stock; Orders/preparation/delivery; Reports; Insights; Management; System Setting; My Profile/QR according to role; Reports is a standalone entry before Insights |
| 3 | Use-case diagram with the seven actual actors and permission boundaries |
| 4 | Login: supported username/email, password, visibility control, remember option where present, and recovery guidance; no self-registration request |
| 5 | Authorized administrator dashboard with actual summaries and honest sales availability |
| 6 | Branch Staff QR landing interface, replacing the unsupported generic Staff dashboard |
| 7 | All Branches Inventory with grouped branch monitoring and permitted tabs |
| 8 | Own-branch inventory for a Branch Manager, not unrestricted Branch Staff access |
| 9 | Stock receiving interface with branch, quantity, eligibility, and QR context |
| 10 | Stock issue interface showing FEFO and sufficient-stock validation |
| 11 | Authorized oversight reports with shared Period, Date From, Date To, and Branch controls; Commissary Sales shows verified-payment data unavailable |
| 12 | Branch-scoped reporting for a Branch Manager, showing receipt-date and expiry-date meanings without granting Commissary Sales access |
| 13 | Alerts and notification interface; no account-approval function |
| 14 | My Profile and supported password/photo actions; system settings shown separately if included |
| 15 | Login and role-aware landing flow: Branch Staff to QR, permitted management roles to Dashboard |
| 16 | Authorized account creation flow: validation, role/branch assignment, account save, and audit |
| 17 | Dashboard request flow: authorization, scoped queries, and rendered operational summaries |
| 18 | Inventory flow: select authorized scope, inspect batches, validate movement, update ledger, and refresh view |
| 19 | Reports flow: authorize report, validate dates and branch scope, apply the selected report's date basis, render results, and permit supported export/print actions; sales remains unavailable without verified payment data |
| 20 | Alerts flow: generate supported conditions, persist alerts, retrieve notifications, and resolve where authorized |
| 21 | User management flow with permission checks, account validation, and activation/reset actions |
| 22 | System administration flow for settings, audit access, and backup initiation |
| 23 | Fishbone specification covering people, process, technology, environment, and management; causes require field evidence |
| 24 | Three-layer architecture: web presentation, Laravel processing, relational database and protected files |
| 25 | Iterative development lifecycle supported by dated project records |
| 26 | Actual login implementation screenshot for Results and Discussion, replacing a missing image |

## Operational, Technical, and Economic Feasibility

Operational feasibility should be assessed through role-specific tasks: creating a request, preparing a shortage, dispatching, receiving with exceptions, issuing eligible stock, disposing expired goods, and reviewing a report. Required training includes explaining why catalog availability, usable stock, disposal, and archive are different concepts. Client participation and acceptance remain matters for documented field evaluation.

Technical feasibility depends on a supported PHP server, a compatible database, persistent file storage, browser access, and the correct environment configuration. HTTPS, camera permissions, printer access, scheduled tasks, and mail delivery need explicit verification. The code does not establish guaranteed uptime, support for every browser, or tested large-scale concurrency.

Table 1. Technical resources reported in the original manuscript; physical availability was not established by source inspection.

| Resource | Reported quantity/status | Verification required |
|---|---|---|
| Laptop | Three; described as working | Inventory, specifications, ownership, and test date |
| 3-in-1 printer | Described as working | Model, consumables, and label-print compatibility |
| Internet/Wi-Fi | Described as working | Access, reliability, and service arrangement |

Table 2. Original illustrative equipment budget, retained as an unverified estimate rather than actual expenditure.

| Equipment | Supplied estimate |
|---|---|
| Laptop | PHP 55,000.00 |
| Smartphone | PHP 28,000.00 |
| 3-in-1 printer | PHP 9,000.00 |
| Total | PHP 92,000.00 |

The arithmetic is consistent, but the prices, purchase quantities, receipts, and procurement dates require confirmation. This is not a complete project budget: hosting, connectivity, printing supplies, backup storage, maintenance, and labor may also apply. Current quotations are needed before presenting the estimate as a deployment cost.

## Development Procedure

Planning establishes scope and interview-backed requirements. Design translates them into roles, data relationships, page layouts, and transaction flows. Implementation builds the Laravel routes, services, views, and schema changes. Testing checks expected outcomes and important failure conditions. Review compares those outcomes with stakeholder requirements. Deployment preparation documents environment setup, operational responsibilities, training, and rollback arrangements.

The researcher-described Agile approach should be supported by actual iteration records. Neither a working repository nor a list of development phases proves that client reviews or training occurred. Future milestones are retained as planned until evidence is supplied.

## System Testing

Functional validation should use controlled fixtures and record starting balances, actions, expected results, actual results, and responsible users. Unauthorized requests and repeated operations are important failure cases. Automated tests complement manual scenarios; they do not replace observation of camera behavior, printing, mail delivery, or usability.

Relevant scenarios include correct login and role-aware redirection; invalid credentials and account lockout; cross-branch access denial; catalog permissions and availability; payment proof validation and private access; partial preparation; correct dispatch/receipt; FEFO selection; duplicate scan protection; disposal; archive retention; report filtering; and forecast calculation. Every reported pass or fail must be linked to an executed case, rather than inferred from source code.

## System Evaluation

The planned evaluation uses selected applicable characteristics from ISO/IEC 25010:2023. The research team should validate the instrument with its advisers, identify respondent groups and sample sizes, obtain consent, and specify a task sequence before administering it. Some characteristics require technical evidence rather than a general user rating. There are no supplied completed questionnaires or respondent-level scores in this review.

Table 3. Proposed HVFRMS-specific evaluation questions, adapted for the project rather than copied as an official ISO instrument.

| Characteristic | Example evaluation statement | Evidence or evaluator |
|---|---|---|
| Functional suitability | I can complete the request, preparation, receiving, or stock task assigned to my role. | Role-specific user tasks |
| Functional suitability | The displayed result agrees with the quantities and events recorded in the test case. | Functional test records |
| Performance efficiency | The permitted list, filter, and save actions respond within the acceptance target established before testing. | Timed measurements, device/network details |
| Performance efficiency | The system remains usable at the tested record volume and number of simultaneous users. | Controlled load measurements |
| Compatibility | The workflow operates on each browser and device explicitly included in the test plan. | Compatibility matrix |
| Compatibility | QR scanning, label printing, and exports work with the tested equipment and output viewers. | Hardware and output checks |
| Interaction capability | Labels and page organization help me distinguish catalog availability, stock quantity, disposal, and archives. | Users performing relevant tasks |
| Interaction capability | Validation explains an error and lets me correct it without losing necessary input. | Usability tasks and observations |
| Reliability | Repeating a request or scan does not cause an unintended duplicate stock change. | Replay and concurrency scenarios |
| Reliability | A failed operation leaves consistent records and gives an understandable result. | Transaction-failure scenarios |
| Security | An account cannot retrieve or change another branch's protected records through a direct request. | Authorization tests |
| Security | Payment images and privileged actions are available only to the permitted accounts. | Private-file and role tests |
| Maintainability | Stock and order rules can be traced to the responsible services and their tests. | Technical review |
| Maintainability | A scoped change can be checked with the affected regression tests. | Maintainer review and test evidence |
| Flexibility | The application can be installed using documented environment requirements and configuration. | Deployment rehearsal |
| Flexibility | Tested layout adjustments accommodate supported desktop and mobile tasks. | Device/browser observations |
| Safety | The system blocks normal use of expired or damaged goods under its recorded rules. | Stock validation tests |
| Safety | Warnings and disposal records help users recognize recorded expiry risks and accountability. | Operational task review |

Inventory checks support administrative risk control; they are not a certification of food safety or a replacement for physical inspection. Technical ratings must be based on observed evidence, not assumed from the presence of a framework.

## Data Analysis Plan

For applicable questionnaire items, the team may compute the weighted mean as the sum of each response score multiplied by its frequency, divided by the number of valid responses. Report the respondent count and handling of missing/not-applicable answers. Summarize categories only when the items and respondent groups make that aggregation meaningful. Do not assign a default score to unanswered items.

Table 4. Proposed interpretation bands retained from the supplied five-point plan, with the lowest interval completed.

| Mean, rounded consistently to two decimals | Proposed interpretation |
|---|---|
| 4.51–5.00 | Excellent |
| 3.51–4.50 | Very Good |
| 2.51–3.50 | Good |
| 1.51–2.50 | Fair |
| 1.00–1.50 | Poor |

These are researcher-defined interpretation bands, subject to adviser approval. They are not ISO pass thresholds. No mean or interpretation is assigned in this draft because completed response data were not supplied.

A manual-versus-system comparison, if retained as an objective, must use comparable tasks, starting data, participants, and recorded timing/error outcomes. The repository does not supply that experiment. Forecast evaluation should define the target, held-out weeks, and a suitable error measure before claiming accuracy. Zero actual values require care if percentage errors are used.

## Implementation Plan

Table 5. Proposed deployment activities; durations are to be agreed rather than reported as completed facts.

| Phase | Activities | Responsible parties | Completion evidence |
|---|---|---|---|
| Approval | Confirm scope, location access, responsibilities, and authorization | Researchers and client administrator | Approved plan and consent records |
| Environment preparation | Verify PHP, database, web server, TLS, storage, and backups | Deployment administrator and researchers | Configuration checklist without exposed secrets |
| Data preparation | Confirm catalog, branches, roles, opening records, and supporting data | Authorized client personnel | Reviewed import/setup records |
| Pilot | Exercise request-to-receipt, disposal, reports, and permissions | Selected authorized users | Signed scenarios and issue log |
| Operational setup | Verify scheduler, email, archive pruning, camera access, and printing | Administrator and researchers | Job, mail, and equipment logs |
| Training | Demonstrate role-specific tasks and recovery/escalation procedures | Researchers and users | Attendance and observed task results |
| Acceptance | Review unresolved defects and obtain client sign-off | Client administrator and researchers | Acceptance record and agreed limitations |
| Handover | Provide maintenance responsibilities, backup/restore procedure, and support contacts | Researchers and maintenance owner | Handover checklist and restore rehearsal |

# RESULTS AND DISCUSSION

## Basis of the Reported Results

This chapter distinguishes implemented functions from measured study outcomes. The initial source review and test snapshot are dated October 24, 2026. A follow-up review and full automated test run were conducted on October 25, 2026, after the report-filter, Commissary Sales, and sidebar-order changes. Those application changes preceded this documentation update; no application source or test was edited during this documentation task. Interview transcripts, completed quality questionnaires, before/after timing measurements, production mail logs, and signed deployment acceptance were not supplied.

## System Design and Development Findings

The repository implements a Laravel application with role-aware navigation, a Commissary catalog, branch supply requests, production/picking and label workflows, dispatch and receiving, stock records, reports, forecasting, notifications, and administration. Source records distinguish the Commissary from the Main Store. The user model and route definitions establish seven role identifiers, correcting the original four-role and generic Staff descriptions.

The product catalog is a configuration screen rather than a combined-stock table. Order handling supports actual fulfillment and shortages; request totals are not always the final fulfilled value. Normal stock issue uses FEFO and recorded eligibility checks. Disposal and archive are separate operations with different effects. These are implementation findings rather than measured reductions in waste or processing time.

The reviewed dashboard includes a monthly-sales presentation for authorized management but explicitly identifies the absence of verified-payment data. Reports now uses Commissary Sales in place of Order Summary, with an unavailable verified-sales state and disabled sales CSV export. This limitation prevents a valid claim of completed verified-sales reporting. An uploaded GCash proof image is retained evidence, not an independently confirmed financial transaction.

Stock Summary and Expiry Report both retain the common period and branch controls. Their meanings differ: Stock Summary selects batches by received date and reports current remaining stock; Expiry Report selects batches by expiry date and reports current balances and expiry status. The administrative navigation places Reports between Orders and Insights. These are interface and filtering findings, not evidence of increased revenue or improved user performance.

Selected inventory/catalog workflows use asynchronous updates and loading indicators. The notification interface polls at thirty-second intervals. This supports timely retrieval but does not establish continuous real-time synchronization across every screen. Email alerts depend on the hourly command and its mail configuration.

## Automated Test Results

The current PHPUnit suite was executed on October 25, 2026, without adding or changing tests for this documentation update. It reported 228 tests, 2,273 assertions, no errors, and one failure, with a runner-reported elapsed time of approximately 21.90 seconds. Therefore, 227 tests passed. These figures describe that dated working-tree run only and are not a claim of complete requirements coverage or operational readiness.

Table 6. Technical validation snapshot of the reviewed working tree.

| Measure | Observed result |
|---|---|
| Review date | October 25, 2026 |
| Test runner | PHPUnit 11.5.56 |
| Test database configuration | In-memory SQLite |
| Tests executed | 228 |
| Passing tests | 227 |
| Failed tests | 1 |
| Errors / skipped tests | 0 / 0 |
| Assertions | 2,273 |
| Reported execution time | Approximately 21.90 seconds |

For comparison, the retained October 24 snapshot reported 211 tests, 2,171 assertions, 210 passes, and one failure in approximately 20.86 seconds. The suite changed between those dates during application work. The older figures are historical evidence, not the latest totals.

The October 25 run includes 32 passing tests and 158 assertions across ReportBatchDateFilterTest, ReportMovementFilterTest, and ReportsArchiveTest. These classes cover inclusive date boundaries, report-specific date bases, current remaining balances, CSV scope, branch restrictions, sales availability/access, and report/archive behavior. The 32 are a subset of the 228 tests, not an additional suite total.

The remaining failing case is InventoryOrderingRenderTest::test_inventory_renders_with_correct_breakdown. Its assertion expects Physical Stock in the product-catalog response. The current Commissary Products screen intentionally excludes physical and usable stock columns. This is an inconsistent expectation requiring review; it does not by itself demonstrate incorrect stock arithmetic. The test was not edited to manufacture a passing result.

Some existing dashboard coverage accepts a server error as a possible SQLite limitation because the dashboard contains MySQL-specific SQL. Consequently, a large passing-test count must not be presented as proof that every dashboard query renders successfully. Actual-database integration tests and browser checks remain necessary.

## Functional Validation Matrix

Table 7. Implemented behavior and evidence that should accompany the final functional report.

| Area | Code-supported behavior | What remains necessary for a final result |
|---|---|---|
| Authentication | Credential validation, account state checks, lockout, session regeneration, and role-aware landing | Recorded browser cases for each supported role and recovery path |
| Catalog | Product configuration, manual availability, and protected actions | Actual saved-record and filter checks under authorized accounts |
| Ordering/payment proof | Required proof/reference validation and restricted supporting documents | Device upload checks and explicit acknowledgement that payment verification is unavailable |
| Preparation/delivery | Actual quantities, shortage reasons, label handling, release, and receipt records | Observed multi-user and repeated-scan scenarios |
| Stock | Batch eligibility, FEFO, movements, and history | Physical-versus-recorded reconciliation and actual-engine tests |
| Disposal/archives | Recorded disposal and separate archive visibility/retention behavior | Browser persistence checks, permissions, and dated retention cases |
| Reports | Receipt-date Stock Summary, date-ranged movements, expiry-date report, applied-scope CSV/printing, and unavailable verified-sales state | Actual-database reconciliation, production output review, and authoritative payment verification before sales calculation |
| Forecast | Deterministic weighted-average and trend calculations | Known-input tests, held-out validation, and assessment of non-sales/expiry input limitations |
| Notifications | Persisted alerts, polling, scheduled generation, and conditional email | Scheduler and SMTP execution evidence |
| Administration | Branch/user settings, profile actions, audit records, and backup command | Production configuration review and a tested restoration procedure |

## Software Quality Evaluation Results

No completed respondent dataset was included. This draft therefore does not report an overall mean, an Excellent rating, respondent percentages, or claims that the system passed ISO/IEC 25010 evaluation. The proposed instrument and analysis plan must be administered and documented before this section can contain numerical findings.

## Discussion of Limitations and Further Work

Priority issues include establishing a verified-payment source before reporting sales, clarifying forecast inputs and eligible stock, reconciling outdated tests with the accepted catalog design, checking MySQL-specific dashboard and alert queries, and securing password recovery for public deployment. Production scheduler, mail, backup restoration, and multi-user behavior also require direct validation.

The documented application provides a practical foundation for franchise supply coordination. Evidence of improved efficiency, reduced waste, stronger security, or better forecasts must still come from controlled measurements and user evaluation. The final manuscript should describe these as evaluation questions until results are available.

# REFERENCES

Barto, J., Eruguz, A. S., Spliet, R., & Wøhlk, S. (2024). Reduced food waste through inventory control despite throwing out food before expiration: Online vs. offline retail. Omega, 128, Article 103131. https://doi.org/10.1016/j.omega.2024.103131

Bonggolto, I. J., Calderon, F., Eleazar, S. E., Lobos, A., Martinez, J. C., Pasco, J. M., Payas, R., Ponce, R., Quizon, C. I., & Magcalas, M. G. (2024). Impact of inventory management and operational performance of selected restaurants in Pagsanjan, Laguna. Journal of Research in Business and Management, 12(5), 8–13. https://www.questjournals.org/jrbm/papers/vol12-issue5/12050813.pdf

Dio, K. B. A., Puente, D. J., Estrada, J. S., Miano, A. B., & Rafael, R. M. (2024). Hotel and restaurant management system core transaction 1 (order management w/POS, kitchen and bar module, food and beverage costing, inventory management, resto analytics). Ascendens Asia Singapore–Bestlink College of the Philippines Journal of Multidisciplinary Research, 3(1B). https://ojs.aaresearchindex.com/index.php/aasgbcpjmra/article/view/12919

International Organization for Standardization & International Electrotechnical Commission. (2023). ISO/IEC 25010:2023: Systems and software engineering—Systems and software Quality Requirements and Evaluation (SQuaRE)—Product quality model. https://www.iso.org/standard/78176.html

Oghenekaro, L. U., & Okafor, J. C. (2023). Web-based integrated restaurant management system. International Journal of Applied Information Systems, 12(40), 48–53. https://www.ijais.org/archives/volume12/number40/1137-2023451945/

Xie, Z. (2024). Restaurant management database system design and implement. Applied and Computational Engineering, 38, 210–218. https://ace.ewapub.com/article/view/10167

The associated validation notes identify original citations that were not retained as verified support. Publisher metadata should receive a final adviser/style review before submission.
