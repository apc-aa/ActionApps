# Product

## Register

product

## Users

APC-AA serves two operator personas in roughly equal weight. The design must absorb both without dumbing one down or burying the other.

**Non-profit content editor.** Staffer or volunteer at an APC member organization or affiliated NGO. Adds news items, events, profiles to a slice via `admin/itemedit.php`. Often non-English-first; English may be their second or third working language. Uses APC-AA among a half-dozen other tools, returns to it a few times a week, never builds full muscle memory. Needs the basic flows (open item, edit a field, save, see it published) to be obvious without reading docs. Trusts the tool when its labels are clear and its messages don't lie about what just happened.

**Technical superadmin.** System admin running one or more APC-AA installs from `central/`, managing slices, fields, sync between installs, schema updates, performance tuning, permissions. Comfortable with SQL, item IDs, slice configs, the legacy `.php3` quirks. Lives in `admin/aa_statistics.php`, `central/index.php`, `service/sql_update.php`. Density is a *feature* here, not a bug. Keyboard shortcuts and exposed knobs are first-class.

The design must serve both via **progressive disclosure** rather than two parallel UIs. Clean primary surface for the editor; full power one click away for the admin.

## Product Purpose

APC-AA (ActionApps) is a 25+ year old PHP CMS built by the Association for Progressive Communications, a global network of non-profits working on internet rights, social justice, and movement infrastructure. It is the publishing backbone for member organizations and partner NGOs that need real editorial operations (multi-slice content, syndication between sites, scheduled publishing, permissions, multi-language) but cannot afford dedicated technical staff or commercial CMS licences.

The product's job is to make a single small NGO behave like a much larger publishing operation by sharing infrastructure, content, and code across the APC network. Success looks like:

- A part-time editor at a 5-person NGO publishes a news item in two minutes without asking for help.
- A superadmin updates 12 federated installs from `central/` without ssh-ing into any of them.
- The tool runs on a small VPS, in a region with intermittent connectivity, for another decade.

Endurance matters more than novelty. APC-AA exists to outlast every commercial CMS its users would otherwise have to migrate off of.

## Brand Personality

Three words: **frank, patient, civic**.

- **Frank.** Says what it means. "Cache hit ratio is 32%, below the 70% threshold" rather than "Looks like caching could use some love!" Numbers in the UI are real numbers, not rounded comfort. Errors describe what went wrong and what to try.
- **Patient.** Doesn't punish a hesitant editor. Help text earns its place under inputs; tooltips explain jargon (`C+NM`, `slice`, `feeding`) the first time without making the user feel dumb. The editor flow is forgiving; undo and revision history are visible.
- **Civic.** APC's mission is movement infrastructure, not commerce. Voice closer to a well-run public library than a startup. No exclamation points, no celebratory confetti, no "Awesome!". Tool dignity over delight.

Aesthetic anchor: the **modern app shell** family (Sentry, Plausible, Mailcoach) calibrated *down* toward editorial/civic, not up toward SaaS marketing. Cleaner type and rhythm than classic CMS admins, less ornament than commercial dashboards, data-first throughout.

## Anti-references

What APC-AA should NOT look like, in order of how easy it is to drift into each one:

1. **Silicon Valley SaaS cliché** (Linear, Stripe dashboards, Vercel). Hyper-polished cards, gradient accents, big hero numbers, generous whitespace as identity. Wrong category: APC-AA's users do not want a "delightful experience"; they want their published item live by 11am.
2. **Generic Bootstrap admin theme** (AdminLTE, SBAdmin, "Material Dashboard"). Identikit blue-on-white cards, four-up KPI grids, hamburger drawers. Reads as "admin theme" instead of a real tool. APC-AA has 25 years of identity; don't bury it under template chrome.
3. **Dark observability dashboard** (Grafana, Datadog). Cold dark surfaces, neon traces, ops-engineer aesthetic. Mismatched with editorial work and with APC's warm-cream existing palette.
4. **AI-app chat aesthetic** (Claude, Cursor, modern composer-led tools). Monochrome with one purple accent, big text composer in the middle, soft pillowy shadows. Currently saturated; would make APC-AA feel like a trend chaser. The opposite of civic.

The existing warm-cream variable (`--clr-basic-dark: #e8e3d4`) already resists most of these naturally. Keep it; don't replace it with a cooler "modern" grey.

## Design Principles

1. **Clean default, depth on demand.** Every surface shows the minimum necessary for the primary task. Everything else (related items, full data, history, advanced knobs, deep links) is one affordance away: a `<details>` summary, a small "see more" link, a tooltip on a metric, a row that becomes a drill-down. The editor sees a clean form; the admin clicks once and sees every byte we have. The interface should never *hide* information from the curious user, only defer it.
2. **Frank, not soft.** Show real values. Round only where rounding adds clarity. Empty states say what's empty and why, then point at the next action. Error messages name the cause. Never reassure for the sake of reassurance.
3. **Data-first, decoration last.** Every pixel of ornament must do work: a coloured bar encodes a value, a heading separates real sections, a border calls out a real boundary. No decorative gradients, no hero numbers without their context, no chrome that exists only to look modern.
4. **Honor i18n at the layout level.** English labels are the *short* version of every label. Czech, German, Spanish, French translations can be 1.5x to 2x longer. Layouts must absorb that without breaking; truncation must degrade gracefully and never hide the meaning. Test labels with their longest reasonable translation before shipping.
5. **Performance is accessibility.** APC members operate in regions with slow connections and older hardware. Small CSS, vanilla JS preferred, no heavy webfonts, no large images in the admin chrome. Browser target is Baseline Widely Available per `.browserslistrc`. A page that takes 3 seconds to render is broken for the people we exist to serve.
6. **Respect the legacy.** APC-AA admins have 25 years of muscle memory in the existing layouts. Don't re-flow a working screen without a real reason; don't rename a working button to be cleverer; don't relocate something common just to "modernize". Modernization is allowed in places the user has been frustrated; nostalgia is not the standard, but disruption isn't free either.

## Accessibility & Inclusion

- **WCAG 2.1 AA contrast** is the floor: 4.5:1 for body text, 3:1 for large text and UI components against their backgrounds. The warm-cream palette currently floats around this threshold; new components must verify and the tokens in DESIGN.md will codify compliant pairings.
- **Long-form language tolerance** is a layout requirement, not an i18n team concern. Every component must work when its visible labels are doubled. Verify with a long-Czech or long-German translation before considering a layout done.
- **Low bandwidth and older hardware** are baseline assumptions. Vanilla JS over framework JS where the choice exists. Avoid large dependencies for trivial widgets. CSS payload per surface should be measured in tens of KB, not hundreds. Test on throttled connections (`fast 3G` / 4× CPU slowdown in Chrome DevTools) before declaring a feature shipped.
- **Reduced motion**: respect `prefers-reduced-motion: reduce` for any animation longer than 200ms. Decorative motion is already discouraged by Principle 3; this is the safety net.
- **Keyboard parity**: every click target must be reachable and operable by keyboard. The superadmin persona effectively requires this; the editor persona benefits from it.
- **Out of scope (for now): RTL support.** APC's current user base does not include sites that need mirrored layouts. If that changes, RTL becomes a first-class concern and this section gets revised.
