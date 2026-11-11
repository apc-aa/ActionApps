# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

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

## Positioning

Three mechanisms together are what a neighbouring CMS does not offer:

- **One install, many sites.** A single code tree serves the sites of many organizations, so an update or a fix reaches all of them at once.
- **The data model belongs to the user.** A slice gets new fields, views and templates in the admin, with no schema change and no deploy.
- **Content pooling.** Items are fed (syndicated) between slices, also between separate installs; `central/` manages several installs and moves whole applications from one to another.

Not recorded: which products organizations weigh ActionApps against, and why they stay.

## Operating Context

- **Editors** work in the Item Manager and the item editor (`admin/itemedit.php`). **Site builders** write templates in the admin in the `{…}` expression language and look commands up in the AA Expression Reference (`https://actionapps.org/expressions/`).
- **Operators** read State Check (`admin/aa_statecheck.php`) and the mail it sends. Its readers are technicians and system administrators, not editors.
- The admin ships in 15 languages (`include/lang/`). New texts are written in English and translated afterwards.
- An install updates by pulling the built `dist` branch (or its SVN mirror); nothing is built on the server. `cron.php` runs the scheduled work, and long jobs (imports, mail, feeds) run from a task queue in short steps, so their pages show state and progress instead of waiting.
- The admin stylesheets are edited by hand, also by a designer, and are served unminified.
- The product is maintained by a very small team.

## Capabilities and Constraints

- **Terms to keep in the interface:** slice, item, field, view, alias, constants, feeding, site (the module); the item bins (active, pending, expired, hold bin, trash); the roles author, editor, administrator and superadmin.
- **Server-rendered PHP 8.2+ over MySQL/MariaDB.** Admin pages are plain HTML and CSS with a little JavaScript, no single-page framework. Colours and sizes are custom properties on `:root` in `admin-ecn.css`, which a skin may override; each newer surface has its own small stylesheet in `css/`.
- **Third-party libraries are served by the install itself** (`cdn/`, a local mirror), not from an external CDN.
- **Browsers:** Baseline Widely Available (`.browserslistrc`).
- **Building blocks, not fixed screens:** 28 field widgets (`src/Widget/`), several hundred template commands, and the modules site, alerts, polls, links and jump.
- **Out of scope for now:** right-to-left layouts (see Accessibility & Inclusion).

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

The existing warm-cream palette (the `--clr-basic*` variables in `admin-ecn.css`, such as `--clr-basic: #ebdcc3`) already resists most of these naturally. Keep it; don't replace it with a cooler "modern" grey.

## Evidence on Hand

- `DESIGN.md` with `.impeccable/design.json`: the recorded visual system of the admin.
- `doc/`: FAQ, developer notes, the Central reference (`doc/central.md`) and the database schema (`doc/AAdb.html`, outdated). `INSTALL.md` and `UPDATE.md` describe installing and updating.
- AA Expression Reference at `https://actionapps.org/expressions/`: every template command with examples that also run as tests.
- `doc/archive/sourceforge/*.json`: the bug reports, support and feature requests of the former SourceForge tracker, in the users' own words.
- `tests/`: the PHPUnit suite that CI runs on every push.

Absent, and not to be made up: a logo or brand mark (the repository holds only `images/favicon.ico`), screenshots of the admin, testimonials or case studies, usage numbers, a list of organizations, any record of user research.

## Product Principles

Decisions the maintainer recorded for development; they bind the interface as much as the code.

1. **Endurance over novelty.** A change has to make the product easier to keep running for another decade: no build step on the server, a small proven library before own code, and never a large framework for a small need.
2. **One way to do each thing.** Every shared job has a single path (items, lists of managed records and their actions, outgoing requests, keys), and a new feature extends it. For the user that means the same list, the same buttons and the same rules on every page.
3. **Built from its own parts.** Data of modest volume lives in a slice, not in a new table. The user then gets fields, translations, history and templates with it, and can extend a feature without a developer.
4. **Nothing fails silently.** A feature that works in the background (queue, mail, import, cache, an outside service) ships together with its State Check finding, so the operator learns of a failure where they already look.

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
