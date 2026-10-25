import { useEffect, useState, useCallback } from 'react'
import { Table, Tag, Select, Space, Flex, Button, Typography, Tooltip, App as AntApp } from 'antd'
import { ReloadOutlined, DeleteOutlined } from '@ant-design/icons'
import { api } from '../api'
import { formatDate } from '../format'
import BrandLoader from '../components/BrandLoader'

const LEVEL_COLORS = { success: 'green', error: 'red', info: 'blue' }
const LEVEL_LABELS = { success: 'Success', error: 'Error', info: 'Info' }
const PER_PAGE = 25

export default function Logs() {
  const { message, modal } = AntApp.useApp()
  const [items, setItems] = useState([])
  const [level, setLevel] = useState('')
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(true)
  // First payload only: paging and filtering keep the page up and spin the table.
  const [ready, setReady] = useState(false)
  const [selectedRowKeys, setSelectedRowKeys] = useState([])
  const [expandedRowKeys, setExpandedRowKeys] = useState([])
  const [deleting, setDeleting] = useState(false)

  // Only rows with a payload have anything to show when expanded.
  const isExpandable = row => Boolean(row.request || row.response)

  const toggleRow = row => {
    if (!isExpandable(row)) {
      return
    }
    setExpandedRowKeys(keys =>
      keys.includes(row.id) ? keys.filter(k => k !== row.id) : [...keys, row.id]
    )
  }

  const load = useCallback(() => {
    setLoading(true)
    const query = new URLSearchParams({ page: String(page) })
    if (level) {
      query.set('level', level)
    }
    api
      .get(`/logs?${query}`)
      .then(data => {
        setItems(data.items)
        // Drop selection and expansion for rows that just left the view.
        const present = k => data.items.some(i => i.id === k)
        setSelectedRowKeys(keys => keys.filter(present))
        setExpandedRowKeys(keys => keys.filter(present))
      })
      .catch(e => message.error(e.message))
      .finally(() => {
        setLoading(false)
        setReady(true)
      })
  }, [level, page, message])

  useEffect(load, [load])

  // Step back a page when deleting emptied the current one.
  const reload = removed => {
    if (page > 1 && removed >= items.length) {
      setPage(page - 1)
    } else {
      load()
    }
  }

  const deleteSelected = () => {
    const ids = [...selectedRowKeys]
    modal.confirm({
      title: ids.length > 1 ? `Delete ${ids.length} log entries?` : 'Delete this log entry?',
      content: 'The entries are removed permanently. Delivered orders are not affected.',
      okText: 'Delete',
      okButtonProps: { danger: true },
      onOk: async () => {
        setDeleting(true)
        try {
          await api.post('/logs/delete', { ids })
          setSelectedRowKeys([])
          message.success(ids.length > 1 ? `${ids.length} log entries deleted.` : 'Log entry deleted.')
          reload(ids.length)
        } catch (e) {
          message.error(e.message)
        } finally {
          setDeleting(false)
        }
      }
    })
  }

  // "All" means everything the filter matches, not just this page.
  const clearAll = () => {
    const scope = level ? `every ${LEVEL_LABELS[level].toLowerCase()} log entry` : 'every log entry'
    modal.confirm({
      title: level
        ? `Clear all ${LEVEL_LABELS[level].toLowerCase()} log entries?`
        : 'Clear all log entries?',
      content: `This permanently removes ${scope}, including entries on other pages. This cannot be undone.`,
      okText: 'Clear all',
      okButtonProps: { danger: true },
      onOk: async () => {
        setDeleting(true)
        try {
          const data = await api.post('/logs/clear', level ? { level } : {})
          setSelectedRowKeys([])
          message.success(
            data?.deleted ? `${data.deleted} log entries deleted.` : 'No log entries to delete.'
          )
          if (page > 1) {
            setPage(1)
          } else {
            load()
          }
        } catch (e) {
          message.error(e.message)
        } finally {
          setDeleting(false)
        }
      }
    })
  }

  const nothingToDelete = page === 1 && items.length === 0

  if (!ready) {
    return <BrandLoader />
  }

  return (
    <>
      {/* Flex, not Space: Space's inline-flex would pull the selection bar
          below onto the same line. */}
      <Flex align="center" gap={8} wrap style={{ marginBottom: 16 }}>
        <Select
          style={{ width: 160 }}
          placeholder="All levels"
          allowClear
          value={level || undefined}
          onChange={v => {
            setPage(1)
            setSelectedRowKeys([])
            setLevel(v || '')
          }}
          options={[
            { value: 'success', label: 'Success' },
            { value: 'error', label: 'Error' },
            { value: 'info', label: 'Info' }
          ]}
        />
        <Button icon={<ReloadOutlined />} onClick={load}>
          Refresh
        </Button>
        <Button
          danger
          icon={<DeleteOutlined />}
          loading={deleting}
          disabled={nothingToDelete}
          onClick={clearAll}
        >
          {level ? 'Clear all filtered' : 'Clear all'}
        </Button>
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
              loading={deleting}
              onClick={deleteSelected}
            />
          </Tooltip>
        </Space>
      )}

      <Table
        rowKey="id"
        loading={loading}
        dataSource={items}
        pagination={false}
        size="middle"
        rowSelection={{
          selectedRowKeys,
          onChange: setSelectedRowKeys,
          columnWidth: 48
        }}
        expandable={{
          // No expand column; the whole row is the toggle.
          showExpandColumn: false,
          expandedRowKeys,
          expandedRowRender: row => (
            <pre
              style={{
                whiteSpace: 'pre-wrap',
                fontSize: 12,
                margin: 0
              }}
            >
              {JSON.stringify(
                {
                  request: row.request,
                  response: row.response
                },
                null,
                2
              )}
            </pre>
          ),
          rowExpandable: isExpandable
        }}
        onRow={row => ({
          style: { cursor: isExpandable(row) ? 'pointer' : 'default' },
          // A click on the checkbox must not also toggle the payload.
          onClick: e => {
            if (e.target.closest('button, a, input, .ant-table-selection-column')) {
              return
            }
            toggleRow(row)
          }
        })}
        columns={[
          {
            title: 'Integration',
            dataIndex: 'flow_title',
            width: 200,
            ellipsis: true,
            // Logs outlive the integration that created them.
            render: (title, row) =>
              title || <Typography.Text type="secondary">Deleted (#{row.flow_id})</Typography.Text>
          },
          { title: 'Message', dataIndex: 'message' },
          {
            title: 'Status',
            dataIndex: 'level',
            width: 110,
            render: l => <Tag color={LEVEL_COLORS[l] || 'default'}>{LEVEL_LABELS[l] || l}</Tag>
          },
          {
            title: 'Created at',
            dataIndex: 'created_at',
            width: 190,
            render: formatDate
          }
        ]}
      />

      <Space style={{ marginTop: 16 }}>
        <Button disabled={page <= 1} onClick={() => setPage(page - 1)}>
          Previous
        </Button>
        <span>Page {page}</span>
        <Button disabled={items.length < PER_PAGE} onClick={() => setPage(page + 1)}>
          Next
        </Button>
      </Space>
    </>
  )
}
