import { readdir, readFile } from 'node:fs/promises'
import path from 'node:path'
import Engine from 'php-parser'

const root = process.cwd()
const parser = new Engine({
  parser: { extractDoc: true },
  ast: { withPositions: true },
})

async function collectPhpFiles(directory) {
  const entries = await readdir(directory, { withFileTypes: true })
  const files = []
  for (const entry of entries) {
    const fullPath = path.join(directory, entry.name)
    if (entry.isDirectory()) files.push(...(await collectPhpFiles(fullPath)))
    if (entry.isFile() && entry.name.endsWith('.php')) files.push(fullPath)
  }
  return files
}

const phpFiles = [
  path.join(root, 'wpcommander.php'),
  ...(await collectPhpFiles(path.join(root, 'includes'))),
]
for (const file of phpFiles) {
  parser.parseCode(await readFile(file, 'utf8'), path.relative(root, file))
}

const inspectorPath = path.join(
  root,
  'includes',
  'class-wpcommander-developer-inspect.php',
)
const inspector = await readFile(inspectorPath, 'utf8')
const forbiddenPatterns = [
  /\$wpdb->(?:query|insert|update|delete|replace)\s*\(/i,
  /\b(?:UPDATE|DELETE\s+FROM|INSERT\s+INTO|REPLACE\s+INTO|ALTER\s+TABLE|DROP\s+TABLE|TRUNCATE)\b/i,
  /\b(?:file_put_contents|unlink|rename|fwrite|mkdir|rmdir|shell_exec|proc_open|passthru)\s*\(/i,
]
for (const pattern of forbiddenPatterns) {
  if (pattern.test(inspector)) {
    throw new Error(
      `Developer inspector violated read-only contract: ${pattern}`,
    )
  }
}

for (const marker of [
  'MAX_FILE_BYTES',
  'MAX_DB_SAMPLE_BYTES',
  'base_prefix',
  'redact_database_row',
  'is_path_inside_base',
  'wp-content',
]) {
  if (!inspector.includes(marker)) {
    throw new Error(`Developer inspector safety marker missing: ${marker}`)
  }
}

console.log(
  `PHP parse + developer read-only contract passed (${phpFiles.length} files).`,
)
