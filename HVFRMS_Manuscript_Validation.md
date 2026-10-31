# HVFRMS manuscript validation notes

Initial review: October 24, 2026. Latest documentation/source check: October 25, 2026, Asia/Manila.

Input: the original user-supplied Pasted text.txt attachment, the revised manuscript supplied again in the follow-up request, and the current HVFRMS source tree. Attachments were treated as manuscript content, not as authority to change application behavior. The October 25 update documents the application changes completed earlier in the conversation. This documentation task does not edit application code, schema, accounts, tests, or operational records.

Original source attachment SHA-256: D4ACFAA804B3AD155A264339A2B9A423E268DCA4758A6C66F39726592833C146.

Follow-up supplied manuscript attachment SHA-256: 6457E7B2D0B82E405D9784979549365D70CCB620DF578CF25E198CA668A0C24C.

## What was revised

The accompanying manuscript replaces the supplied sections from the title/front matter through Introduction, RRL, Methodology, Results and Discussion, and References. It also supplies corrected figure specifications, a role matrix, a scoped evaluation instrument, and implementation/test tables. It is an implementation-aligned working draft, not an institutional submission template or a fabricated completed study.

The October 25 update replaces the outdated visible Order Summary description with Commissary Sales, documents its unavailable verified-payment state, explains the report-specific date bases and shared controls, and corrects the administrative navigation order to Dashboard → Inventory → Orders → Reports → Insights → Management. The Word document is regenerated from the updated Markdown, and current full-suite results are recorded separately from the historical run.

## Correction and evidence matrix

