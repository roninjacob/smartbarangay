# Schedule organization

Admin Schedule Management defaults to **Upcoming**. **Past Schedules** preserves slots whose start time has been reached, including slots still marked Active.

- The cutoff uses Asia/Manila date and start time, with one captured timestamp per listing request. At the exact start time, the slot belongs to Past.
- Upcoming sorts nearest first; Past sorts most recent first. Date, time and ID provide deterministic ordering.
- Booking status and inclusive From/Through date filters apply before database pagination (15 slots). Pagination preserves the selected filters. Invalid filters are rejected by server validation.
- Active/Inactive is an administrative flag, separate from time eligibility. Past slots cannot be activated, including forged or stale form submissions; the guard runs after locking the schedule.
- Past slots show booking closed and the number of currently in-progress reservations, rather than suggesting that remaining capacity can be booked.
- Existing Resident time checks, schedule capacity rules, appointment snapshots and reservation processing rules remain in place.
- A linked reservation, including terminal reservations, continues to prevent deletion and date/time edits. An unused slot can still be deleted through the existing confirmation.
- Viewing either list does not archive, deactivate, cancel, complete or delete business records. No scheduled cleanup or migration is needed to move entries between the views; the next request recalculates the cutoff.
- Tables become cards on narrower screens. Empty states, filter reset and historical-protection notes explain what the Admin can do next.

Automated coverage: `tests/Feature/ScheduleManagementTest.php`, including exact Manila cutoff, filters, ordering, pagination, authorization, CSRF, protected deletion and activation rejection.

October 27 follow-up: new slots and changed date/time ranges must start in the future at both request validation and transaction validation. Unchanged historical date/time ranges can still receive allowed capacity edits. Edit forms now require an HMAC state revision and reject stale or missing revisions before writes. Linked-date/time restrictions and occupancy floors remain enforced.
