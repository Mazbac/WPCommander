import type { ReactNode } from 'react'
import { AppShell, Burger, Group, NavLink, Stack, Text } from '@mantine/core'
import { useDisclosure } from '@mantine/hooks'
import { layoutTokens } from '../theme/theme'

type AppFrameProps = {
  children: ReactNode
}

export function AppFrame({ children }: AppFrameProps) {
  const [opened, { toggle }] = useDisclosure(false)

  return (
    <AppShell
      header={{ height: layoutTokens.headerHeight }}
      navbar={{
        width: layoutTokens.navbarWidth,
        breakpoint: 'sm',
        collapsed: { mobile: !opened },
      }}
      padding="lg"
    >
      <AppShell.Header>
        <Group h="100%" px="md">
          <Burger
            opened={opened}
            onClick={toggle}
            hiddenFrom="sm"
            size="sm"
            aria-label="Toggle navigation"
          />
          <Text fw={600}>AI Project Starter</Text>
        </Group>
      </AppShell.Header>

      <AppShell.Navbar p="sm">
        <Stack gap="xs">
          <NavLink label="UI baseline" active />
        </Stack>
      </AppShell.Navbar>

      <AppShell.Main>{children}</AppShell.Main>
    </AppShell>
  )
}
