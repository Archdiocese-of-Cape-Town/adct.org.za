# ADR 0006: Approach to secondary channels (WhatsApp, Facebook, web)

- Status: Proposed. Each channel needs a research spike before it is built.
- Date: 2026-09-24

## Context
Parishes also communicate through WhatsApp groups, Facebook pages, websites and PDFs. The idea is to monitor these and ask the parish whether a found item should be published. The constraints:
- **WhatsApp**: there is no official API for reading groups. Unofficial clients (whatsapp-web.js, Baileys) go against WhatsApp's terms of service, risk getting the number banned, and need an always-on process, which shared hosting can't run. The WhatsApp Business Cloud API only handles 1:1 chats with a business number, not groups.
- **Facebook**: reading public Page posts through the Graph API needs a Meta app with "Page Public Content Access" review, or Page admin consent (a Page token from each parish). Scraping is fragile and against Meta's terms.
- **Websites/RSS/PDF URLs**: straightforward HTTP polling.

## Decision (direction)
- All channels use the same **source registry** (`official` vs `monitored`), checkpoints, batching and the "found on X, publish?" confirmation flow.
- **WhatsApp**: forwarding, not bots. Parish contacts (or a volunteer in the group) forward messages to `events@` or, later, to a WhatsApp Business number using the official Cloud API (1:1 messages, which is allowed). A forwarded message is attributed to its sender like any email.
- **Facebook**: first choice is asking parish Page admins to connect their Page once (OAuth, Page token) so the plugin can read their own posts. Fallback: parishes use Facebook's own tools to also email posts, or a manual "import this post URL" in the portal.
- **Web/RSS/PDF**: polled directly, a few sources per run.
- Each channel is marked fragile or unreliable in the registry, and failures show on the health dashboard. **Manual entry is always the fallback.**

## Consequences
- No terms-of-service violations and no always-on process.
- Coverage depends on parish cooperation (forwarding, connecting a Page). The reminder and monitoring features support this.
