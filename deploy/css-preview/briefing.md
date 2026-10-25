# RIFT people counter: CSS rework briefing

You are helping restyle a small website: the **RIFT people counter** (a church's attendance counter, interface in
European Portuguese). You work on ONE file, the site's complete stylesheet `assets/site.css`, through the HTTP API below.
Nothing you do here touches the live site: this is a private preview with invented data. The site owner reviews your
work and merges it.

Base URL: `{{BASE}}`   (this document: `{{BASE}}/_edit/`, also at `{{BASE}}/llms.txt`)

## The rules

1. Change **only the stylesheet**. You cannot change HTML or JavaScript. The JavaScript builds markup that uses the
   existing class names and ids, so **never rename or remove a class/id that the pages use** (add new rules freely).
   If a design needs a markup change, write it down for the site owner instead.
2. Keep it **one file**, plain CSS: no `@import`, no external fonts, images or URLs (the API rejects them; system font
   stacks only; `url(#id)`, `url(data:image/...)` and `url(/same-site-path)` are allowed). No JavaScript in CSS.
3. Keep **both colour schemes**: the site follows the visitor's setting through `@media (prefers-color-scheme: dark)`.
   Colours live in design tokens (CSS variables on `:root`, below); restyling mostly means changing tokens, then the rules.
   You may add new tokens and rules freely; you may change token values; keep existing token names defined.
4. Keep it **usable on phones** (design for 390 px wide AND 1200 px). Existing breakpoints: 720 px (admin menu becomes a
   slide-in drawer, tables become cards, report dialog is pinned above the keyboard) and 520 px.
5. Accessibility floor: text contrast at least 4.5:1 (3:1 for large text and UI borders), a visible `:focus-visible`
   outline on every interactive element, tap targets of at least 36 px, honour `prefers-reduced-motion`, and never remove information or controls
   (rule 8 says what may be hidden).
6. File size limit {{MAX_KB}} KB. Do not delete rules you do not understand; the API warns when selectors disappear, and
   refuses an upload that drops more than half of the existing selectors (it looks truncated) unless you add `&force=1`.
7. Contrast: compute it (WCAG relative luminance: L = 0.2126 R + 0.7152 G + 0.0722 B on linearised sRGB channels, ratio =
   (L1 + 0.05) / (L2 + 0.05)) rather than judging by eye; check text on `--surface-1` AND `--surface-2`, in both schemes.
8. Hiding text. You MAY hide text that is **purely explanatory**: it explains how to read the page, but holds no data, no
   status and no action. Typical examples: the explanatory paragraphs in the page footer (`footer p`, such as "O que
   significam os valores" and "Saldo"), and `.hint` help lines on admin pages and in the report dialog. You MAY NOT hide:
   - data and its labels: counts, times, names, the "por anunciar" placeholders, tile titles and their `.note` lines, table
     headings, form labels, chart axes and legends;
   - status: `.state` ("Ainda não começou", "A decorrer ...", "Terminou às ..."), the "hoje" badge, "dados parciais" notes;
   - messages: `.flash`, `.err`, `.form-error`, `#toast`, anything that reports an error or a result;
   - controls and navigation, including the `footer .footer-links` link to the admin area, and the text of a button;
   - a `.hint` that states a required format (for example HH:MM) or warns about the consequence of an action;
   - screen-reader-only text (`.sr`), and the sign-up form's hidden robot trap (`.hp`, must stay hidden and off-screen).
   Never hide headings (`h1`, `h2`, `h3`). Hide by a selector that targets only the explanatory element (so the `footer`
   itself and its links stay), put all of it in one commented block, `/* Hidden explanatory text */`, so the owner can review
   it at a glance, and list in your final report exactly what you hid. If you are unsure whether a text is explanatory,
   keep it visible and ask the owner.

## The API (plain HTTP; reading is open, writing needs the token)

```
GET  {{BASE}}/_edit/css                      the current stylesheet (text/css)
PUT  {{BASE}}/_edit/css?message=Short+note   replace the WHOLE stylesheet with the request body
POST {{BASE}}/_edit/css                      same as PUT, for clients that cannot send PUT
GET  {{BASE}}/_edit/log                      the last 30 saves (commit, time, message)
POST {{BASE}}/_edit/revert?to=<commit>       go back to an earlier version (a new save; history is kept)
GET  {{BASE}}/_edit/pages                    the preview pages to look at (JSON)
```

Writing needs the header `Authorization: Bearer <token>` (the site owner gave you the token). Example:

```
curl -s {{BASE}}/_edit/css -o site.css                                            # download
# ... edit site.css ...
curl -s -X PUT --data-binary @site.css -H "Authorization: Bearer $TOKEN" "{{BASE}}/_edit/css?message=Softer+cards"
```

Answers are JSON. Success: `{"ok":true,"changed":true,"commit":"a1b2c3d","warnings":[...]}`. Read `warnings`: they list
selectors or tokens that existed before and are now gone, and a missing dark mode or phone breakpoint. A failure has
`"ok":false`, an `error` in plain language, a `hint`, and for a rejected stylesheet a `problems` list (with line
numbers); a rejected stylesheet is not saved at all. Every save is a git commit on the branch `css-rework`.
Status codes: 401 wrong/missing token, 413 too large, 422 rejected CSS, 429 too many requests, 409 save in progress.
`message` must be URL-encoded (`+` for spaces is fine). Every save is a commit the site owner may read: work in a few
meaningful saves rather than hundreds (more than 120 saves in an hour are refused with 429). Going back is always possible.

## How to work

1. `GET /_edit/css` and read it: it has five sections, marked by comment banners `/* ===== 1. Design tokens */` ...
   (tokens; base and shared; meetings page `body.reunioes`; admin `body.admin`; accounts: sign-in/sign-up pages, header chip, pending badge). Rules of the meetings page and the admin area are scoped by a class on `<body>` because the same class
   names (`.card`, `.hint` ...) look different on each.
2. Make a change, `PUT` the whole file, then **look at the pages** in a browser (reload; nothing is cached) at 390 px and
   1200 px wide, in light AND dark (emulate `prefers-color-scheme`). Iterate with small saves and short `message`s.
3. Check every page type, not only the one you changed: charts, meetings (all the states), admin (all the pages). States that
   need a gesture (script them with Playwright or similar):
   - report dialog: on `/reunioes` click the first `.info-btn:not([disabled])` (clipboard icon); close with Escape;
   - admin menu drawer: viewport under 720 px wide, click `#menutoggle`; close with Escape or the backdrop;
   - chart tooltip: on `/` move the mouse over the chart (`.tip`); keyboard focus: press Tab and look at the outlines;
   - the pulsing "running" dot animates: emulate `prefers-reduced-motion: reduce` to freeze it in screenshots.
4. When done, tell the site owner what you changed and anything that needs a markup change.

## Preview pages (static, invented data; buttons that "save" do nothing)

{{PAGES}}

The preview's DATA is as of Sunday 18 October 2026, 12:15 Lisbon (the "Atualizado às ..." text in the page header shows the
real time of day). Known, pre-existing details that are not yours to fix unless you want to: on the charts page at 390 px the
range buttons wrap to two lines; native date/time inputs show the browser's own format.

