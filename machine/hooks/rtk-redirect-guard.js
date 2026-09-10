// Skip rtk rewrite when stdout is consumed by a file/pipe/substitution —
// rtk compresses into the stream and corrupts the artifact (rtk-ai/rtk#1282).
// Skipping is always safe: worst case is a missed compaction, never corruption.
const { execFileSync } = require('node:child_process');

let raw = '';
process.stdin.on('data', (d) => (raw += d));
process.stdin.on('end', () => {
  let cmd = '';
  try {
    cmd = JSON.parse(raw)?.tool_input?.command ?? '';
  } catch {}

  const stripped = cmd.replace(/'[^']*'|"[^"]*"/g, ''); // ignore quoted literals
  const consumesStream =
    /(^|[^0-9&])>>?|\|\s*tee\b|\$\(|`|\|\s*(head|tail|wc|grep|sed|awk|sort|uniq|xargs|jq)\b/.test(
      stripped,
    );

  if (consumesStream) {
    process.stdout.write('{}'); // no rewrite, run the command raw
    return;
  }

  try {
    process.stdout.write(execFileSync('rtk', ['hook', 'claude'], { input: raw }));
  } catch {
    process.stdout.write('{}');
  }
});
