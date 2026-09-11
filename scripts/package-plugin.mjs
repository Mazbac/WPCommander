import { cp, mkdir, readFile, rm } from 'node:fs/promises'
import { spawnSync } from 'node:child_process'
import path from 'node:path'

const root = process.cwd()
const releaseDir = path.join(root, 'release')
const stageDir = path.join(releaseDir, 'wpcommander')
const pkg = JSON.parse(await readFile(path.join(root, 'package.json'), 'utf8'))
const archiveName = `wpcommander-${pkg.version}.zip`
const archivePath = path.join(releaseDir, archiveName)

await rm(stageDir, { recursive: true, force: true })
await rm(archivePath, { force: true })
await mkdir(stageDir, { recursive: true })

await cp(
  path.join(root, 'wpcommander.php'),
  path.join(stageDir, 'wpcommander.php'),
)
await cp(path.join(root, 'includes'), path.join(stageDir, 'includes'), {
  recursive: true,
})
await cp(path.join(root, 'dist'), path.join(stageDir, 'dist'), {
  recursive: true,
})
const command = process.platform === 'win32' ? 'tar' : 'zip'
const args =
  process.platform === 'win32'
    ? ['-a', '-c', '-f', archiveName, 'wpcommander']
    : ['-qr', archiveName, 'wpcommander']
const packed = spawnSync(command, args, { cwd: releaseDir, stdio: 'inherit' })
if (packed.status !== 0) process.exit(packed.status ?? 1)

const listCommand = process.platform === 'win32' ? 'tar' : 'unzip'
const listArgs =
  process.platform === 'win32' ? ['-tf', archiveName] : ['-Z1', archiveName]
const listed = spawnSync(listCommand, listArgs, {
  cwd: releaseDir,
  encoding: 'utf8',
})
if (listed.status !== 0) process.exit(listed.status ?? 1)

const entries = listed.stdout.split(/\r?\n/).filter(Boolean)
if (
  !entries.length ||
  entries.some((entry) => !entry.startsWith('wpcommander/'))
) {
  throw new Error(
    'Release archive must contain exactly one fixed top-level wpcommander/ directory.',
  )
}
if (!entries.includes('wpcommander/wpcommander.php')) {
  throw new Error('Release archive is missing wpcommander/wpcommander.php.')
}

console.log(`Created release/${archiveName}`)
console.log(entries.join('\n'))
