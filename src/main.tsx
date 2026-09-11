import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { MantineProvider } from '@mantine/core'
import '@mantine/core/styles.css'
import './index.css'
import App from './App'
import { cssVariablesResolver, theme } from './theme/theme'

const rootElement =
  document.getElementById('wpcommander-root') ?? document.getElementById('root')

if (!rootElement) {
  throw new Error('WPCommander root element was not found.')
}

createRoot(rootElement).render(
  <StrictMode>
    <MantineProvider theme={theme} cssVariablesResolver={cssVariablesResolver}>
      <App />
    </MantineProvider>
  </StrictMode>,
)
