# Changelog

## Unreleased

### Added
- `CsrfTokenProviderInterface`; `CsrfMiddleware` exposes the form token to handlers in the
  `csrf_token` request attribute (new `tokenAttribute` constructor argument).
- `SignedDoubleSubmitCookieStrategy` generates the cookie token on the first request so a
  valid form token is available immediately.
- `SynchronizerTokenStrategy` accepts `sessionIdResolver: callable(ServerRequestInterface): ?string`
  (falls back to the session attribute).
- `CsrfMiddleware` options `clientIpResolver` and `failureResponseFactory`.
- `RouteDispatchMiddleware` `propagateExceptions` flag; `Router` constructor argument
  `propagateExceptions` and `Router::setPropagateExceptions()`.
- `HoneypotMiddleware` options `timestampSecret` (HMAC-signed `_timestamp` tokens, required when
  set), `maxTimestampAge` (default 3600) and `clientIpResolver`; matching builder methods
  `withTimestampSecret()`, `withMaxTimestampAge()`, `withClientIpResolver()`.

### Security
- `HoneypotMiddleware`: array values in honeypot fields no longer raise a TypeError/notice and are
  treated as filled. Documented that the unsigned timestamp is bypassable and that the default
  in-memory rate-limit storage is ineffective under PHP-FPM.

### Changed
- Requires PHP >= 8.3 (was 8.2).
- `SynchronizerTokenStrategy` now issues one token per request: the token placed in the
  request attribute is the same one returned in the `X-CSRF-TOKEN` header (previously a token
  was generated only after the handler).
- Strategies receive the request as modified by `prepareRequest()` in `attachToken()`.
