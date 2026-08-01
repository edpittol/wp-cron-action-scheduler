# Guard section 5 blocks the cron entry point at the nginx layer

Once nginx is in front of PHP, the cron entry point can be blocked before PHP bootstraps at all — which is the only way to produce an **observable status** for that block, since any guard running inside WordPress arrives after the response has already been closed. We add this as **guard section** 5 so the repo measures both halves of the same request: a guard inside PHP yielding a **post-flush status** with nothing drained, and a guard in front of PHP yielding a readable 403 with nothing drained.

## The trade-off this exists to measure

nginx cannot read a PHP constant, so an nginx-level block is unconditional. It therefore **cannot honour the fleet caveat** — the conditional check that lets a shared guard reach a site still depending on the web loopback without silently killing its cron. So: an **observable status** or a fleet-safe conditional block, at one layer, but not both.

The two source documents this repo derives from already disagreed about this without noticing. The original handoff recommends blocking at the web server *and* gating the block on the cron constant, in the same list — mutually exclusive at the nginx layer. The blog post draft resolved it silently, by dropping the web-server recommendation and pointing at the in-PHP guard instead, without saying a choice was being made. **Guard section** 5 exists so the trade-off can be stated as a measured fact rather than settled by omission: the matrix includes the counter-example where the nginx block is armed, the cron constant is off, and cron is genuinely lost.

## Consequences

- **Guard section** no longer means "a mu-plugin file". The glossary definition is deliberately layer-agnostic, and the arming mechanism is unchanged in spirit: section 5 arms by the presence of a marker file, tested per request, so there is still exactly one way to arm a guard and still no reload.
- Section 5 is not gated on the SAPI the way the in-PHP guards are, because it never sees one. It must be scoped so that WP-CLI is untouched by construction rather than by a runtime check.
