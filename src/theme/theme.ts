import { createTheme, type CSSVariablesResolver } from '@mantine/core'

const fontFamily =
  'Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif'

export const layoutTokens = {
  headerHeight: 56,
  navbarWidth: 224,
} as const

export const theme = createTheme({
  primaryColor: 'blue',
  primaryShade: { light: 8, dark: 6 },
  autoContrast: true,
  defaultRadius: 'sm',
  fontFamily,
  headings: {
    fontFamily,
    fontWeight: '600',
  },
})

export const cssVariablesResolver: CSSVariablesResolver = (resolvedTheme) => ({
  variables: {},
  light: { '--mantine-color-dimmed': resolvedTheme.colors.gray[7] },
  dark: { '--mantine-color-dimmed': resolvedTheme.colors.gray[4] },
})
