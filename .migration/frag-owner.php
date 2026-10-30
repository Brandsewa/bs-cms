        $vpanel = '<div class="vpanel">'
            . '<div class="vrow"><span class="k">Server</span><span class="v">' . htmlspecialchars($conn->getServerType()) . ' ' . htmlspecialchars($conn->getServerVersion()) . '</span><span class="st pass">&#10003; Connected</span></div>'
            . '<div class="vrow"><span class="k">Database</span><span class="v">' . htmlspecialchars($dbname) . '</span></div>'
            . '<div class="vrow"><span class="k">Host / User</span><span class="v">' . htmlspecialchars($host) . ' / ' . htmlspecialchars($user) . '</span></div>'
            . '<div class="vrow"><span class="k">Site URL</span><span class="v">' . htmlspecialchars($siteUrl) . '</span></div>'
            . '<div class="vrow"><span class="k">Private Path Probe</span><span class="st pass">&#10003; PASSED</span></div>'
            . '</div>';

        $form = '<form method="POST" action="/install/execute">';
        $form .= '<input type="hidden" name="_csrf" value="' . htmlspecialchars($csrfToken) . '">';
        $form .= '<label>Owner Name</label><input name="owner_name" value="Administrator" required>';
        $form .= '<label>Owner Email</label><input type="email" name="owner_email" placeholder="owner@example.com" required>';
        $form .= '<label>Owner Password</label><input type="password" name="owner_password" id="owner_password" required minlength="12" maxlength="256">';
        $form .= '<div id="strength-meter" class="meter">Password policy: At least 12 characters. Common passwords are blocked.</div>';
        $form .= '<div class="actions"><button type="submit" class="btn">Run Migrations &amp; Finish Installation &rarr;</button></div>';
        $form .= '</form>';

        $bodyEnd = '<script>'
            . 'var pwd = document.getElementById("owner_password");'
            . 'var meter = document.getElementById("strength-meter");'
            . 'if (pwd && meter) {'
            . '  pwd.addEventListener("input", function() {'
            . '    var val = pwd.value;'
            . '    if (val.length === 0) {'
            . '      meter.textContent = "Password policy: At least 12 characters. Common passwords are blocked.";'
            . '      meter.style.color = "var(--muted)";'
            . '    } else if (val.length < 12) {'
            . '      meter.textContent = "Too short (" + val.length + "/12 characters)";'
            . '      meter.style.color = "var(--err)";'
            . '    } else {'
            . '      meter.textContent = "Length acceptable (" + val.length + " chars). Unique passphrase recommended.";'
            . '      meter.style.color = "var(--ok)";'
            . '    }'
            . '  });'
            . '}'
            . 'var f = document.querySelector("form");'
            . 'if (f) { f.addEventListener("submit", function() {'
            . '  var b = f.querySelector("button[type=submit]");'
            . '  if (b) { b.disabled = true; b.innerHTML = "<span class=spin></span> Running migrations & seeding..."; }'
            . '}); }'
            . '</script>';

        return $this->htmlResponse(200, InstallerViews::shell(3, 'Create Owner Account', $vpanel . '<h2>Owner Account</h2>' . $form, [
            'back' => 'javascript:history.back()',
            'bodyEnd' => $bodyEnd,
        ]));
