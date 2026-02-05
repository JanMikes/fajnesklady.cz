# Generate Domain Documentation

Use this prompt with Claude Code to regenerate comprehensive domain documentation with Mermaid diagrams.

## Prompt

```
Analyze the entire codebase and create comprehensive domain documentation in `.claude/DOMAIN.md`.

Include:

## 1. Overview
- What the application does (business purpose)
- Target users and their roles

## 2. User Roles
- Table of all roles with Czech names and descriptions
- Role hierarchy

## 3. Core Entities
- All domain entities with their purpose
- Key fields and relationships
- Status fields and their meanings

## 4. Main Business Flows
- Step-by-step flows for core operations
- Events triggered at each step
- Side effects (emails, invoices, etc.)

## 5. Authorization Matrix
- Table showing which role can do what action
- Include all CRUD operations per entity

## 6. Key Business Rules
- Validation rules
- State transitions
- Timing constraints (expiration, retry logic)
- Pricing logic

## 7. External Integrations
- Payment gateway
- Invoicing system
- Email services

## 8. Mermaid Diagrams
Include these diagrams:

### Entity Diagrams
- Entity Relationship Diagram (erDiagram)

### State Machines
- Order status state machine (stateDiagram-v2)
- Storage status state machine (stateDiagram-v2)
- Contract lifecycle (stateDiagram-v2)

### Sequence Diagrams
- Order & payment flow (sequenceDiagram)
- Email verification flow (sequenceDiagram)
- Password reset flow (sequenceDiagram)

### Flowcharts
- User registration flow (flowchart TD)
- Recurring billing flow (flowchart TD)
- Authorization flow (flowchart TD)
- Storage selection modes (flowchart TD)
- Pricing resolution (flowchart TD)
- Commission rate resolution (flowchart TD)

### Other
- Dashboard views by role (flowchart TD)
- Domain events flow (flowchart LR)
- Full order journey (journey)

Format all diagrams using proper Mermaid syntax with ```mermaid code blocks.
```

## Convert to PDF

After generating `DOMAIN.md`, run these commands to create PDF with rendered diagrams:

### 1. Install dependencies (one-time)

```bash
mkdir -p /tmp/pdf-gen && cd /tmp/pdf-gen && npm init -y && npm install puppeteer
```

### 2. Create conversion script

```bash
cat > /tmp/convert-md.py << 'PYEOF'
import re
import sys

with open(sys.argv[1], 'r') as f:
    content = f.read()

# Extract and protect mermaid blocks first
mermaid_blocks = []
def save_mermaid(match):
    idx = len(mermaid_blocks)
    mermaid_blocks.append(match.group(1))
    return f'MERMAID_PLACEHOLDER_{idx}'

content = re.sub(r'```mermaid\n(.*?)\n```', save_mermaid, content, flags=re.DOTALL)

# Extract and protect code blocks
code_blocks = []
def save_code(match):
    idx = len(code_blocks)
    lang = match.group(1) or ''
    code = match.group(2)
    code_blocks.append((lang, code))
    return f'CODE_PLACEHOLDER_{idx}'

content = re.sub(r'```(\w+)?\n(.*?)\n```', save_code, content, flags=re.DOTALL)

# Handle headers
content = re.sub(r'^# (.+)$', r'<h1>\1</h1>', content, flags=re.MULTILINE)
content = re.sub(r'^## (.+)$', r'<h2>\1</h2>', content, flags=re.MULTILINE)
content = re.sub(r'^### (.+)$', r'<h3>\1</h3>', content, flags=re.MULTILINE)

# Handle tables
def convert_table(match):
    lines = match.group(0).strip().split('\n')
    html = '<table>\n'
    for i, line in enumerate(lines):
        if '---' in line and '|' in line:
            continue
        cells = [c.strip() for c in line.split('|')[1:-1]]
        if not cells:
            continue
        tag = 'th' if i == 0 else 'td'
        html += '<tr>' + ''.join(f'<{tag}>{c}</{tag}>' for c in cells) + '</tr>\n'
    html += '</table>'
    return html

content = re.sub(r'(\|.+\|[\n\r]+)+', convert_table, content)

# Handle bold
content = re.sub(r'\*\*(.+?)\*\*', r'<strong>\1</strong>', content)

# Handle inline code
content = re.sub(r'`([^`]+)`', r'<code>\1</code>', content)

# Handle horizontal rules
content = re.sub(r'^---+$', '<hr>', content, flags=re.MULTILINE)

# Handle lists
content = re.sub(r'^- (.+)$', r'<li>\1</li>', content, flags=re.MULTILINE)
content = re.sub(r'(<li>.*</li>\n)+', r'<ul>\g<0></ul>', content)

