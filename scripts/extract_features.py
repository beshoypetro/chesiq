import re

path = 'C:/Users/beshoy/.claude/projects/C--Users-beshoy-source-repos-chessiq/b750fdc4-e7af-45df-ba5e-cb90c05319a8.jsonl'
best = ''

with open(path, encoding='utf-8') as f:
    for line in f:
        if '# Chesiq Feature Backlog' not in line:
            continue
        idx = line.find('# Chesiq Feature Backlog')
        raw = line[idx:idx+150000]
        # Unescape JSON string encoding
        raw = raw.replace(r'\n', '\n').replace(r'\t', '\t')
        # Remove line number prefixes like "1\t"
        raw = re.sub(r'^\d+\t', '', raw, flags=re.MULTILINE)
        if len(raw) > len(best):
            best = raw

# Find the end of the file - the last updated line should end the content
# Find the footer
footer_marker = '*Last updated:'
idx = best.find(footer_marker)
if idx != -1:
    # Find end of that line
    end_idx = best.find('\n', idx)
    if end_idx == -1:
        end_idx = len(best)
    best = best[:end_idx + 1]

# Now replace [x] APPROVED with DONE
best = best.replace('- **Approval:** [x] APPROVED', '- **Approval:** ✅ DONE')

with open('C:/Users/beshoy/source/repos/chessiq/FEATURES.md', 'w', encoding='utf-8') as out:
    out.write(best)
print(f'Written {len(best)} chars')
