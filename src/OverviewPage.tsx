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
  planned: { color: 'blue.7', foreground: 'black' },
  applied: { color: 'green.8', foreground: 'black' },
  reverted: { color: 'gray.7', foreground: 'white' },
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
      ['post-meta', 'Post metadata / builders', 'post/42/meta/_elementor_data'],
      ['option', 'Options and theme settings', 'option/blogname'],
      ['media', 'Media library', 'media/120'],
      ['term', 'Taxonomies and terms', 'term/category/3'],
      ['user', 'Users', 'user/1'],
      ['comment', 'Comments', 'comment/17'],
      ['menu', 'Classic navigation menus', 'menu/4'],
      ['plugin', 'Plugins', 'plugin/elementor%2Felementor.php'],
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
        description="Connect ChatGPT to WordPress, inspect the site, and keep control of every action."
        actions={
          <Group gap="xs">
            <Badge variant="outline">
              {snapshot.accessMode === 'read-only'
                ? 'Read-only diagnostics'
                : 'Writes enabled'}
            </Badge>
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

      <Group gap="lg" wrap="wrap" className="wpcommander-meta-row">
        <Text size="sm">
          <Text component="span" fw={600}>
            WordPress
          </Text>{' '}
          {snapshot.wordpressVersion}
        </Text>
        <Text size="sm">
          <Text component="span" fw={600}>
            Resources
          </Text>{' '}
          11 kinds
        </Text>
        <Text size="sm">
          <Text component="span" fw={600}>
            Authentication
          </Text>{' '}
          Application Password
        </Text>
      </Group>

      <Section
        title="Connect Custom GPT"
        description="Generate the connection once, then paste the token, Action schema, and instructions into your Custom GPT."
      >
        <Paper withBorder p="lg">
          <Stack gap="lg">
            <Group justify="space-between" align="flex-start" wrap="wrap">
              <Stack gap={3}>
                <Text fw={600}>Connection setup</Text>
                <Text c="dimmed" size="sm">
                  WPCommander generates everything for this WordPress site. No
                  manual Application Password setup is required.
                </Text>
              </Stack>
              <Badge variant="outline">
                {!snapshot.applicationPasswordSupported
                  ? 'Authentication unavailable'
                  : snapshot.connectionCredentialExists || credential
                    ? 'Credential ready'
                    : 'Not connected'}
              </Badge>
            </Group>

            {snapshot.connectionStatus !== 'ready' ? (
              <Text c="orange.9" size="sm" role="status">
                {snapshot.connectionMessage}
              </Text>
            ) : null}

            <Divider />

            <Stack gap="sm">
              <Group justify="space-between" align="flex-start" wrap="wrap">
                <Stack gap={3}>
                  <Text fw={600}>1. Create connection token</Text>
                  <Text c="dimmed" size="sm">
                    Creates a dedicated WordPress Application Password for your
                    current account and prepares the Basic token required by GPT
                    Actions.
                  </Text>
                </Stack>
                <Button
                  onClick={generateCredential}
                  loading={credentialLoading}
                  disabled={!snapshot.applicationPasswordSupported}
                >
                  {snapshot.connectionCredentialExists || credential
                    ? 'Regenerate token'
                    : 'Generate connection token'}
                </Button>
              </Group>
              {snapshot.connectionCredentialExists && !credential ? (
                <Text c="dimmed" size="sm">
                  A WPCommander credential already exists. Regenerating it
                  revokes the previous token, so update the GPT immediately.
                </Text>
              ) : null}
              {credentialError ? (
                <Text c="red.8" size="sm">
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
                      In the GPT Action editor choose Authentication → API key →
                      Basic. Copy this token now; WPCommander does not store the
                      plaintext credential.
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
            </Stack>

            <Divider />

            <SetupCopyItem
              title="2. Copy Action schema"
              description="Paste this JSON directly into the Custom GPT Action editor. Direct paste avoids URL-import, redirect, cache, and encoding issues."
              value={snapshot.schemaText}
              copyLabel="Copy Action schema"
              detailLabel="View Action schema"
            />

            <Divider />

            <SetupCopyItem
              title="3. Copy GPT instructions"
              description="Paste these instructions into the GPT Instructions field so it knows how to search, inspect, use Abilities, and respect the current access mode."
              value={snapshot.customGptInstructions}
              copyLabel="Copy GPT instructions"
              detailLabel="View GPT instructions"
            />

            <Text c="dimmed" size="xs">
              Optional schema URL: <Code>{snapshot.schemaUrl}</Code>
            </Text>
          </Stack>
        </Paper>
      </Section>

      <Section
        title="Production access test"
        description="Verify what WPCommander can read on this site without changing production data."
      >
        <Paper withBorder p="lg">
          <Stack gap="md">
            <Group justify="space-between" align="flex-start" wrap="wrap">
              <Stack gap={3}>
                <Text fw={600}>Read-only diagnostics</Text>
                <Text c="dimmed" size="sm">
                  Runs real search → inspect probes across all 11 resource kinds
                  plus exposed WordPress Abilities.
                </Text>
              </Stack>
              <Button
                onClick={runDiagnostics}
                loading={diagnosticsLoading}
                variant="default"
              >
                Run access test
              </Button>
            </Group>

            {diagnosticsError ? (
              <Text c="red.8" size="sm">
                {diagnosticsError}
              </Text>
            ) : null}

            {diagnostics ? (
              <Stack gap="md">
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
                          {check.address ? <Code>{check.address}</Code> : '—'}
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
                        {check.address ? <Code>{check.address}</Code> : null}
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
            ) : null}
          </Stack>
        </Paper>
      </Section>

      <Section
        title="Capabilities"
        description="The GPT discovers capabilities at runtime instead of relying on vendor-specific adapters."
      >
        <Paper withBorder>
          <Stack gap={0}>
            {snapshot.capabilities.map((capability, index) => (
              <Box key={capability.id}>
                <Group
                  justify="space-between"
                  align="flex-start"
                  p="md"
                  wrap="nowrap"
                >
                  <Stack gap={3}>
                    <Text fw={600}>{capability.label}</Text>
                    <Text c="dimmed" size="sm">
                      {capability.description}
                    </Text>
                    <Text c="dimmed" size="xs">
                      Source: {capability.source}
                    </Text>
                  </Stack>
                  <Badge
                    variant="filled"
                    color={
                      capability.access === 'write' ? 'orange.8' : 'blue.7'
                    }
                    c="black"
                  >
                    {capability.access}
                  </Badge>
                </Group>
                {index < snapshot.capabilities.length - 1 ? <Divider /> : null}
              </Box>
            ))}
          </Stack>
        </Paper>
      </Section>

      <Section
        title="Recent activity"
        description="Applied and reverted changes remain inspectable."
      >
        {snapshot.activity.length === 0 ? (
          <Paper withBorder>
            <EmptyState
              title="No activity yet"
              description="Changes made through WPCommander will appear here."
            />
          </Paper>
        ) : (
          <>
            <Stack gap="sm" hiddenFrom="sm">
              {snapshot.activity.map((item) => (
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
                  {snapshot.activity.map((item) => (
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
