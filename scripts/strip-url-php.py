import re
import os

root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
dirs = [root, os.path.join(root, "admin"), os.path.join(root, "client"), os.path.join(root, "includes")]
pat = re.compile(r'\b(href|action)\s*=\s*(["\'])(/(?!api/)[^"\']*?)\.php(\?[^"\']*)?\2', re.I)
changed = 0
seen = set()

for d in dirs:
    if not os.path.isdir(d):
        continue
    for dirpath, _, files in os.walk(d):
        if os.sep + "api" + os.sep in dirpath:
            continue
        for fn in files:
            if not fn.endswith(".php"):
                continue
            path = os.path.normpath(os.path.join(dirpath, fn))
            if path in seen:
                continue
            seen.add(path)
            with open(path, "r", encoding="utf-8", errors="replace") as f:
                content = f.read()
            new = pat.sub(lambda m: f"{m.group(1)}={m.group(2)}{m.group(3)}{m.group(4) or ''}{m.group(2)}", content)
            if new != content:
                with open(path, "w", encoding="utf-8", newline="\n") as f:
                    f.write(new)
                changed += 1
                print(os.path.relpath(path, root))

print(f"Done. {changed} files updated.")
