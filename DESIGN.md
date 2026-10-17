---
name: MCNP-ISAP QR Attendance
description: Floating-panel dashboard for GPS-verified laboratory attendance across two medical colleges
colors:
  gatekeeper-blue: "#1d6bf3"
  gatekeeper-blue-deep: "#1558cc"
  gatekeeper-blue-ink: "#0f1b3c"
  slate-ink: "#0e1a33"
  slate-line: "#e8eef7"
  slate-surface: "#ffffff"
  slate-canvas: "#f4f7fc"
  present-green: "#16a34a"
  late-amber: "#e8820c"
  absent-red: "#dc2626"
  excused-violet: "#6366f1"
typography:
  display:
    fontFamily: "'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif"
    fontSize: "30px"
    fontWeight: 800
    lineHeight: 1.1
    letterSpacing: "-0.02em"
  headline:
    fontFamily: "'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif"
    fontSize: "22px"
    fontWeight: 800
    lineHeight: 1.2
    letterSpacing: "-0.015em"
  title:
    fontFamily: "'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif"
    fontSize: "16px"
    fontWeight: 800
    letterSpacing: "-0.01em"
  body:
    fontFamily: "'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif"
    fontSize: "15px"
    fontWeight: 400
    lineHeight: 1.5
  label:
    fontFamily: "'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif"
    fontSize: "12px"
    fontWeight: 700
    letterSpacing: "0.08em"
rounded:
  sm: "12px"
  md: "16px"
  lg: "20px"
  xl: "24px"
  pill: "999px"
spacing:
  gutter: "16px"
  card-padding: "24px"
  sidebar-w: "248px"
components:
  button-primary:
    backgroundColor: "{colors.gatekeeper-blue}"
    textColor: "#ffffff"
    rounded: "{rounded.pill}"
    padding: "10px 20px"
  button-primary-hover:
    backgroundColor: "{colors.gatekeeper-blue-deep}"
  button-outline:
    backgroundColor: "{colors.slate-surface}"
    textColor: "#2b3a55"
    rounded: "{rounded.pill}"
    padding: "10px 20px"
  card:
    backgroundColor: "{colors.slate-surface}"
    rounded: "{rounded.lg}"
    padding: "24px"
  badge-present:
    backgroundColor: "#dcf6e6"
    textColor: "#15803d"
    rounded: "{rounded.pill}"
    padding: "4px 12px"
  badge-absent:
    backgroundColor: "#fde4e4"
    textColor: "#b91c1c"
    rounded: "{rounded.pill}"
    padding: "4px 12px"
---

# Design System: MCNP-ISAP QR Attendance

## Overview

**Creative North Star: "The Quiet Gatekeeper"**

This is a system whose entire purpose is catching attendance fraud, and it deliberately does not look or feel like one. Instead of alarms, locks, or heavy institutional chrome, it uses a soft blue gradient backdrop with two floating white panels — the sidebar and the main content panel — riding above it like paper on a desk. Verification happens quietly and constantly underneath (GPS radius, device binding, signed QR payloads, server-side re-checks on every rule), but the surface stays calm, precise, and trustworthy. The product earns trust by being legible and unhurried, not by looking locked down.

Every interactive surface is a pill or a rounded rectangle; nothing is sharp-cornered. One accent color — Gatekeeper Blue — marks everything selected or actionable; it never competes with the four status colors (Present, Late, Absent, Excused), which are reserved strictly for attendance state. The whole system reads as **soft-edged and quietly confident**: generous radii, restrained shadows, bold (800-weight) headings doing hierarchy's work instead of loud color or large size jumps, and a scale(.97) press on every button that makes the interface feel like it's listening.

**Key Characteristics:**
- Floating-panel shell: a visible gradient backdrop behind two elevated white (or dark-surface) panels, never a single flat background
- One brand accent (Gatekeeper Blue), four status colors that never do double duty as decoration
- Pills everywhere interactive; cards and panels round at a larger, consistent scale
- Headings differentiate by weight (800) and negative letter-spacing, not by dramatic size jumps
- Full light/dark theme parity — every token has a dark-mode counterpart, not just an inverted background

## Colors

A single confident blue accent against cool slate neutrals, with four status colors doing all the semantic work.

