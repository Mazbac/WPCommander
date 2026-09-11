export type ConnectionStatus = 'ready' | 'needs-setup' | 'warning' | 'error'
export type AccessMode = 'read-only' | 'write-enabled'

export type DiagnosticCheck = {
  id: string
  label: string
  status: 'ok' | 'warning'
  detail: string
  address?: string
  encoding?: string
}

export type DiagnosticReport = {
  generatedAt: string
  accessMode: AccessMode
  resourceKinds?: string[]
  checks: DiagnosticCheck[]
}

export type CapabilitySummary = {
  id: string
  label: string
  description: string
  access: 'read' | 'write'
  source: 'WordPress' | 'WPCommander'
}

export type ActivityItem = {
  id: string
  action: string
  target: string
  state: 'planned' | 'applied' | 'reverted'
  timestamp: string
}

export type ControlPlaneSnapshot = {
  siteName: string
  wordpressVersion: string
  pluginVersion: string
  accessMode: AccessMode
  connectionStatus: ConnectionStatus
  connectionMessage: string
  schemaUrl: string
  diagnosticsUrl: string
  restNonce: string
  resourceKinds?: string[]
  capabilities: CapabilitySummary[]
  activity: ActivityItem[]
}

export const developmentControlPlane: ControlPlaneSnapshot = {
  siteName: 'Demo WordPress site',
  wordpressVersion: '7.1',
  pluginVersion: '0.1.0-dev',
  accessMode: 'read-only',
  connectionStatus: 'ready',
  connectionMessage: 'Control plane is ready for a Custom GPT connection.',
  schemaUrl: 'https://example.com/wp-json/wpcommander/v1/openapi',
  diagnosticsUrl: 'https://example.com/wp-json/wpcommander/v1/diagnostics',
  restNonce: 'development',
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

  capabilities: [
    {
      id: 'discover',
      label: 'Discover site capabilities',
      description:
        'Inspect exposed WordPress Abilities and generic WPCommander resources.',
      access: 'read',
      source: 'WPCommander',
    },
    {
      id: 'inspect',
      label: 'Inspect site data',
      description:
        'Search and inspect 11 generic WordPress resource kinds, including builder data, media, users, menus, plugins, themes, and site state.',
      access: 'read',
      source: 'WPCommander',
    },
    {
      id: 'execute',
      label: 'Run read-only abilities',
      description:
        'Execute exposed read-only WordPress Abilities while production writes stay blocked.',
      access: 'read',
      source: 'WordPress',
    },
  ],
  activity: [],
}

declare global {
  interface Window {
    wpCommanderBootstrap?: ControlPlaneSnapshot
  }
}

export function getControlPlaneSnapshot(): ControlPlaneSnapshot {
  return window.wpCommanderBootstrap ?? developmentControlPlane
}
