# The shared webroot volume's staleness is detected, not prevented

nginx and PHP-FPM run as separate services and both need the webroot on disk, so they share it as a named volume. Composer populates that webroot at image build time, which means the volume is filled once, on first start, and is never refreshed afterwards — an image rebuild does not reach a running stack. We accept that, and defend against it with detection instead: **preflight** reads the live WordPress and Action Scheduler versions back from the running site and refuses to measure when they disagree with what the image's `composer.lock` resolved.

This is the ADR most worth reading before touching **preflight**, because that version assertion looks like paranoia on its own. It is the only thing standing between a stale volume and a **result record** that looks entirely plausible while describing software nobody intended to measure — which is precisely the class of false result the rest of this repo is built to make impossible.

## Considered options

- **Re-sync from a pristine copy on every start.** Bake the webroot outside the volume and have the entrypoint sync it in, excluding the database file and the guard directory. Would have made staleness impossible rather than merely visible, with no manual step ever needed.
- **Recreate the volume on every `up`.** Nothing would be lost, since the database has its own separate volume.

Both were considered and declined in favour of the simpler volume arrangement. Detection was added because accepting the trap silently was not acceptable.

## Consequences

- After changing `composer.lock`, a rebuild alone is not enough: the webroot volume has to be removed before the next start. The failure mode is a loud, non-zero **preflight** refusal naming the mismatch, not a wrong number.
- Because every measurement re-runs **preflight** internally, every **scenario** inherits this protection without opting in.
- Both versions now land in every **result record**, which incidentally closes a documented gap: WordPress's version was previously neither pinned nor recorded anywhere.