### Primary
- **Gatekeeper Blue** (`#1d6bf3`): the only brand accent. Primary buttons, active sidebar nav item (solid fill, not just a tint), links, focus rings, toggle/checkbox "on" states, active pagination, date-chip and stat icon accents.
- **Gatekeeper Blue Deep** (`#1558cc`): hover/pressed state for Gatekeeper Blue surfaces.
- **Gatekeeper Blue Ink** (`#0f1b3c`): the darkest point of the accent ramp; used only in the login page's background-photo duotone (`linear-gradient(160deg, Gatekeeper Blue Ink, Gatekeeper Blue)` blended over the photo with `background-blend-mode: color`, so the photo reads in-brand instead of in its own colors), never as a UI surface color.

### Neutral
- **Slate Ink** (`#0e1a33`): primary text and headings (light theme).
- **Slate scale** (`#f6f9fd` → `#0e1a33`, 10 steps): body text, secondary text, borders, hover tints, and disabled states, all drawn from one slate ramp rather than ad hoc grays.
- **Slate Surface** (`#ffffff`): cards, modals, inputs, the sidebar and main panel.
- **Slate Canvas** (`#f4f7fc`): the content area inside the main panel that cards sit on — one step darker than Surface so cards read as lifted.
- **Slate Line** (`#e8eef7`): card borders and row separators.
- **Backdrop gradient** (`linear-gradient(135deg, #d9e8ff, #eaf1fc, #d6e5ff)`): the fixed-attachment page background behind both floating panels.

### Status Colors
Attendance state and nothing else. A status color appearing anywhere that isn't an attendance badge, dot, or chart series is a mistake, not a stylistic choice.
- **Present Green** (`#16a34a`, tint `#dcf6e6`): a verified, on-time check-in.
- **Late Amber** (`#e8820c`, tint `#ffefd9`): checked in after the grace period.
- **Absent Red** (`#dc2626`, tint `#fde4e4`): no record, or a rejected scan.
- **Excused Violet** (`#6366f1`, tint `#e0e7ff`): a teacher/admin override — counts as neither attended nor absent, and gets its own color family specifically so it's never confused with the other three.

### Named Rules
**The Single Accent Rule.** Gatekeeper Blue is the only brand color. Every other hue on screen is either a neutral (slate) or a status signal — never a second decorative accent.

**The Status Colors Never Multitask Rule.** Present green, Late amber, Absent red, and Excused violet map 1:1 to attendance meaning everywhere in the product (badges, dots, chart series, stat icons). They are never repurposed for unrelated success/warning/error messaging elsewhere — a generic alert uses the same token only when it is, in fact, reporting that same status.

## Typography

**Display/Body Font:** Plus Jakarta Sans (weights 400/500/600/700/800), falling back to the system UI stack.

**Character:** A single geometric sans carries the entire system — no serif, no mono, no second display face. Hierarchy is built almost entirely from weight (jumping straight to 800 for anything that needs to lead) and tight negative letter-spacing on larger sizes, not from a wide range of sizes.

### Hierarchy
- **Display** (800, 30px, 1.1 line-height, -0.02em): stat-card numbers — the biggest, most load-bearing figures on a dashboard.
- **Headline** (800, 22px, 1.2, -0.015em): the top-bar page title.
- **Title** (800, 16–17px, -0.01em): card and modal headers.
- **Body** (400, 15px, 1.5): running text and table content; no tracking adjustment.
- **Label** (700, 12px, 0.08em, uppercase): section eyebrows (`.nav-label`), table column headers, and form section titles — the only places letter-spacing opens up instead of tightening.

### Named Rules
**The Weight-Over-Size Rule.** Headings jump straight to font-weight 800 rather than stepping through 500/600/700; the type scale stays compact (12px label to 30px display) because weight and negative tracking do the differentiation, not dramatic size jumps.

## Layout

A fixed-position sidebar (248px) and a flex-growing main panel sit on top of a fixed-attachment gradient backdrop, both inset by a 16px gutter from the viewport edge and from each other — the backdrop is always visible as a sliver around and between them. The main panel itself splits into a sticky top bar and a scrolling canvas (`--canvas`, one tone darker than the panel) that holds the page's cards.

Content grids default to `grid-2` (2fr/1fr, e.g. a chart beside a smaller summary card) and `grid-3` (equal thirds); both collapse to a single column at 1000px.

