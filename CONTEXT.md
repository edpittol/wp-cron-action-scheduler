# Cron runner policy PoC

A measurement harness for the question "which entry points can drain an Action Scheduler queue, and what does closing each one actually close?". Every claim it makes is backed by a committed record of an observed run, so the language below distinguishes carefully between what was *exercised*, what was *observed*, and what was merely *inferred*.

## Language

### Policy and arming

**Guard section**:
One independently armable unit of the cron runner policy, identified by number. A section is not tied to any one layer — some act inside WordPress, some in front of it.
_Avoid_: rule, policy, module, mu-plugin, mitigation

**Armed**:
A guard section is armed when it is in effect for the running stack, and disarmed when it is absent from it. There is no third state and no internal flag: whatever is in effect is exactly the code that would ship.
_Avoid_: enabled, active, on, applied

**Fleet caveat**:
The condition under which a guard section deliberately goes inert, so a site that still genuinely depends on the web loopback does not lose cron the moment a shared guard reaches it.
_Avoid_: multisite caveat, shared mu-plugins bug, safety check

### Entry points and how they are exercised

**Vector**:
An entry point exercised over HTTP the way an outside client would reach it.
_Avoid_: attack, endpoint, path, route, request

**Control**:
An entry point exercised through WP-CLI, used to prove the queue can still drain after a vector has been shown not to.
_Avoid_: baseline, sanity check, happy path, positive test

**Scenario**:
One named combination of a vector or control, the guard sections armed for it, and the runtime conditions it runs under.
_Avoid_: test, case, run, experiment

**Drain**:
A queue run that carries pending probe actions through to completion.
_Avoid_: run, flush, process, execution, sweep

**Probe action**:
A seeded scheduled action whose hook records which process executed it, and when.
_Avoid_: test action, dummy action, job, task

### Evidence

**Result record**:
The canonical file one scenario writes, carrying everything needed to judge that scenario's outcome without trusting anything outside it.
_Avoid_: result, output, log, report, measurement

**Negative result**:
A result record for a scenario that failed its preflight or its measurement, committed as evidence rather than discarded or retried into a different answer.
_Avoid_: failure, error, skipped scenario, flake

**Preflight**:
The gate that asserts the site is in a state a measurement can be trusted from, and refuses to measure when it is not.
_Avoid_: setup, precondition check, health check, fixture

**Outcome**:
What a scenario is judged on — how many probe actions drained, and what executed them. Never an HTTP status.
_Avoid_: verdict, pass, fail, status

### Server behaviour

**Server model**:
The web server and PHP SAPI pairing a scenario ran under. Figures measured under one server model are not transferable to another.
_Avoid_: environment, stack, platform, SAPI

**Observable status**:
The HTTP status a client can actually read — the one that reached the wire before the response was closed.
_Avoid_: real status, actual status, returned status, client status

**Post-flush status**:
The status in effect once the response had already been closed to the client — read back from the server rather than inferred from what the application asked for. Distinct from the **attempted status**, and measurement showed the two need not agree: on this stack a guard's post-flush `http_response_code()` call is refused outright, so the post-flush status stays the flushed one and the attempted status exists nowhere but in the source. A post-flush status is only ever a reading, never a restatement of an intent.
_Avoid_: fake status, hidden status, masked status, late status

**Attempted status**:
A status the application asked for. A fact about the code, which becomes a **post-flush status** only if the server actually accepted it.
_Avoid_: intended status, set status, real status

**Occupancy**:
How many server workers a drain holds, and for how long — the capacity cost of running the queue in a web request.
_Avoid_: load, utilisation, concurrency, saturation
