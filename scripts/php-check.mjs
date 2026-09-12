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

const mutationsPath = path.join(
  root,
  'includes',
  'class-wpcommander-mutations.php',
)
const mutations = await readFile(mutationsPath, 'utf8')
for (const marker of [
  'expectedResourceFingerprint',
  'hash_equals',
  'verify_change',
  'rollback_prepared_change',
  'MAX_REVERSIBLE_BYTES',
  'is_high_risk_option',
  'OPTION_ACTIVITY',
  'wpcommander_array_remove_blocked',
  'wpcommander_array_append_blocked',
  'contains_sensitive_keys',
  'wpcommander_rollback_failed',
]) {
  if (!mutations.includes(marker)) {
    throw new Error(`Structured mutation safety marker missing: ${marker}`)
  }
}

for (const pattern of [
  /\$wpdb->(?:query|insert|update|delete|replace)\s*\(/i,
  /\b(?:eval|shell_exec|proc_open|passthru|exec|system)\s*\(/i,
  /\b(?:file_put_contents|unlink|rename|fwrite|mkdir|rmdir)\s*\(/i,
  /\b(?:activate_plugin|deactivate_plugins|delete_plugins|switch_theme)\s*\(/i,
]) {
  if (pattern.test(mutations)) {
    throw new Error(
      `Structured mutation surface exceeded its boundary: ${pattern}`,
    )
  }
}

const resources = await readFile(
  path.join(root, 'includes', 'class-wpcommander-resources.php'),
  'utf8',
)
if (
  !resources.includes("$value   = $this->redact_value( $loaded['value'] );")
) {
  throw new Error(
    'Resource inspection must redact before applying a JSON Pointer.',
  )
}

const core = await readFile(
  path.join(root, 'includes', 'class-wpcommander.php'),
  'utf8',
)
if (
  !core.includes(
    "apply_filters( 'wpcommander_write_abilities_enabled', false )",
  )
) {
  throw new Error('Arbitrary write Abilities must remain disabled by default.')
}

console.log(
  `PHP parse + developer/mutation safety contracts passed (${phpFiles.length} files).`,
)
