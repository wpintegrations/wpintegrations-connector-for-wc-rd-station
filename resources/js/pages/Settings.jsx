import { useEffect, useState } from 'react'
import { Form, InputNumber, Input, Button, Divider, App as AntApp } from 'antd'
import { CheckCircleFilled, ArrowRightOutlined } from '@ant-design/icons'
import { api } from '../api'
import RdStationConnection from '../components/RdStationConnection'
import BrandLoader from '../components/BrandLoader'

// What the separate Pro plugin offers, as the readme lists it.
const PRO_FEATURES = [
  'RD Station CRM as a destination: a contact and a deal for every order, with its products and value',
  'Deals marked won or lost as the order completes, is cancelled or refunded',
  'Tags, funnel stage, lead owner and the opportunity flag',
  'A delivery queue that retries a failed send on its own'
]

export default function Settings({ meta, onChanged }) {
  const { message } = AntApp.useApp()
  const [form] = Form.useForm()
  const [saving, setSaving] = useState(false)
  // The page waits on the payload, so the form takes it as initial values:
  // setFieldsValue would land on an unmounted form and be dropped.
  const [initial, setInitial] = useState(null)
  const [ready, setReady] = useState(false)

  useEffect(() => {
    api
      .get('/settings')
      .then(setInitial)
      .catch(e => message.error(e.message))
      .finally(() => setReady(true))
  }, [message])

  const save = async values => {
    setSaving(true)
    try {
      const saved = await api.post('/settings', values)
      form.setFieldsValue(saved)
      message.success('Settings saved.')
      onChanged?.()
    } catch (e) {
      message.error(e.message)
    } finally {
      setSaving(false)
    }
  }

  const actionsWithGlobals = meta.actions.filter(a => a.global_fields?.length)
  const marketingAction = meta.actions.find(a => a.key === 'rd_station') || meta.actions[0]

  if (!ready) {
    return <BrandLoader />
  }

  return (
    <div style={{ maxWidth: 560 }}>
      <RdStationConnection actionKey={marketingAction?.key} onChanged={onChanged} />

      <Form form={form} layout="vertical" initialValues={initial || undefined} onFinish={save}>
        {actionsWithGlobals.map(action => (
          <div key={action.key}>
            <Divider plain>{action.label}</Divider>
            {action.global_fields.map(gf => (
              <Form.Item key={gf.key} name={['actions', action.key, gf.key]} label={gf.label}>
                <Input autoComplete="off" spellCheck={false} />
              </Form.Item>
            ))}
          </div>
        ))}

        <Divider plain>Logs</Divider>
        <Form.Item name="log_retention_days" label="Keep delivery logs for (days)">
          <InputNumber min={1} max={365} />
        </Form.Item>

        <Button type="primary" htmlType="submit" loading={saving}>
          Save settings
        </Button>
      </Form>

      {meta.pro_url && (
        <div className="wpi-pro-card">
          <div className="wpi-pro-card__head">
            <span className="wpi-pro-card__badge">PRO</span>
            <span className="wpi-pro-card__title">Get more out of RD Station</span>
          </div>
          <p className="wpi-pro-card__lead">
            Turn every order into a deal in RD Station CRM, keep it in step with the order status, and
            never lose one to a failed send.
          </p>
          <ul className="wpi-pro-card__list">
            {PRO_FEATURES.map(feature => (
              <li key={feature}>
                <CheckCircleFilled className="wpi-pro-card__check" />
                <span>{feature}</span>
              </li>
            ))}
          </ul>
          <div className="wpi-pro-card__foot">
            <a className="wpi-pro-card__cta" href={meta.pro_url} target="_blank" rel="noreferrer">
              Upgrade to Pro <ArrowRightOutlined />
            </a>
          </div>
        </div>
      )}
    </div>
  )
}