**Responsive collapse**, in order:
- **900px:** the sidebar becomes an off-canvas drawer (hamburger toggle, slides in from the left over a translateX transform); students additionally get a fixed mobile bottom nav bar (home/scan/history/alerts/profile) that other roles don't have; `grid-2` flattens; page padding drops to 18px.
- **700px:** two-column form rows (`.form-row`) collapse to one column.
- **600px:** main panel margin shrinks to 8px, page padding to 14px; the top bar hides the date chip and user name (avatar stays) and the page title truncates with ellipsis rather than wrapping; stat grid drops to 2 columns.
- **480–380px:** stat grid drops to 1 column; remaining large numerals shrink further.

## Elevation & Depth

Shadows are structural, not decorative: they are what makes the floating-panel layout read as floating at all, and depth increases in one direction as you move from the backdrop toward the user's current focus.

### Shadow Vocabulary
- **Panel** (`0 10px 40px rgba(37,84,170,.10)`): the sidebar and main panel — the two largest surfaces, directly against the gradient backdrop. The deepest ambient shadow in the system because it has the most backdrop to clear.
- **Small / resting** (`0 1px 2px rgba(30,64,120,.05)`): cards, stat cards, inputs, chips — anything resting one level down on the canvas, barely lifted.
- **Medium / interactive response** (`0 6px 20px rgba(30,64,120,.08)`): not a resting state — it only appears on hover/focus of specific elements (a stat card lifting `translateY(-2px)` on hover, a calendar event on hover/focus-visible), signaling "this is about to be activated."
- **Large / overlay** (`0 18px 48px rgba(30,64,120,.18)`): modals, toasts, the open mobile sidebar drawer, the mobile bottom nav — content that's temporarily above everything else.

### Named Rules
**The Panel Lift Rule.** Depth decreases as you move inward: backdrop (flat) → sidebar/main panel (Panel shadow) → cards (Small shadow) → nothing, until an element becomes an overlay (modal/toast) or gains interactive focus (Medium shadow), both of which jump back up.

## Shapes

- **sm (12px):** inputs, form controls, small blocks, role tabs.
- **md (16px):** alerts, toasts, QR display frame, lab-status wrapper.
- **lg (20px):** cards, stat cards, the mobile bottom nav bar.
- **xl (24px):** the sidebar panel, the main content panel, modals.
- **pill (999px):** every button, badge, chip, tab, and the day-picker's selectable day labels — anything the user directly acts on.

No sharp corners exist anywhere in the system; every radius step is one of these five values.

### Named Rules
**The Shape Rule.** Panels round at 24px, cards at 20px, inputs and small blocks at 12px; buttons, badges, chips, and tabs are always full pills. A new component picks from this exact scale — it never introduces its own radius value.

## Components

### Buttons
- **Shape:** full pill (999px radius), 10px/20px padding, 14px/700-weight label text.
- **Primary:** Gatekeeper Blue fill, white text, a soft blue glow shadow (`0 6px 14px rgba(29,107,243,.24)`); darkens to Gatekeeper Blue Deep on hover.
- **Outline:** white/surface fill, slate border and text; hover shifts to a faint slate tint, never the accent color.
- **Success / Danger:** flat Present-green / Absent-red fill, white text — used for destructive or confirming actions, not for status display (that's the badge's job).
- **Press feedback:** every button scales to `.97` on `:active` — the interface visibly "gives" under a tap.
- **Small variant:** 7px/14px padding, 13px text, for dense row actions.

### Status Badges (signature component)
- **Shape:** full pill, 4px/12px padding, 12.5px/700-weight text.
- **Style:** a 100-level tint of the status color as background, the matching `-fg` (darker/more saturated) shade as text — never a solid fill like buttons get. This is what visually tells a badge apart from a button at a glance.
- **Variants:** Present, Late, Absent, Excused, plus a neutral slate pair (Active/Inactive, Pending) for non-attendance state.

### Cards / Containers
- **Corner Style:** 20px radius (stat cards and regular cards share one scale).
- **Background:** Surface color, with a hairline `Slate Line` border — the border matters more than the shadow for separating a card from the canvas behind it.
- **Shadow Strategy:** Small/resting at rest; a subset (stat cards) lifts to Medium + `translateY(-2px)` on hover as an affordance that the card is clickable.
- **Internal Padding:** 24px (20px header top-padding tightens to a 4px gap before the body starts).

