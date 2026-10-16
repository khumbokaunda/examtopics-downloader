# Theme: light and dark, how it works

A description of how Examtopics colours its interface in both themes, written so another
project can build the same look. It describes the rules and the values, not this app's file
names. Where a name appears it is only an example.

The short version: surfaces are neutral, colour is used sparingly, and highlighted things
carry their colour as a **tint**, never as a bright block or a solid outline.

Contents:

1. The idea
2. Tokens: the only source of colour
3. The light palette
4. The dark palette
5. The accent colour
6. The four ways colour is used
7. Component by component
8. Selection and results (picked, right, wrong)
9. How strong each tint is
10. Switching themes
11. Accessibility
12. Lessons that shaped it
13. Checklist for a new app

---

## 1. The idea

- **Neutral surfaces.** Page, cards and panels are plain greys: near-white in light, charcoal
  in dark. No blue or purple cast.
- **Colour is information, not decoration.** It marks the one primary action, a status
  (success, warning, danger, info), the current place in the menu, or a result. Everything
  else is neutral.
- **Highlighted things are tinted, not outlined.** A chip, an alert, a correct answer or a
  selected row gets a soft fill of its hue. It does not also get a coloured border. One
  signal is calm; two (fill plus outline) look busy.
- **Unhighlighted things keep a quiet neutral border**, so items still read as separate
  items, and a highlighted item stands out by its fill.
- **Both themes follow the same rules.** The dark theme is not the light theme inverted: it
  has its own values, chosen to look calm on charcoal.

---

## 2. Tokens: the only source of colour

Every colour in the interface reads a CSS custom property. No component uses a raw hex value.
A theme is a set of values for the same names.

| Group | Tokens |
|---|---|
| Neutrals | `bg` (page), `surface` (cards), `surface-alt` (hover, headers, wells), `border`, `border-strong`, `text`, `text-muted`, `text-subtle` |
| Accent | `primary`, `primary-hover`, `primary-soft` (its tint), `accent`, `on-accent` (text on a primary fill), `focus-ring` |
| Status | for each of `success`, `warning`, `danger`, `info`: the colour, a `-soft` tint, and an `-ink` (text colour on that tint) |

Rules:

- A component that needs a new colour gets a new token, defined in both themes.
- A search for hex values outside the token file should find nothing, except image previews
  and email templates (which cannot use variables).

---

## 3. The light palette

| Token | Value | Notes |
|---|---|---|
| bg | `#F7F8FA` | The page behind cards |
| surface | `#FFFFFF` | Cards, panels, inputs |
| surface-alt | `#F1F3F6` | Hover, table headers, wells |
| border | `#E3E7EC` | Card and item borders |
| border-strong | `#C9D0D8` | Inputs, secondary buttons |
| text | `#1A2028` | |
| text-muted | `#5C6B75` | Secondary text |
| text-subtle | `#68737F` | Hints; still 4.5:1 on white |
| success / soft / ink | `#2F855A` / `#E6F4EC` / `#1E6B45` | |
| warning / soft / ink | `#E8A33D` / `#FBEFD8` / `#7A4E00` | |
| danger / soft / ink | `#C0392B` / `#F8E3E0` / `#A32B20` | |
| info / soft / ink | `#2E86AB` / `#E1EEFB` / `#1A5F7E` | |
| on-accent | `#FFFFFF` | Text on a primary fill |

On white, a soft fill with ink-coloured text is calm and readable. The ink is darker than
the status colour, because the status colour itself is too light to read as text.

---

## 4. The dark palette

