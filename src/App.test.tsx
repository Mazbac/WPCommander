import { render, screen } from '@testing-library/react'
import { MantineProvider } from '@mantine/core'
import { describe, expect, it } from 'vitest'
import App from './App'
import { theme } from './theme/theme'

describe('starter UI', () => {
  it('renders the canonical UI baseline', () => {
    render(
      <MantineProvider theme={theme}>
        <App />
      </MantineProvider>,
    )

    expect(screen.getByRole('heading', { name: 'UI baseline' })).toBeVisible()
    expect(screen.getByRole('button', { name: 'Primary action' })).toBeVisible()
  })
})
