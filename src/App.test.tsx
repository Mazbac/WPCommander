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

  it('renders the compact connection and access overview', () => {
    render(
      <MantineProvider theme={theme}>
        <App />
      </MantineProvider>,
    )

    expect(screen.getByRole('heading', { name: 'WPCommander' })).toBeVisible()
    expect(screen.getByRole('heading', { name: 'Connection' })).toBeVisible()
    expect(screen.getByRole('heading', { name: 'Site access' })).toBeVisible()
    expect(
      screen.getByRole('button', { name: 'Create credential' }),
    ).toBeVisible()
    expect(screen.getByText('Custom GPT setup')).toBeVisible()
  })
  it('switches between inspect and edit access levels', () => {
    render(
      <MantineProvider theme={theme}>
        <App />
      </MantineProvider>,
    )

    const inspect = screen.getByRole('button', { name: 'Inspect only' })
    const edit = screen.getByRole('button', { name: 'Edit site' })
    expect(inspect).toHaveAttribute('aria-pressed', 'true')
    expect(edit).toHaveAttribute('aria-pressed', 'false')

    fireEvent.click(edit)

    expect(edit).toHaveAttribute('aria-pressed', 'true')
    expect(inspect).toHaveAttribute('aria-pressed', 'false')
  })

  it('treats full control as one coherent access level', () => {
    render(
      <MantineProvider theme={theme}>
        <App />
      </MantineProvider>,
    )

    const full = screen.getByRole('button', { name: 'Full control' })
    const edit = screen.getByRole('button', { name: 'Edit site' })
    fireEvent.click(full)
    expect(full).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByText(/every WordPress control layer/i)).toBeVisible()

    fireEvent.click(edit)
    expect(edit).toHaveAttribute('aria-pressed', 'true')
    expect(full).toHaveAttribute('aria-pressed', 'false')
  })
  it('keeps diagnostics secondary until requested', () => {
    render(
      <MantineProvider theme={theme}>
        <App />
      </MantineProvider>,
    )

    expect(screen.queryByText(/Diagnostics complete:/i)).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Run diagnostics' }))
    expect(screen.getByText(/Diagnostics complete:/i)).toBeVisible()
    expect(screen.getByText('View diagnostic report')).toBeVisible()
  })

  it('explains when WordPress connection authentication is unavailable', () => {
    window.wpCommanderBootstrap = {
      ...developmentControlPlane,
      connectionStatus: 'warning',
      connectionMessage:
        'Application Passwords are disabled by WordPress site policy or a security plugin.',
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
      screen.getByRole('button', { name: 'Create credential' }),
    ).toBeDisabled()
  })

  it('shows universal execution in recent activity', () => {
    window.wpCommanderBootstrap = {
      ...developmentControlPlane,
      executionActivity: [
        {
          id: 'exec-1',
          action: 'call-function',
          target: 'wp_update_nav_menu_item',
          state: 'applied',
          timestamp: '2026-09-12T03:00:00Z',
        },
      ],
    }

    render(
      <MantineProvider theme={theme}>
        <App />
      </MantineProvider>,
    )

    expect(screen.getAllByText('call-function').length).toBeGreaterThan(0)
    expect(
      screen.getAllByText('wp_update_nav_menu_item').length,
    ).toBeGreaterThan(0)
  })
})