### Inputs / Fields
- **Style:** Surface background, 1px Slate 200 border, 12px radius, 14px text.
- **Focus:** border shifts to Gatekeeper Blue plus a 3px soft blue glow ring (`box-shadow: 0 0 0 3px` of the Blue-100 tint) — never just an outline.
- **Icon-prefixed inputs:** icon sits absolutely positioned inside the field with left padding on the input rather than a separate prefix box, except the explicit "input-prefix" pattern used for labeled compound fields (e.g. a unit or code prefix), which uses a joined two-box layout with radius only on the outer corners.

### Navigation
- **Sidebar:** fixed 248px panel; nav links are pills with a muted icon; the active link gets a **solid** Gatekeeper Blue fill with a colored glow shadow — not just a tint or a left border, so "where am I" is unmissable even glanced at from a distance.
- **Top bar:** sticky, houses the page title/subtitle, a date chip, notification bell, and a user chip (avatar + name + role) — all rendered as the same pill/chip visual language as the rest of the system, not a distinct "header" style.
- **Mobile bottom nav (student only, ≤900px):** a floating pill-shaped bar (not full-width, inset 12px from the screen edges) with 5 icon+label targets; active state is a color change only, no background fill (unlike the desktop sidebar).

### Modals
- **Shape:** 24px radius, same scale as the root panels since a modal is conceptually "a panel over the panel."
- **Entrance:** scales up from `.97` + 10px translateY with Large shadow — never appears at full size instantly.
- **Footer:** right-aligned action buttons, separated by a hairline border, matching the button component exactly (no modal-specific button variant).

### Toasts
- **Style:** Surface card, Large shadow, a 4px colored left border (blue/green/red by type) is the only status signal — the toast body itself never changes background color.
- **Motion:** slides in from the right (30px → 0) and slides out faster than it entered — exit is quicker than entrance throughout the system, not just here.

### QR Display (signature component)
The literal core mechanic of the product, rendered with the same restraint as everything else — no scanner chrome, no camera-viewfinder styling, just the code on white.
- **Frame:** the QR canvas always sits on a pure-white card regardless of theme (`#fff`, never `var(--surface)`) — in dark mode this is the one deliberate surface that stays light, because a QR code photographed off a dark background is a scanning-reliability risk, not a stylistic choice.
- **Shape:** 16px radius (the `md` step), 16px internal padding, a 1px Slate 200 border — visually a card, but one size step down from the full card radius so it doesn't compete with the card it usually sits inside.
- **Session meta:** small (13.5px) slate-600 text beneath the code (class/lab/time).
- **Countdown:** when a session is timed, the remaining-time number is treated like a Display-tier number — 28px, 800-weight, Gatekeeper Blue — the only place a non-stat number gets display-scale treatment, because it's the thing a teacher is watching most closely while the session is live.

## Do's and Don'ts

### Do:
- **Do** use the pill shape (999px) for every button, badge, chip, and tab — it's the single most identifying shape in the system.
- **Do** differentiate heading levels with weight (800) and negative letter-spacing before reaching for a bigger font size.
- **Do** keep status colors (Present/Late/Absent/Excused) exclusive to attendance meaning; use slate neutrals for anything else that needs a "pending/inactive" look.
- **Do** give the active sidebar item a solid accent fill with a glow shadow, not a subtle tint or border — navigation state should be unmissable.
- **Do** make exits (toast dismiss, modal close) faster than entrances.

### Don't:
- **Don't** introduce a second brand accent color. Gatekeeper Blue is the only one; a new "secondary" color is almost certainly meant to be a status color or a slate neutral instead.
- **Don't** give a card, panel, or button a radius outside the five-step scale (12/16/20/24/999).
- **Don't** use a flat solid fill for a status badge — badges are always a light tint + dark text pair, never a saturated solid background (that's reserved for buttons).
- **Don't** add drop shadows to small, flat UI like table rows or list items; the shadow vocabulary is reserved for panels, cards, and overlays — most of the interface stays flat against its parent surface.
- **Don't** reach for a second typeface. Plus Jakarta Sans at a higher weight is the answer to "this needs to stand out more," not a new font.
