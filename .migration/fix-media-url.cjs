const fs = require("fs");
const p = "core/src/Http/Controllers/PublicEntriesController.php";
let s = fs.readFileSync(p, "utf8");
const before = s;
s = s.replace("SELECT `url` FROM ", "SELECT `storage_path` FROM ");
s = s.replace("{}media` WHERE `id`", "{}media` WHERE `id`");
s = s.replace("return ['url'] ?? null;", "return isset(['storage_path']) ? '/' . ltrim((string) ['storage_path'], '/') : null;");
fs.writeFileSync(p, s);
console.log("changed:", s !== before, "| has storage_path:", s.includes("storage_path"));