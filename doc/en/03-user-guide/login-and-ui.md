# Sign-in and the interface

How to sign in to the WPS web interface, find your way around it and switch the interface language.

<!-- lang-switch -->
🇬🇧 English | [🇷🇺 Русский](../../ru/03-user-guide/login-and-ui.md)
<!-- /lang-switch -->

## Sign in

1. Open the UI in a browser. With the default `docker compose` setup the address is `http://localhost:3000`; the port is set by `WPS_UI_PORT` in `./.env` (this repository's `.env` sets `3080`).
2. Enter **Email** and **Password**. Right after installation there is one administrator: `admin@wps.local` / `admin`.
3. Click **Sign in**.

![Sign-in page](../../images/screenshots/en/01-login.png)

*The sign-in page. The language switcher is in the top right corner.*

> **Warning**
> Change the password of `admin@wps.local` after the first sign-in (**Users** → edit the user). See [Users and roles](users-and-roles.md).

Messages you may see on the sign-in form:

| Message | Cause |
|---|---|
| Enter email / Invalid email | The email field is empty or is not a valid address |
| Enter password | The password field is empty |
| Invalid email or password | The server returned `401 INVALID_CREDENTIALS` |
| The server is unavailable. Check your connection. | The backend (`/api`) cannot be reached |

After a successful sign-in you land on the process list. If you opened a link to a specific page before signing in, you are taken to that page.

### Session

- The UI keeps an access token and a refresh token in the browser (`localStorage`, keys `wps_access_token` and `wps_refresh_token`).
- The access token lives 15 minutes by default (`JWT_ACCESS_TTL`), the refresh token 7 days (`JWT_REFRESH_TTL`). When the access token expires, the UI renews it silently.
- When the refresh token has expired too, you see **Session expired, please sign in again** and are sent to the sign-in page.
- **Log out** (top right) asks for confirmation (**Log out of the system?**) and deletes the tokens from the browser.

## Layout

![Process list with the main menu](../../images/screenshots/en/02-processes-list.png)

*Main window: header, left menu and the work area (here — the process list).*

| Area | What is there |
|---|---|
| Header | **Workflow Processing Service** logo (link to the process list), your email, role badge (**Administrator**, **Editor**, **Viewer**), language switcher, **Log out** |
| Left menu | **Processes**, **Create process**, **Users** — depending on your role, see below |
| Work area | The current page: process list, process card, editor, execution page, users |

### What the menu shows for each role

| Menu item | `admin` | `editor` | `viewer` |
|---|---|---|---|
| **Processes** | ✓ | ✓ | ✓ |
| **Create process** | ✓ | ✓ | — |
| **Users** | ✓ | — | — |

The same rules protect the pages themselves: if a viewer opens `/processes/create` or an editor opens `/users` directly, the UI redirects to the process list. Buttons follow the role too: **Edit**, **Clone** and **Run** are visible to editors and administrators, **Delete** only to administrators. The full permission matrix is in [Users and roles](users-and-roles.md).

### Pages

| Address | Page | Who |
|---|---|---|
| `/login` | Sign in | everyone |
| `/processes` | Process list | all roles |
| `/processes/create` | New process | admin, editor |
| `/processes/{id}` | Process card, execution log, run-once records | all roles |
| `/processes/{id}/edit` | Edit process | admin, editor |
| `/executions/{id}` | Execution page (steps, context) | all roles |
| `/users` | Users | admin |

The browser tab title follows the page, for example `Processes — WPS`.

## Interface language

The interface is available in English and Russian. Process names, CRM module names and other data are shown as they are stored and are not translated.

![Language menu](../../images/screenshots/en/17-language-switcher.png)

*The language menu in the header: **Русский** / **English**.*

To switch the language:

1. Click the current language in the header (or in the top right corner of the sign-in page).
2. Choose **English** or **Русский**. The page switches immediately, without reloading; unsaved form data is kept.

How the language is chosen when the UI opens (first match wins):

1. The `lang` URL parameter: `?lang=en` or `?lang=ru`. Handy for links and bookmarks, e.g. `http://localhost:3000/processes?lang=en`.
2. The language you chose last time (stored in the browser, `localStorage['wps_locale']`).
3. The browser language.
4. Russian (default).

The chosen language is remembered in this browser and is also sent to the backend in the `Accept-Language` header.

> **Note**
> Module labels in selectors come from the CRM. The bundled mock returns Russian module names (for example `Сделки` for `Deals`) in both interface languages.

## Next steps

- [Processes](processes.md) — the list, the card and the editor.
- [Trigger conditions](triggers-and-conditions.md) — when a process starts.

<!-- footer-nav -->
---

← Previous: [Your first process](../02-getting-started/first-process.md) | [Contents](../README.md) | [Processes](processes.md) Next →
<!-- /footer-nav -->
