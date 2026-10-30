const fs = require('fs');
const p = 'core/src/Install/InstallerController.php';
let s = fs.readFileSync(p, 'utf8');

const tryAnchor = '        // 3. Generate app key and write to storage/keys/app.key';
if (!s.includes(tryAnchor)) { console.log('MISS try anchor'); process.exit(1); }

// The finish render ends with:
//   return $this->htmlResponse(200, InstallerViews::shell(4, 'Installation Complete', $successContent, [
//       'back' => '/admin',
//       'bodyEnd' => $bodyEnd,
//   ]));
// Wrap from the tryAnchor through that return in try {}, then add catch.
const finishReturnEnd = "        ]));\n";
const a = s.indexOf(tryAnchor);
if (a === -1) { console.log('MISS a'); process.exit(1); }
const retIdx = s.indexOf("return $this->htmlResponse(200, InstallerViews::shell(4,", a);
if (retIdx === -1) { console.log('MISS finish return'); process.exit(1); }
const retEnd = s.indexOf(finishReturnEnd, retIdx);
if (retEnd === -1) { console.log('MISS return end'); process.exit(1); }
const endPos = retEnd + finishReturnEnd.length;

const before = s.slice(0, a);
const body = s.slice(a, endPos);
const after = s.slice(endPos);

const indented = body.split('\n').map((l) => (l.trim() === '' ? l : '    ' + l)).join('\n');

const catchBlock = [
  '        } catch (Throwable $e) {',
  "            $msg = htmlspecialchars($e->getMessage());",
  "            $hint = (strpos($e->getMessage(), '1062') !== false)",
  "                ? '<p>The owner email or data already exists from a previous installation. Reuse the original owner account or choose a different email address.</p>'",
  "                : '<p>If a previous installation attempt left partial data, restore the database (or drop its tables) and run the installer again.</p>';",
  "            return $this->htmlResponse(500, InstallerViews::errorPage(",
  "                'Installation Failed',",
  "                '<p>' . $msg . '</p>' . $hint,",
  '                500,',
  "                '/install',",
  "                '3',",
  '            ));',
  '        }',
].join('\n');

s = before + '        try {\n' + indented + '\n' + catchBlock + after;
fs.writeFileSync(p, s);
console.log('try/catch added');
