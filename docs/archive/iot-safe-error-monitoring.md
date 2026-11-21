# Safe IoT backend error monitoring

The selected IoT endpoints previously suppressed every exception report in
`bootstrap/app.php`. Generic JSON responses remain unchanged. Unexpected failures
now produce a sanitized Laravel error-level log record; the original exception
is still suppressed to prevent messages, SQL bindings and trace arguments from
reaching the default exception logger.

Only three context keys are emitted: a fixed `category`, an allowlisted `endpoint`
and a server-generated UUID `correlation_id`. No exception object, message, stack,
request body, headers, query string, user/device identifier or image is included.
The UUID is an operator log identifier, not a client-provided ID or new response
field. Unknown paths collapse to `iot.other`.

Example (fictional):

```text
IoT backend failure. {"category":"database_failure","endpoint":"api/iot/v1/status","correlation_id":"00000000-0000-4000-8000-000000000001"}
```

Categories are `database_failure`, `application_error`, `server_unavailable` and
`unexpected_failure`. Authentication, validation and HTTP errors below 500 stay
quiet. An explicitly disabled IoT API's HTTP 503 stays quiet. HTTP server errors
on enabled IoT endpoints are recorded, including intentional storage/preview
unavailability responses. Rendering also calls the safe reporter because Laravel
ignores HttpExceptions before custom reporting callbacks. A request attribute
prevents report/render duplication.

An atomic file-cache add limits each endpoint/category to one record per 60
seconds across PHP workers on the same filesystem. Suppressed occurrences are
not counted. This deliberately avoids a database dependency during DB outages.
Cache and logging exceptions are swallowed inside the monitoring boundary without
recursive reporting or changes to the original HTTP response.

## Deployment considerations

- No migration, firmware change, new dependency or environment setting is needed.
- Keep Laravel storage outside the public document root. The existing file cache
  directory and log destination must be writable by the PHP account.
- If file cache is unavailable, diagnostics fail closed (no record) rather than
  risking unlimited outage logs. A logging outage can also lose diagnostics.
- Use the existing log rotation/retention policy. Inspect the configured Laravel
  log destination rather than assuming a particular log filename.
- The shared rate budget is filesystem-local, not a distributed fleet limit.
- Request-wide reporting is intentionally limited to the first eligible failure.
- These controls cover the selected backend endpoints only, not web-server access
  logs, PHP startup errors, external log processors or future instrumentation.
  Do not add request-enriching processors to this diagnostic path.
- No live production or physical ESP32 testing was performed for this change.
