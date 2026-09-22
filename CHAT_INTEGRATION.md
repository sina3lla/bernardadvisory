# FeelY chat integration

`chat.html` is the host page. It owns the Bernard Advisory header and embeds the
isolated FeelY application from `feely/chat.html`.

## Local preview

Start the FeelY backend on port 8000, then run this from the Bernard website
folder:

```powershell
powershell -ExecutionPolicy Bypass -File .\serve_local.ps1
```

Open `http://127.0.0.1:5501/chat.html`. Using the local HTTP address is
recommended because CAPTCHA providers may not load on pages opened directly
from disk with a `file://` URL.

To use another site header, replace only the marked **Host header** block in
`chat.html`. The iframe boundary keeps the host CSS and FeelY CSS from changing
each other.

## API URL

The Bernard host page uses the deployed FeelY API. Set the `feely-api-base`
meta value near the top of `chat.html` to the public API URL:

```html
<meta name="feely-api-base" content="https://api.feel-y.com/api/v1" />
```

For a temporary local-backend test, append
`?api_base=http://127.0.0.1:8000/api/v1` to the Bernard chat URL. This overrides
the deployed address for that browser visit without editing the host page.

Add the website origin to `CORS_ORIGINS` in the FeelY backend environment.
Local development also permits the `null` origin as a fallback for pages opened
directly from disk. Remove `null` from production CORS configuration; the
backend's production security check enforces this.

Use `chat.html?mode=register` for links that should open the account-creation
tab directly. The normal `chat.html` URL opens returning-user login.

## Updating the embedded app

The original standalone development version stays in
`../FeelY-Backend/website/chat.html`. After changing it, run:

```powershell
powershell -ExecutionPolicy Bypass -File .\sync_feely.ps1
```

This refreshes only the files under `feely/`; it does not change the host header.
