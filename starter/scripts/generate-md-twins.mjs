import fs from 'node:fs';
import path from 'node:path';

const distDir = path.resolve('dist');

function walk(dir) {
  const files = fs.readdirSync(dir);
  for (const file of files) {
    const fullPath = path.join(dir, file);
    const stat = fs.statSync(fullPath);
    if (stat.isDirectory()) {
      walk(fullPath);
    } else if (file === 'index.html') {
      const mdPath = path.join(dir, 'index.md');
      const htmlContent = fs.readFileSync(fullPath, 'utf8');
      
      // Simple extractor for demonstration / spike twin generation
      const titleMatch = htmlContent.match(/<title>([^<]*)<\/title>/i);
      const title = titleMatch ? titleMatch[1] : 'Untitled';
      
      const bodyMatch = htmlContent.match(/<article>([\s\S]*?)<\/article>/i) || htmlContent.match(/<main>([\s\S]*?)<\/main>/i);
      const rawText = bodyMatch 
        ? bodyMatch[1].replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim()
        : 'Page Content';

      const markdownTwin = `# ${title}\n\n${rawText}\n`;
      fs.writeFileSync(mdPath, markdownTwin, 'utf8');
    }
  }
}

if (fs.existsSync(distDir)) {
  walk(distDir);
  console.log('Generated .md twins for all HTML pages in dist/');
}