| Token | Value | Notes |
|---|---|---|
| bg | `#0C0C0E` | Near black, neutral |
| surface | `#141416` | Cards, one step up from the page |
| surface-alt | `#1B1B1E` | Hover, headers, the active menu item |
| border | `#26262A` | Quiet lines between items |
| border-strong | `#34343A` | Inputs, secondary buttons |
| text | `#ECECEE` | Not pure white: easier on the eyes |
| text-muted | `#A0A0A8` | |
| text-subtle | `#8A8A92` | Still 4.5:1 on the surface |
| success | `#4CBF7F` | Calmer than a neon green |
| warning | `#D9A441` | |
| danger | `#E26A6A` | |
| info | `#6AA7E8` | |
| success-soft | `#1D2F27` | About 16% of the hue over the surface |
| warning-soft | `#342B1D` | |
| danger-soft | `#352223` | |
| info-soft | `#222C38` | |
| ink (all four) | same as the status colour | Light text reads on a dark tint |
| on-accent | `#0C0C0E` | Dark text on a light primary fill |
| shadows | black at 40 to 60% | Shadows matter less on dark; borders do the work |

Why these values:

- **Neutral, not blue.** A blue-navy page (such as `#0E1319`) makes every accent fight the
  background and looks like a code editor. A neutral charcoal lets any accent sit calmly.
- **Three steps of grey** (`bg`, `surface`, `surface-alt`) give depth without shadows.
- **Status colours are a step less saturated** than their light-theme equivalents would be if
  simply brightened. Bright saturated green or red on charcoal reads as neon.

---

## 5. The accent colour

Each preset has a light and a dark version. The dark version is lighter (to read on
charcoal) and slightly less saturated.

| Preset | Light primary | Light soft | Dark primary | Dark soft |
|---|---|---|---|---|
| teal | `#1C7293` | `#E4F1F6` | `#5AAFC6` | `#1F2D32` |
| indigo | `#4F46E5` | `#EAE9FE` | `#8B93F0` | `#272839` |
| violet | `#7C3AED` | `#F0EAFE` | `#A88BF0` | `#2C2739` |
| amber | `#B45309` | `#FCEFD8` | `#E3A64E` | `#352B1F` |
| rose | `#BE185D` | `#FCE7EF` | `#EB7A8E` | `#362429` |
| emerald | `#047857` | `#DDF3E7` | `#4DC194` | `#1D302A` |

**A custom colour** (any hex an organisation picks) becomes both versions on the server:

1. **Light primary:** mix the colour toward black in small steps until its contrast with
   white is at least 4.5:1. A colour that already passes is used as entered.
2. **Dark primary:** mix toward white until its contrast with the dark page is at least 5:1.
3. **Hover:** 15% further toward black (light) or white (dark).
4. **Soft tint:** the light primary mixed 88% toward white; the dark primary mixed 84%
   toward the dark page.
5. **Focus ring:** the primary at about one third opacity.

The result is a small stylesheet served as a file, since a strict content security policy
allows no inline styles.

---

## 6. The four ways colour is used

Every coloured element uses exactly one of these. Choosing the treatment is the design
decision; the values follow from the tokens.

| Treatment | What it looks like | Used for |
|---|---|---|
| **Neutral** | Surface fill, neutral border, normal text | Secondary buttons, cards, inputs, unselected items |
| **Tint** | Soft fill of the hue, text or icon in the hue, **no outline** | Chips, alerts, status buttons (dark), highlighted states, selected rows |
| **Fill** | Solid hue, `on-accent` text | The primary action on a page. In light only, also status buttons |
| **Line** | A thin bar or underline in the hue, on a neutral fill | The active menu item, the current row in a list, active tabs |

Two rules hold everything together:

- **One fill per view.** If several things on a page are filled, none of them leads. Make the
  main action the fill and the rest neutral or tinted.
- **Never fill plus outline.** A tinted thing does not also get a border in its hue. A neutral
  thing has a neutral border. A filled thing needs no border.

---

## 7. Component by component

### Buttons

| Button | Light | Dark |
|---|---|---|
| Primary | Fill: `primary`, `on-accent` text | Same |
| Secondary ("outline primary") | Primary-coloured outline and text | **Neutral**: surface fill, `border-strong`, normal text; only the icon in the accent |
| Neutral ("outline secondary") | Surface fill, `border-strong` | Same |
| Status (success, danger, warning, info) | Fill | **Tint**: soft fill, text in the hue, no border; hover a little stronger (16 to 20%) |
| Outline status | Outline and text in the hue | Tint, as above |
| Link | Muted text, primary on hover | Same |

