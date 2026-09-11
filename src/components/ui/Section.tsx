import type { ReactNode } from 'react'
import { Stack, Text, Title } from '@mantine/core'

type SectionProps = {
  title: string
  description?: string
  children: ReactNode
}

export function Section({ title, description, children }: SectionProps) {
  return (
    <Stack gap="md">
      <Stack gap="xs">
        <Title order={2} size="h4">
          {title}
        </Title>
        {description ? (
          <Text c="dimmed" size="sm">
            {description}
          </Text>
        ) : null}
      </Stack>
      {children}
    </Stack>
  )
}
