import { useEffect, useState, useCallback } from 'react'
import { ConfigProvider, Tabs, Alert, App as AntApp } from 'antd'
import { ApiOutlined, UnorderedListOutlined, SettingOutlined } from '@ant-design/icons'
import { api, bootData } from './api'
import BrandLoader from './components/BrandLoader'
import Flows from './pages/Flows'
import Logs from './pages/Logs'
import Settings from './pages/Settings'

export default function App() {
  const [meta, setMeta] = useState(null)
  const [error, setError] = useState('')
  const [tab, setTab] = useState(bootData.tab || 'flows')

  // Each tab has its own submenu page, so keep the address bar in sync.
  const goToTab = useCallback(key => {
    setTab(key)
    const url = bootData.tabUrls?.[key]
    if (url) {
      window.history.replaceState({}, '', url)
    }
  }, [])

  const reloadMeta = useCallback(() => {
    api
      .get('/meta')
      .then(setMeta)
      .catch(e => setError(e.message))
  }, [])

  useEffect(reloadMeta, [reloadMeta])

  const popupContainer = () => document.getElementById('wpi_wcrds-admin-root') || document.body

  return (
    <ConfigProvider getPopupContainer={popupContainer} theme={{ token: { colorPrimary: '#2271b1' } }}>
      <AntApp>
        <div style={{ padding: '20px 32px 40px' }}>
          <h1
            style={{
              fontSize: 22,
              fontWeight: 600,
              marginBottom: 4
            }}
          >
            Connector for WooCommerce and RD Station
          </h1>
          <p style={{ color: '#646970', marginTop: 0 }}>
            Send WooCommerce orders to RD Station Marketing.
          </p>
          {error && <Alert type="error" message={error} showIcon style={{ marginBottom: 16 }} />}
          {!meta && !error ? (
            <BrandLoader minHeight={320} />
          ) : (
            meta && (
              <Tabs
                activeKey={tab}
                onChange={goToTab}
                items={[
                  {
                    key: 'flows',
                    label: (
                      <span>
                        <ApiOutlined /> Integrations
                      </span>
                    ),
                    children: (
                      <Flows
                        meta={meta}
                        onChanged={reloadMeta}
                        onGoToSettings={() => goToTab('settings')}
                      />
                    )
                  },
                  {
                    key: 'logs',
                    label: (
                      <span>
                        <UnorderedListOutlined /> Logs
                      </span>
                    ),
                    children: <Logs />
                  },
                  {
                    key: 'settings',
                    label: (
                      <span>
                        <SettingOutlined /> Settings
                      </span>
                    ),
                    children: <Settings meta={meta} onChanged={reloadMeta} />
                  }
                ]}
              />
            )
          )}
        </div>
      </AntApp>
    </ConfigProvider>
  )
}
