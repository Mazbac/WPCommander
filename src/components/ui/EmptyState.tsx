import type { ReactNode } from 'react'
import { Center, Stack, Text, Title } from '@mantine/core'

type EmptyStateProps = {
  title: string
  description?: string
  action?: ReactNode
}

export function EmptyState({ title, description, action }: EmptyStateProps) {
  return (
    <Center py="xl">
      <Stack align="center" gap="sm" maw="32rem" ta="center">
        <Title order={3} size="h5">
          {title}
        </Title>
        {description ? (
          <Text c="dimmed" size="sm">
            {description}
          </Text>
        ) : null}
        {action}
      </Stack>
    </Center>
  )
}
