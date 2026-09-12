import { useState } from 'react'
import {
  Badge,
  Box,
  Button,
  Code,
  CopyButton,
  Divider,
  Group,
  Paper,
  Stack,
  Table,
  Text,
} from '@mantine/core'
import { EmptyState } from './components/ui/EmptyState'
import { PageHeader } from './components/ui/PageHeader'
import { Section } from './components/ui/Section'
import {
  getControlPlaneSnapshot,
  type DiagnosticReport,
} from './data/controlPlane'

const statusTone = {
  ready: { color: 'green.8', foreground: 'black' },
  'needs-setup': { color: 'yellow.9', foreground: 'black' },
  warning: { color: 'yellow.9', foreground: 'black' },
  error: { color: 'red.8', foreground: 'white' },
} as const

const activityTone = {
  applied: { color: 'green.8', foreground: 'black' },
  reverted: { color: 'gray.7', foreground: 'white' },
  failed: { color: 'red.8', foreground: 'white' },
} as const

const diagnosticTone = {
  ok: { color: 'green.8', foreground: 'black' },
  warning: { color: 'yellow.9', foreground: 'black' },
} as const

type ConnectionCredential = {
  username: string
  basicToken: string
  createdAt: string
  notice: string
}

type SetupCopyItemProps = {
  title: string
  description: string
  value: string
  copyLabel: string
  detailLabel: string
}

const developmentDiagnostics: DiagnosticReport = {
  generatedAt: new Date().toISOString(),
  accessMode: 'read-only',
  resourceKinds: [
    'post',
    'post-meta',
    'option',
    'media',
    'term',
    'user',
    'comment',
    'menu',
    'plugin',
    'theme',
    'site',
  ],
  checks: [
    ...[
      ['post', 'Posts and pages', 'post/42'],
      ['post-meta', 'Post metadata / builders', 'post/42/meta/layout_settings'],
      ['option', 'Options and theme settings', 'option/blogname'],
      ['media', 'Media library', 'media/120'],
      ['term', 'Taxonomies and terms', 'term/category/3'],
      ['user', 'Users', 'user/1'],
      ['comment', 'Comments', 'comment/17'],
      ['menu', 'Classic navigation menus', 'menu/4'],
      ['plugin', 'Plugins', 'plugin/sample-plugin%2Fsample-plugin.php'],
      ['theme', 'Themes', 'theme/essentials'],
      ['site', 'Site and environment', 'site'],
      ['abilities', 'WordPress Abilities', undefined],
    ].map(([id, label, address]) => ({
      id: id as string,
      label: label as string,
      status: 'ok' as const,
      address: address as string | undefined,
      detail: address
        ? `Search + inspect succeeded for ${address}.`
        : 'Exposed abilities are discoverable.',
    })),
    {
      id: 'developer-inspect',
      label: 'Source and runtime inspection',
      status: 'ok',
      detail:
        'Read-only runtime inventory sees plugin source and database structure.',
    },
    {
      id: 'application-passwords',
      label: 'Application Password authentication',
      status: 'ok',
      detail:
        'WordPress Application Password authentication is available for the current account.',
    },
    {
      id: 'chatgpt-reachability',
      label: 'ChatGPT reachability',
      status: 'ok',
      detail:
        'A ChatGPT-style request reaches WPCommander through the public web edge.',
    },
  ],
}

function SetupCopyItem({
  title,
  description,
  value,
  copyLabel,
  detailLabel,
}: SetupCopyItemProps) {
  return (
    <Stack gap="xs">
      <Group justify="space-between" align="flex-start" wrap="wrap">
        <Stack gap={3}>
          <Text fw={600}>{title}</Text>
          <Text c="dimmed" size="sm">
            {description}
          </Text>
        </Stack>
        <CopyButton value={value}>
          {({ copied, copy }) => (
            <Button variant="default" onClick={copy}>
              {copied ? 'Copied' : copyLabel}
            </Button>
          )}
        </CopyButton>
      </Group>
      <Box component="details" className="wpcommander-details">
        <Box component="summary" className="wpcommander-details-summary">
          {detailLabel}
        </Box>
        <Code block className="wpcommander-code-preview">
          {value}
        </Code>
      </Box>
    </Stack>
  )
}

