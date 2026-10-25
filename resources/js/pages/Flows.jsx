import { useEffect, useState } from 'react'
import {
  Table,
  Button,
  Drawer,
  Form,
  Input,
  Select,
  Space,
  Tag,
  Steps,
  Dropdown,
  Flex,
  Radio,
  Alert,
  Checkbox,
  Switch,
  Empty,
  Typography,
  Tooltip,
  Card,
  App as AntApp
} from 'antd'
import {
  PlusOutlined,
  DeleteOutlined,
  EditOutlined,
  MinusCircleOutlined,
  ReloadOutlined,
  MoreOutlined,
  SearchOutlined
} from '@ant-design/icons'
import { api } from '../api'
import { formatDate } from '../format'
import BrandLoader from '../components/BrandLoader'

// Source sentinel: the row's typed value is sent verbatim.
const STATIC_SOURCE = '_static'

// Values resolved at delivery time instead of read from the order.
// Keep in sync with Action::smart_value() in PHP.
const SMART_SOURCES = [
  { value: '_smart_page_url', label: 'Checkout page URL' },
  { value: '_smart_submitted_at', label: 'Order date & time (UTC)' },
  { value: '_smart_user_email', label: 'Customer account email' },
  { value: '_smart_user_name', label: 'Customer account name' },
  { value: '_smart_site_title', label: 'Site title' },
  { value: '_smart_site_url', label: 'Site URL' }
]

// Short blurbs for the destination step. Keep in sync with the action labels.
const ACTION_DESCRIPTIONS = {
  rd_station: 'Sends WooCommerce orders as conversion events to your RD Station Marketing account.'
}

// Groups gated behind a boolean flow setting: offered only while the toggle is
// on. A group with no entry here is always written.
const GROUP_TOGGLES = {}

const GROUP_LABELS = {
  contact: 'Contact fields',
  default: 'Standard fields'
}

