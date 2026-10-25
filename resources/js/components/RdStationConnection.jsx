import { useEffect, useState } from 'react'
import { Alert, Button, Card, Input, Space, Typography, Form, App as AntApp, Tooltip } from 'antd'
import {
  LinkOutlined,
  CopyOutlined,
  DisconnectOutlined,
  CheckCircleFilled,
  ApiOutlined,
  ReloadOutlined
} from '@ant-design/icons'
import { api } from '../api'
import { formatDate } from '../format'
import { copyText } from '../copy-text'

/**
 * The site's RD Station Marketing connection. Each site registers its own app
 * in the RD Station App Store, so no credentials ship with the plugin.
 */
export default function RdStationConnection({ actionKey, onChanged }) {
  const { message, modal } = AntApp.useApp()
  const [status, setStatus] = useState(null)
  const [saving, setSaving] = useState(false)
  const [connecting, setConnecting] = useState(false)
  const [testing, setTesting] = useState(false)
  const [form] = Form.useForm()

  const productName = 'Marketing'

  const load = () =>
    api
      .get('/oauth/status')
      .then(s => {
        setStatus(s)
        form.setFieldsValue({
          client_id: s.client_id || '',
          // Stars and the secret's last characters; sent back unchanged, it
          // means "keep the stored secret".
          client_secret: s.secret_mask || ''
        })
      })
      .catch(e => message.error(e.message))

  useEffect(() => {
    load()

    // The callback sends the browser back here carrying the outcome.
    const params = new URLSearchParams(window.location.search)
    const result = params.get('wpi_wcrds_oauth')
    if (result === 'connected') {
      message.success(`Connected to RD Station ${productName}.`)
      onChanged?.()
    } else if (result === 'error') {
      message.error(params.get('wpi_wcrds_oauth_error') || 'Connection failed.')
    }
    if (result) {
      params.delete('wpi_wcrds_oauth')
      params.delete('wpi_wcrds_oauth_error')
      window.history.replaceState({}, '', `${window.location.pathname}?${params.toString()}`)
    }
  }, [])

  const copy = async () => {
    if (await copyText(status.redirect_uri)) {
      message.success('Callback URL copied.')
    } else {
      message.warning('Copy failed — select the URL and copy it manually.')
    }
  }

  const saveCredentials = async () => {
    let values
    try {
      values = await form.validateFields()
    } catch {
      return
    }
    setSaving(true)
    try {
      const saved = await api.post('/oauth/credentials', values)
      setStatus(saved)
      form.setFieldsValue({ client_secret: saved.secret_mask || '' })
      message.success('App credentials saved.')
    } catch (e) {
      message.error(e.message)
    } finally {
      setSaving(false)
    }
  }

  const connect = async () => {
    setConnecting(true)
    try {
      const { url } = await api.post('/oauth/authorize', {})
      window.location.href = url
    } catch (e) {
      message.error(e.message)
      setConnecting(false)
    }
  }

  // Re-runs consent with the stored credentials. Tokens are replaced only on
  // success, so a cancelled reconnect leaves the connection working.
  const reconnect = () =>
    modal.confirm({
      title: `Reconnect ${productName} to RD Station?`,
      content:
        'You will be sent to RD Station to authorize again — useful after revoking access there, or to switch to a different account. The current connection keeps working until the new one succeeds.',
      okText: 'Reconnect',
      onOk: connect
    })

  // Proves the token can still deliver; "connected" alone does not, since it
  // may have been revoked in RD Station since.
  const test = async () => {
    setTesting(true)
    try {
      const data = await api.post('/connection/test', { action_key: actionKey })
      message.success(data?.message || 'Connection successful.')
    } catch (e) {
      message.error(e.message)
    } finally {
      setTesting(false)
    }
  }

  const disconnect = () =>
    modal.confirm({
      title: `Disconnect ${productName} from RD Station?`,
      content: 'Your integrations will stop delivering until you reconnect.',
      okText: 'Disconnect',
      okButtonProps: { danger: true },
      onOk: async () => {
        try {
          setStatus(await api.post('/oauth/disconnect', {}))
          message.success('Disconnected.')
          onChanged?.()
        } catch (e) {
          message.error(e.message)
        }
      }
    })

  if (!status) {
    return <Card size="small" loading style={{ marginBottom: 24 }} />
  }

  return (
    <Card size="small" title={`RD Station ${productName} connection`} style={{ marginBottom: 24 }}>
      {status.connected ? (
        <Space direction="vertical" style={{ width: '100%' }}>
          <Alert
            type="success"
            showIcon
            icon={<CheckCircleFilled />}
            message={`Connected to RD Station ${productName}`}
            description={
              <>
                Your integrations are ready — WooCommerce orders are delivered to this RD Station
                account.
                {status.connected_at ? ` Authorized ${formatDate(status.connected_at)}.` : ''}
              </>
            }
          />
          <Space wrap>
            <Button icon={<ApiOutlined />} loading={testing} onClick={test}>
              Test connection
            </Button>
            <Button icon={<ReloadOutlined />} loading={connecting} onClick={reconnect}>
              Reconnect
            </Button>
            <Button icon={<DisconnectOutlined />} danger onClick={disconnect}>
              Disconnect
            </Button>
          </Space>
        </Space>
      ) : (
        <>
          <Typography.Paragraph type="secondary" style={{ fontSize: 12 }}>
            Integrations deliver through this connection, which also lets the plugin read your account's
            custom fields so you can pick them when mapping. Create an app in the RD Station App Store
            register the callback URL below, then paste its credentials here.{' '}
            <Typography.Link
              href="https://developers.rdstation.com/reference/faq-autenticacao"
              target="_blank"
            >
              How to create an app
            </Typography.Link>
          </Typography.Paragraph>

          <Form.Item label="Callback URL to register in your app" style={{ marginBottom: 12 }}>
            <Space.Compact style={{ width: '100%' }}>
              <Input readOnly value={status.redirect_uri} onFocus={e => e.target.select()} />
              <Tooltip title="Copy">
                <Button icon={<CopyOutlined />} onClick={copy} />
              </Tooltip>
            </Space.Compact>
          </Form.Item>

          <Form form={form} layout="vertical">
            <Form.Item
              name="client_id"
              label="Client ID"
              rules={[{ required: true, message: 'Paste the Client ID from your RD Station app.' }]}
            >
              <Input placeholder="e.g. c9d14ec8-2671-404e-b337-ebae63906a8b" />
            </Form.Item>
            <Form.Item
              name="client_secret"
              label="Client Secret"
              rules={[{ required: true, message: 'Paste the Client Secret from your RD Station app.' }]}
            >
              <Input autoComplete="off" spellCheck={false} placeholder="Paste the Client Secret" />
            </Form.Item>
          </Form>

          <Space>
            <Button loading={saving} onClick={saveCredentials}>
              Save credentials
            </Button>
            <Tooltip title={status.has_credentials ? '' : 'Save your Client ID and Secret first.'}>
              <span style={{ display: 'inline-block' }}>
                <Button
                  type="primary"
                  icon={<LinkOutlined />}
                  loading={connecting}
                  disabled={!status.has_credentials}
                  onClick={connect}
                >
                  Connect to RD Station {productName}
                </Button>
              </span>
            </Tooltip>
          </Space>
        </>
      )}
    </Card>
  )
}