export function OverviewPage() {
  const snapshot = getControlPlaneSnapshot()
  const [diagnostics, setDiagnostics] = useState<DiagnosticReport | null>(null)
  const [diagnosticsError, setDiagnosticsError] = useState('')
  const [diagnosticsLoading, setDiagnosticsLoading] = useState(false)
  const [credential, setCredential] = useState<ConnectionCredential | null>(
    null,
  )
  const [credentialError, setCredentialError] = useState('')
  const [credentialLoading, setCredentialLoading] = useState(false)
  const [writesEnabled, setWritesEnabled] = useState(
    snapshot.structuredWritesEnabled,
  )
  const [universalExecutionEnabled, setUniversalExecutionEnabled] = useState(
    snapshot.universalExecutionEnabled,
  )
  const [accessError, setAccessError] = useState('')
  const [accessLoading, setAccessLoading] = useState(false)
  const accessLevel = universalExecutionEnabled
    ? 'full'
    : writesEnabled
      ? 'edit'
      : 'inspect'
  const accessLabel =
    accessLevel === 'full'
      ? 'Full control'
      : accessLevel === 'edit'
        ? 'Edit site'
        : 'Inspect only'
  const accessDescription =
    accessLevel === 'full'
      ? 'ChatGPT may use every WordPress control layer, including plugin/theme internals, database, files, and WP-CLI when available.'
      : accessLevel === 'edit'
        ? 'ChatGPT may make normal site edits with fresh-state checks, verification, activity, and safe revert where supported.'
        : 'ChatGPT may inspect and explain the site, but it cannot change WordPress state.'
  const recentActivity = [
    ...snapshot.activity,
    ...snapshot.executionActivity,
  ].sort((left, right) => right.timestamp.localeCompare(left.timestamp))

  async function generateCredential() {
    setCredentialLoading(true)
    setCredentialError('')

    try {
      if (snapshot.restNonce === 'development') {
        setCredential({
          username: 'admin',
          basicToken: 'ZGVtbzpkZXZlbG9wbWVudA==',
          createdAt: new Date().toISOString(),
          notice: 'Copy this token now. It will not be shown again.',
        })
        return
      }
      const response = await fetch(snapshot.credentialUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': snapshot.restNonce,
        },
        credentials: 'same-origin',
        body: '{}',
      })
      const payload = (await response.json()) as ConnectionCredential & {
        message?: string
      }
      if (!response.ok) {
        throw new Error(
          payload.message ??
            'Credential setup failed (' + response.status + ')',
        )
      }
      setCredential(payload)
    } catch (error) {
      setCredentialError(
        error instanceof Error ? error.message : 'Credential setup failed.',
      )
    } finally {
      setCredentialLoading(false)
    }
  }

  async function updateAccessLevel(target: 'inspect' | 'edit' | 'full') {
    if (target === accessLevel) return

    setAccessLoading(true)
    setAccessError('')

    try {
      if (snapshot.restNonce === 'development') {
        setWritesEnabled(target !== 'inspect')
        setUniversalExecutionEnabled(target === 'full')
        return
      }

      const useUniversalRoute =
        target === 'full' || (target === 'edit' && accessLevel === 'full')
      const enabled =
        target === 'full' || (target === 'edit' && accessLevel !== 'full')
      const response = await fetch(
        useUniversalRoute
          ? snapshot.universalExecutionUrl
          : snapshot.writeAccessUrl,
        {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': snapshot.restNonce,
          },
          credentials: 'same-origin',
          body: JSON.stringify({ enabled }),
        },
      )
      const payload = (await response.json()) as {
        structuredWritesEnabled?: boolean
        universalExecutionEnabled?: boolean
        message?: string
      }
      if (
        !response.ok ||
        typeof payload.structuredWritesEnabled !== 'boolean' ||
        typeof payload.universalExecutionEnabled !== 'boolean'
      ) {
        throw new Error(
          payload.message ?? `Access update failed (${response.status})`,
        )
      }
      setWritesEnabled(payload.structuredWritesEnabled)
      setUniversalExecutionEnabled(payload.universalExecutionEnabled)
    } catch (error) {
      setAccessError(
        error instanceof Error ? error.message : 'Access update failed.',
      )
    } finally {
      setAccessLoading(false)
    }
  }

  async function runDiagnostics() {
    setDiagnosticsLoading(true)
    setDiagnosticsError('')

    try {
      if (snapshot.restNonce === 'development') {
        setDiagnostics(developmentDiagnostics)
        return
      }

      const response = await fetch(snapshot.diagnosticsUrl, {
        headers: { 'X-WP-Nonce': snapshot.restNonce },
        credentials: 'same-origin',
      })
      if (!response.ok) {
        throw new Error(`Diagnostics failed (${response.status})`)
      }
      setDiagnostics((await response.json()) as DiagnosticReport)
    } catch (error) {
      setDiagnosticsError(
        error instanceof Error ? error.message : 'Diagnostics failed.',
      )
    } finally {
      setDiagnosticsLoading(false)
    }
  }

  return (
    <Stack component="main" className="wpcommander-app" gap="xl">
      <PageHeader
        title="WPCommander"
        description="ChatGPT control for this WordPress site."
        actions={
          <Group gap="xs">
            <Badge variant="outline">{accessLabel}</Badge>
            <Badge
              color={statusTone[snapshot.connectionStatus].color}
              c={statusTone[snapshot.connectionStatus].foreground}
              size="lg"
              variant="filled"
            >
              {snapshot.connectionStatus === 'ready'
                ? 'Ready'
                : 'Needs attention'}
            </Badge>
          </Group>
        }
      />

      <Section
        title="Connection"
        description="One revocable WordPress credential connects ChatGPT to WPCommander."
      >
        <Paper withBorder p="lg">
          <Stack gap="lg">
            <Group justify="space-between" align="flex-start" wrap="wrap">
              <Stack gap={3} maw={700}>
                <Text fw={600}>WordPress connection</Text>
                <Text c="dimmed" size="sm">
                  WordPress {snapshot.wordpressVersion}. Authentication uses a
                  dedicated WordPress Application Password; WPCommander never
                  stores its plaintext value.
                </Text>
              </Stack>
              <Badge variant="outline">
                {!snapshot.applicationPasswordSupported
                  ? 'Authentication unavailable'
                  : snapshot.connectionCredentialExists || credential
                    ? 'Credential ready'
                    : 'Ready to connect'}
              </Badge>
            </Group>
            {snapshot.connectionStatus !== 'ready' ? (
              <Text c="orange.9" size="sm" role="status">
                {snapshot.connectionMessage}
              </Text>
            ) : null}
            <Group justify="space-between" align="flex-start" wrap="wrap">
              <Stack gap={3} maw={700}>
                <Text fw={600}>
                  {snapshot.connectionCredentialExists || credential
                    ? 'Connection credential'
                    : 'Create connection credential'}
                </Text>
                <Text c="dimmed" size="sm">
                  {snapshot.connectionCredentialExists || credential
                    ? 'A WPCommander credential already exists. Rotate it only when reconnecting a client or replacing a lost credential.'
                    : 'Create one dedicated credential for ChatGPT or another authorized WPCommander client.'}
                </Text>
              </Stack>
              <Button
                onClick={generateCredential}
                loading={credentialLoading}
                disabled={!snapshot.applicationPasswordSupported}
                variant={
                  snapshot.connectionCredentialExists || credential
                    ? 'default'
                    : 'filled'
                }
              >
                {snapshot.connectionCredentialExists || credential
                  ? 'Rotate credential'
                  : 'Create credential'}
              </Button>
            </Group>
            {credentialError ? (
              <Text c="red.8" size="sm" role="alert">
                {credentialError}
              </Text>
            ) : null}
            {credential ? (
              <Paper withBorder p="md">
                <Stack gap="sm">
                  <Text size="sm">
                    WordPress user: <Code>{credential.username}</Code>
                  </Text>
                  <Text c="dimmed" size="sm">
                    Copy this Basic auth token now. WPCommander cannot show it
                    again after this page is reloaded.
                  </Text>
                  <CopyButton value={credential.basicToken}>
                    {({ copied, copy }) => (
                      <Button variant="default" onClick={copy}>
                        {copied ? 'Token copied' : 'Copy Basic auth token'}
                      </Button>
                    )}
                  </CopyButton>
                </Stack>
              </Paper>
            ) : null}
            <Divider />
            <Group justify="space-between" align="flex-start" wrap="wrap">
              <Stack gap={3} maw={700}>
                <Text fw={600}>Connection health</Text>
                <Text c="dimmed" size="sm">
                  Run read-only probes when setup or access needs checking.
                </Text>
              </Stack>
              <Button
                onClick={runDiagnostics}
                loading={diagnosticsLoading}
                variant="default"
              >
                Run diagnostics
              </Button>
            </Group>
            {diagnosticsError ? (
              <Text c="red.8" size="sm" role="alert">
                {diagnosticsError}
              </Text>
            ) : null}
            {diagnostics ? (
              <Stack gap="sm">
                <Text size="sm" role="status">
                  Diagnostics complete:{' '}
                  {
                    diagnostics.checks.filter((check) => check.status === 'ok')
                      .length
                  }
                  /{diagnostics.checks.length} checks passed.
                </Text>
                <Box component="details" className="wpcommander-details">
                  <Box
                    component="summary"
                    className="wpcommander-details-summary"
                  >
                    View diagnostic report
                  </Box>
                  <Stack gap="md" mt="sm">
                    <Table
                      visibleFrom="sm"
                      verticalSpacing="sm"
                      horizontalSpacing="md"
                    >
                      <Table.Thead>
                        <Table.Tr>
                          <Table.Th>Resource</Table.Th>
                          <Table.Th>Status</Table.Th>
                          <Table.Th>Sample address</Table.Th>
                        </Table.Tr>
                      </Table.Thead>
                      <Table.Tbody>
                        {diagnostics.checks.map((check) => (
                          <Table.Tr key={check.id}>
                            <Table.Td>
                              <Stack gap={2}>
                                <Text size="sm" fw={600}>
                                  {check.label}
                                </Text>
                                <Text size="xs" c="dimmed">
                                  {check.detail}
                                </Text>
                              </Stack>
                            </Table.Td>
                            <Table.Td>
                              <Badge
                                color={diagnosticTone[check.status].color}
                                c={diagnosticTone[check.status].foreground}
                                variant="filled"
                              >
                                {check.status}
                              </Badge>
                            </Table.Td>
                            <Table.Td>
                              {check.address ? (
                                <Code>{check.address}</Code>
                              ) : (
                                '—'
                              )}
                            </Table.Td>
                          </Table.Tr>
                        ))}
                      </Table.Tbody>
                    </Table>
                    <Stack hiddenFrom="sm" gap={0}>
                      {diagnostics.checks.map((check, index) => (
                        <Box key={check.id}>
                          <Stack gap={4} py="sm">
                            <Group
                              justify="space-between"
                              align="flex-start"
                              wrap="nowrap"
                            >
                              <Text fw={600} size="sm">
                                {check.label}
                              </Text>
                              <Badge
                                color={diagnosticTone[check.status].color}
                                c={diagnosticTone[check.status].foreground}
                                variant="filled"
                              >
                                {check.status}
                              </Badge>
                            </Group>
                            <Text c="dimmed" size="sm">
                              {check.detail}
                            </Text>
                            {check.address ? (
                              <Code>{check.address}</Code>
                            ) : null}
                          </Stack>
                          {index < diagnostics.checks.length - 1 ? (
                            <Divider />
                          ) : null}
                        </Box>
                      ))}
                    </Stack>
                    <CopyButton value={JSON.stringify(diagnostics, null, 2)}>
                      {({ copied, copy }) => (
                        <Button variant="default" onClick={copy}>
                          {copied ? 'Report copied' : 'Copy diagnostic report'}
                        </Button>
                      )}
                    </CopyButton>
                  </Stack>
                </Box>
              </Stack>
            ) : null}
            <Divider />
            <Box component="details" className="wpcommander-details">
              <Box component="summary" className="wpcommander-details-summary">
                Custom GPT setup
              </Box>
              <Stack gap="lg" mt="md">
                <SetupCopyItem
                  title="Action schema"
                  description="Paste this JSON into the Custom GPT Action editor."
                  value={snapshot.schemaText}
                  copyLabel="Copy Action schema"
                  detailLabel="View Action schema"
                />
                <Divider />
                <SetupCopyItem
                  title="GPT instructions"
                  description="Paste these instructions into the Custom GPT Instructions field."
                  value={snapshot.customGptInstructions}
                  copyLabel="Copy GPT instructions"
                  detailLabel="View GPT instructions"
                />
                <Text c="dimmed" size="xs">
                  Optional schema URL: <Code>{snapshot.schemaUrl}</Code>
                </Text>
              </Stack>
            </Box>
          </Stack>
        </Paper>
      </Section>

      <Section
        title="Site access"
        description="Choose how much control connected ChatGPT may use on this WordPress site."
      >
        <Paper withBorder p="lg">
          <Stack gap="md">
            <Group justify="space-between" align="flex-start" wrap="wrap">
              <Stack gap={4} maw={700}>
                <Group gap="xs">
                  <Text fw={600}>Access level</Text>
                  <Badge variant="filled" color="blue.9" c="white">
                    {accessLabel}
                  </Badge>
                </Group>
                <Text c="dimmed" size="sm">
                  {accessDescription}
                </Text>
                {accessLevel === 'full' ? (
                  <Text c="dimmed" size="xs">
                    Full control still uses the narrowest available primitive
                    first. Broad, destructive, irreversible, or privileged steps
                    require clear user intent or explicit confirmation.
                  </Text>
                ) : null}
              </Stack>
              <Group gap="xs" wrap="wrap">
                <Button
                  variant={accessLevel === 'inspect' ? 'filled' : 'default'}
                  onClick={() => updateAccessLevel('inspect')}
                  disabled={accessLoading}
                  aria-pressed={accessLevel === 'inspect'}
                >
                  Inspect only
                </Button>
                <Button
                  variant={accessLevel === 'edit' ? 'filled' : 'default'}
                  onClick={() => updateAccessLevel('edit')}
                  disabled={accessLoading}
                  aria-pressed={accessLevel === 'edit'}
                >
                  Edit site
                </Button>
                <Button
                  variant={accessLevel === 'full' ? 'filled' : 'default'}
                  onClick={() => updateAccessLevel('full')}
                  disabled={accessLoading}
                  aria-pressed={accessLevel === 'full'}
                >
                  Full control
                </Button>
              </Group>
            </Group>
            {accessError ? (
              <Text c="red.8" size="sm" role="alert">
                {accessError}
              </Text>
            ) : null}
            <Divider />
            <Text c="dimmed" size="sm">
              WPCommander can inspect WordPress data, Abilities, plugin/theme
              source and runtime, and—at Full control—use the universal
              execution fallback for anything WordPress/PHP can reach. No
              vendor-specific adapter is required for access.
            </Text>
          </Stack>
        </Paper>
      </Section>

      <Section
        title="Recent activity"
        description="Changes made through WPCommander and their recovery status."
      >
        {recentActivity.length === 0 ? (
          <Paper withBorder>
            <EmptyState
              title="No activity yet"
              description="Changes made through WPCommander will appear here."
            />
          </Paper>
        ) : (
          <>
            <Stack gap="sm" hiddenFrom="sm">
              {recentActivity.map((item) => (
                <Paper withBorder p="md" key={item.id}>
                  <Stack gap="xs">
                    <Group justify="space-between" align="flex-start">
                      <Text fw={600}>{item.action}</Text>
                      <Badge
                        color={activityTone[item.state].color}
                        c={activityTone[item.state].foreground}
                        variant="filled"
                      >
                        {item.state}
                      </Badge>
                    </Group>
                    <Text size="sm">{item.target}</Text>
                    <Text c="dimmed" size="xs">
                      {item.timestamp}
                    </Text>
                  </Stack>
                </Paper>
              ))}
            </Stack>
            <Paper withBorder visibleFrom="sm">
              <Table verticalSpacing="sm" horizontalSpacing="md">
                <Table.Thead>
                  <Table.Tr>
                    <Table.Th>Change</Table.Th>
                    <Table.Th>Target</Table.Th>
                    <Table.Th>Status</Table.Th>
                    <Table.Th>Time</Table.Th>
                  </Table.Tr>
                </Table.Thead>
                <Table.Tbody>
                  {recentActivity.map((item) => (
                    <Table.Tr key={item.id}>
                      <Table.Td>{item.action}</Table.Td>
                      <Table.Td>{item.target}</Table.Td>
                      <Table.Td>
                        <Badge
                          color={activityTone[item.state].color}
                          c={activityTone[item.state].foreground}
                          variant="filled"
                        >
                          {item.state}
                        </Badge>
                      </Table.Td>
                      <Table.Td>{item.timestamp}</Table.Td>
                    </Table.Tr>
                  ))}
                </Table.Tbody>
              </Table>
            </Paper>
          </>
        )}
      </Section>

      <Text c="dimmed" size="xs">
        WPCommander {snapshot.pluginVersion} · {snapshot.siteName}
      </Text>
    </Stack>
  )
}