The secondary button changes most between themes. On white a coloured outline is light and
fine; on charcoal a row of coloured outlines glows.

### Chips (status labels)

- A pill with the soft fill and ink text, in both themes. No border, no outline.
- A neutral chip uses `surface-alt` and muted text.
- An "outline" chip (the only bordered one) is for labels that must not look like a status.

### Alerts

- **Light:** the soft fill and ink text, no border.
- **Dark:** a nearly neutral panel (the hue at about 14% over the surface), normal text
  colour, and **the icon in the hue**. The icon carries the meaning; the panel stays calm.

### Navigation

- **Active menu item:** `surface-alt` fill, normal text, the icon in the accent, and a 2px
  accent bar on the leading edge. In light, the soft accent fill is fine; in dark it reads as
  a purple block, so dark uses the neutral fill.
- **Hover:** `surface-alt`, in both themes.

### Lists and tables

- **Current row** (the version being viewed, the open conversation): `surface-alt` plus the
  2px accent bar. Not the accent tint.
- **Unread row:** `surface-alt` and a bolder title. In light, the primary soft tint is
  acceptable.
- **Row hover:** `surface-alt`.

### Result and information boxes

- An answer box, a "corrected" box, a warning box: the soft fill of their hue, ink text, an
  icon in the hue, **no border**.
- In dark, these sit at about 15% so they read clearly against the card.

### Icon bubbles and KPI tiles

- A round or square bubble with the soft fill and the icon in the hue.
- In dark, a 1px neutral inset keeps the bubble's edge visible.

### Forms

- Inputs: surface fill, `border-strong`, normal text. Focus: a primary border and the focus
  ring.
- Checked checkboxes and radios: primary fill (they are small, so a fill is fine).
- Select arrows are drawn in `text-muted` for each theme.

---

## 8. Selection and results (picked, right, wrong)

Anywhere a person selects something and then sees whether it was right (a quiz, a review, a
checklist), colour means only "right" or "wrong". Selection itself is neutral.

| State | Light | Dark |
|---|---|---|
| Unselected | Surface, neutral border | Same |
| Hover | `surface-alt`, `border-strong` | Same |
| **Picked** (before the result) | Grey: `text` at 9% over the surface, **no border**, bolder letter | `text` at 15% over the surface |
| **Right** (after the result) | `success-soft`, ink letter, no border | `success` at 24% over the surface |
| **Wrong pick** | `danger-soft`, ink letter, no border | `danger` at 24% over the surface |
| A right answer the person did not pick | Shown as right, so they learn it | Same |

- Mark the person's own picks with a small "your choice" label at the end of the row, in the
  row's hue, so right and wrong picks are told apart from right answers they missed.
