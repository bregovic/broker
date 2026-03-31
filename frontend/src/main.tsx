import React from 'react'
import ReactDOM from 'react-dom/client'
import { FluentProvider, webLightTheme } from '@fluentui/react-components'
import App from './App.tsx'
import { TranslationProvider } from './context/TranslationContext'
import './index.css'
import './App.css'

console.log(`Investyx App Version: ${__APP_VERSION__} (${__APP_BUILD_DATE__})`);

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <FluentProvider theme={webLightTheme}>
      <TranslationProvider>
        <App />
      </TranslationProvider>
    </FluentProvider>
  </React.StrictMode>,
)