## Colour roles

Two kinds of colour token. **Data colours** `--series-in` (entries, blue), `--series-out` (exits, orange), `--series-bal`
(balance, green), `--series-4`, `--series-5` paint the charts and their legends/swatches. **Role colours** are used by the
interface itself and by default point at a data colour, so you can restyle them independently:
`--accent` (current page in the nav, the pulsing "running" dot, highlights, admin menu marker), `--focus` (keyboard focus
outlines, the card a link points at), `--ok` (success messages), `--danger` (errors, invalid fields, destructive buttons).
To change the brand/accent colour, change `--accent` (and probably `--focus`), not `--series-bal`.

## Current design tokens (light  |  dark)

{{TOKENS}}

## What the pages contain (class and id index)

**Every page**: `header` (`h1`, `.sub` status text), `nav.nav` (links, `aria-current="page"` on the current one), `button`
(`aria-pressed="true"` when selected; `.primary`, `.danger`), `footer` (`p`, `.footer-links`), `.empty` (nothing to show).

**Charts page `/`**: `.tiles > .tile` (`.label` with a `.swatch.in|.out|.bal` colour dot, `.value`, `.note`); `.controls`
(`.ranges` buttons, `.legend`, `#toggle` to switch to a table); `#chart` holding an SVG (colours come from the `--series-*`
tokens; `.grid line`, `svg text`), `.tip` (hover tooltip with `.k` and `.swatch`), `.tablewrap table`.

**Meetings page `/reunioes`** (`body.reunioes`): `.weekbar` (`.label`, `.buttons`); `#days > section.day`; `.day h2` (`.dayname`,
`.badge` "hoje"); `.cards > .card` (`.event` for an event; `.linked` for the card the URL points at; inside: `.title` with a
`.time`, `.count` (`.unknown` before the meeting starts), `.state` (with a pulsing `.dot` while running), `.note`, `dl.people` with
`dt`/`dd` (`dd.tbd` = "por anunciar"), round buttons `.info-btn` (report; `:disabled` before the meeting starts) and `.link-btn`
(copy link)); the report dialog `dialog#report` (`.dlg-head`, `.dlg-close`, `.dlg-body`, `fieldset`/`legend`, `.field` with
`label`, `input`, `.hint`, `.err`, `.grid2`, `.dlg-actions`, `#reportOut`, `#reportText`, `.form-error`); `#toast`.

**Accounts** (the whole site needs an account; these are the pages a stranger sees). They are admin-style pages, so they use `body.admin`
rules: `/entrar` (sign in), `/registar` (sign up), `/esqueci-palavra-passe`, `/repor-palavra-passe`, `/conta` (my account); each is a `form.card.narrow`
with `label`, `input` (text, email, password), `.hint`, `button.primary`, messages in `.flash` (`.ok` / `.error`) and `.hp` (hidden robot trap).
On the charts and meetings pages a small chip is added at the end of `header` by script: `.userchip` (name link, "Sair" button, or "Ecrã · só leitura").
In the admin users page: `.pending-list` (sign-up requests), `.count-badge` (number next to "Utilizadores" in the menu), `.pill.pending`, `.pill.disabled`, `.pill.code`.

**Admin `/admin/*`** (`body.admin`): `header` (`.title`, `.menu-toggle` hamburger, `.who`), `nav.nav` (top bar; slide-in drawer on
phones, with `.nav-backdrop`, and `body.nav-open`), `h2`/`h3`, `.flash` (`.ok` / `.error`), `.card` (`.narrow`), forms
(`label`, `input`, `select`, `textarea`, `.row`, `.actions`, `.hint`, `.tag`), `table.admin` (cards on phones: `.stack`,
`.has-field`, `.actions-cell`), statistics (`.stats`, `.s-sec`, `.s-head`, `.s-card`, `.s-chart`, `.s-table`, ...), users page
(`.roles`, `.users > .user.card`, `.pills > .pill`, `.user-edit`, `.field-form`, `.add-user`), `details.card`.

## A message you can paste to start

> Read {{BASE}}/_edit/ completely, then follow it. The token for saving is in the variable TOKEN (I will tell you its value).
> Goal: restyle the site as I describe. Work on the stylesheet only, look at every page type at phone and desktop width in
> light and dark after each save, and report what you changed.