# Handle paragraphs
paragraphs = re.split(r'\n\n+', content)
result = []
for p in paragraphs:
    p = p.strip()
    if not p:
        continue
    if p.startswith('<h') or p.startswith('<table') or p.startswith('<pre') or p.startswith('<ul') or p.startswith('<hr') or p.startswith('MERMAID_') or p.startswith('CODE_'):
        result.append(p)
    else:
        result.append(f'<p>{p}</p>')

content = '\n'.join(result)

# Restore mermaid blocks
for i, block in enumerate(mermaid_blocks):
    content = content.replace(f'MERMAID_PLACEHOLDER_{i}', f'<div class="mermaid">\n{block}\n</div>')

# Restore code blocks
for i, (lang, code) in enumerate(code_blocks):
    escaped = code.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;')
    content = content.replace(f'CODE_PLACEHOLDER_{i}', f'<pre><code>{escaped}</code></pre>')

print(content)
PYEOF
```

### 3. Generate HTML

```bash
python3 /tmp/convert-md.py .claude/DOMAIN.md > /tmp/content.html

cat > .claude/DOMAIN.html << 'EOF'
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Domain Documentation</title>
  <script src="https://cdn.jsdelivr.net/npm/mermaid@10/dist/mermaid.min.js"></script>
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 1000px; margin: 0 auto; padding: 20px; line-height: 1.6; font-size: 14px; }
    h1 { color: #333; border-bottom: 2px solid #333; padding-bottom: 10px; }
    h2 { color: #444; border-bottom: 1px solid #ddd; padding-bottom: 5px; margin-top: 30px; }
    h3 { color: #555; margin-top: 25px; }
    table { border-collapse: collapse; width: 100%; margin: 15px 0; font-size: 13px; }
    th, td { border: 1px solid #ddd; padding: 6px 10px; text-align: left; }
    th { background-color: #f5f5f5; }
    code { background-color: #f5f5f5; padding: 2px 6px; border-radius: 3px; font-size: 0.9em; }
    pre { background-color: #f5f5f5; padding: 15px; border-radius: 5px; overflow-x: auto; font-size: 12px; }
    pre code { background: none; padding: 0; }
    .mermaid { background: white; text-align: center; margin: 20px 0; }
    .mermaid svg { max-width: 100%; height: auto; }
    ul { margin: 10px 0; padding-left: 25px; }
    li { margin: 5px 0; }
    hr { border: none; border-top: 1px solid #ddd; margin: 20px 0; }
    @media print { .mermaid { page-break-inside: avoid; } }
  </style>
</head>
<body>
EOF

cat /tmp/content.html >> .claude/DOMAIN.html

cat >> .claude/DOMAIN.html << 'EOF'
<script>
  mermaid.initialize({
    startOnLoad: true,
    theme: 'default',
    securityLevel: 'loose',
    flowchart: { useMaxWidth: true, htmlLabels: true },
    sequence: { useMaxWidth: true },
    er: { useMaxWidth: true }
  });
</script>
</body>
</html>
EOF
```

### 4. Generate PDF

```bash
cat > /tmp/pdf-gen/generate.js << 'EOF'
const puppeteer = require('puppeteer');

const htmlPath = process.argv[2];
const pdfPath = process.argv[3];

(async () => {
  const browser = await puppeteer.launch({ headless: 'new' });
  const page = await browser.newPage();

  await page.goto('file://' + htmlPath, { waitUntil: 'networkidle0', timeout: 60000 });

  // Wait for mermaid to render all diagrams
  await page.waitForFunction(() => {
    const mermaids = document.querySelectorAll('.mermaid');
    if (mermaids.length === 0) return false;
    return Array.from(mermaids).every(m => m.querySelector('svg'));
  }, { timeout: 60000 });

  await new Promise(r => setTimeout(r, 2000));

  const count = await page.evaluate(() => document.querySelectorAll('.mermaid svg').length);
  console.log('Rendered ' + count + ' diagrams');

  await page.pdf({
    path: pdfPath,
    format: 'A4',
    margin: { top: '15mm', bottom: '15mm', left: '15mm', right: '15mm' },
    printBackground: true
  });

  await browser.close();
  console.log('PDF generated: ' + pdfPath);
})();
EOF

node /tmp/pdf-gen/generate.js "$(pwd)/.claude/DOMAIN.html" "$(pwd)/.claude/DOMAIN.pdf"
```

### 5. Open results

```bash
open .claude/DOMAIN.html  # View in browser
open .claude/DOMAIN.pdf   # View PDF
```

## Alternative: View in GitHub

Push the `DOMAIN.md` file to GitHub - it renders Mermaid diagrams natively in the markdown preview.

## Alternative: VS Code

Install "Markdown Preview Mermaid Support" extension, then use `Cmd+Shift+V` to preview with rendered diagrams.
