---
name: APC-AA
description: Newsroom-grade admin UI for movement-tech publishing; warm earth palette, dense editorial typography, flat by default.
colors:
  press-paper: "#f3f3f1"
  bond-paper: "#eeebe3"
  dust-jacket: "#e6e3d9"
  newsroom-sand: "#ebdcc3"
  reporters-tan: "#d5c2a4"
  filing-cabinet: "#A8977A"
  press-black: "#1f1f1f"
  margin-line: "#dcd5c2"
  brass-stamp: "#c9b57f"
  hover-olive: "#a59850"
  olive-ink: "#6a5e22"
  selected-highlighter: "#f3e6c3"
  floating-paper: "#fbfaf9"
  published-green: "#1eb585"
  link-slate: "#2F3D99"
  link-hover-blue: "#007ed7"
  visited-muted: "#896996"
  data-cnm-best: "#2e7d52"
  data-cm-good: "#7fb069"
  data-ncm-bad: "#dd6644"
  data-ncnm-worst: "#a02222"
typography:
  display:
    fontFamily: "'Open Sans', sans-serif"
    fontSize: "1.3rem"
    fontWeight: 400
    lineHeight: 1.4
    letterSpacing: "normal"
  headline:
    fontFamily: "'Open Sans', sans-serif"
    fontSize: "1.23rem"
    fontWeight: 600
    lineHeight: 1.4
    letterSpacing: "normal"
  title:
    fontFamily: "'Open Sans', sans-serif"
    fontSize: "1.1rem"
    fontWeight: 600
    lineHeight: 1.4
    letterSpacing: "normal"
  body:
    fontFamily: "'Open Sans', sans-serif"
    fontSize: "1rem"
    fontWeight: 400
    lineHeight: 1.4
    letterSpacing: "normal"
  body-small:
    fontFamily: "'Open Sans', sans-serif"
    fontSize: "0.955rem"
    fontWeight: 400
    lineHeight: 1.4
    letterSpacing: "normal"
  label:
    fontFamily: "'Open Sans', sans-serif"
    fontSize: "0.867rem"
    fontWeight: 400
    lineHeight: 1.4
    letterSpacing: "normal"
rounded:
  none: "0"
  sm: "4px"
  md: "6px"
  lg: "8px"
  pill: "13px"
spacing:
  xs: "2px"
  sm: "4px"
  md: "8px"
  lg: "12px"
  xl: "18px"
components:
  button-default:
    backgroundColor: "{colors.press-black}"
    textColor: "{colors.press-paper}"
    typography: "{typography.body-small}"
    rounded: "{rounded.none}"
    padding: "6px 12px"
  button-default-hover:
    backgroundColor: "{colors.filing-cabinet}"
    textColor: "{colors.press-paper}"
  button-primary:
    backgroundColor: "{colors.published-green}"
    textColor: "{colors.press-paper}"
    rounded: "{rounded.none}"
    padding: "6px 12px"
  button-secondary:
    backgroundColor: "{colors.press-paper}"
    textColor: "{colors.olive-ink}"
    rounded: "{rounded.none}"
    padding: "6px 12px"
  button-secondary-hover:
    backgroundColor: "{colors.reporters-tan}"
    textColor: "{colors.press-black}"
  input-text:
    backgroundColor: "#ffffff"
    textColor: "{colors.press-black}"
    typography: "{typography.body-small}"
    rounded: "{rounded.none}"
    padding: "3px 3px"
  chip-details:
    backgroundColor: "{colors.press-paper}"
    textColor: "{colors.olive-ink}"
    rounded: "{rounded.pill}"
    padding: "4px 12px"
  table-cell:
    backgroundColor: "#ffffff"
    textColor: "{colors.press-black}"
    typography: "{typography.body}"
    padding: "2px 6px"
---

# Design System: APC-AA

## 1. Overview

**Creative North Star: "The Open Newsroom"**

APC-AA is the back office of a public-interest newsroom that has been running since 1999. Every screen is a layout slug for somebody else's published work; nothing in the chrome competes with the article it is helping to ship. The warm palette is newsprint and stained-pine desks, not Silicon Valley cream. The type sits in a tight 1.05x–1.1x scale because dense reference material is what editors actually read. The H1 is `font-weight: normal` on purpose: nothing in the admin is "the headline", everything is the work.

