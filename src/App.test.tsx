import { fireEvent, render, screen } from '@testing-library/react'
import { MantineProvider } from '@mantine/core'
import { afterEach, describe, expect, it } from 'vitest'
import App from './App'
import { developmentControlPlane } from './data/controlPlane'
import { theme } from './theme/theme'

describe('WPCommander overview', () => {
  afterEach(() => {
    delete window.wpCommanderBootstrap
  })

  it('renders connection readiness and GPT setup', () => {
    render(
      <MantineProvider theme={theme}>
        <App />
      </MantineProvider>,
    )

    expect(screen.getByRole('heading', { name: 'WPCommander' })).toBeVisible()
    expect(screen.getByText('Connect Custom GPT')).toBeVisible()
    expect(
      screen.getByRole('button', { name: 'Generate connection token' }),
    ).toBeVisible()
    expect(
      screen.getByRole('button', { name: 'Copy Action schema' }),
    ).toBeVisible()
    expect(
      screen.getByRole('button', { name: 'Copy GPT instructions' }),
    ).toBeVisible()
  })

  it('gates structured command access from wp-admin', async () => {
    render(
      <MantineProvider theme={theme}>
        <App />
      </MantineProvider>,
    )

    const enable = screen.getByRole('button', {
      name: 'Enable structured writes',
    })
    expect(enable).toBeVisible()
    fireEvent.click(enable)

    expect(
      await screen.findByRole('button', { name: 'Disable structured writes' }),
    ).toBeVisible()
    expect(screen.getByText('Writes enabled')).toBeVisible()
  })

  it('explains when Application Password authentication is unavailable', () => {
    window.wpCommanderBootstrap = {
      ...developmentControlPlane,
      connectionStatus: 'warning',
      connectionMessage:
        'Application Passwords are disabled by WordPress site policy or a security plugin. Enable them before connecting ChatGPT.',
      applicationPasswordSupported: false,
    }

    render(
      <MantineProvider theme={theme}>
        <App />
      </MantineProvider>,
    )

    expect(screen.getByText('Authentication unavailable')).toBeVisible()
    expect(screen.getByRole('status')).toHaveTextContent(
      'Application Passwords are disabled by WordPress site policy or a security plugin.',
    )
    expect(
      screen.getByRole('button', { name: 'Generate connection token' }),
    ).toBeDisabled()
  })
})
