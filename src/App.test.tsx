import { render, screen } from '@testing-library/react'
import { MantineProvider } from '@mantine/core'
import { describe, expect, it } from 'vitest'
import App from './App'
import { theme } from './theme/theme'

describe('WPCommander overview', () => {
  it('renders connection readiness and GPT setup', () => {
    render(
      <MantineProvider theme={theme}>
        <App />
      </MantineProvider>,
    )

    expect(screen.getByRole('heading', { name: 'WPCommander' })).toBeVisible()
    expect(screen.getByText('Control plane')).toBeVisible()
    expect(screen.getByRole('button', { name: 'Copy URL' })).toBeVisible()
    expect(screen.getByText('Connect ChatGPT')).toBeVisible()
  })
})
