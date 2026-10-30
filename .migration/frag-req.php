        $content = '';
        $content .= '<ul class="checklist">';
        $phpPass = $checks['php_version']['status'] === 'pass';
        $content .= '<li><span class="st ' . ($phpPass ? 'pass' : 'fail') . '">' . ($phpPass ? '&#10003;' : '&#10007;') . '</span><span>PHP Version <strong style="color:var(--text)">' . htmlspecialchars($checks['php_version']['current']) . '</strong></span></li>';
        $storW = (bool) $checks['storage_writable']['writable'];
        $content .= '<li><span class="st ' . ($storW ? 'pass' : 'fail') . '">' . ($storW ? '&#10003;' : '&#10007;') . '</span><span>Storage writable <strong style="color:var(--text)">' . ($storW ? 'Yes' : 'No') . '</strong></span></li>';
        foreach ($checks['extensions'] as $ext => $res) {
            $loaded = (bool) $res['loaded'];
            $required = (bool) $res['required'];
            $cls = $loaded ? 'pass' : ($required ? 'fail' : 'warn');
            $mark = $loaded ? '&#10003;' : ($required ? '&#10007;' : '!') ;
            $statusText = $loaded ? 'Loaded' : ($required ? 'Missing (Required)' : 'Missing (Optional)');
            $content .= '<li><span class="st ' . $cls . '">' . $mark . '</span><span>Extension ' . htmlspecialchars($ext) . ' <span style="color:var(--muted)">' . htmlspecialchars($statusText) . '</span>';
            if ($res['hint'] !== null) {
                $content .= '<span class="hint">' . htmlspecialchars($res['hint']) . '</span>';
            }
            $content .= '</span></li>';
        }
        $content .= '</ul>';
        $content .= '<h3>CLI PHP Detection</h3><p style="font-size:.9rem;margin:4px 0 0">Detected PHP binary: <code>' . htmlspecialchars($cliPhp['path']) . '</code> (' . htmlspecialchars($cliPhp['type']) . ')</p>';
        if ($cliPhp['warning'] !== null) {
            $content .= '<p class="warn" style="font-size:.85rem">' . htmlspecialchars($cliPhp['warning']) . '</p>';
        }

        if (!$checks['can_install']) {
            $content .= '<div class="alert error"><strong>Requirements not met</strong><div class="alertbody">Please resolve the issues above, then refresh this page to continue the installation.</div></div>';
            return $this->htmlResponse(200, InstallerViews::shell(1, 'System Requirements', $content));
        }

        $form = '<form method="POST" action="/install/database">';
        $form .= '<input type="hidden" name="_csrf" value="' . htmlspecialchars($csrfToken) . '">';
        $form .= '<label>Site URL (Admin &amp; API Base)</label><input name="site_url" value="' . htmlspecialchars($defaultUrl) . '" required>';
        $form .= '<label>Database Host</label><input name="db_host" value="127.0.0.1" required>';
        $form .= '<label>Database Port</label><input name="db_port" value="3306" required>';
        $form .= '<label>Database Name</label><input name="db_name" placeholder="e.g. bs_cms" required>';
        $form .= '<label>Database User</label><input name="db_user" placeholder="e.g. bs_user" required>';
        $form .= '<label>Database Password</label><input type="password" name="db_password">';
        $form .= '<label>Table Prefix</label><input name="db_prefix" value="bs_" required>';
        $form .= '<label>CLI PHP Binary Override</label><input name="cli_php" value="' . htmlspecialchars($cliPhp['path']) . '">';
        $form .= '<div class="actions"><button type="submit" class="btn">Verify Database &amp; Continue &rarr;</button></div>';
        $form .= '</form>';
        $content .= '<h2>Site &amp; Database Configuration</h2>';
        $content .= $form;

        $bodyEnd = '<script>'
            . 'var f = document.querySelector("form");'
            . 'if (f) { f.addEventListener("submit", function() {'
            . '  var b = f.querySelector("button[type=submit]");'
            . '  if (b) { b.disabled = true; b.innerHTML = "<span class=spin></span> Verifying database connection..."; }'
            . '}); }'
            . '</script>';

        return $this->htmlResponse(200, InstallerViews::shell(2, 'Site & Database Configuration', $content, ['bodyEnd' => $bodyEnd]));
