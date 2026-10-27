# SmartBarangay — System Walkthrough para kay Dealyn

Updated: October 26, 2026. Based sa current code sa `main`, checkpoint `ec85a26`.

**Para saan ang guide na ito?** Para maintindihan mo muna ang system bago mo i-test o i-edit. Ang installation at Git commands ay nasa [Simple Setup, Testing at Push Guide](dealyn-simple-guide-taglish.md).

## 1. Ano ang SmartBarangay?

SmartBarangay ay document reservation system para sa **Barangay Calayo, Nasugbu, Batangas**.

Imbes na walang idea ang Resident kung ano ang kailangan at ano na ang nangyayari sa request, puwede niyang gamitin ang website para mag-request, pumili ng available schedule, magsubmit ng supporting files, mag-track at kumuha ng QR ticket kapag approved.

Sa Admin side naman, dito inaayos ang services, requirements at schedules, nire-review ang requests, pinapersonalize ang certificates, at nirerecord ang payment, arrival at completion.

**Dalawa lang ang roles:**

| Role | Simpleng paliwanag |
| --- | --- |
| Resident | Taong humihingi ng barangay document. Sarili niyang account at requests ang naa-access niya. |
| Admin | Authorized barangay operator na nagse-set up at nagpo-process ng requests. |

Walang Staff role, public Admin registration o ibang barangay selector.

## 2. Buong flow sa isang tingin

```text
LANDING PAGE
   -> Create Account bilang Resident
   -> Verify Email
   -> Complete/check Profile kung kailangan ng document
   -> New Reservation: service, information, schedule, files
   -> Submit: Pending
   -> Admin reviews: Under Review
   -> Admin approves: Approved + QR ticket issued
   -> Admin reviews personalized Preview at finalizes certificate
   -> Admin manually sets Ready for Pickup
   -> Resident visits at presents QR
   -> Admin verifies QR at opens correct Reservation Details
   -> Admin explicitly confirms arrival, kung magre-record ng check-in
   -> Admin records on-site payment kung may positive fee
   -> Admin manually marks Completed
   -> QR becomes Used / Historical
```

Ito ang isang typical successful scenario, hindi isang automatic chain ng actions. Halimbawa, payment at check-in ay magkahiwalay. Printing does not change status. QR verification alone does not record arrival. Hindi rin lahat ng services ay may dynamic fields o positive fee.

**Main record ang Reservation.** Ang QR, certificate, attachments, payment at history ay konektado sa reservation; hindi sila magkakahiwalay na transaction system.

## 3. Landing page — public website

Ito ang unang makikita sa `/`. Puwedeng tingnan kahit hindi logged in.

| Section | Ano ang ginagawa |
| --- | --- |
| Header/menu | Links papuntang Services, How it works, About at Contact. May mobile menu sa maliit na screen. |
| Hero / welcome section | Ipinapaliwanag ang purpose ng SmartBarangay at nagbibigay ng main action para magsimula. |
| Services | Active service names at descriptions. Halimbawa: clearance, indigency o residency kung configured sa database. Hindi fixed na laging iyon ang available list. |
| How it works | Simple guide: create account -> request -> track at visit. |
| About | Paliwanag kung para kanino at para saan ang system. |
| Contact | Barangay location at public contact-information area. Sa current page, may placeholders pa kung hindi posted ang office address/hours/number/email. |
| Footer | Branding at Back to top link. |

**Kapag Guest:** may Sign In, Register, Request a Document at Create an Account. Kailangan munang mag-login para makapag-request.

**Kapag logged in:** may role-appropriate Go to Dashboard at View My Requests / View Reservations. Kung unverified, puwedeng Verify Email ang action. Hindi automatic na nawawala ang landing page kapag authenticated.

Public page ito, kaya walang private Resident records, uploads o QR credentials. Hindi rin ipinapakita ang service fees sa public service cards; makikita ang applicable fees sa authenticated request flow.

## 4. Create Account — Resident registration

Sa Register page, hinihingi ang:

- Full name.
- Unique email address.
- Valid contact number.
- Address in Barangay Calayo.
- Password at password confirmation. At least eight characters, may letters at numbers.

Resident ang role na ibinibigay ng server. Walang dropdown para gawing Admin ang sarili.

Pagkatapos mag-register:

