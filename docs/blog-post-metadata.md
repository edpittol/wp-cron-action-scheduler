# Blog post metadata

Metadata for [blog-post-draft.md](blog-post-draft.md), targeting dev.to.

## Front matter

Paste-ready for the dev.to markdown editor:

```yaml
---
title: "DISABLE_WP_CRON Doesn't Close the Door It's Named After"
published: false
description: "Setting the constant stops WordPress from calling wp-cron.php. It doesn't stop anyone else. A measured walkthrough of the doors that reach your scheduled work — core's own WP-Cron events and the Action Scheduler queue on top of them — and what closing each one actually closes."
tags: wordpress, php, devops, security
cover_image: https://raw.githubusercontent.com/edpittol/wp-cron-action-scheduler/main/assets/cover.png
canonical_url:
---
```

`cover_image` points at the PNG rather than the SVG because `raw.githubusercontent.com` serves `.svg` as `text/plain`, which dev.to will not render. Upload `assets/cover.png` through the editor if the raw URL is rejected.

## Title

Recommended: **DISABLE_WP_CRON Doesn't Close the Door It's Named After**

It follows the established voice — a named thing quietly not doing what its name promises — and leads with the one claim in the post that a reader can check in a minute.

Alternates:

- Your `DISABLE_WP_CRON` Is Not a Lock on the Door — closest to the existing "Your X Is Secretly Y" pattern.
- Four Doors Reach Your Action Scheduler Queue. Closing One Closes Nothing. — leads with the structure rather than the constant.
- Run WP-Cron and Action Scheduler from WP-CLI, and Close the Doors Behind It — the draft's own title; accurate and tutorial-flavoured, but it buries the hook.

## Tags

dev.to caps tags at four. Recommended set:

```
wordpress, php, devops, security
```

| Tag | Reach on dev.to | Why it fits |
|---|---|---|
| `wordpress` | Small but precisely on-target | The only tag whose followers all have this exact problem. Non-negotiable. |
| `php` | Large — one of the bigger language tags | Broadens past WordPress; the `fastcgi_finish_request()` masking in §2 is a plain PHP finding that stands on its own. |
| `devops` | Large | System cron, nginx config, and the "stop trusting the HTTP status" argument are infrastructure concerns, not WordPress ones. |
| `security` | Large | §1 is an unauthenticated endpoint that drains a queue and costs the attacker one closed socket. This is where the post's sharpest section lives. |

Follower counts are not published on dev.to tag pages, so the reach column above is relative, not measured.

Swaps worth considering:

- `security` → `performance` if you'd rather frame it as a load story than a hardening story. Weaker fit: the post explicitly puts drain cost out of scope.
- `devops` → `webdev` for the widest possible reach. It's the largest tag on the platform, but the audience match is poor and it dilutes the other three.
- `php` → `nginx` only if you want the §3 fleet tradeoff to be the draw. Much smaller tag; not worth trading a language tag for.

Avoid `tutorial` and `beginners` — Part II is the substance and neither is beginner material.

## Cover image

`assets/cover.svg` (source) and `assets/cover.png` (2000×840 export, 2×).

Built from the [s3-stream-multiple-operations cover](https://raw.githubusercontent.com/edpittol/s3-stream-multiple-operations/refs/heads/main/assets/cover.svg): same gradient, grid overlay, eyebrow/headline/subtitle column, and right-hand mapping card with pill badges. The template's repo-URL line is dropped — the left column is centred on the card's vertical midpoint instead. The card's left column lists entry points instead of PHP calls, and the badges carry a result each.

The card is split into the post's two layers rather than being one flat list, so the cover states the structural claim before the reader opens anything: **WP-Cron** has one web door (`wp-cron.php` → 403) and one you keep (`wp cron event run` → runs), and **+ ACTION SCHEDULER** adds three more that a block on the cron endpoint cannot see. The amber `open` on the row action is deliberate — §4's fourth door is meant to stay reachable, and the colour marks it as the odd one out rather than as a failure.

The headline says *WordPress cron* rather than naming either component, because the post's subject is the scheduled work as a whole; the eyebrow carries both names.

### Aspect ratio

The canvas is 1000×420 — 2.38:1, dev.to's ratio. A centred 1.91:1 crop of that canvas is 802×420, keeping `x ∈ [99, 901]`.

Every element sits inside `x ∈ [118, 882]`, so both ratios are served by the one file with nothing lost at either. The tradeoff is that the layout can't run edge-to-edge the way the template does (it reaches `x = 936`, which a 1.91:1 crop would cut into): margins are generous at 2.38:1 and tight — about 19px — at 1.91:1.

Verify after any edit:

```bash
rsvg-convert -w 1000 -h 420 assets/cover.svg -o /tmp/c.png && sips -c 420 802 /tmp/c.png
```
