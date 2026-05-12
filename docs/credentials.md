# Fabric (fabric.so) credential setup

The canonical walkthrough lives in [`README.md` §Credential setup](../README.md#credential-setup-junior-proof-step-by-step).

## Cheatsheet

1. Sign in to <https://fabric.so>.
2. Open <https://developers.fabric.so> → **"Generate API Key"** → label it `AskMyDocs ingest`.
3. Pick **Personal API Key** (single-tenant) or **Developer API Key** (multi-tenant — also note the Workspace ID).
4. Copy the key value (displays once).
5. Write to `.env`:
    - `CONNECTOR_FABRIC_API_KEY=<your-api-key>`
    - `CONNECTOR_FABRIC_WORKSPACE_ID=<workspace-id>` *(only for Developer API Keys)*
6. Verify:
    ```bash
    curl -s https://api.fabric.so/v2/users/me \
      -H "X-Api-Key: <your-key>" \
      -H "Accept: application/json"
    ```
7. Install via AskMyDocs admin UI → Settings → Connectors → Fabric → Install.

OAuth2 is "coming soon" upstream — when Fabric ships it, flip `CONNECTOR_FABRIC_OAUTH_ENABLED=true` to activate the OAuth path.