1. Created na ang account pero kailangan pang mag-verify ng email.
2. Buksan ang signed verification link para sa mismong account.
3. Pagkatapos, puwede nang gamitin ang protected Resident features kung active ang account.
4. I-check ang Profile / Account Settings. Hindi kailangan kumpletuhin agad ang bawat optional demographic field; ang napiling document ang magsasabi kung aling data ang required.

**Local testing note:** kapag `MAIL_MAILER=log`, nasa local Laravel log ang verification email. Sundin ang setup guide; huwag mag-bypass sa database.

Kung mali ang email ng active, unverified Resident, may existing secure email-correction flow bago verification. Hindi ito ordinaryong verified-email editing at hindi ito Admin bypass.

## 5. Sign In, verification, reset at logout

Parehong login page ang ginagamit ng Resident at Admin. Pag-login, server ang magdadala sa tamang role area.

| Feature | Gamit |
| --- | --- |
| Sign In | Email at password para makapasok. May protection laban sa repeated failed attempts. |
| Show password | Pang-check ng typed password; hindi nito binabago ang account. |
| Forgot Password | Humingi ng password-reset link. Hindi nito ibinibigay ang lumang password. |
| Verify/resend email | Complete o ulitin ang verification message kung kailangan. |
| Logout | Tapusin ang current login session gamit ang protected logout action. |

Active, verified at tamang role ang kailangan para sa protected modules. Ang account na inactive/unverified ay may access restrictions kahit tama ang password. Wrong-role access remains forbidden.

Admin accounts are created through `php artisan smartbarangay:create-admin` sa authorized environment, at normal email verification pa rin ang kailangan.

## 6. Resident area — bawat module

### A. Dashboard

Home overview ito ng **sarili mong** requests:

- Total reservations.
- In progress: Pending + Under Review + Approved.
- Ready for pickup.
- Completed.
- Latest requests, status overview at helpful next-step guidance.
- Request a document button at quick links.

Hindi global barangay totals ang Resident dashboard. Hindi rin ibig sabihin ng Approved ay puwede nang pumunta para kumuha; hintayin ang Ready for Pickup.

### B. Notifications

Dito makikita ang in-app updates gaya ng submitted request, status changes, cancellation, payment recorded at completion.

May All / Unread filters, opening the linked request, marking a notification read at Mark all as read. Read notifications stay in history.

Notifications are updates, hindi transaction controls. Pagbasa ng notification ay hindi approval, payment o completion. Hindi rin ito live chat, SMS o guaranteed email notification ng bawat status.

### C. New Reservation

Guided form ito na may apat na steps:

| Step | Ano ang gagawin ng Resident |
| --- | --- |
| 1. Choose service | Piliin ang service. Basahin ang description at fee. Ang service na hindi pa maayos ang fee/configuration ay maaaring hindi bookable. |
| 2. Document information | Basahin ang requirements. Kung dynamic ang document, sagutan ang custom fields at i-check ang system information mula sa profile. Required missing profile data must be corrected first. |
| 3. Choose schedule | Pumili ng valid future date/time na active at may remaining capacity. |
| 4. Confirm request | I-review ang service, fee, information at appointment; attach required supporting files, saka Confirm Reservation. |

Supporting files: PDF, JPG/JPEG o PNG, within the displayed limits. Required uploads cannot be skipped; optional uploads may be blank. Sa current flow, up to 5 MB bawat requirement file.

Kapag successful ang submission:

- May bagong reservation na **Pending**.
- Naka-record ang supporting attachments at initial history.
- May applicable in-app notifications.
- Naka-save ang historical fee, appointment, requirements at configured document information/source/layout para sa request.

Important safeguards:

- Hindi puwedeng piliin ang past, inactive o full slot.
- Backend ang final authority sa capacity; hindi sapat na mukhang available sa screen.
- Active duplicate ay blocked kapag same Resident + service + schedule. Active: Pending, Under Review, Approved, Ready for Pickup.
- Cancelled/Rejected/Completed previous request does not trigger that active-duplicate rule, pero kailangan pa rin pumasa sa time/capacity/configuration checks.
- May protection sa repeated submission. Hindi dapat dalawang reservations ang resulta ng accidental double click.

### D. My Reservations

Listahan ng submitted requests ng logged-in Resident. Puwedeng tingnan ang details ng bawat sarili niyang request.