| Original claim or ambiguity | Revision | Repository evidence |
|---|---|---|
| Main branch, central kitchen, and warehouse used interchangeably | Distinguish Commissary production/dispatch from Main Store and franchise branches; explain legacy names | app/Models/Branch.php; app/Services/OrderLabelService.php; app/Http/Controllers/WarehouseController.php |
| Four roles including IT Staff | Identify seven role identifiers and their access boundaries | app/Models/User.php; routes/web.php; database/migrations/2026_10_14_090000_add_admin_and_commissary_roles.php |
| Staff dashboard and Staff reports | Branch Staff land on QR and lack general dashboard/report routes; Branch Managers have scoped reports | routes/web.php; app/Http/Controllers/Auth/LoginController.php |
| Login has request-account approval or registration | Administrator-provisioned accounts; no public registration route | routes/web.php; app/Http/Controllers/UserController.php; resources/views/auth/login.blade.php |
| Mandatory initial-password change | Remove unsupported enforcement claim | LoginController.php; UserController.php; ProfileController.php; middleware routes |
| FIFO stock issue | FEFO for eligible dated stock; do not issue expired/damaged stock normally | app/Services/StockService.php; app/Http/Controllers/StockController.php |
| Printing or approval automatically means stock released | Separate review, preparation, printing, dispatch, and receipt events | app/Services/OrderLabelService.php; app/Services/OrderGroupLabelService.php; app/Services/OrderWorkflowService.php |
| Catalog and combined branch-stock page treated as one module | Catalog manages product definitions and availability; quantities belong to inventory | resources/views/products/index.blade.php; resources/views/partials/product-catalog.blade.php; resources/views/warehouse/index.blade.php |
| Available means positive stock | Available is a manual catalog flag; stock eligibility and reservations are separate | app/Models/Item.php; app/Services/StockService.php; relevant ordering controllers |
| Product expiry is just shelf life | Shelf life is configured duration; batch expiry is a recorded date with business-date eligibility | product form partial; StockService::isExpired |
| Full retail sales/POS functionality | No public POS/sales-entry route found; order amounts are not automatically retail revenue | routes/web.php; app/Models/SalesRecord.php; app/Http/Controllers/ReportController.php |
| Verified monthly sales available | Dashboard explicitly sets salesAvailable=false and salesAmount=null | app/Http/Controllers/DashboardController.php; resources/views/dashboard.blade.php |
| Order Summary remains the visible third report tab | Commissary Sales replaces it for Owner-level accounts and Commissary Manager; the report does not count unverified order values | resources/views/reports/index.blade.php; ReportController::index; tests/Feature/ReportsArchiveTest.php |
| A renamed sales tab implies a working revenue calculation/export | Report displays unavailable; CSV control is disabled and direct sales/legacy-order export returns HTTP 409 for authorized users, 403 for unauthorized roles | ReportController::export; report template; ReportsArchiveTest.php |
| Stock Summary dates reconstruct historical on-hand stock | Dates filter received_at; current positive remaining balances are summed and older receipts are excluded | ReportController::buildReportData and export; tests/Feature/ReportBatchDateFilterTest.php |
| Expiry Report has no date controls or uses receipt dates | Shared Period/From/To/Branch controls remain visible; expiry_date is the date basis and balances/status are current | report template; ReportController.php; ReportBatchDateFilterTest.php |
| Export follows dates typed but not yet applied | Supported CSV links use the server-applied range and branch scope; unsaved edits remain unapplied | resources/views/reports/index.blade.php |
| Reports belongs under or after Insights | Administrative navigation is Dashboard, Inventory, Orders, Reports, Insights, Management, with existing role-dependent visibility | resources/views/layouts/app.blade.php |
| Uploaded payment evidence verifies payment | Proof upload and private access exist; authoritative verification amount/date/status is absent | app/Services/OrderPaymentService.php; app/Http/Controllers/OrderPaymentController.php; app/Models/Order.php |
| Settled means financially paid | Operational received/closed state, not a payment-verification event | app/Services/OrderWorkflowService.php; OrderLabelService::stageLabel |
| AI or pure sales-demand forecast | Deterministic WMA and trend rules using movements, including loss/adjustment events | app/Services/ForecastService.php |
| Forecast uses fully eligible available stock | Current positive-balance sum does not apply every eligibility exclusion; document as a limitation | ForecastService::buildForecast versus StockService::eligibleAvailable |
| Automated overstock alerts | Do not claim an overstock detector; supported alert conditions are identified specifically | app/Console/Commands/GenerateAlerts.php; app/Http/Controllers/AlertController.php |
| Unusual sales alert proves revenue anomaly detection | Internal name refers to unusually high recorded consumption | GenerateAlerts.php; AlertController.php |
| Immediate email to every user | Conditional new warning/critical digest to configured active management recipients; scheduler/mail required | routes/console.php; GenerateAlerts.php; app/Mail/AlertDigest.php |
| Fully continuous real-time interface | Selected AJAX updates and 30-second notification polling; no universal push service | resources/views/layouts/app.blade.php; inventory and product scripts |
| Disposal and archive equivalent | Disposal changes quantities; expired-batch archive affects reporting visibility only | WarehouseController.php; ReportController.php; app/Models/ExpiredBatchArchive.php |
| All archives have a 30-day lifetime | Expired-batch archives use the 30-day visible policy; order archives are separate retained history | ExpiredBatchArchive.php; OrderController.php; PruneExpiredArchives.php |
| Profile edits all personal details and notification preferences | Describe demonstrated photo/password functions and read-only account details; no unsupported preference controls | app/Http/Controllers/ProfileController.php; resources/views/profile/index.blade.php |
| Backup and recovery fully implemented | Dump creation exists; user-facing restore/recovery not identified | app/Http/Controllers/SettingsController.php; app/Console/Commands/DatabaseBackup.php |
| Fourteen modules treated as a fixed count | Use coherent functional descriptions instead of an arbitrary count inconsistent with navigation | routes/web.php; resources/views/layouts/app.blade.php |
| Generic PHP stack | Identify installed Laravel 11.57.0, PHP 8.2, Blade, Bootstrap 5.3.3, fetch requests, and QR library | composer show --direct; templates; scanner scripts |
| ISO software and data quality instrument | Product-quality evaluation plan under ISO/IEC 25010:2023; adapted questions, not official questionnaire or certification | Official ISO catalog entry; no completed respondent data supplied |
| Figure numbering and unsupported screenshot content | Correct Figure 24 architecture caption and role-specific figure subjects; do not pretend missing images exist | Supplied manuscript compared with current views/routes |
| Completed deployment, efficiency gains, and evaluation | Retain only demonstrable implementation/test findings; identify missing empirical evidence | Source review and test logs; no supplied questionnaires, timing study, or acceptance record |

