        $cronEsc = htmlspecialchars($cronLine);
        $successContent = '<div class="success-hero">'
            . '<div class="big">&#10003;</div>'
            . '<p style="margin:0 0 6px;font-size:1.05rem;font-weight:600">BS CMS is installed and ready.</p>'
            . '<p style="margin:0;font-size:.88rem;color:var(--muted)">Owner account <strong style="color:var(--text)">' . htmlspecialchars($ownerEmail) . '</strong> created with role <strong>Owner</strong>.</p>'
            . '</div>'
            . '<p class="note" style="text-align:center">Email verification is deferred until SMTP is configured in Settings &rarr; Email.</p>'
            . '<h3 style="text-align:center">Automate background jobs</h3>'
            . '<p class="note" style="text-align:center">Add this line to your hosting cron schedule (run every minute):</p>'
            . '<pre id="cronline">' . $cronEsc . '</pre>'
            . '<p style="text-align:center"><button type="button" class="btn ghost" id="copycron">Copy cron command</button></p>';

        $bodyEnd = '<script>'
            . 'var b = document.getElementById("copycron");'
            . 'if (b) { b.addEventListener("click", function() {'
            . '  var line = document.getElementById("cronline").textContent;'
            . '  if (navigator.clipboard) { navigator.clipboard.writeText(line); b.textContent = "Copied!"; setTimeout(function(){ b.textContent = "Copy cron command"; }, 2000); }'
            . '}); }'
            . '</script>';

        return $this->htmlResponse(200, InstallerViews::shell(4, 'Installation Complete', $successContent, [
            'back' => '/admin',
            'bodyEnd' => $bodyEnd,
        ]));