Sa details, makikita ang applicable service, appointment, fee/payment status, submitted information, historical requirements, supporting files, status at history. Available personalized previews/generated-document actions depend sa saved data at authorization.

Puwedeng **i-cancel ang sariling Pending request** through the confirmation/reason flow. Hindi ito deletion: may record at history pa rin. Walang ordinary Resident cancellation ng Under Review, Approved, Ready for Pickup o Completed.

Hindi puwedeng buksan ang request o private files ng ibang Resident kahit malaman ang URL/ID.

### E. My QR Tickets

QR ticket ay issued kapag **Approved** na ang request. One ticket per reservation.

- Approved / Ready for Pickup: puwedeng magkaroon ng active ticket, subject sa account/ticket eligibility.
- Completed: retained as Used / Historical; hindi valid para ulitin ang transaction.
- Pending / Under Review: wala pang active approved ticket.

QR is a secure reference to the reservation, hindi kopya ng lahat ng personal information. Ipapakita ito sa authorized Admin para ma-verify ang tamang request.

**May QR na sa Approved, pero hintayin pa rin ang Ready for Pickup bago collection.**

### F. Request Status

Dedicated tracking view ito: current status, understandable message at supported history/progression.

**My Reservations = listahan at full request details. Request Status = mas focused sa progress.** Parehong reservation records ang pinagbabatayan, hindi separate applications.

Internal Admin processing notes are not automatically exposed sa Resident tracking.

### G. Profile / Account Settings

Dito ina-update ang sariling account information:

- Name, contact number at address.
- Optional profile picture: upload, replace o remove.
- Resident demographics: birthdate, civil status, occupation, street at zone/purok.
- Optional emergency-contact name at number.

Current profile email is read-only sa normal verified profile page. Hindi editable ang role, active status o verification fields dito.

Demographic fields can support configured certificates. Emergency-contact data stays private and is not automatically printed as document fields. Separate pa rin ang existing full address at structured street/purok fields; hindi basta pinapalitan ang full address.

New requests use the applicable current profile data. **Pag-edit ng profile ngayon ay hindi dapat baguhin ang historical information ng old requests.**

### H. Settings

Appearance at About page ito:

- Preview at save ng light/dark mode.
- Saved preference follows the account across sessions.
- About SmartBarangay information.

Hindi binabago ng dark mode ang official PDF/certificate appearance. Hindi ito kapalit ng Profile / Account Settings.

### I. Public Website, account popup at mobile navigation

Public Website returns to the landing page. Ang topbar account button ay quick profile overview at link to profile editing. Sa phone, may Resident quick navigation para sa Home, Requests, QR tickets at Account.

## 7. Ano ang ibig sabihin ng statuses?

| Status | Meaning / next step |
| --- | --- |
| Pending | Submitted; naghihintay ng review. Owner may cancel. Admin may start review or reject. |
| Under Review | Admin is reviewing. Admin may approve or reject. |
| Approved | Approved na; QR issued. Eligible ang document preparation. Hindi pa automatically ready for collection. |
| Ready for Pickup | Admin explicitly set collection readiness. Eligible ang verified arrival confirmation at positive-fee payment recording. |
| Completed | Admin finished the transaction through the completion action; QR is consumed. |
| Rejected | Admin rejected at an allowed stage. Retained ang record/history. |
| Cancelled | Resident cancelled sariling Pending request. Retained ang record/history. |

Primary progression: **Pending -> Under Review -> Approved -> Ready for Pickup -> Completed.**

Walang ordinary button na magre-reset ng terminal Completed/Rejected/Cancelled request pabalik sa Pending. Huwag gumawa ng shortcut sa database bilang normal workflow.

## 8. Admin area — bawat module

### A. Dashboard

Barangay-wide operational overview:

- Needs attention: Pending + Under Review.
- Ready for pickup, total reservations at registered Residents.
- Scheduled-today count, based sa recorded appointment date, with legacy fallback where needed; hindi ito bilang ng actual arrivals.
- Collections today/month, paid transactions today at Ready for Pickup unpaid count.
- Latest requests, current-status overview at quick links.

Dashboard totals can differ from period-filtered Reports: magkaiba ang scope, dates at reviewed fixture exclusions. Hindi automatic bug ang difference.

### B. Notifications