The system explicitly rejects four neighbours. **SaaS-cliché polish** (Linear, Stripe dashboards): big numbers, gradient accents, generous whitespace as identity. Wrong category; APC users want their item live by 11am, not a delightful experience. **Bootstrap-admin templates** (AdminLTE, "Material Dashboard"): identikit blue-on-white card grids that read as theme rather than as a tool. **Dark observability dashboards** (Grafana, Datadog): cold neon traces, ops-engineer aesthetic, no relation to editorial work. **AI-app chat** (Claude, Cursor): monochrome with one purple accent, soft pillowy shadows; the opposite of civic.

Aesthetic family is **modern app shell calibrated down toward editorial-civic**: cleaner type and rhythm than legacy CMS admins, dramatically less ornament than commercial dashboards, data-first throughout. Closer to a well-run public library than a startup.

**Key Characteristics:**
- Warm earth palette (newsprint, sand, brass, olive ink). No cool greys.
- Editorial type density: 13px root, 1.05–1.1 scale, `font-weight: normal` on H1.
- Square by default. Border-radius is rare and earns its place (chips, dropdowns).
- Flat by default. Shadows are warm-tinted and only appear on floating surfaces or focus.
- Progressive disclosure as identity: clean default surface, full power one click away.
- Vibrant green for *published* (not for "delight") and warm red-orange for *wasted work* (not "danger").

## 2. Colors

A warm earth palette of newsprint, sand, brass, and olive ink, with a single vibrant **Published Green** as the only saturated voice and a graded warm red-orange family reserved for performance-state data.

### Primary
- **Newsroom Sand** (`#ebdcc3`, `--clr-basic`): the principal surface color. Used for the chrome of admin tabs, the tinted band behind table headers, the active filter strip. It is the *paper* the work sits on.
- **Press Black** (`#1f1f1f`, `--clr-dark`): the ink. Body text, the default button background, footer rule lines. Warm near-black, never `#000`. Future-renamed `--clr-text`.
- **Brass Stamp** (`#c9b57f`, `--clr-gold`): the accent. Strong borders, the focus ring tint, highlight strokes on important affordances. Future-renamed `--clr-border-strong` / `--clr-accent`. The closest thing to a "brand color" the system has.

### Secondary
- **Published Green** (`#1eb585`, `--clr-green`): the single saturated voice. Reserved for the *publish* button, success confirmation, and "active item" markers. Future-renamed `--clr-success`. Never decorative.

### Tertiary (data-state palette — performance dashboards only)
A four-step ramp from best to worst, used only in `aa_statistics.php`, `{hitcounter:…}` and equivalent diagnostic surfaces. Best to worst:
- **Filed Green** (`#2e7d52`): cached + 304 response, the cheapest serve.
- **Working Green** (`#7fb069`): cached + 200 response.
- **Deadline Orange** (`#dd6644`): uncached + 200, normal regen cost.
- **Stop the Press** (`#a02222`): uncached + 304, "wasted work" (page rebuilt then client cache matched).

### Neutral
- **Press Paper** (`#f3f3f1`, `--clr-body`): page background. The newsprint underneath.
- **Bond Paper** (`#eeebe3`, `--clr-basic-light`): inset surfaces, alternating row tint, light callout backgrounds.
- **Dust Jacket** (`#e6e3d9`, `--clr-footer`): footer chrome, secondary surface.
- **Reporter's Tan** (`#d5c2a4`, `--clr-basic-dark`): hover and selected surfaces; the warm bg of a row you are about to click.
- **Filing Cabinet** (`#A8977A`, `--clr-basic-darker`): strong tan, used for primary-button hover and emphatic surfaces.
- **Margin Line** (`rgb(220 213 194)`, `--clr-border`): the rule line in the margin, the default border for inputs, tables, panels.
- **Floating Paper** (`#fbfaf9`, `--clr-surface-popup`): dropdown and popover surface (almost-white but warm).
- **Selected Highlighter** (`#f3e6c3`, `--clr-surface-selected`): gentle yellow wash for a selected row.
- **Olive Ink** (`#6a5e22`, `--clr-text-accent`): accent text, secondary-button label color.
- **Hover Olive** (`#a59850`, `--clr-border-hover`): border color when a focusable element is hovered.
- **Link Slate** (`#2F3D99`): default link color. The deep slate-blue of a fountain pen.
- **Link Hover Blue** (`#007ed7`): brighter blue on hover.
- **Visited Muted** (`#896996`): muted purple for visited links in table contexts.

### Named Rules

**The Earth-Only Rule.** Cool greys are forbidden. Every neutral is tinted warm. If a token wants to lean cool (`#f5f5f7`, `#e0e0e0`, blue-grey scales), it is the wrong choice, not a different choice. Tint toward `oklch(96% 0.003 60)` (yellow-warm) before adding.

