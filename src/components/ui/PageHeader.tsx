import type { ReactNode } from 'react'
import { Group, Stack, Text, Title } from '@mantine/core'

type PageHeaderProps = {
  title: string
  description?: string
  actions?: ReactNode
}

export function PageHeader({ title, description, actions }: PageHeaderProps) {
  return (
    <Group justify="space-between" align="flex-start" wrap="wrap">
      <Stack gap="xs">
        <Title order={1} size="h2">
          {title}
        </Title>
        {description ? (
          <Text c="dimmed" size="sm">
            {description}
          </Text>
        ) : null}
      </Stack>
      {actions}
    </Group>
  )
}