Admin receives relevant notifications such as new Resident submissions at Resident cancellations, with links to the request. May own read/unread history ang bawat eligible recipient.

Hindi lahat ng Admin action ay dapat gumawa ng notification sa sarili niyang Admin account. Resident updates go to the relevant owner according sa existing event rules.

### C. Reservation Management

Ito ang **main processing module**.

1. Hanapin ang request gamit ang list/search/status filter.
2. Open Reservation Details.
3. Review Resident information, appointment, requirements, attachments at document information.
4. Record only the allowed next status; puwedeng maglagay ng processing note where supported.
5. Use the same details page for certificate preparation, QR state, check-in, payment at completion.

May status history at activity information. May protection laban sa stale status updates, halimbawa kung may ibang Admin na nauna nang nag-process.

Hindi permanent deletion ang normal request handling. Wala nang separate Request Status Management placeholder; dito talaga nangyayari ang Admin status updates.

### D. Schedule & Slot Management

Admin can create/manage schedule dates, start/end times, capacity at active status, at makita ang occupancy. Unused schedules can be deleted safely.

Pending, Under Review, Approved at Ready for Pickup count toward occupancy. Cancelled, Rejected at Completed are excluded from that active occupancy.

Hindi puwedeng bawasan ang capacity below current occupying requests. Protected ang linked schedules; date/time editing is blocked kapag may linked reservations. New reservations preserve appointment snapshots, kaya hindi dapat mag-shift silently ang historical appointment.

### E. QR Verification

Browser/laptop camera scans the ticket; Laravel verifies it sa server.

Successful verification:

```text
Scan QR -> server checks ticket -> success feedback
        -> short delay -> correct Reservation Details
```

Server ang nagre-resolve ng reservation, hindi trusted front-end ID. Invalid, ineligible o used ticket stays rejected without exposing private details.

**Tatlong magkaibang actions:**

| Action | Meaning |
| --- | --- |
| Verify QR | Valid ba at anong reservation ito? Hindi pa arrival o completion. |
| Confirm Check-In | Admin explicitly confirms physical arrival for eligible Ready for Pickup request, after recent successful QR verification. One operational arrival per request. |
| Mark Completed | Finish the eligible transaction. Dito ginagamit/kinu-consume ang QR. |

Check-in does not collect payment, change status or consume QR. Current completion logic does not make check-in an additional mandatory gate; huwag mag-imbento ng ganoong rule.

### F. Services & Document Management

May magkakaugnay na parts dito:

| Part | Purpose |
| --- | --- |
| Service catalog | Create/edit service names, descriptions, explicit fees at active/inactive status. Deactivation preserves old requests. |
| Requirements | Manage name/description at required/optional state ng supporting files. Referenced records are protected from unsafe deletion. |
| Official PDF | Upload/replace/remove a validated private PDF master through guarded actions. May blank preview, print at download tools. |
| Document configuration | Set up/activate the dynamic document configuration and review its current PDF reference/readiness. |
| Document fields | System fields from allowed profile/system sources, plus custom Text, Number, Date at Dropdown fields with validation. |
| Design Document Layout | Place fields on PDF pages, move/resize, set font size/alignment at wrap/auto-resize. Sample-data preview is illustrative, not a generated real certificate. |
| Reservation certificate actions | Access the request's saved personalized output when authorized; reservation printing never substitutes the blank master. |

Master replacement can require reconfiguration before **new** dynamic requests. Historical requests keep their applicable preserved source/data; do not replace them with today's master/profile as a workaround.

### G. Personalized certificate preparation

Sa eligible Approved / Ready for Pickup Reservation Details:

1. Open **Preview & Finalize Certificate** / applicable preview action.
2. Review the protected personalized PDF using captured request data and saved placement.
3. Explicitly finalize after reviewing the actual preview PDF.
4. The exact reviewed bytes are promoted to the saved final PDF, without rerendering.
5. Preview/download/print the authorized saved output.

Sa current Admin reservation printing, Approved at Ready for Pickup ang allowed statuses; hindi automatically extended sa Completed ang Print action dahil historical na ang record. Sundin ang existing available actions at authorization.

Opening the preview page alone, an unserved preview, or a HEAD request is not valid review evidence. Expired/stale/unauthorized review attempts must not finalize a certificate.