**The One Saturated Voice Rule.** Published Green is the only saturated UI color in the chrome. Charts and diagnostic surfaces may use the data-state ramp; nothing else may go saturated. No green CTAs that are not "publish/save/confirm". No second accent color competing with Published Green.

**The Reserved-Red Rule.** Stop the Press red (`#a02222`) and Deadline Orange (`#dd6644`) are reserved for the data-state palette. Do not co-opt them for generic error/danger UI without a chart-shaped reason. Errors use Press Black on Press Paper plus copy that names the cause; the warning system is *language*, not red borders.

## 3. Typography

**Display Font:** Open Sans (self-hosted woff2, with `sans-serif` fallback). Weights shipped: 400 regular, 400 italic, 600 semibold, 600 italic.
**Body Font:** Open Sans. Single-family system, full stop.
**Label/Mono Font:** none. `font-variant-numeric: tabular-nums` is applied directly to numeric tables for column alignment; no separate mono family.

**Character:** Editorial density. The root size is `13px` (set on `html`), every named size is a rem multiple of that. The scale is *tight* (1.05x to 1.1x between adjacent steps) because admin pages are dense reference material, not marketing landing pages. Weight contrast carries hierarchy where size cannot.

### Hierarchy
- **Display / H1** (`font-weight: normal`, `1.3rem` ≈ 16.9px, `1.4` line-height): page-level section titles. Deliberately *not* bold; the H1 announces the section without competing with the work. The single most distinctive typographic choice in the system.
- **Headline / H2** (`font-weight: 600`, `1.23rem` ≈ 16px, `1.4`): subsection titles. Bold because they need to separate dense forms below.
- **Title / H3** (`font-weight: 600`, `1.1rem` ≈ 14.3px, `1.4`): sub-subsection / chart titles inline with content.
- **Body** (`font-weight: 400`, `1rem` = 13px, `1.4`): the default. Forms, paragraphs, table cells. Cap body line length at 65–75ch where the layout allows; admin tables are exempt by purpose.
- **Body-small** (`font-weight: 400`, `0.955rem` ≈ 12.4px, `1.4`): help text under inputs, secondary table rows, tab subtitles. The `var(--sz-s)` token.
- **Label** (`font-weight: 400`, `0.867rem` ≈ 11.3px, `1.4`): tiny metadata (item short-id, slice prefix, timestamp). The `var(--sz-xs)` token.

### Named Rules

**The Normal-Weight H1 Rule.** Page-level H1 is `font-weight: 400`, never 600 or 700. The page heading announces, it does not shout. The first new component that ships with `<h1 style="font-weight:600">` is the one that has misread the system.

**The Tabular-Numerics Rule.** Any table or chart cell that carries numeric data MUST set `font-variant-numeric: tabular-nums`. Column alignment is part of the contract with the operator scanning for outliers.

**The 1.4-Line-Height Rule.** Line-height is `1.4` everywhere by default. Tighter (1.2) is allowed for ALL-CAPS micro-labels only; looser (1.6+) is allowed for paragraph-form help text inside `<details>` panels. No other exceptions.

## 4. Elevation