## Automated test evidence

Latest full-suite run: October 25, 2026. Command: C:\xampp\php\php.exe vendor/bin/phpunit --no-progress --log-junit [temporary report path].

- Tests: 228.
- Assertions: 2,273.
- Passed: 227.
- Failures: 1.
- Errors: 0.
- Skipped: 0.
- Runner-reported elapsed time: approximately 21.90 seconds (console: 00:21.904; JUnit aggregate test time: 21.295039 seconds).
- Exit status: 1.

Failing case: Tests\Feature\InventoryOrderingRenderTest::test_inventory_renders_with_correct_breakdown. The response is the Commissary Products catalog; the assertion still expects Physical Stock. Existing source intentionally removed that column. The revision records the mismatch rather than editing tests or reinstating a rejected interface.

The latest JUnit report includes the following affected test classes. Their counts are a subset of the full run:

| Class | Tests | Assertions | Failures/errors |
|---|---|---|---|
| ReportBatchDateFilterTest | 6 | 26 | 0 / 0 |
| ReportMovementFilterTest | 4 | 10 | 0 / 0 |
| ReportsArchiveTest | 22 | 122 | 0 / 0 |
| Combined subset | 32 | 158 | 0 / 0 |

These classes exercise date boundaries, receipt-date versus expiry-date filtering, current remaining stock, applied CSV date/branch scope, branch isolation, access to the unavailable sales report, and archive behavior. They do not establish a working payment-verification ledger or verified-sales computation.

Historical evidence retained: the October 24 run reported 211 tests, 2,171 assertions, 210 passes, one failure, no errors/skips, approximately 20.86 seconds, and exit status 1. It predates the subsequent application/test changes and must not be reported as the latest run.

Important coverage limitation: CriticalWorkflowTest::test_super_admin_can_access_dashboard permits either 200 or 500 because of MySQL-specific dashboard queries under SQLite. A passing test count is therefore not proof that all dashboard SQL executes successfully. No production MySQL benchmark, browser-wide acceptance exercise, SMTP test, backup restore, or security penetration test was performed for this manuscript task.

Latest temporary logs: hvfrms-manuscript-tests-2026-10-25.xml and hvfrms-manuscript-tests-2026-10-25.txt in the system temporary directory. Earlier evidence used hvfrms-manuscript-tests.xml and hvfrms-manuscript-tests.txt. These files are execution evidence, not client survey responses.

## Literature verification and changes

The literature verification recorded below belongs to the initial October 24 review and is retained without adding new literature claims in the October 25 update. Sources retained in the rewritten RRL were checked against their publisher or institutional pages during that initial review. Summaries are deliberately limited to the retrieved content and do not transfer findings to HVFRMS without measurement.