Generation is not automatic on submission, approval, QR scanning or printing. Existing final output cannot be casually overwritten; correction/regeneration/versioning is not a normal implemented workflow.

If complete historical source/data is missing, generation may be unavailable. Huwag palitan ng current master bilang silent fallback.

### H. Payment recording inside Reservation Details

On-site payment recording ito, **hindi online checkout**.

- Eligible only at Ready for Pickup, with a known positive historical fee and no existing payment.
- Recorded amount comes from the reservation's fee snapshot, hindi today's Service price.
- Duplicate/overwrite payment attempts are blocked.
- Resident can see the authorized payment status/details of their own request.

| Payment situation | Completion behavior, subject sa other existing checks |
| --- | --- |
| Positive fee + Unpaid | Cannot Complete. |
| Positive fee + valid Paid record | Can proceed to completion. |
| Explicit `0.00` / No Payment Required | Can proceed without payment. |
| Legacy NULL / Fee Not Recorded | Retains existing legacy completion eligibility; hindi automatic na may zero fee. |
| Payment Integrity Issue | Cannot Complete; requires investigation. |

Recording payment does **not** automatically Complete, mark Ready, generate a certificate or consume QR. Walang ordinary payment edit/delete, refund o online payment gateway workflow.

### I. User Management

Admin can list/search/filter Residents, view authorized account information/avatar at activate/deactivate Resident access.

Hindi nito ipinapakita ang password at hindi ito public Admin creation o arbitrary role-changing tool. Admin can view authorized Resident avatars, pero hindi niya puwedeng i-edit ang Resident profile picture dito.

### J. Reservation Logs

Read-only activity timeline across reservations, with search/activity filters at pagination.

Possible events come from actual authoritative records: submission, status history, QR issuance, certificate finalization, explicit check-in at actual payment. Completion comes from status history; hindi kailangan ng duplicate standalone QR Used event.

Viewing/searching/paginating logs does not create transactions. Walang fake payment para sa free requests at walang fake arrival mula sa ordinary scan.

### K. Reports and CSV downloads

| Sidebar module | Ano ang sinusukat / date basis |
| --- | --- |
| Reservation Reports | Recorded submissions in the chosen submission period. Status breakdown is their **current status**, not a reconstruction of period-end status. |
| Collections Reports | Valid recorded payments using **paid_at** and immutable recorded amount. May separate Integrity Issues table view. |
| Recorded Check-ins | Explicit valid operational arrivals using **verified_at**. Ordinary scans/verification are not arrivals. |
| Finalized PDFs | Valid finalized PDF records using **generated_at**. Hindi ito count ng physical prints o lahat ng submitted requests. |

May period choices gaya ng Today, Yesterday, This Week, This Month, Specific Date at Custom Range. May applicable summary, charts, service breakdown at paginated detail table. Date fields reveal according sa selected period; invalid filters show errors without fallback totals.

May **Download CSV** para sa matching dataset across pages, using the same filters/partitions/exclusions. Collections export follows the active Valid Payments / Integrity Issues view. Malformed arrivals at legacy/incomplete document records appear as applicable period-bound warnings, hindi valid historical event totals.

Reports use an explicitly reviewed private exclusion policy. Sa new laptop/database, puwedeng unavailable muna habang pending ang local manifest review. Hindi puwedeng kopyahin ang reservation IDs ng ibang environment. Hindi rin certified official transactions ang lahat ng natira just because included sila sa report.

**Dates are displayed/filtered in Philippine time; internal event timestamps are stored/queried with UTC boundaries.** Magkakaiba ang submission date, appointment date, arrival date, payment date at finalization date. Huwag ipagpalit ang mga iyon kapag nagte-test.

### L. Admin profile, Settings at Public Website

Admin can edit their own allowed profile fields/avatar through Profile / Account Settings. Same protected-profile architecture, hindi separate Resident editing system.

Settings has appearance and About. The **System controls** section is currently a structured placeholder; walang implemented operational toggle doon. Hindi ito bug na hindi makapag-edit ng SMTP, environment secrets, website contact information o announcements sa Settings.

Public Website opens the public landing page. Logout ends the current session.

## 9. Apat na files/concepts na huwag pagpalitin