The system is **flat by default**. Surfaces sit directly on the warm-cream background; depth is conveyed by tonal contrast (Press Paper → Bond Paper → Newsroom Sand → Reporter's Tan), not by shadow. Buttons are flat. Tables are flat. Tabs are flat. Cards as a category are a code smell here, not a primitive.

Shadows appear in exactly three roles, all warm-tinted toward the brass/sand family. None of them is generic `rgba(0,0,0,0.X)`; cool shadows are wrong.

### Shadow Vocabulary
- **Focus Ring** (`box-shadow: 0 0 0 3px rgba(184, 162, 70, .20)`): the gold-glow ring around focused buttons and inputs that opted into the modern focus treatment. Replaces the legacy blue outline where the team has updated the component.
- **Floating Surface** (`box-shadow: 0 14px 36px rgba(60, 50, 20, .20)`): used on dropdowns, the per-search popover, the comparison drill-down. Strongly diffuse, warm-tinted, low intensity — a *paper hovering above paper* feel rather than a layered material card.
- **Subtle Lift** (`box-shadow: 0 1px 2px rgba(0, 0, 0, 0.10)`): a single small bump used sparingly on chips that need to read as "interactive without elevation". Legitimate but rare.

### Named Rules

**The Flat-By-Default Rule.** New components ship flat. A shadow is added only when (a) the element floats above the page (popovers, dropdowns), (b) it carries focus, or (c) the user has the right to drag/rearrange it. Decorative shadow is forbidden.

**The Warm-Shadow Rule.** Every shadow uses warm-tinted color (rgba(60, 50, 20, .X), rgba(184, 162, 70, .X), or a similar sand-brass family). Black shadows (`rgba(0,0,0,0.X)` with no tint) read as templated admin chrome — the design family we are not in.

## 5. Components

Lead with character; specify shape, color, state. Density matters as much as ornament here, because the dense editor sees these elements dozens of times per session.

### Buttons
- **Shape:** square (`border-radius: 0`). Buttons are *tools*, not cards. The square edges are deliberate; the first component that rounds a button has misread the system.
- **Default** (the workhorse): background Press Black (`#1f1f1f`), text Press Paper (`#f3f3f1`), padding `6px 12px`, font Open Sans `0.955rem`. Hover: background Filing Cabinet (`#A8977A`), no transform, no shadow. Transition `all 0.3s` (legacy long; new components should move toward `0.15s ease-out-quart`).
- **Primary** (`.btn-primary`): same shape, background Published Green (`#1eb585`). Used only for publish/save/confirm actions in the editor flow. Never for "next step" wayfinding.
- **Secondary** (`.btn-secondary`): background `rgba(255,255,255,0.2)` over the warm surface, border `1px solid rgba(26,4,4,0.3)`, color Olive Ink (`#6a5e22`). Hover background Reporter's Tan.
- **Size variants:** `.btn-l` (`padding: 6px 18px`) for the primary action in a tab footer. `.btn-s` (`padding: 3px 7px`) for inline-edit and table-row buttons.

### Inputs
- **Style:** background white, border `1px solid var(--clr-border)` (Margin Line `#dcd5c2`), padding `3px`, font `0.955rem` Open Sans, `border-radius: 0` (square). Dense by intent.
- **Focus (legacy):** `outline: 1px solid rgba(30, 102, 233, 0.7)` with `outline-offset: -1px`. The legacy blue ring; older surfaces still use this.
- **Focus (modern):** `border-color: var(--clr-border-hover)` (Hover Olive `#a59850`), plus the gold Focus Ring shadow. New components MUST use the modern treatment; the legacy blue is grandfathered, not aspirational.
- **File input:** styled with a `::before` pseudo-element carrying the localized button text (`"Vybrat soubor"` in Czech installs); same Press Black background as default buttons.

### Tables
- **Style:** `border-collapse: collapse`, body font `1rem` Open Sans, `font-variant-numeric: tabular-nums` on numeric cells, padding `2px 6px` (tight admin density). Background alternates per-row: odd `#ffffff`, even Bond Paper (`#eeebe3`).
- **Sticky header:** `position: sticky; top: 0; background: var(--clr-basic-dark)` for the stats and comparison tables. First-column sticky on horizontal scroll where the row label is the key.
- **In-cell bar chart:** the `aa-stat-bar` pattern uses `background-image: linear-gradient(to right, var(--bar, #7a9) calc(var(--pct, 0) * 1%), transparent 0); background-size: 100% 3px; background-position: left bottom`. A 3px-tall bar at the bottom of the cell encodes a value as bar length. Cache-style cells additionally set `--bar: hsl(calc(var(--pct,0) * 1.2), 60%, 55%)` so hue swings red→amber→green with the percentage.

### Chips / Pills (`details summary`, filter pills)
- **Shape:** `border-radius: 13px` (full pill), `padding: 4px 12px`, inline-block. The single rounded primitive in the system; chips read as "expandable / toggleable affordance".
- **Background:** `rgba(255,255,255,0.2)` over the warm surface, border `1px solid rgba(26,4,4,0.3)`, color `#61454A` (warm brown).
- **Hover:** background Reporter's Tan (`#d5c2a4`), color Press Black, transition `0.3s`.
- **Open state (`details[open] summary`):** the same pill, with the disclosure caret rotated 180°.

### Cards / Containers
**Do not create new cards.** The system has no general-purpose card primitive and does not want one. Where a container is genuinely needed, use a flat surface with `background: var(--clr-basic-light)` or `var(--clr-footer)` and a single full-width hairline border `1px solid var(--clr-border)`. Never use `box-shadow` decoratively.

If you find yourself reaching for a card, ask first: would a row of a table, an inline `<details>` panel, or a tab serve the same purpose better? In APC-AA the answer is almost always yes.

### Links
- **Default:** Link Slate (`#2F3D99`), no underline. Inside admin tables, no underline by default; underline appears on hover.
- **Hover:** Link Hover Blue (`#007ed7`), underline.
- **Visited (table context only):** Visited Muted (`#896996`).
- **Inline admin link patterns:** `.aa-stat-th-link` for header drill-downs, `.aa-stat-tip-link` for diagnostic-card click-throughs. Both inherit `color: inherit; text-decoration: none` and underline on hover.

### Charts (Chart.js wrapper)
- **Helper:** `HitcounterDisplay::getGraph()` wraps Chart.js v4 with our conventions.
- **Bar charts:** `borderWidth: 0` always (sub-pixel bars + 2px border render as a solid black mass; see project memory).
- **Stacked variants:** scales locked to `stacked: true` on both axes; aspect ratio `5/1` (`.aa-stat-chart-pct`) for percent timelines, `3/1` for line charts.
- **Mini variants:** `.aa-stat-chart-mini` is 280×280 with hidden tick labels — used in dense grids of per-item cache-state donuts.
- **Color discipline:** series colors come from the saturated hue ring (`generateSaturatedColor(i)` → `hslToHex(h, 70, 45)`); the "all" series is the only one allowed to use Press Black with `borderWidth: 3`. Charts never mix the data-state palette (Filed Green / Working Green / Deadline Orange / Stop the Press) with the per-item saturated palette in the same canvas.

## 6. Do's and Don'ts

Concrete, forceful. Every anti-reference in PRODUCT.md is repeated here as a "Don't" by name.

### Do:
- **Do** tint every neutral toward the warm-earth family. Cool greys are wrong, not different.
- **Do** keep buttons square (`border-radius: 0`). The legacy decision is the right decision; rounding a button means you have misread the system.
- **Do** set `font-variant-numeric: tabular-nums` on every numeric column or chart cell.
- **Do** use `font-weight: 400` on page-level H1. The page heading announces; it does not shout.
- **Do** show real numbers. "Cache hit ratio is 32%, below the 70% threshold." Not "Looks like caching could use some love!"
- **Do** put depth one click away (`<details>`, `aa-stat-th-link`, tooltip on metric, drill-down link). Never *hide* information from a curious user; defer it.
- **Do** use the gold Focus Ring shadow (`0 0 0 3px rgba(184, 162, 70, .20)`) on new components. The legacy blue outline is grandfathered, not aspirational.
- **Do** verify long-form translations (Czech, German) before declaring a layout done. Labels can be 1.5x to 2x the English length; the layout must absorb that.
- **Do** ship vanilla JS and small CSS where the choice exists. APC users include slow-bandwidth regions; framework-shaped solutions to widget-shaped problems are a tax on the people we exist to serve.

### Don't:
- **Don't** drift toward **SaaS-cliché polish** (Linear, Stripe dashboards, Vercel): hyper-polished cards, gradient accents, big hero numbers, generous whitespace as identity. Wrong category.
- **Don't** import a **generic Bootstrap admin theme** look (AdminLTE, SBAdmin, "Material Dashboard"): identikit blue-on-white cards, four-up KPI grids, hamburger drawers. APC-AA has 25 years of identity; template chrome would bury it.
- **Don't** swing to **dark observability** (Grafana, Datadog): cold dark surfaces, neon traces, ops-engineer aesthetic. Mismatched with editorial work and with the warm-cream palette.
- **Don't** ape **AI-app chat** (Claude, Cursor, modern composer-led tools): monochrome with one purple accent, big text composer, soft pillowy shadows. Currently saturated; the opposite of civic.
- **Don't** use side-stripe borders (`border-left: 4px solid X` as a colored accent on cards, list items, alerts). Across the whole spec, full borders, background tints, leading numbers, or nothing.
- **Don't** use gradient text (`background-clip: text` + gradient). Single solid color. Emphasis via weight or size.
- **Don't** add decorative shadow. Shadows belong to floating surfaces, focus states, and drag affordances. That is the full list.
- **Don't** use `#000` or `#fff` as a token. Press Black (`#1f1f1f`) and Press Paper (`#f3f3f1`) tinted toward the brand. White is permitted inside form inputs and table-row bodies only.
- **Don't** introduce em dashes ("—") in UI copy. Commas, colons, semicolons, periods, or parentheses. Also not `--`. (Em dashes are fine in this DESIGN.md prose; they are not fine in user-facing strings.)
- **Don't** add modal dialogs as the first thought. Inline `<details>`, popovers, and progressive disclosure exhaust their alternatives first.
- **Don't** ship without verifying `prefers-reduced-motion: reduce` is respected for any animation longer than 200ms.