- Write the result in a line of text too ("Correct." or "Not quite: you chose B; the answer
  is A."), in the hue with an icon. Colour alone is not enough.
- **Order matters in CSS:** the right and wrong rules must come after the picked rule (or be
  more specific), or a wrong pick stays grey.

---

## 9. How strong each tint is

The light theme uses fixed soft colours (section 3). In dark, every tint is the hue mixed
over the surface. These strengths were tuned on screen; weaker ones disappear on charcoal,
stronger ones glow.

| Element | Dark strength (hue over `surface`) |
|---|---|
| Soft fill: chips, tinted buttons, icon bubbles | about 16% (the `-soft` tokens) |
| Tinted button on hover / pressed | 16% / 20% |
| Alert panel | 14% |
| Answer, correction and warning boxes | 15% |
| Right and wrong choices | 24% |
| Neutral pick | `text` at 15% |

In CSS this is `color-mix(in srgb, var(--color-success) 24%, var(--color-surface))`.
`color-mix` works in every current browser. For older ones, precompute the values as tokens.

---

## 10. Switching themes

- **One attribute on `<html>`** chooses the theme: `data-theme="light"` or `"dark"`. A
  person's choice is `light`, `dark` or `system`. `system` follows the device and changes
  live when the device does.
- **No flash.** A tiny script in the `<head>`, before any stylesheet paints, resolves
  `system` and sets the attribute. The server also renders the saved choice on `<html>`.
- **Accent, density and motion** are attributes too (`data-accent`, `data-density`,
  `data-motion`), so a change is an attribute change, never a new stylesheet.
- **Stylesheet order:** tokens, then the UI framework, then base, layout and components, then
  a dark-only file, then the custom brand colour. The dark-only file contains nothing but
  `[data-theme="dark"]` rules, so the light theme cannot be changed by accident.
- **Map the framework's variables to the tokens** (Bootstrap's `--bs-btn-bg`,
  `--bs-alert-bg` and so on) instead of overriding its classes property by property.

---

## 11. Accessibility

- **Text contrast:** 4.5:1 for body text and 3:1 for large text, in both themes, including
  text on tints (ink on soft in light; the hue on a 24% tint in dark).
- **Colour is never the only signal:** results also have an icon and words; statuses have an
  icon and a label.
- **Focus:** a visible ring in the accent on every interactive element, including clickable
  rows.
- **Motion:** expanding panels animate (a grid row from `0fr` to `1fr`, with opacity), and the
  animation is off under `prefers-reduced-motion` or a "reduce motion" preference.
- **Clickable rows** behave like controls: `role="radio"` or `"checkbox"`, `aria-checked`,
  reachable by Tab, toggled by Space or Enter.

---

## 12. Lessons that shaped it

- **A blue-navy dark background** made every accent clash and the app look like an editor.
  It is now neutral charcoal.
- **Bright fills in dark mode read as neon.** Solid green download buttons, deep purple panels
  and saturated soft fills looked "vibe-coded". Status buttons and panels are now tints.
- **Accent-coloured outlines on every secondary button** turned a toolbar into a light show.
  Secondary buttons are neutral; only the icon keeps the accent.
- **Solid outlines around highlighted things** (a green border around a green answer, a
  coloured border around an alert) doubled the signal and looked busy in both themes. They
  were removed; the tint carries the meaning.
- **After the outlines went, the dark tints were too faint** to tell a correct answer from
  the background. Their strength was raised (section 9). Tune tints with the outlines off.
- **A selection coloured like a result** confused people. Picks are neutral grey; green and
  red appear only when the answer is revealed.
- **The grey "picked" rule overrode the red "wrong" rule** because it came later in the
  file. State rules now follow selection rules.
- **An active menu item in the accent's soft tint** was a purple block in dark mode. It is
  now a neutral highlight with a thin accent bar.

---

## 13. Checklist for a new app

1. Tokens for neutrals, accent and status (colour, soft, ink), defined for both themes. No
   hex values in components.
2. Light palette and dark palette as in sections 3 and 4, with neutral (not tinted) greys.
3. Accent presets with light and dark versions, and the contrast-checked custom colour method.
4. Every coloured element assigned one treatment: neutral, tint, fill or line. One fill per
   view; never fill plus outline.
5. No coloured borders on highlighted things (chips, alerts, result boxes, selected and
   graded items), in either theme.
6. A dark-only stylesheet loaded last, holding the dark treatments of buttons, chips, alerts,
   navigation, current rows and results.
7. Tint strengths as in section 9, checked on screen with the outlines off.
8. Selection neutral, results coloured, with text and icons as well as colour.
9. Theme chosen by one attribute, resolved before first paint, following the device for
   "system".
10. Checks:
    - every page screenshotted in both themes, on a desktop and a phone width;
    - contrast of text on every tint;
    - a search for raw hex values outside the token file;
    - reduced motion turns animations off;
    - keyboard selection of clickable rows.