| Concept | Example | Saan galing / purpose |
| --- | --- | --- |
| Supporting attachment | Fake Valid ID / proof | Resident upload to support a request. Hindi ito certificate template. |
| Official master PDF | Blank clearance form | Admin-managed background. Blank printing tools use this. |
| Personalized Preview | Certificate with captured Resident details | Protected review output before finalization. Hindi pa permanent final record. |
| Generated final PDF | Saved personalized certificate | Explicitly finalized output tied to the reservation. Reservation printing uses this only. |

Current engine is **PDF-based** with saved placements. Hindi ito direct DOCX placeholder replacement. Do not add DOCX generation or recreate a certificate as ordinary HTML just because may older wording sa conversation.

## 10. Example: isang complete test transaction

Gawin lang ito sa sariling synthetic local environment:

1. Admin prepares `QA Clearance`, fee `50.00`, requirements, future schedule at valid sample PDF configuration/layout.
2. Resident A registers, verifies email at checks profile information needed by that document.
3. Resident submits information, selects schedule, uploads fake required files at confirms.
4. Resident sees Pending. Admin sees the new request.
5. Admin opens details -> Under Review -> Approved. QR becomes available.
6. Admin opens personalized Preview, checks long text/layout at finalizes the reviewed PDF.
7. Admin may inspect/print the saved certificate. Request stays Approved until manually changed.
8. Admin manually sets Ready for Pickup. Resident sees the readiness update.
9. Resident presents the QR; Admin scans/verifies and is guided to correct details.
10. Admin explicitly confirms physical arrival if recording check-in. QR stays active.
11. Try completion before payment: expected blocked for this positive-fee request, with no partial status/history/QR changes.
12. Admin records the valid payment. Status stays Ready for Pickup.
13. Admin manually marks Completed. History updates and QR becomes Used / Historical.
14. Scan the ticket again: expected rejected as already completed, not reusable.
15. Inspect notifications/logs and the relevant reports when the local reporting policy is approved.
16. Log in as Resident B: A's private details/files/certificate/QR must remain inaccessible.

Use separate requests for cancellation, rejection at free-service testing. Hindi puwedeng piliting i-reset ang completed transaction para lang ulitin ang example.

## 11. Expected limitations — huwag ituring na implemented features

- Walang IoT/ESP32 hardware workflow na dapat i-assume na complete sa current handoff.
- Walang third role, multi-barangay mode o public Admin registration.
- Walang GCash/card checkout, payment refunds/corrections o editable payment records sa normal UI.
- Walang ordinary overwrite/regeneration ng finalized certificates.
- Walang standalone Scheduled Appointments Report sa current report set; Recorded Check-ins measures actual recorded arrivals.
- Settings does not currently manage public announcements/contact content or secret configuration.
- Private files at reviewed reporting configuration are not delivered by Git clone. Empty/unavailable states may be expected until the local setup is complete.
- Historical records with missing old evidence may be restricted; huwag mag-invent ng missing data para magmukhang complete.
- Scheduler registrations exist for housekeeping, pero production/background execution needs separate environment setup. Hindi automatic na tumatakbo nightly dahil cloned lang ang repo.

Kung may hindi tugma sa guide, i-check muna ang actual code, data prerequisites at task instruction ni Ronin. Report the discrepancy; huwag gumawa agad ng bagong policy o module.

## 12. Paano gamitin ito bilang tester

1. Basahin ang Guest flow, then Resident flow, then Admin flow.
2. Sundan ang complete sample transaction using fictional data.
3. Subukan ang invalid/blocked paths at ownership checks, hindi puro successful clicks.
4. I-record ang actual result, branch/commit, steps at sanitized screenshot.
5. Markahan ng **Blocked / Not tested** ang hindi mo nagawa; huwag mag-claim ng Pass.
6. Report muna kay Ronin. Edit only the assigned/approved fix sa `qa/dealyn-whole-system`.
7. Ronin at Codex ang reviewers. Ronin ang final overall tester at siya lang ang mag-i-integrate sa `main`.

Related guides: [Simple setup/testing/push](dealyn-simple-guide-taglish.md), [detailed onboarding](dealyn-onboarding-and-testing.md), [printing](certificate-printing.md), [payments](on-site-payments.md), [check-in](check-in.md), [workflow history](reservation-workflow-hardening.md), [notifications](notifications.md), [reporting](reservation-reports.md).

Documentation only: no application behavior, database records, branch settings, commits or pushes were changed when preparing this walkthrough.
