import { existsSync } from 'node:fs'
import { resolve } from 'node:path'

const [major] = process.versions.node.split('.').map(Number)
const supported = major >= 24

const required = [
  'package-lock.json',
  'AGENTS.md',
  'docs/PRODUCT.md',
  'docs/STATE.md',
  'docs/UI.md',
  'src/theme/theme.ts',
]
const missing = required.filter((file) => !existsSync(resolve(file)))

if (!supported || missing.length > 0) {
  if (!supported)
    console.error(`Unsupported Node.js ${process.versions.node}; use 24+.`)
  if (missing.length > 0)
    console.error(`Missing required files: ${missing.join(', ')}`)
  process.exit(1)
}

console.log(
  `Environment OK — Node ${process.versions.node}, npm project files present.`,
)
