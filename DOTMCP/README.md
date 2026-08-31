# DOTMCP

An MCP server that lets authorised staff query the support desk from Claude,
without exporting data or building a dashboard for every new question.

Read-only. Nothing here can reply to a customer, change a ticket, or alter any
setting.

## How a user connects

1. An administrator enables MCP for the user at **Manage → MCP** and sets an
   access level.
2. The user adds `https://support.dotrust.org/mcp` as a custom connector in
   Claude.
3. Claude sends them to FreeScout to sign in and approve. The resulting token
   is theirs alone.

Access is **not** implied by being an administrator — it must be granted
explicitly, and users without it cannot see that the module exists.

## Access levels

Each user is granted one. The level bounds what every tool may return, so a
low-level user cannot reach customer data by asking a different question.

| Level | Sees |
|---|---|
| `low` | Aggregate figures only |
| `medium` | Conversations, with customer details hidden |
| `high` | Everything, including customer names and addresses |

## Tools

`conversation_volume`, `volume_trend`, `response_times`, `agent_workload`,
`triage_summary`, `triage_accuracy`, `noise_summary`, `topic_summary`,
`list_conversations`, `search_conversations`, `get_conversation`.

Each declares its own minimum access level; tools above a user's level are not
offered to them.

## Settings

| Variable | Default | Description |
|---|---|---|
| `MCP_ENABLED` | `false` | Master switch |
| `MCP_KEY_PATH` | `storage/app/mcp-keys` | OAuth signing keypair |
| `MCP_RATE_LIMIT` | `60` | Requests per minute per token |
| `MCP_MAX_PAGE` | `100` | Hard cap on rows any tool may return |

## Notes

- **The signing keypair lives outside the web root and outside the module
  directory**, so neither nginx nor a module reinstall can expose or destroy
  it. Replacing it silently invalidates every issued token.
- **The rate limit is not primarily anti-abuse.** With 8 PHP-FPM workers, an
  agent retrying in a loop could exhaust the pool and take the whole help desk
  down.
- **Revoking access revokes tokens immediately.** Disabling a user at
  Manage → MCP revokes their active tokens rather than waiting for expiry.
- **This module bundles its own copy of `league/oauth2-server`.** Security
  patches need a `composer update` inside the module — it does not happen
  automatically, and `deploy.sh` does not run composer.

## DOTMCP is an OAuth *server*, DOTSSO is an OAuth *client*

Easy to confuse, since both say "OAuth". DOTMCP **issues** tokens — it is the
thing being authenticated to. DOTSSO **consumes** Google's tokens — it is the
thing authenticating against someone else. They are separate modules with
independent kill switches on purpose: switching off MCP must not switch off the
ability to sign in.