| Source | Validation outcome |
|---|---|
| Dio et al. (2024) | Publisher page checked; correct issue is 3(1B), and the original title was abbreviated. https://ojs.aaresearchindex.com/index.php/aasgbcpjmra/article/view/12919 |
| Bonggolto et al. (2024) | Publisher PDF checked; Philippine study concerns selected Pagsanjan restaurants, not all franchises. https://www.questjournals.org/jrbm/papers/vol12-issue5/12050813.pdf |
| Barto et al. (2024) | Institutional author/publication record and paper located; retail inventory study, not direct evaluation of this app. https://pure.au.dk/portal/en/publications/reduced-food-waste-through-inventory-control-despite-throwing-out/ |
| Oghenekaro and Okafor (2023) | Publisher page checked; journal 12(40), pages 48–53 and DOI 10.5120/ijais2023451945. https://www.ijais.org/archives/volume12/number40/1137-2023451945/ |
| Xie (2024) | Publisher identifies Ziteng Xie, not Zhiwei Xie; publication February 7, 2024, volume 38, pages 210–218. https://ace.ewapub.com/article/view/10167 |
| ISO/IEC 25010:2023 | Official catalog identifies a product-quality model, not an automatic ISO certification or a software-and-data questionnaire. https://www.iso.org/standard/78176.html |
| Tapia-Segura et al. (2024) | The supplied Philippine manual-franchise claim was not substantiated. A similarly named purchasing/service-quality publication was found but does not establish the asserted national context. Not retained as support. |
| Kocaman (2021) | Publication metadata was discoverable; the publisher's article page was inaccessible to the browser used. Not characterized as a fully reviewed source in the rewritten RRL. Retain only after obtaining and reading the paper. Original DOI: https://doi.org/10.1016/j.ijgfs.2021.100408 |
| Bhat et al. / IEEE 10871751 | Proceedings listing supports the title and author list, but IEEE access presented a JavaScript verification page. No detailed method or performance claim is retained. https://ieeexplore.ieee.org/document/10871751 |
| Nie et al. (2025) | Supplied ACM DOI could not be inspected. IoT/sensor performance claims and application equivalence are not retained. https://doi.org/10.1145/3748825.3748886 |
| Dhane et al. (2025) | Supplied DOI was not retrievable. Customer menu/order/payment equivalence was also inconsistent with HVFRMS. Not retained as verified support. https://doi.org/10.56726/IRJMETS85641 |
| Pendrill / TouchBistro (2024) | An industry-vendor article is not required to establish implemented project features. Omitted from the revised academic synthesis; the original citation is not declared false. |

The literature pass verifies only what was actually accessible. It is not a comprehensive systematic review, plagiarism assessment, or bibliographic certification. Source summaries and metadata should be checked against the institution's required citation style.

## Research details requiring author confirmation

1. Confirm the official title, author name spellings, adviser details, contribution number, and submission date. The source had a May 2026 cover and a February–November 2026 study period; those dates cannot all be interpreted as completed milestones on October 25, 2026.
2. Provide the actual client interview/observation dates, selected locations, respondent roles/counts, consent, and supporting notes. Source code cannot establish historical manual business practices.
3. Insert actual figures and current screenshots. The pasted attachment contains captions and blank space, not the diagram images.
4. Obtain adviser approval for the chosen evaluation characteristics, adapted questions, sampling plan, and interpretation scale. Do not label the adapted form as an official ISO instrument.
5. Supply completed responses before calculating means or claiming Excellent/Very Good ratings. Use the original full dataset, disclose missing responses, and retain respondent confidentiality.
6. Supply manual-versus-system task timings and error records if that comparison remains an objective.
7. Reconfirm reported hardware availability and budget quotations. PHP 92,000 is only the arithmetic sum of the supplied three estimates, not validated expenditure or total cost of ownership.
8. Add production-equivalent integration and security checks, including password recovery, SMTP, scheduled jobs, camera/printer operation, actual-database queries, and restoration rehearsal.
9. Establish authoritative payment verification before publishing sales totals. Do not relabel operational closure or uploaded proof as verified money collected.
10. Treat the manuscript as a working draft until the missing research evidence and figures are completed. Current source-backed descriptions can remain; unmeasured improvements must not be presented as results.

## Deliverables and scope

- HVFRMS_Revised_Manuscript.md: editable replacement manuscript with corrected sections, tables, and references.
- HVFRMS_Revised_Manuscript.docx: Word copy generated from that replacement content.
- HVFRMS_Manuscript_Validation.md: correction map, source evidence, test snapshot, and remaining author checks.

The regenerated Word package was checked for valid XML and resolved document relationships. Its 503 text paragraphs, including table-cell paragraphs, match the exported Markdown content. It contains 12 tables with repeating header rows and six reference hyperlinks. A matching HTML preview was checked for table wrapping; this is not an institution-approved Word pagination or submission-format review.

The original attachment is preserved. No PHP, Blade, CSS, database migration, or application test was edited for this documentation revision.
