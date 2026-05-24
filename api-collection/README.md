# Bruno Collection — CV AI Job Platform

## Setup

1. Install [Bruno](https://www.usebruno.com/) (free, open-source)
2. Open Bruno → **Open Collection** → select the `bruno/` folder
3. Select an environment (top-right dropdown):
   - `production` → `https://joboffers-main-uj2ehj.free.laravel.cloud`
   - `local` → `http://localhost:8000`

## Typical flow

1. **Auth / Register** or **Auth / Login** — the `token` env var is set automatically via the post-response script
2. Use any authenticated request — the token is injected via `{{token}}`
3. **Jobs / Create Job Post** sets `{{job_id}}` automatically
4. **JobSeeker / Apply to Job** sets `{{application_id}}` automatically
5. **Employer / Send Direct Offer** sets `{{offer_id}}` automatically

## Variables

| Variable | Set by |
|---|---|
| `token` | Register or Login post-response script |
| `job_id` | Create Job Post post-response script |
| `application_id` | Apply to Job post-response script |
| `offer_id` | Send Direct Offer post-response script |
| `seeker_id` | Set manually from a Search Job Seekers response |
| `employer_user_id` | Set manually from List Employer Applications response |
| `company_id` | Set manually from List Companies response |

## CLI usage

```bash
# Run the full collection against production
npx @usebruno/cli run bruno/ --env production

# Run a single folder
npx @usebruno/cli run bruno/Auth --env production
```
