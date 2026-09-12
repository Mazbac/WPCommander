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
if (
  !inspector.includes("case 'stat-path'") ||
  !inspector.includes("hash_file( 'sha256'")
) {
  throw new Error(
    'Developer inspector must expose hash-only stat-path for stale-safe file execution.',
  )
}
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
  'create_resource',
  'mutate_batch',
  'delete_resource',
  'copy_post_relations',
  "'attachment' === $post->post_type",
  'delete_post_meta( $target_id, (string) $key )',
  "wp_set_object_terms( $target_id, array_map( 'intval', $terms )",
  'created_state_fingerprint',
  'find_create_replay',
  'find_delete_replay',
  'wp_trash_post',
  'wp_untrash_post',
  'wpcommander_delete_confirmation_required',
  'find_batch_replay',
  'record_batch_activity',
  'batch_pointers_overlap',
  'wpcommander_overlapping_batch_pointer',
  'wpcommander_batch_revert_missing_state',
  'wpcommander_batch_revert_rollback_failed',
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

const executor = await readFile(
  path.join(root, 'includes', 'class-wpcommander-developer-execute.php'),
  'utf8',
)
for (const marker of [
  "OPTION_ENABLED = 'wpcommander_universal_execution_enabled'",
  "current_user_can( 'manage_options' )",
  "true !== ( $input['confirmed'] ?? false )",
  'MAX_OUTPUT_BYTES',
  'MAX_CLI_SECONDS',
  'expectedSha256',
  'require_file_fingerprint',
  'wpcommander_file_stale',
  'wpcommander_sql_scope_blocked',
  'wpcommander_credential_route_blocked',
  'wpcommander_credential_callable_blocked',
  'wpcommander_execution_path_escape',
  'wpcommander_php_terminator_blocked',
  'wpcommander_wp_cli_uses_dedicated_primitive',
  'contentBase64',
  'sanitize_error',
  'contains_sensitive_context',
  'sql_targets_external_schema',
  'bound_select_limit',
  'append_process_output',
  'LOAD_FILE',
  'record_activity',
  "case 'internal-rest'",
  "case 'call-function'",
  "case 'php-eval'",
  "case 'sql'",
  "case 'write-file'",
  "case 'wp-cli'",
]) {
  if (!executor.includes(marker)) {
    throw new Error(`Universal execution safety marker missing: ${marker}`)
  }
}

const core = await readFile(
  path.join(root, 'includes', 'class-wpcommander.php'),
  'utf8',
)

for (const match of core.matchAll(/'description'\s*=>\s*'([^']*)'/g)) {
  if (match[1].length > 300) {
    throw new Error(
      `Custom GPT Action description exceeds 300 characters (${match[1].length}).`,
    )
  }
}

if (
  !core.includes(
    "return $this->executor->is_enabled() && current_user_can( 'manage_options' );",
  )
) {
  throw new Error(
    'Write Abilities must stay behind the universal execution admin gate.',
  )
}

if (
  !core.includes("true !== ( $params['confirmed'] ?? false )") ||
  !core.includes("'contentBase64' => array(")
) {
  throw new Error(
    'Privileged write Abilities and binary filesystem execution must remain explicit in the API contract.',
  )
}

for (const marker of [
  "'/resources/create'",
  "'/resources/update'",
  "'/resources/update-batch'",
  "'/resources/delete'",
  "'operationId' => 'createWordPressResource'",
  "'operationId' => 'updateWordPressResource'",
  "'operationId' => 'updateWordPressResourceBatch'",
  "'operationId' => 'deleteWordPressResource'",
]) {
  if (!core.includes(marker)) {
    throw new Error(`Generic CRUD API contract missing: ${marker}`)
  }
}
if (core.includes("'/wp-json/wpcommander/v1/resources/clone'")) {
  throw new Error(
    'Machine-facing API must express duplication as generic Create, not a clone endpoint.',
  )
}

for (const providerMarker of [
  '_elementor_data',
  'Elementor',
  'WooCommerce',
  'Divi',
  'Bricks',
  'Advanced Custom Fields',
]) {
  if ((core + resources + mutations).includes(providerMarker)) {
    throw new Error(
      `Provider-specific implementation marker is forbidden: ${providerMarker}`,
    )
  }
}

const fingerprintUses = executor.match(/require_file_fingerprint\s*\(/g) ?? []
if (fingerprintUses.length < 3) {
  throw new Error(
    'Existing file move/delete must enforce the stat-path SHA-256 fingerprint helper.',
  )
}

if (
  executor.includes("'returnValue' => $value") ||
  executor.includes("'output' => $this->bound_text( $output )")
) {
  throw new Error(
    'php-eval must not return raw evaluated values/stdout through the privileged API.',
  )
}

console.log(
  `PHP parse + inspection/mutation/universal-execution safety contracts passed (${phpFiles.length} files).`,
)
