const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');
const dirs = [root, path.join(root, 'admin'), path.join(root, 'client'), path.join(root, 'includes')];
const seen = new Set();
let changed = 0;

function strip(content) {
  let n = content;
  // .php before PHP echo in attributes: href="/path.php<?=
  n = n.replace(/(\b(?:href|action|formaction)\s*=\s*["'])(\/(?!api\/)[^"'<]*?)\.php(<\?=)/gi,
    '$1$2$3');
  // .php before PHP echo in quoted strings in arrays: '/client/foo.php',
  n = n.replace(/(["'])(\/(?!api\/)(?:admin|client)\/[a-zA-Z0-9_/-]*?)\.php(["',])/g,
    '$1$2$3');
  // hash anchors: /page.php#section
  n = n.replace(/(\/(?!api\/)[a-zA-Z0-9_/-]+)\.php(#)/g, '$1$2');
  // form action index.php#contact
  n = n.replace(/(\/(?!api\/)[a-zA-Z0-9_/-]*)\.php(#)/g, '$1$2');
  return n;
}

for (const d of dirs) {
  if (!fs.existsSync(d)) continue;
  const stack = [d];
  while (stack.length) {
    const dir = stack.pop();
    for (const ent of fs.readdirSync(dir, { withFileTypes: true })) {
      const full = path.join(dir, ent.name);
      if (ent.isDirectory()) {
        if (full.includes(`${path.sep}api${path.sep}`)) continue;
        stack.push(full);
        continue;
      }
      if (!ent.name.endsWith('.php')) continue;
      const norm = path.normalize(full);
      if (seen.has(norm)) continue;
      seen.add(norm);
      const content = fs.readFileSync(norm, 'utf8');
      const updated = strip(content);
      if (updated !== content) {
        fs.writeFileSync(norm, updated, 'utf8');
        changed++;
        console.log(path.relative(root, norm));
      }
    }
  }
}

console.log(`Done. ${changed} files updated.`);
