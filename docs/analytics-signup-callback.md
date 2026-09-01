# Signup conversion callback

Loom exposes `Loom\Analytics\SignupConversion` for signup flows that complete in
an external console. The browser-side collector exposes the same correlation
context as `window.LoomAnalytics.signupContext(tenant)`.

## Contract

1. The Loom signup page adds the returned `signup_token`, `session_id`,
   `visitor_id`, and `tenant` to the console request.
2. The console emits the `signup_completed` event only after account creation
   succeeds.
3. The console signs the exact JSON body with HMAC-SHA256:

```text
sha256=hmac_sha256(timestamp + "." + raw_body, shared_secret)
```

Headers are `X-Loom-Timestamp` and `X-Loom-Signature`. The receiver must reject
an empty/unknown tenant, stale timestamps, invalid signatures, duplicate event
IDs, and PII fields. Secrets stay in the console and analytics-server
configuration, never in browser code or Git.

`SignupConversion::event()` stores only the anonymous session/visitor IDs and a
hash of the one-time signup token in the analytics event metadata. It does not
store signup names, email addresses, mobile numbers, or customer IDs.

The callback receiver belongs in the separate `loom-analytics` reporting
application. This package provides the shared event, correlation, and signing
contract used by Loom consumers and legacy console adapters.
