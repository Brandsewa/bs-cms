const fs = require('fs');
const p = 'core/src/Install/InstallerController.php';
let s = fs.readFileSync(p, 'utf8');

function readFrag(name) {
  return fs.readFileSync('.migration/' + name, 'utf8').replace(/\r\n/g, '\n').trimEnd();
}

// Replace a span [startMarker .. endMarker] (inclusive) with replacement.
function splice(src, startMarker, endMarker, replacement, label) {
  const a = src.indexOf(startMarker);
  if (a === -1) throw new Error('start marker missing: ' + label);
  const b = src.indexOf(endMarker, a);
  if (b === -1) throw new Error('end marker missing: ' + label);
  return src.slice(0, a) + replacement + src.slice(b + endMarker.length);
}

// 1. Requirements render
s = splice(
  s,
  "$html = '<!DOCTYPE html><html><head><meta charset=\"utf-8\"><title>BS CMS Web Installer</title>';",
  'return $this->htmlResponse(200, $html);',
  readFrag('frag-req.php') + '\n',
  'requirements',
);

// 2. Security violation (processDatabase)
const secStart = "            $errorHtml = '<h1>Security Violation: Private Files Exposed</h1>';";
const secEnd = '            return $this->htmlResponse(403, $errorHtml);';
const secSpanStart = s.indexOf(secStart);
if (secSpanStart === -1) throw new Error('sec marker missing');
const secSpanEnd = s.indexOf(secEnd, secSpanStart);
if (secSpanEnd === -1) throw new Error('sec end marker missing');
const secReplacement = [
  '            $exposedList = \'<ul>\';',
  "            foreach ($probeResult['exposed'] as $exposedPath) {",
  "                $exposedList .= '<li><code>' . htmlspecialchars($exposedPath) . '</code></li>';",
  '            }',
  "            $exposedList .= '</ul>';",
  "            return $this->htmlResponse(403, InstallerViews::errorPage(",
  "                'Private Files Exposed',",
  "                '<p>The automated private-path probe discovered that private files are accessible via HTTP. Installation cannot proceed until private directories are protected.</p>' . $exposedList . '<p>Point your document root to <code>public/</code> (Mode A) or apply the Mode B hardened <code>.htaccess</code>.</p>',",
  '                403',
  '            ));',
].join('\n');
s = s.slice(0, secSpanStart) + secReplacement + s.slice(secSpanEnd + secEnd.length);

// 3. Database connection failed
s = s.replace(
  'return $this->htmlResponse(400, "<h1>Database Connection Failed</h1><p>{$msg}</p><p><a href=\\"/install\\">Back</a></p>");'.replace('\\"', '"'),
  "return $this->htmlResponse(400, InstallerViews::errorPage('Database Connection Failed', '<p>' . $msg . '</p>', 400));",
);

// 4. Owner render (step 3)
s = splice(
  s,
  "$html = '<!DOCTYPE html><html><head><meta charset=\"utf-8\"><title>Create Owner Account - BS CMS</title>';",
  'return $this->htmlResponse(200, $html);',
  readFrag('frag-owner.php') + '\n',
  'owner',
);

// 5. Execute probe fail
s = s.replace(
  "return $this->htmlResponse(403, '<h1>Security Probe Failed</h1><p>Private paths are exposed via HTTP. Installation aborted.</p>');",
  "return $this->htmlResponse(403, InstallerViews::errorPage('Security Probe Failed', '<p>Private paths are exposed via HTTP. Installation aborted.</p>', 403));",
);

// 6. Finish render
s = splice(
  s,
  "$html = '<!DOCTYPE html><html><head><meta charset=\"utf-8\"><title>Installation Complete</title></head><body>';",
  'return $this->htmlResponse(200, $html);',
  readFrag('frag-finish.php') + '\n',
  'finish',
);

// 7. already-installed 404 themed
s = s.replace(
  "return $this->htmlResponse(404, '<h1>404 Not Found</h1><p>BS CMS is already installed.</p>');",
  "return $this->htmlResponse(404, InstallerViews::errorPage('Already Installed', '<p>BS CMS is already installed on this host.</p><p>You can access the <a style=\\'color:var(--primary)\\' href=\\'/admin\\'>admin dashboard</a>.</p>', 404, '/admin', '4'));",
);

fs.writeFileSync(p, s);
console.log('patched. views calls:', (s.match(/InstallerViews::/g) || []).length);
