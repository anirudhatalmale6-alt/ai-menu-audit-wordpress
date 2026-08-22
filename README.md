# AI Menu Audit

A WordPress plugin that turns a pasted or uploaded restaurant menu into an
AI-generated audit report, shows it on screen, emails it to the visitor, and
stores the contact as a lead.

Built as a lead-generation tool for food & beverage consultants: the visitor
gets something genuinely useful in under a minute, you get the contact.

## Install

1. Upload the `menu-audit` folder to `wp-content/plugins/` (or install the zip
   from **Plugins → Add New → Upload**).
2. Activate it.
3. Go to **Menu Audit → Settings**, choose your AI provider and paste your API key.
4. Put `[menu_audit]` on any page, Gutenberg block or Elementor widget.

## Shortcode

```
[menu_audit]
[menu_audit title="Free menu review" subtitle="Takes 60 seconds." button="Analyse my menu"]
```

## How it works

1. The visitor pastes their menu, or uploads a PDF, Word file, text file or a
   photo of it, and fills in name / business / email.
2. The submission is saved immediately and the AI call runs in the background,
   so nothing hangs on a slow request or a PHP timeout.
3. The report renders on the page as soon as it is ready, and is emailed at the
   same time along with a permanent link to a printable version.
4. The lead is stored with the menu, the scores and the full report, and can be
   exported to CSV or pushed to a webhook.

## What the audit covers

Menu differentiation · recipe and product uniqueness · pricing opportunities ·
menu structure · high-margin potential · customer appeal · competitive
positioning · room for improvement — each scored 1–10 with a written
justification, followed by 3–5 actions the owner can take this week.

The full prompt is editable in the settings screen, so the criteria and tone can
be tuned without touching any code.

## File handling

| Format | How it is read |
| --- | --- |
| `.txt` `.md` `.csv` | Read directly |
| `.docx` | Unpacked and read from `word/document.xml` |
| `.doc` `.rtf` | Readable text extracted |
| `.pdf` | Text layer decoded; scans fall back to the vision model |
| `.jpg` `.png` `.webp` `.gif` | Read by the vision model |

Uploads are capped at 8 MB and validated by real file type, not extension.

## Providers

| Provider | Models |
| --- | --- |
| Anthropic | Claude Opus 5, Claude Sonnet 5, Claude Haiku 4.5 |
| OpenAI | GPT-4.1, GPT-4.1 mini, GPT-4o |

Both use structured outputs, so the report always parses and always renders in
the same layout — no half-broken or free-text reports.

The API key is yours and stays in your own database; usage is billed to you
directly by the provider.

## Notes

- Set up SMTP (Brevo, SendGrid, Mailgun, or the host's own service). Default
  WordPress mail lands in spam far too often for a lead-gen tool.
- Anti-spam: honeypot field, nonce checks, and a per-IP hourly limit you can set
  or disable in the settings.
- Requires WordPress 5.6+ and PHP 7.4+.

## Licence

GPL-2.0-or-later.