export default function Flows({ meta, onChanged, onGoToSettings }) {
  const { message, modal } = AntApp.useApp()
  const [flows, setFlows] = useState([])
  const [loading, setLoading] = useState(true)
  // First payload only: later reloads keep the page up and spin the table.
  const [ready, setReady] = useState(false)
  const [sources, setSources] = useState(null)
  const [refreshingForms, setRefreshingForms] = useState(false)
  const [editing, setEditing] = useState(null) // null = closed, {} = new, {...flow} = edit
  const [step, setStep] = useState(0)
  const [saving, setSaving] = useState(false)
  const [actionKey, setActionKey] = useState(meta.actions[0]?.key)
  const [rdFields, setRdFields] = useState(null) // null = not discovered, else the account's fields
  const [rdFieldsLoading, setRdFieldsLoading] = useState(false)
  const [rdFieldsFetched, setRdFieldsFetched] = useState(false)
  const [optionMap, setOptionMap] = useState({}) // sourceKey => {options, loading, fetched}
  const [selectedRowKeys, setSelectedRowKeys] = useState([])
  const [query, setQuery] = useState('')
  const [form] = Form.useForm()

  // The plugin ships one trigger, so the UI never asks which store event.
  const trigger = meta.triggers[0]

  const action = meta.actions.find(a => a.key === actionKey) || meta.actions[0]

  // Which record groups this flow writes: everything not gated by a toggle,
  // plus the gated ones whose toggle is on.
  const settings = Form.useWatch('settings', form) || {}
  const groupOn = group => !GROUP_TOGGLES[group] || !!settings[GROUP_TOGGLES[group]]
  const includedGroups = new Set(
    (action?.target_fields || []).map(t => t.group || 'default').filter(groupOn)
  )

  // The group a target belongs to, or null when it is a key the user typed
  // freehand and we cannot place it.
  const groupOfTarget = key => {
    const standard = (action?.target_fields || []).find(t => t.key === key)
    if (standard) {
      return standard.group || 'default'
    }
    const discovered = (rdFields || []).find(f => f.key === key)
    return discovered ? discovered.group || 'default' : null
  }

  // Required targets get pinned rows with a fixed target; the user only picks
  // the order field that feeds them.
  const allTargets = (action?.target_fields || []).filter(t => includedGroups.has(t.group || 'default'))

  // Account-mandated custom fields count as required too — always, or once the
  // flow writes into the stage the rule names. The rule's property is a setting
  // key, so it reads straight off the current settings.
  const ruleRequires = field =>
    (field.required_rules || []).some(
      r => r.always || (r.property && String(settings[r.property] ?? '') === String(r.value))
    )
  const requiredDiscovered = (rdFields || []).filter(
    f => includedGroups.has(f.group || 'default') && ruleRequires(f)
  )

  const requiredTargetKeys = [
    ...allTargets.filter(t => t.required).map(t => t.key),
    ...requiredDiscovered.map(f => f.key)
  ]
  const requiredKeySet = new Set(requiredTargetKeys)

  const labelForTarget = key =>
    allTargets.find(t => t.key === key)?.label ||
    requiredDiscovered.find(f => f.key === key)?.label ||
    key

  // One row per required target, always first. Existing rows move up
  // rather than being duplicated.
  const withRequiredRows = map => {
    const rows = Array.isArray(map) ? map.filter(Boolean) : []
    const required = requiredTargetKeys.map(k => {
      const idx = rows.findIndex(r => r?.target === k)
      return idx === -1 ? { target: k } : rows.splice(idx, 1)[0]
    })
    return [...required, ...rows]
  }

  const actionLabel = key => meta.actions.find(a => a.key === key)?.label || key

  const load = () => {
    setLoading(true)
    api
      .get('/flows')
      .then(setFlows)
      .catch(e => message.error(e.message))
      .finally(() => {
        setLoading(false)
        setReady(true)
      })
  }
  useEffect(() => {
    load()
    api
      .get('/sources')
      .then(setSources)
      .catch(() => setSources({}))
  }, [])

  const refreshForms = () => {
    setRefreshingForms(true)
    api
      .get('/sources?refresh=1')
      .then(setSources)
      .catch(e => message.error(e.message))
      .finally(() => setRefreshingForms(false))
  }

  // Order statuses read from WooCommerce, each offering the order fields.
  // Shapes seen in real orders fill the gaps: fields no longer discovered.
  const forms = sources?.[trigger?.key] || []
  const shapes = meta.recent_shapes.filter(s => s.trigger === trigger?.key)

  const selectedSource = Form.useWatch('trigger_source', form)
  const selectedForm = forms.find(f => f.id === selectedSource)

  // A status can feed any number of integrations, so the picker never
  // disables or annotates options.
  const formOptions = [
    ...forms.map(f => ({
      value: f.id,
      label: f.name
    })),
    ...shapes
      .filter(s => !forms.some(f => f.id === s.source))
      .map(s => ({
        value: s.source,
        label: `${s.label || s.source} (recently seen)`
      }))
  ]
  if (selectedSource && !formOptions.some(o => o.value === selectedSource)) {
    formOptions.push({ value: selectedSource, label: selectedSource })
  }

  const sourceName = value => formOptions.find(o => o.value === value)?.label || value

  // Fields offered in the mapping's left column for the chosen status.
  // With none selected, fall back to the union of everything known.
  const knownFields = (() => {
    const shapeFields = source =>
      (shapes.find(s => s.source === source)?.fields || []).map(id => ({ id, label: id, type: 'text' }))

    if (selectedForm) {
      const ids = new Set(selectedForm.fields.map(f => f.id))
      return [...selectedForm.fields, ...shapeFields(selectedForm.id).filter(f => !ids.has(f.id))]
    }
    if (selectedSource) {
      return shapeFields(selectedSource)
    }
    const seen = new Set()
    const all = []
    forms.forEach(f =>
      f.fields.forEach(ff => {
        if (!seen.has(ff.id)) {
          seen.add(ff.id)
          all.push(ff)
        }
      })
    )
    shapes.forEach(s =>
      s.fields.forEach(id => {
        if (!seen.has(id)) {
          seen.add(id)
          all.push({ id, label: id, type: 'text' })
        }
      })
    )
    return all
  })()

  // A field id is usually just a slug of its label ("Name" → "name"), so append
  // it only when it adds information: a hand-set key, or an ambiguous name.
  const fieldOptions = (() => {
    const slug = s =>
      String(s ?? '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '')

    const named = knownFields.map(f => ({ field: f, name: f.label || f.id }))
    const seen = new Set()
    const ambiguous = new Set()
    named.forEach(({ name }) => (seen.has(name) ? ambiguous.add(name) : seen.add(name)))

    return named.map(({ field, name }) => ({
      value: field.id,
      label: slug(name) === slug(field.id) && !ambiguous.has(name) ? name : `${name} (${field.id})`
    }))
  })()

  // Each RD Station product has its own connection slot, and a flow only needs
  // the one its action delivers through.
  const connectionFor = aKey => (aKey === 'rd_station' ? !!meta.oauth?.connected : false)
  const anyConnected = meta.actions.some(a => connectionFor(a.key))

  const goToSettings = (title, content) => {
    modal.confirm({
      title,
      content,
      okText: 'Go to Settings',
      cancelText: 'Cancel',
      onOk: () => onGoToSettings?.()
    })
    return false
  }

  // Nothing connected means no destination worth opening the editor for.
  const requireAnyConnection = () =>
    anyConnected ||
    goToSettings(
      'Connect RD Station first',
      'Integrations deliver through your RD Station connection, and none exists yet. Connect your account in Settings, then create the integration.'
    )

  // The destination is chosen inside the editor, so its own connection can
  // only be checked once it is known — on the way out of the first step.
  const requireConnection = aKey =>
    connectionFor(aKey) ||
    goToSettings(
      `Connect ${actionLabel(aKey)} first`,
      `${actionLabel(
        aKey
      )} delivers through its own RD Station connection, and none exists yet. Connect the ${actionLabel(
        aKey
      )} app under Settings, then come back.`
    )

  const openEditor = flow => {
    const aKey = flow?.action_key || meta.actions[0]?.key
    setActionKey(aKey)
    setEditing(flow || {})
    setStep(0)
    setRdFields(null)
    setRdFieldsFetched(false)
    setOptionMap({})

    const selected = meta.actions.find(a => a.key === aKey) || meta.actions[0]
    const nextSettings = { ...(flow?.settings || {}) }
    // A new flow starts from each setting's declared default.
    if (!flow) {
      ;(selected?.flow_fields || []).forEach(ff => {
        if (ff.default !== undefined && nextSettings[ff.key] === undefined) {
          nextSettings[ff.key] = ff.default
        }
      })
    }

    form.setFieldsValue(
      flow
        ? {
            title: flow.title,
            // Legacy "all statuses" flows ('') must pick one when next edited.
            trigger_source: flow.trigger_source || undefined,
            // Stored as-is: actionKey only updates next render, so pinning the
            // required rows here would use the previous action's. The step-2
            // effect pins them once the mapping step opens.
            field_map: (flow.field_map || []).filter(Boolean),
            settings: nextSettings
          }
        : {
            title: '',
            // Processing: the status a paid order reaches.
            trigger_source: forms.some(f => f.id === 'processing') ? 'processing' : undefined,
            // Every order carries these, so a new integration starts mapped.
            field_map: [
              { source: 'billing_email', target: 'email' },
              { source: 'billing_full_name', target: 'name' }
            ],
            settings: nextSettings
          }
    )
  }

  const close = () => {
    setEditing(null)
    form.resetFields()
  }

  // Switching destination mid-edit resets discovery and options for the new
  // action; the mapping rows are rebuilt below when the record groups change.
  useEffect(() => {
    if (editing === null) {
      return
    }
    setRdFields(null)
    setRdFieldsFetched(false)
    setOptionMap({})
  }, [actionKey])

  const nextFromDestination = () => {
    if (!meta.actions.length) {
      message.warning('No destination available.')
      return
    }
    if (!requireConnection(actionKey)) {
      return
    }
    setStep(1)
  }

  const next = async () => {
    try {
      await form.validateFields(['title'])
    } catch {
      return
    }
    if (!form.getFieldValue('trigger_source')) {
      message.warning('Pick the order status this integration listens to.')
      return
    }
    setStep(2)
  }

  // The account's own field list, cf_* customs included. Fetched once per drawer
  // session on reaching the mapping step; Refresh re-fetches past the server
  // cache. Without a connection the static targets still work.
  const loadRdFields = (refresh = false) => {
    setRdFieldsFetched(true)
    setRdFieldsLoading(true)
    api
      .post('/connection/fields', {
        action_key: actionKey,
        flow_id: editing?.id,
        refresh
      })
      .then(data => {
        const discovered = data?.discovered && data.fields?.length ? data.fields : null
        setRdFields(discovered)
        if (refresh) {
          if (discovered) {
            message.success('Field list refreshed from your RD Station account.')
          } else {
            message.info(
              'Connect RD Station under Settings to load your account fields. Standard fields and typed cf_* keys still work.'
            )
          }
        }
      })
      .catch(e => {
        setRdFields(null)
        if (refresh) {
          message.error(e.message)
        }
      })
      .finally(() => setRdFieldsLoading(false))
  }

  useEffect(() => {
    if (step === 2 && editing !== null && !rdFieldsFetched) {
      loadRdFields()
    }
  }, [step, editing, rdFieldsFetched, actionKey])

  // Mapping targets, grouped by the record they write. Standard fields always
  // work; discovered account fields join their group. Required targets live
  // only in pinned rows, so editable rows never offer them.
  const selectableTargets = allTargets.filter(t => !requiredKeySet.has(t.key))
  // Pinned rows hold a required target, so their (disabled) select needs the
  // required keys in its options too or it would render the bare key.
  const lockedTargetOptions = [
    ...allTargets.map(t => ({ value: t.key, label: t.label })),
    ...requiredDiscovered.map(f => ({ value: f.key, label: f.label }))
  ]
  const standardKeys = new Set(allTargets.map(t => t.key))
  const accountTargets = (rdFields || []).filter(
    f =>
      includedGroups.has(f.group || 'default') && !standardKeys.has(f.key) && !requiredKeySet.has(f.key)
  )
  const targetOptions = [...includedGroups].map(group => ({
    label: GROUP_LABELS[group] || group,
    options: [
      ...selectableTargets
        .filter(t => (t.group || 'default') === group)
        .map(t => ({ value: t.key, label: t.label })),
      ...accountTargets
        .filter(t => (t.group || 'default') === group)
        .map(t => ({ value: t.key, label: t.custom ? `${t.label} (${t.key})` : t.label }))
    ]
  }))

  // Mapping sources: the form's fields, delivery-time values, and a static
  // literal. Free text still works for field ids the scanner hasn't seen.
  const sourceOptions = [
    { label: 'Order fields', options: fieldOptions },
    { label: 'Smart values', options: SMART_SOURCES },
    { label: 'Other', options: [{ value: STATIC_SOURCE, label: 'Static value…' }] }
  ]

  // Current rows, used to show the value input on static ones.
  const fieldMapRows = Form.useWatch('field_map', form) || []

  // When the record groups change, the mapping rows follow: rows for a group
  // this flow no longer writes are dropped (they would never be sent), and the
  // pinned required rows are rebuilt around whatever is required now.
  useEffect(() => {
    if (editing === null || step < 2) {
      return
    }
    const rows = (form.getFieldValue('field_map') || []).filter(Boolean)
    const kept = rows.filter(r => {
      const group = groupOfTarget(r?.target)
      return group === null || includedGroups.has(group)
    })
    form.setFieldsValue({ field_map: withRequiredRows(kept) })

    const dropped = rows.length - kept.length
    if (dropped > 0) {
      message.info(
        `Removed ${dropped} mapping${
          dropped > 1 ? 's' : ''
        } for records this integration no longer creates.`
      )
    }
    // The helpers are recreated every render; only these triggers matter.
    // rdFields and the stage are here because a mandatory custom field is only
    // known once discovery lands, and its rule can hang off the stage.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [step, editing, actionKey, rdFields])

  // Options for flow settings whose field carries an options_source, fetched
  // once per (action, source) per drawer session.
  const loadOptions = (sourceKey, ctxSettings) => {
    const cacheKey = `${actionKey}:${sourceKey}`
    const cached = optionMap[cacheKey]
    if (cached && (cached.loading || cached.fetched)) {
      return
    }
    setOptionMap(m => ({
      ...m,
      [cacheKey]: { options: cached?.options || [], loading: true, fetched: false }
    }))
    api
      .post('/connection/options', {
        action_key: actionKey,
        option_key: sourceKey,
        settings: ctxSettings || {}
      })
      .then(data =>
        setOptionMap(m => ({
          ...m,
          [cacheKey]: { options: data?.options || [], loading: false, fetched: true }
        }))
      )
      .catch(() =>
        setOptionMap(m => ({
          ...m,
          [cacheKey]: { options: [], loading: false, fetched: true }
        }))
      )
  }

  const visibleFlowFields = (action?.flow_fields || []).filter(ff => {
    if (!ff.depends_on) {
      return true
    }
    return Object.entries(ff.depends_on).every(([key, values]) =>
      Array.isArray(values) ? values.includes(settings[key]) : Boolean(settings[key])
    )
  })

  // Record toggles render above the field map, since they decide what it
  // offers; everything else sits below.
  const groupToggleFields = visibleFlowFields.filter(ff => ff.type === 'boolean')
  const detailFlowFields = visibleFlowFields.filter(ff => ff.type !== 'boolean')

  // One Form.Item per flow setting.
  const renderFlowField = ff => {
    const extra = ff.help
    const label = ff.label

    // A toggle carries its own label, next to the box rather than above it.
    if (ff.type === 'boolean') {
      return (
        <Form.Item
          key={ff.key}
          name={['settings', ff.key]}
          valuePropName="checked"
          extra={extra}
          // Defaults are applied in openEditor(), so editing an older flow
          // never silently switches a record on.
          initialValue={false}
          style={{ marginBottom: 8 }}
        >
          <Checkbox>{label}</Checkbox>
        </Form.Item>
      )
    }

    const rule = ff.required
      ? [
          {
            required: true,
            message: `Pick ${ff.label.toLowerCase()}.`
          }
        ]
      : []
    let control
    if (ff.type === 'select') {
      let options = ff.options || []
      let loading = false
      let fetched = false
      if (ff.options_source) {
        const source = optionMap[`${actionKey}:${ff.options_source}`]
        options = source?.options || []
        loading = !!source?.loading
        fetched = !!source?.fetched
      }
      control = (
        <Select
          options={options}
          mode={ff.multiple ? 'multiple' : undefined}
          allowClear={!!ff.multiple}
          showSearch={ff.options_source}
          optionFilterProp="label"
          loading={loading}
          placeholder={
            ff.options_source
              ? loading
                ? 'Loading…'
                : options.length || !fetched
                ? 'Choose…'
                : !connectionFor(actionKey)
                ? 'Connect RD Station in Settings first'
                : 'Nothing found in your RD Station account'
              : undefined
          }
        />
      )
    } else {
      control = <Input />
    }
    return (
      <Form.Item
        key={ff.key}
        name={['settings', ff.key]}
        label={label}
        extra={extra}
        rules={rule}
        // Flows saved before a select existed have no stored value, so fall
        // back to "leave unchanged" rather than a blank box. A multi-select
        // needs that empty state to be a list, not a string.
        initialValue={ff.type === 'select' ? (ff.multiple ? [] : '') : undefined}
      >
        {control}
      </Form.Item>
    )
  }

  useEffect(() => {
    if (editing === null || step < 2) {
      return
    }
    const ctxSettings = form.getFieldValue('settings') || {}
    visibleFlowFields.forEach(ff => {
      if (ff.options_source) {
        loadOptions(ff.options_source, ctxSettings)
      }
    })
    // loadOptions depends on optionMap, which is only read for its guard; the
    // cache key covers everything that changes what should be fetched.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [step, editing, actionKey])

  const save = async () => {
    let values
    try {
      values = await form.validateFields()
    } catch {
      return
    }

    if (!values.trigger_source) {
      message.warning('Pick the order status this integration listens to.')
      setStep(1)
      return
    }

    // A row counts only when fully filled in; static rows also need a value.
    const isComplete = r =>
      r?.source && r?.target && (r.source !== STATIC_SOURCE || (r.value || '').trim() !== '')

    const rows = (values.field_map || []).filter(Boolean)
    const mapped = rows.filter(isComplete)

    const missingRequired = requiredTargetKeys.filter(k => !mapped.some(m => m.target === k))
    if (missingRequired.length) {
      const labels = missingRequired.map(labelForTarget).join(', ')
      message.warning(`Map an order field to ${labels} — RD Station requires it.`)
      return
    }

    // Block half-filled rows rather than dropping them silently. Required
    // rows were already reported above with a clearer message.
    if (rows.some(r => !isComplete(r) && !requiredKeySet.has(r?.target))) {
      message.warning(
        'Complete or remove the empty mapping rows — each row needs an order field, an RD Station field, and a value on static rows.'
      )
      return
    }

    const settingsOut = { ...(values.settings || {}) }

    setSaving(true)
    try {
      const payload = {
        ...values,
        settings: settingsOut,
        field_map: mapped,
        trigger_key: trigger?.key,
        action_key: actionKey
      }
      if (editing?.id) {
        await api.put(`/flows/${editing.id}`, payload)
      } else {
        await api.post('/flows', payload)
      }
      message.success('Integration saved.')
      close()
      load()
      onChanged?.()
    } catch (e) {
      message.error(e.message)
    } finally {
      setSaving(false)
    }
  }

  const deleteFlows = async ids => {
    const results = await Promise.allSettled(ids.map(id => api.del(`/flows/${id}`)))
    const failed = results.filter(r => r.status === 'rejected').length
    if (failed) {
      message.error(`${failed} of ${ids.length} integrations could not be deleted.`)
    } else {
      message.success(ids.length > 1 ? `${ids.length} integrations deleted.` : 'Integration deleted.')
    }
    setSelectedRowKeys(keys => keys.filter(k => !ids.includes(k)))
    load()
  }

  const confirmDelete = rows => {
    modal.confirm({
      title: rows.length > 1 ? `Delete ${rows.length} integrations?` : `Delete “${rows[0].title}”?`,
      content: 'Orders will no longer be sent to RD Station. This cannot be undone.',
      okText: 'Delete',
      okButtonProps: { danger: true },
      onOk: () => deleteFlows(rows.map(r => r.id))
    })
  }

  const q = query.trim().toLowerCase()
  const visibleFlows = q
    ? flows.filter(
        f =>
          (f.title || '').toLowerCase().includes(q) ||
          String(sourceName(f.trigger_source || ''))
            .toLowerCase()
            .includes(q) ||
          actionLabel(f.action_key).toLowerCase().includes(q)
      )
    : flows

  const selectedRows = flows.filter(f => selectedRowKeys.includes(f.id))

  const columns = [
    {
      title: 'Name',
      dataIndex: 'title',
      // Flexible column: absorbs the remaining width.
      minWidth: 180,
      ellipsis: true,
      sorter: (a, b) => (a.title || '').localeCompare(b.title || '')
    },
    {
      title: 'Destination',
      dataIndex: 'action_key',
      width: 150,
      ellipsis: true,
      render: key => <Tag>{actionLabel(key)}</Tag>
    },
    {
      title: 'Order status',
      dataIndex: 'trigger_source',
      width: 200,
      ellipsis: true,
      render: s => (s ? <Tag>{sourceName(s)}</Tag> : <Tag color="blue">All statuses</Tag>)
    },
    {
      title: 'Created at',
      dataIndex: 'created_at',
      width: 190,
      sorter: (a, b) => (a.created_at || '').localeCompare(b.created_at || ''),
      render: formatDate
    },
    {
      title: 'Updated at',
      dataIndex: 'updated_at',
      width: 190,
      sorter: (a, b) => (a.updated_at || '').localeCompare(b.updated_at || ''),
      render: formatDate
    },
    {
      title: 'Status',
      dataIndex: 'status',
      width: 110,
      filters: [
        { text: 'Enabled', value: 'active' },
        { text: 'Disabled', value: 'inactive' }
      ],
      onFilter: (v, f) => (v === 'active' ? f.status === 'active' : f.status !== 'active'),
      render: (s, f) => (
        <Switch
          checked={s === 'active'}
          checkedChildren="On"
          unCheckedChildren="Off"
          onChange={() =>
            api
              .post(`/flows/${f.id}/toggle`)
              .then(load)
              .catch(e => message.error(e.message))
          }
        />
      )
    },
    {
      title: 'Actions',
      key: 'actions',
      align: 'center',
      width: 90,
      render: (_, f) => (
        <Dropdown
          trigger={['hover']}
          placement="bottomRight"
          menu={{
            items: [
              {
                key: 'edit',
                icon: <EditOutlined />,
                label: 'Edit'
              },
              { type: 'divider' },
              {
                key: 'delete',
                icon: <DeleteOutlined />,
                label: 'Delete',
                danger: true
              }
            ],
            onClick: ({ key }) => (key === 'edit' ? openEditor(f) : confirmDelete([f]))
          }}
        >
          <Button type="text" icon={<MoreOutlined />} aria-label="Integration actions" />
        </Dropdown>
      )
    }
  ]

  const steps = [{ title: 'Destination' }, { title: 'Trigger' }, { title: 'Map fields' }]

  if (!ready) {
    return <BrandLoader />
  }

  return (
    <>
      {!anyConnected && (
        <Alert
          type="warning"
          showIcon
          style={{ marginBottom: 16 }}
          message="RD Station is not connected"
          description={
            'Orders cannot be delivered until you connect your RD Station account. Orders placed before then are not delivered.'
          }
          action={
            <Button size="small" type="primary" onClick={() => onGoToSettings?.()}>
              Connect
            </Button>
          }
        />
      )}

      <Flex justify="space-between" align="center" wrap gap={8} style={{ marginBottom: 16 }}>
        <Button
          type="primary"
          icon={<PlusOutlined />}
          onClick={() => requireAnyConnection() && openEditor(null)}
        >
          New integration
        </Button>
        <Input
          allowClear
          prefix={<SearchOutlined style={{ color: 'rgba(0,0,0,.45)' }} />}
          placeholder="Search integrations"
          value={query}
          onChange={e => setQuery(e.target.value)}
          style={{ width: 260 }}
        />
      </Flex>

      {selectedRowKeys.length > 0 && (
        <Space style={{ marginBottom: 12 }}>
          <Typography.Text strong>{selectedRowKeys.length} selected</Typography.Text>
          <Tooltip title="Delete selected">
            <Button
              type="text"
              danger
              size="small"
              icon={<DeleteOutlined />}
              aria-label="Delete selected"
              onClick={() => confirmDelete(selectedRows)}
            />
          </Tooltip>
        </Space>
      )}

      <Table
        rowKey="id"
        dataSource={visibleFlows}
        columns={columns}
        loading={loading}
        size="middle"
        scroll={{ x: 1080 }}
        rowSelection={{
          selectedRowKeys,
          onChange: setSelectedRowKeys,
          columnWidth: 48
        }}
        onRow={record => ({
          style: { cursor: 'pointer' },
          // The whole row opens the editor, except clicks on the switch,
          // checkbox or action menu.
          onClick: e => {
            if (
              e.target.closest(
                'button, a, .ant-switch, .ant-checkbox-wrapper, .ant-table-selection-column, .ant-dropdown'
              )
            ) {
              return
            }
            openEditor(record)
          }
        })}
        pagination={{
          defaultPageSize: 10,
          showSizeChanger: true,
          pageSizeOptions: [10, 20, 50],
          showTotal: (total, range) => `${range[0]}–${range[1]} of ${total}`
        }}
        locale={{
          emptyText: q ? (
            <Empty description={`No integrations match “${query.trim()}”.`} />
          ) : (
            <Empty description="No integrations yet — send your WooCommerce orders to RD Station in three quick steps.">
              <Button
                type="primary"
                icon={<PlusOutlined />}
                onClick={() => requireAnyConnection() && openEditor(null)}
              >
                Create your first integration
              </Button>
            </Empty>
          )
        }}
      />

      <Drawer
        title={editing?.id ? 'Edit integration' : 'New integration'}
        open={editing !== null}
        onClose={close}
        width={600}
        destroyOnClose
      >
        <Steps
          size="small"
          current={step}
          items={steps}
          style={{ marginBottom: 24 }}
          onChange={editing?.id ? setStep : undefined}
        />

        <Form form={form} layout="vertical" requiredMark={false}>
          {/* Step 1 — pick the destination. */}
          <div style={{ display: step === 0 ? 'block' : 'none' }}>
            <Typography.Paragraph type="secondary" style={{ marginTop: 0 }}>
              {meta.actions.length > 1
                ? 'Where should orders go? You can change this later.'
                : 'Where orders go.'}
            </Typography.Paragraph>
            {meta.actions.length > 1 ? (
              <Radio.Group value={actionKey} onChange={e => setActionKey(e.target.value)}>
                <Flex vertical gap={8}>
                  {meta.actions.map(a => (
                    <Radio.Button
                      key={a.key}
                      value={a.key}
                      style={{
                        height: 'auto',
                        whiteSpace: 'normal',
                        padding: '10px 14px'
                      }}
                    >
                      <div>
                        <strong>{a.label}</strong>
                      </div>
                      <div style={{ fontSize: 12, color: '#646970' }}>
                        {ACTION_DESCRIPTIONS[a.key] || a.label}
                      </div>
                    </Radio.Button>
                  ))}
                </Flex>
              </Radio.Group>
            ) : (
              <Card size="small">
                <strong>{action?.label}</strong>
                <div style={{ fontSize: 12, color: '#646970' }}>
                  {ACTION_DESCRIPTIONS[action?.key] || action?.label}
                </div>
              </Card>
            )}
          </div>

          {/* Step 2 — pick the form. Steps stay mounted so values persist. */}
          <div style={{ display: step === 1 ? 'block' : 'none' }}>
            {trigger && !trigger.available && (
              <Alert
                type="warning"
                showIcon
                style={{ marginBottom: 16 }}
                message="WooCommerce was not detected on this site, so orders will not arrive until it is active."
              />
            )}
            <Form.Item
              name="title"
              label="Integration name"
              rules={[
                {
                  required: true,
                  message: 'Give this integration a name.'
                }
              ]}
            >
              <Input placeholder="e.g. Paid orders" />
            </Form.Item>
            <Form.Item
              label="Order status"
              extra="The integration sends an order once, when it reaches this status. Processing is a paid order. Added custom checkout fields? Hit refresh."
            >
              <Space.Compact style={{ width: '100%' }}>
                <Form.Item name="trigger_source" noStyle>
                  <Select
                    options={formOptions}
                    showSearch
                    optionFilterProp="label"
                    placeholder="Choose an order status"
                  />
                </Form.Item>
                <Tooltip title="Reload order statuses and fields">
                  <Button icon={<ReloadOutlined spin={refreshingForms} />} onClick={refreshForms} />
                </Tooltip>
              </Space.Compact>
            </Form.Item>
          </div>

          {/* Step 3 — field mapping + options */}
          <div style={{ display: step === 2 ? 'block' : 'none' }}>
            <Flex justify="space-between" align="center" gap={8} style={{ marginBottom: 12 }}>
              <Typography.Text type="secondary">
                Match your order fields to {action?.label} fields.
              </Typography.Text>
              <Tooltip title="Fetch the latest field list from your RD Station account">
                <Button
                  size="small"
                  icon={<ReloadOutlined spin={rdFieldsLoading} />}
                  onClick={() => loadRdFields(true)}
                >
                  Refresh fields
                </Button>
              </Tooltip>
            </Flex>

            {groupToggleFields.length > 0 && (
              <div style={{ marginBottom: 16 }}>
                <Typography.Text strong style={{ display: 'block', marginBottom: 4 }}>
                  Every order adds the customer to your contacts.
                </Typography.Text>
                {groupToggleFields.map(renderFlowField)}
              </div>
            )}

            <Form.List name="field_map">
              {(fields, { add, remove }) => (
                <>
                  {fields.map(({ key, name }) => {
                    // Pinned rows have a fixed target and cannot be removed.
                    const locked = requiredKeySet.has(fieldMapRows[name]?.target)
                    return (
                      <div key={key}>
                        <Space
                          style={{
                            display: 'flex',
                            marginBottom: 4
                          }}
                          align="baseline"
                        >
                          <Form.Item
                            name={[name, 'source']}
                            style={{ marginBottom: 4 }}
                            getValueProps={v => ({ value: v ? [v] : [] })}
                            getValueFromEvent={arr =>
                              Array.isArray(arr) && arr.length ? arr[arr.length - 1] : undefined
                            }
                          >
                            <Select
                              mode="tags"
                              style={{ width: 210 }}
                              placeholder="Order field"
                              options={sourceOptions}
                              optionFilterProp="label"
                            />
                          </Form.Item>
                          <span>→</span>
                          {/* tags mode, keeping only the last entry, also accepts a
                              typed custom field key (e.g. cf_language). Required
                              keys are rejected — they have their own pinned rows. */}
                          <Form.Item
                            name={[name, 'target']}
                            style={{ marginBottom: 4 }}
                            getValueProps={v => ({ value: v ? [v] : [] })}
                            getValueFromEvent={arr => {
                              const v =
                                Array.isArray(arr) && arr.length ? arr[arr.length - 1] : undefined
                              return !locked && requiredKeySet.has(v) ? fieldMapRows[name]?.target : v
                            }}
                          >
                            <Select
                              mode="tags"
                              style={{ width: 210 }}
                              placeholder={action?.label + ' field'}
                              disabled={locked}
                              loading={!locked && rdFieldsLoading}
                              options={locked ? lockedTargetOptions : targetOptions}
                              optionFilterProp="label"
                            />
                          </Form.Item>
                          {locked ? (
                            <MinusCircleOutlined style={{ visibility: 'hidden' }} />
                          ) : (
                            <MinusCircleOutlined onClick={() => remove(name)} />
                          )}
                        </Space>
                        {fieldMapRows[name]?.source === STATIC_SOURCE && (
                          <Form.Item name={[name, 'value']} style={{ margin: '0 0 8px' }}>
                            <Input placeholder="Value to send to RD Station" />
                          </Form.Item>
                        )}
                      </div>
                    )
                  })}
                  <Button
                    type="dashed"
                    onClick={() => add()}
                    block
                    icon={<PlusOutlined />}
                    style={{ marginBottom: 24 }}
                  >
                    Add field
                  </Button>
                </>
              )}
            </Form.List>

            {detailFlowFields.map(renderFlowField)}
          </div>
        </Form>

        <Space style={{ marginTop: 16 }}>
          <Button onClick={close}>Cancel</Button>
          {step > 0 && <Button onClick={() => setStep(step - 1)}>Back</Button>}
          {step === 0 && (
            <Button type="primary" onClick={nextFromDestination}>
              Next
            </Button>
          )}
          {step === 1 && (
            <Button type="primary" onClick={next}>
              Next
            </Button>
          )}
          {step === 2 && (
            <Button type="primary" loading={saving} onClick={save}>
              {editing?.id ? 'Save changes' : 'Finish & activate'}
            </Button>
          )}
        </Space>
      </Drawer>
    </>
  )
}
