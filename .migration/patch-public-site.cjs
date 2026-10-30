const fs = require('fs');
const p = 'core/src/Http/Controllers/PublicEntriesController.php';
let s = fs.readFileSync(p, 'utf8');

const insert = `        // 3. Resolve branding tokens (singleton) with media URLs
        $branding = null;
        try {
            $stmtBrand = $this->connection->execute("SELECT \`data\` FROM \`{$prefix}c_branding\` WHERE \`deleted_at\` IS NULL LIMIT 1");
            $brandRow = $stmtBrand->fetch();
            if ($brandRow && !empty($brandRow['data'])) {
                $brandData = is_string($brandRow['data']) ? json_decode($brandRow['data'], true) : $brandRow['data'];
                if (is_array($brandData)) {
                    $resolveMedia = function ($mediaId) use ($prefix) {
                        $mediaId = trim((string) $mediaId);
                        if ($mediaId === '') {
                            return null;
                        }
                        try {
                            $stmtM = $this->connection->execute(
                                "SELECT \`url\` FROM \`{$prefix}media\` WHERE \`id\` = ? AND \`deleted_at\` IS NULL LIMIT 1",
                                [$mediaId]
                            );
                            $m = $stmtM->fetch();
                            return $m['url'] ?? null;
                        } catch (\\Throwable) {
                            return null;
                        }
                    };
                    $branding = [
                        'logo_url' => $resolveMedia($brandData['logo'] ?? null),
                        'logo_dark_url' => $resolveMedia($brandData['logo_dark'] ?? null),
                        'favicon_url' => $resolveMedia($brandData['favicon'] ?? null),
                        'logo_alt' => (string) ($brandData['logo_alt'] ?? ''),
                        'color_primary' => (string) ($brandData['color_primary'] ?? ''),
                        'color_secondary' => (string) ($brandData['color_secondary'] ?? ''),
                        'color_accent' => (string) ($brandData['color_accent'] ?? ''),
                        'color_background' => (string) ($brandData['color_background'] ?? ''),
                        'color_surface' => (string) ($brandData['color_surface'] ?? ''),
                        'color_text' => (string) ($brandData['color_text'] ?? ''),
                        'font_heading' => (string) ($brandData['font_heading'] ?? ''),
                        'font_body' => (string) ($brandData['font_body'] ?? ''),
                        'radius' => (string) ($brandData['radius'] ?? ''),
                    ];
                }
            }
        } catch (\\Throwable) {
            // Branding singleton not present — omit gracefully.
        }

`;

const marker = `        $body = [
            'site_name' => $siteName,`;
const idx = s.indexOf(marker);
if (idx === -1) { console.log('MARKER MISSING'); process.exit(1); }
s = s.slice(0, idx) + insert + s.slice(idx);

const tailOld = `            'navigation' => $navItems,
        ];

        return $this->json($body);`;
const tailNew = `            'navigation' => $navItems,
        ];
        if ($branding !== null) {
            $body['branding'] = $branding;
        }

        return $this->json($body);`;
if (!s.includes(tailOld)) { console.log('TAIL MISSING'); process.exit(1); }
s = s.replace(tailOld, tailNew);

fs.writeFileSync(p, s);
console.log('patched:', s.includes('c_branding') && s.includes("$body['branding']"));
