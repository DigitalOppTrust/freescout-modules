# DOTTheme

DO Trust branding for FreeScout: logo, colours, self-hosted font, footer.

Presentation only. No routes, no tables, no hooks that act on tickets — the
worst a fault here can do is make the site look wrong.

## How it applies

Through FreeScout's own filters, never by editing its views. That matters: an
edited core view is silently reverted by the next `upgrade.sh`, whereas a hook
survives. Disabling the module restores the stock appearance exactly.

| Hook | What it does |
|---|---|
| `layout.header_logo` | DOT logo in the header (signed-in pages) |
| `login.banner` | DOT logo on the sign-in page |
| `footer.text` | Replaces FreeScout's copyright line |
| `layout.head` | Injects the stylesheet, font preloads and theme colour |

**The header and the sign-in page use different filters.** `layout.header_logo`
only feeds the header shown to signed-in users; the login page draws its own
banner. Setting one without the other leaves the first page anyone sees
unbranded — which is exactly what happened before the `login.banner` hook was
added.

## Settings

All via `.env`, all optional:

| Variable | Default | Description |
|---|---|---|
| `THEME_ENABLED` | `true` | Master switch. False restores stock FreeScout. |
| `THEME_BRAND` | `#0079B2` | Brand colour, from the DOT logo |
| `THEME_BRAND_DARK` | `#005F8C` | Hover and active states |
| `THEME_BRAND_LIGHT` | `#E6F2F8` | Tinted backgrounds |
| `THEME_FOOTER_TEXT` | `DOT Support — this platform is for DOT staff members providing support.` | Footer line. Empty restores FreeScout's default. |
| `THEME_SELF_HOST_FONT` | `true` | Serve Montserrat locally |

## Notes

- **The font is self-hosted.** A support desk should not make a third-party
  request on every page load. Two weights are preloaded (400, 600) because
  they appear above the fold; preloading all four would delay the render it is
  meant to improve.
- **The stylesheet is cache-busted on file mtime.** It previously read a
  `dottheme.version` config key that was never defined, so it always emitted
  `?v=1` and every CSS change stayed behind the browser cache indefinitely.
- **The sign-in banner is sized in CSS.** Core hardcodes `height="36"`, which
  suits FreeScout's 5:1 wordmark; the DOT logo is roughly 2.7:1 and would
  render about half the width. The CSS overrides it with a width instead.
- **`footer.text` output is escaped here.** Core echoes that filter unescaped.

## Upgrade check

After a FreeScout upgrade, confirm: the sign-in page shows the DOT logo, the
footer reads "DOT Support", and the header logo is right. If any of them
reverts, core has renamed or removed that filter — find the new hook rather
than patching the view.
