import {
  Badge,
  Button,
  Group,
  Paper,
  ScrollArea,
  Select,
  SimpleGrid,
  Stack,
  Table,
  Text,
  TextInput,
} from '@mantine/core'
import { EmptyState } from '../components/ui/EmptyState'
import { PageHeader } from '../components/ui/PageHeader'
import { Section } from '../components/ui/Section'

const projects = [
  { name: 'Customer portal', status: 'Active', updated: 'Today' },
  {
    name: 'Annual consolidated international procurement project — 2027 revision final',
    status: 'Draft',
    updated: 'Sep 10, 2026',
  },
]

export function UiShowroom() {
  return (
    <Stack gap="xl" maw="80rem">
      <PageHeader
        title="UI baseline"
        description="Canonical components and stress states used to prevent visual drift."
        actions={<Button>Primary action</Button>}
      />

      <Section title="Controls">
        <Paper withBorder p="lg">
          <SimpleGrid cols={{ base: 1, md: 2 }} spacing="lg">
            <Stack gap="md">
              <TextInput
                label="Project name"
                placeholder="Enter a project name"
              />
              <Select
                label="Status"
                placeholder="Select status"
                data={['Active', 'Draft', 'Archived']}
              />
            </Stack>
            <Stack gap="md" justify="space-between">
              <Group>
                <Badge>Active</Badge>
                <Badge color="yellow">Warning</Badge>
                <Badge color="red">Error</Badge>
              </Group>
              <Group justify="flex-end">
                <Button variant="default">Cancel</Button>
                <Button>Save changes</Button>
              </Group>
            </Stack>
          </SimpleGrid>
        </Paper>
      </Section>

      <Section title="Data">
        <Paper withBorder>
          <ScrollArea>
            <Table verticalSpacing="sm" horizontalSpacing="md" highlightOnHover>
              <Table.Thead>
                <Table.Tr>
                  <Table.Th>Name</Table.Th>
                  <Table.Th>Status</Table.Th>
                  <Table.Th>Updated</Table.Th>
                  <Table.Th>Action</Table.Th>
                </Table.Tr>
              </Table.Thead>
              <Table.Tbody>
                {projects.map((project) => (
                  <Table.Tr key={project.name}>
                    <Table.Td>{project.name}</Table.Td>
                    <Table.Td>{project.status}</Table.Td>
                    <Table.Td>{project.updated}</Table.Td>
                    <Table.Td>
                      <Button variant="subtle" size="xs">
                        Open
                      </Button>
                    </Table.Td>
                  </Table.Tr>
                ))}
              </Table.Tbody>
            </Table>
          </ScrollArea>
        </Paper>
      </Section>

      <Section title="Empty state">
        <Paper withBorder>
          <EmptyState
            title="No projects yet"
            description="Create a project to get started."
            action={<Button>Create project</Button>}
          />
        </Paper>
      </Section>

      <Text c="dimmed" size="xs">
        Stress sample: über-long-filename_final_COMPLETE_2026_revision-172.pdf ·
        €1,293,827.43 · العربية
      </Text>
    </Stack>
  )
}
