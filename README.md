# BrightEdge AI — Drupal AI Assistant & Demo Lead Capture (PoC)

A lightweight proof-of-concept Drupal 11 module that adds two capabilities to a
Drupal site:

1. **An AI-powered chat assistant** — a site-wide chat widget backed by a secure,
   server-side LLM integration (Anthropic Claude). The API key never reaches the
   browser.
2. **A "Request a Demo" lead-capture flow** — a validated form that stores leads
   as a typed custom entity, modelled specifically for a clean hand-off to
   Salesforce.

This is a PoC, not a production build. The goal is to prove the approach works
and to show how the pieces would scale, not to ship a finished feature.

---

## Table of contents

- [What's included](#whats-included)
- [Architecture overview](#architecture-overview)
- [Setup & installation](#setup--installation)
- [The one-page write-up](#the-one-page-write-up)
  - [AI tools used during development](#1-ai-tools-used-during-development)
  - [Key technical decisions](#2-key-technical-decisions)
  - [Salesforce CRM hand-off design](#3-salesforce-crm-hand-off-design)
  - [What I'd improve with more time](#4-what-id-improve-with-more-time)

---

## What's included

| Component | File(s) | Purpose |
|---|---|---|
| AI service | `src/Service/AiClient.php` | Server-side LLM client. Reads key from env, calls Claude, handles errors. |
| Chat endpoint | `src/Controller/ChatController.php` | POST `/api/brightedge-ai/chat`. Rate-limits, validates, delegates to the service. |
| Chat widget | `css/chat.css`, `js/chat.js` | Dependency-free floating chat UI attached site-wide. |
| Lead entity | `src/Entity/DemoRequest.php` | Custom content entity. Typed fields + Salesforce sync state. |
| Lead form | `src/Form/DemoRequestForm.php` | `/request-demo`. Validates (incl. work-email check), persists the entity. |
| Admin listing | `src/DemoRequestListBuilder.php` | `/admin/content/demo-requests`. Ops view of incoming leads + sync status. |
| Wiring | `*.routing.yml`, `*.services.yml`, `*.libraries.yml`, `*.module`, `*.links.menu.yml` | Routes, DI, asset library, page attachment, admin menu. |

---

## Architecture overview

```
                          BROWSER
            ┌──────────────────────────────────┐
            │  Chat widget (vanilla JS)        │
            │  Request Demo form               │
            └───────────────┬──────────────────┘
                            │  same-origin requests only
                            │  (no API key ever in the browser)
                            ▼
                          DRUPAL
   ┌────────────────────────────────────────────────────────┐
   │  ChatController          DemoRequestForm               │
   │   • flood / rate limit    • input validation           │
   │   • input validation      • work-email check           │
   │        │                       │                       │
   │        ▼                       ▼                       │
   │   AiClient (service)     DemoRequest entity            │
   │   • key from ENV          • typed fields               │
   │   • system prompt         • sf_sync_status = 'pending' │
   │   • Guzzle HTTP           • sf_lead_id                 │
   │        │                       │                       │
   └────────┼───────────────────────┼───────────────────────┘
            │                       │
            ▼                       ▼ (designed, not built)
     Anthropic Claude API     Queue → Salesforce sync worker
                                    → Salesforce Lead
```

Key principle: **the controller/form layer is thin; the service layer holds the
logic.** This keeps the AI provider swappable and the lead model reusable.

---

## Setup & installation

> Built and tested with DDEV + Docker on Drupal 11.

```bash
# 1. Start the environment
ddev start

# 2. Install Composer dependencies (Drupal core, Drush)
ddev composer install

# 3. Provide the LLM API key (NEVER committed — see Security note below).
#    Create .ddev/.env.web with:
#      ANTHROPIC_API_KEY=sk-ant-xxxxx
#      AI_PROVIDER=claude
#      AI_MODEL=claude-sonnet-4-20250514
ddev restart

# 4. Enable the module
ddev drush en brightedge_ai -y

# 5. Install the custom entity schema
ddev drush php:eval "\Drupal::entityDefinitionUpdateManager()->installEntityType(\Drupal::entityTypeManager()->getDefinition('brightedge_demo_request'));"

# 6. Clear cache and open
ddev drush cr
ddev launch
```

Then:
- Chat: click the chat button on any page.
- Demo form: visit `/request-demo`.
- Admin: visit `/admin/content/demo-requests`.

### Security note on the API key

The key is read from the `ANTHROPIC_API_KEY` environment variable inside
`AiClient`. It is never written to code, the database, or any browser-served
asset. Locally it lives in `.ddev/.env.web`, which is gitignored. In production
the same `getenv()` pattern works with server environment variables or, better,
Drupal's Key module backed by a secrets manager.

---

## The one-page write-up

### 1. AI tools used during development

I used **Claude (Anthropic)** as a working partner throughout, in two distinct
modes:

**As an analyst before writing any code.** I used Claude to pull apart the
assignment itself — clarifying what "lightweight PoC" actually means versus a
production build, separating the *build* parts (chat, lead capture) from the
*design* part (the Salesforce hand-off), and pressure-testing scope decisions.
For example, I worked through the trade-offs between Form API, Webform, and a
custom entity before committing to one, and I rehearsed how I'd defend
deliberately leaving out things like RAG, conversation memory, and authentication.

**As a pair programmer (during the build).** Claude helped write boilerplate
(routing, services YAML, entity annotations), explained Drupal-11-specific
patterns, and helped me debug real errors quickly — for instance, a PHP property
collision where my form redeclared a property that Drupal's `FormBase` already
owns. I read the errors, understood the cause (strict property contracts between
parent and child classes), and applied the fix — using FormBase's inherited
methods rather than redeclaring.

**I made the design decisions** — to go with provider abstraction, custom entity
over webform, where to put rate limiting, what the system prompt should enforce.
Claude helped me understand the possible solutions and accelerated the work; the
judgment was mine.

The product itself is also AI-powered: the chat assistant calls Claude
server-side with a purpose-built system prompt that gives it a BrightEdge persona,
keeps it on-topic, and directs high-intent visitors toward the demo form.

### 2. Key technical decisions

**Service layer with provider abstraction.** All LLM logic lives in `AiClient`,
not in the controller. The provider and model are environment-driven, so swapping
Claude → Gemini → OpenAI in production is a config change, not a rewrite. The
controller stays simple and testable.

**Server-side key handling.** The API key is read from an environment variable
and never leaves the server. The browser only talks to a Drupal endpoint. This
keeps the API key safe.

**Custom entity for leads (not a webform).** The lead is the data contract for the
Salesforce hand-off, so it deserves a typed, queryable model — not a serialized
submission blob. The entity carries `sf_sync_status` and `sf_lead_id` fields,
giving future functionality such as the sync worker a clean state machine to
operate against. This cost a little more code, but it makes the integration much
easier.

**Rate limiting via Drupal's Flood API.** Both the chat endpoint (20/hour/IP) and
the demo form (5/hour/IP) are flood-protected using the Flood API, to avoid wasted
API credits. The chat endpoint registers the attempt *before* the upstream call,
so failed/abusive requests still count — protecting the API budget.

**Error handling.** The service catches HTTP and JSON errors, logs the real cause
internally, and returns a generic, user-safe message. Internal details never leak
to the browser. Timeouts are used so a slow API never hangs the user.

**Work-email validation on the lead form.** Personal email domains are rejected,
since this is a B2B funnel and only genuine business users should reach the CRM.

### 3. Salesforce CRM hand-off design

> This part is a design, like the brief asked — a CRM admin owns the Salesforce
> side. My job is to make sure the Drupal side feeds it cleanly.

**In plain terms:** when someone asks for a demo, we save their details in Drupal
straight away and show them a thank-you — instantly, no waiting. Sending that lead
over to Salesforce happens quietly in the background a moment later. If Salesforce
happens to be slow or down, the visitor never notices, and no lead is ever lost.
Each lead carries a little status label — *pending*, *synced*, or *failed* — so the
team can always see at a glance what made it into Salesforce.

That's the whole idea. The rest of this section is the technical detail behind it,
for whoever builds the Salesforce side.

---

**The core principle: don't make the visitor wait on Salesforce.** The submission
should always succeed and feel instant, even if Salesforce is slow or down. So
Drupal saves the lead locally first, then syncs in the background.

Here's the flow:

```
1. Visitor submits /request-demo
      → DemoRequest saved locally, sf_sync_status = 'pending'
      → success message shown immediately (never blocked on Salesforce)

2. Saving the lead enqueues a job (Drupal Queue API):
      queue:   'brightedge_sf_lead_sync'
      payload: { submission_id: <entity id> }

3. A cron-run QueueWorker handles each job:
      a. Load the DemoRequest.
      b. Map Drupal fields → Salesforce Lead fields (table below).
      c. POST to Salesforce: /services/data/vXX.0/sobjects/Lead
      d. Success → sf_sync_status = 'synced', save the returned sf_lead_id.
      e. Failure → bump a retry counter, re-queue with backoff; after a few
         tries mark 'failed' and alert.

4. Admins watch it all at /admin/content/demo-requests (status + Lead ID columns).
```

The field mapping — basically the data contract between the two systems:

| Drupal (DemoRequest) | Salesforce (Lead) | Notes |
|---|---|---|
| `name` | `FirstName` + `LastName` | Split on the first space; all of it to LastName if there's no space. |
| `email` | `Email` | Main match key. |
| `company` | `Company` | Salesforce requires it on a Lead. |
| (constant) | `LeadSource` | Something like `"Website — AI Assistant"`. |
| `uuid` | external id field | For idempotency — see below. |

**Auth:** OAuth 2.0, server-to-server (client credentials or JWT bearer). Creds
live in Drupal's Key module or env vars, never in code. Token refresh sits inside
a dedicated Salesforce client service — same idea as how `AiClient` keeps the
Anthropic stuff in one place.

**Avoiding duplicates:** the lead's `uuid` goes to Salesforce as an external ID.
If a retry fires after Salesforce already made the Lead, an upsert keyed on that
ID means no duplicate gets created.

**Why this feeds Salesforce cleanly:**
- The queue means Drupal never depends on Salesforce being up.
- Retries with backoff handle blips without dropping leads.
- The external-ID upsert kills duplicate Leads.
- The typed entity gives the CRM admin a clear, documented payload to build
  against — they own their side, I own a clean, observable Drupal side.
- Sync state is visible and queryable, so monitoring and manual fixes are easy.

In the code, the exact spot where this integration starts is marked with a comment
in `DemoRequestForm::submitForm()`, right where the
`\Drupal::queue(...)->createItem()` call would go.

### 4. What I'd improve with more time

Each of these is a deliberate next step, not a gap I missed. For each, the first
line is *why it matters*; the detail after it is *how I'd do it*.

- **Give the chat a memory.**
  Right now each question is answered on its own — the assistant doesn't remember
  what you asked a moment ago. Letting it hold a conversation makes it feel far
  more natural and helpful.
  *How:* store the recent exchange server-side in private tempstore, with a
  token-window cap and a clear retention policy.

- **Let the assistant answer from BrightEdge's real content (RAG).**
  Today it answers from a fixed personality prompt. Feeding it BrightEdge's actual
  pages, blog, and docs would let it give specific, accurate answers about real
  products and content instead of staying general.
  *How:* embed the site content into a vector store (likely pgvector on a
  Postgres-compatible stack) and retrieve the relevant bits per question.

- **Actually build the Salesforce sync, not just design it.**
  The hand-off is designed and the integration point is marked in code — the next
  step is wiring it to a real Salesforce sandbox so leads flow end-to-end.
  *How:* implement the QueueWorker plugin, a dedicated Salesforce client service,
  and an admin "resync" action for failed leads.

- **Stream the chat replies.**
  Instead of waiting for the full answer, the assistant would start typing
  immediately — the same instant feel people expect from modern AI chats.
  *How:* stream tokens from the API through the endpoint to the browser.

- **Add automated tests.**
  So future changes can't quietly break the AI call or the lead-capture flow.
  *How:* unit tests for `AiClient` with mocked HTTP, and a kernel test covering
  the form submission + entity save path.

- **Tighten security further.**
  A couple of standard hardening steps for a public endpoint.
  *How:* add a CSRF token to the chat endpoint and a stricter
  Content-Security-Policy.

- **Make the assistant tunable without code.**
  So a marketer or admin could adjust the assistant's tone, instructions, or model
  without a developer — useful for iterating on messaging.
  *How:* expose the system prompt and model choice on an admin settings form
  (the API key stays environment-only, never editable in the UI).

- **Accessibility and multi-language support.**
  So the chat works well for everyone — screen-reader users, keyboard-only users,
  and non-English visitors.
  *How:* add ARIA live regions, full keyboard flows, and translatable strings
  to the widget.

---

## A note on scope

Several capabilities were deliberately left out as appropriate for a one-day PoC:
RAG, conversation history, user authentication on the chat, and a live Salesforce
integration. Each one is a real design decision with product, legal, and
infrastructure implications. The PoC's job, as I saw it, was to prove the core
round-trips work and to show how the rest would scale. The "what I'd improve"
section above is the roadmap.
