# Apache `mod_xsendfile` - offload `var/uploads/` to Apache

In production, Aurora stores every uploaded/generated file under
`var/uploads/` and serves them exclusively through Symfony controllers
(see `apache_xsendfile.md` here and CLAUDE.md §5bis in aurora-core). For high traffic, you
don't want PHP-FPM to stream the bytes itself - `mod_xsendfile` lets
PHP return *just the X-Sendfile header*, and Apache reads the file
directly from disk.

`BinaryFileResponse::trustXSendfileTypeHeader()` is already enabled at
boot via `XSendfileBootSubscriber`. There's nothing to change in PHP;
the controller-side flow is:

1. Request hits Symfony.
2. Auth + path-traversal guard run in PHP (microseconds).
3. `BinaryFileResponse` includes the `X-Sendfile` header pointing at
   the absolute path under `var/uploads/`.
4. Apache (with `mod_xsendfile` enabled) sees the header, drops the
   PHP body, and `sendfile()`s the file to the client at full speed.

Dev / staging without the module → Symfony falls back to `readfile()`
automatically; functionally identical, just slower.

## Install + enable

```bash
sudo apt install libapache2-mod-xsendfile
sudo a2enmod xsendfile
```

## VHost configuration

```apache
<VirtualHost *:443>
    ServerName aurora.example.com
    DocumentRoot /var/www/aurora/public

    # mod_xsendfile activation. XSendFilePath MUST list every
    # directory the application might point at - `var/uploads/`
    # is the only one for Aurora.
    XSendFile On
    XSendFilePath /var/www/aurora/var/uploads

    # Make sure Apache NEVER serves /var/uploads/ directly. Nothing
    # outside /public should be web-accessible, but make it explicit.
    <Directory /var/www/aurora/var/uploads>
        Require all denied
    </Directory>

    # Standard Symfony rewrites under /public - unchanged.
    <Directory /var/www/aurora/public>
        AllowOverride None
        Require all granted
        FallbackResource /index.php
    </Directory>
</VirtualHost>
```

## Sanity-check

```bash
# In a logged-in session, fetch any uploaded asset:
curl -v https://aurora.example.com/uploads/media/2026/01/test.jpg 2>&1 | grep -i 'x-sendfile\|content-length'
```

If `mod_xsendfile` is active you should see no `X-Sendfile` header in
the response (Apache strips it after acting on it) but the body bytes
should still arrive. Comparing `time curl …` before/after `a2enmod
xsendfile` is the easiest way to confirm the optimisation kicks in on
large files.

## Local dev (no module needed)

Nothing to do. Symfony's `BinaryFileResponse` calls `readfile()` and
the catch-all serve route returns the file via PHP. Sufficient for
the volumes a single developer hits.
