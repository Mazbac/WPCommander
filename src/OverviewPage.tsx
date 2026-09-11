import { useState } from 'react'
import {
  Badge,
  Button,
  Code,
  CopyButton,
  Group,
  List,
  Paper,
  SimpleGrid,
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
}

export function OverviewPage() {
  const snapshot = getControlPlaneSnapshot()
  const [diagnostics, setDiagnostics] = useState<DiagnosticReport | null>(null)
  const [diagnosticsError, setDiagnosticsError] = useState('')
  const [diagnosticsLoading, setDiagnosticsLoading] = useState(false)

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
      if (!response.ok)
        throw new Error(`Diagnostics failed (${response.status})`)
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
        description="A safe control plane for ChatGPT to inspect and change WordPress."
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
      <SimpleGrid cols={{ base: 1, md: 2 }} spacing="lg">
        <Paper withBorder p="lg">
          <Stack gap="md">
            <Group justify="space-between" align="flex-start">
              <Stack gap={4}>
                <Text fw={600}>Control plane</Text>
                <Text c="dimmed" size="sm">
                  {snapshot.connectionMessage}
                </Text>
              </Stack>
              <Badge variant="outline">
                WordPress {snapshot.wordpressVersion}
              </Badge>
            </Group>
            <Group gap="xs">
              <Badge variant="light">Abilities API</Badge>
              <Badge variant="light">Application Passwords</Badge>
              <Badge variant="light">Plan → apply</Badge>
            </Group>
          </Stack>
        </Paper>

        <Paper withBorder p="lg">
          <Stack gap="sm">
            <Text fw={600}>Action schema</Text>
            <Text c="dimmed" size="sm">
              Import this URL in your Custom GPT Action. Use Basic auth with a
              dedicated WordPress Application Password.
            </Text>
            <Group gap="xs" align="center" wrap="nowrap">
              <Code block className="wpcommander-schema">
                {snapshot.schemaUrl}
              </Code>
              <CopyButton value={snapshot.schemaUrl}>
                {({ copied, copy }) => (
                  <Button variant="default" onClick={copy}>
                    {copied ? 'Copied' : 'Copy URL'}
                  </Button>
                )}
              </CopyButton>
            </Group>
          </Stack>
        </Paper>
      </SimpleGrid>

      <Section
        title="Connect ChatGPT"
        description="Three steps, using WordPress-native authentication."
      >
        <Paper withBorder p="lg">
          <List spacing="sm" type="ordered">
            <List.Item>
              Create a dedicated Application Password for the WordPress
              administrator account.
            </List.Item>
            <List.Item>
              Import the Action schema URL into the Custom GPT editor.
            </List.Item>
            <List.Item>
              Choose Basic authentication and enter the WordPress username plus
              Application Password.
            </List.Item>
          </List>
        </Paper>
      </Section>

      <Section
        title="Production access test"
        description="Check real WordPress access without changing production data."
      >
        <Paper withBorder p="lg">
          <Stack gap="md">
            <Group justify="space-between" align="flex-start" wrap="wrap">
              <Stack gap="xs">
                <Text fw={600}>Read-only diagnostics</Text>
                <Text c="dimmed" size="sm">
                  Performs real search + inspect probes across 11 WordPress
                  resource kinds plus exposed Abilities. Write abilities stay
                  blocked.
                </Text>
              </Stack>
              <Button onClick={runDiagnostics} loading={diagnosticsLoading}>
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
                <SimpleGrid cols={{ base: 1, md: 2 }} spacing="sm">
                  {diagnostics.checks.map((check) => (
                    <Paper withBorder p="md" key={check.id}>
                      <Group
                        justify="space-between"
                        align="flex-start"
                        wrap="nowrap"
                      >
                        <Stack gap={4}>
                          <Text fw={600} size="sm">
                            {check.label}
                          </Text>
                          <Text c="dimmed" size="sm">
                            {check.detail}
                          </Text>
                          {check.address ? <Code>{check.address}</Code> : null}
                        </Stack>
                        <Badge
                          color={diagnosticTone[check.status].color}
                          c={diagnosticTone[check.status].foreground}
                          variant="filled"
                        >
                          {check.status}
                        </Badge>
                      </Group>
                    </Paper>
                  ))}
                </SimpleGrid>
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
        <SimpleGrid cols={{ base: 1, md: 3 }} spacing="md">
          {snapshot.capabilities.map((capability) => (
            <Paper withBorder p="lg" key={capability.id}>
              <Stack gap="xs">
                <Group justify="space-between" align="flex-start">
                  <Text fw={600}>{capability.label}</Text>
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
                <Text c="dimmed" size="sm">
                  {capability.description}
                </Text>
                <Text c="dimmed" size="xs">
                  Source: {capability.source}
                </Text>
              </Stack>
            </Paper>
          ))}
        </SimpleGrid>
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
