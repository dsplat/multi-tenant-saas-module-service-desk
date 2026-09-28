<template>
  <div class="page">
    <div class="page-header">
      <h2>客服看板</h2>
      <div class="form-tip">客服会话的运营度量。指标口径与限制随数字一起给出，避免看板被误读。</div>
    </div>

    <el-card shadow="never" style="max-width: 1080px; margin-bottom: 16px">
      <div class="toolbar">
        <el-date-picker
          v-model="range"
          type="daterange"
          value-format="YYYY-MM-DD"
          start-placeholder="开始日期"
          end-placeholder="结束日期"
          :clearable="false"
        />
        <el-button type="primary" :loading="loading" @click="load">查询</el-button>
        <span class="form-tip">默认最近 30 天，窗口上限 90 天（超出自动收敛）。</span>
      </div>
    </el-card>

    <div v-loading="loading" style="max-width: 1080px">
      <!-- 指标卡 -->
      <el-row :gutter="12">
        <el-col v-for="m in metrics" :key="m.label" :span="6" style="margin-bottom: 12px">
          <el-card shadow="never" class="metric">
            <div class="metric-label">{{ m.label }}</div>
            <div class="metric-value">{{ m.value }}</div>
            <div class="metric-sub">{{ m.sub || '—' }}</div>
          </el-card>
        </el-col>
      </el-row>

      <!-- 分布 -->
      <el-row :gutter="12">
        <el-col :span="12">
          <el-card shadow="never">
            <template #header><span>转人工原因分布</span></template>
            <el-table :data="reasonRows" size="small" empty-text="窗口内无转人工">
              <el-table-column prop="reason" label="原因" />
              <el-table-column prop="count" label="次数" width="100" align="right" />
            </el-table>
            <div class="form-tip">未解决轮数多 = 知识库覆盖不足；风险多 = 该看词表；用户主动多 = 对 AI 信任度低。</div>
          </el-card>
        </el-col>
        <el-col :span="12">
          <el-card shadow="never">
            <template #header><span>满意度分布</span></template>
            <el-table :data="satisfactionRows" size="small" empty-text="窗口内无评价（采集默认关闭）">
              <el-table-column prop="value" label="评价" />
              <el-table-column prop="count" label="次数" width="100" align="right" />
            </el-table>
            <div class="form-tip">采集依赖用户主动回复，评价率天然偏低——请结合「评价率」看，勿只读满意率。</div>
          </el-card>
        </el-col>
      </el-row>

      <!-- 口径说明（必须随数字展示） -->
      <el-alert
        v-if="notes.ai_resolved || notes.first_response"
        type="info"
        :closable="false"
        show-icon
        style="margin-top: 12px"
      >
        <template #title>指标口径</template>
        <div v-if="notes.ai_resolved" class="form-tip">· AI 闭环：{{ notes.ai_resolved }}</div>
        <div v-if="notes.first_response" class="form-tip">· 首次响应：{{ notes.first_response }}</div>
      </el-alert>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'
import { ElMessage } from 'element-plus'

const API = '/api/v1/service-desk/stats'

// 转人工原因码 → 中文（兜底显示原码）
const REASON_LABELS: Record<string, string> = {
  visitor_request: '用户主动要求',
  risk: '风险拦截',
  unresolved: '连续未命中',
  unresolved_turns: '连续未命中',
}

const fmtDate = (d: Date) => {
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
}
const defaultRange = (): [string, string] => {
  const to = new Date()
  const from = new Date()
  from.setDate(from.getDate() - 30)
  return [fmtDate(from), fmtDate(to)]
}

const pct = (r?: number | null) => (r == null ? '—' : `${(r * 100).toFixed(1)}%`)
const fmtSec = (s?: number | null) => {
  if (s == null) return '—'
  return s < 60 ? `${s}s` : `${Math.floor(s / 60)}m${s % 60}s`
}

const loading = ref(false)
const range = ref<[string, string]>(defaultRange())
const stats = ref<any>(null)

const notes = computed(() => stats.value?.notes || {})

const metrics = computed(() => {
  const s = stats.value
  if (!s) return []
  return [
    { label: '会话量', value: s.conversations ?? 0, sub: `${s.range?.days ?? 0} 天窗口` },
    { label: '转人工', value: s.handoff?.count ?? 0, sub: `占比 ${pct(s.handoff?.rate)}` },
    { label: 'AI 闭环', value: s.ai_resolved?.count ?? 0, sub: `占比 ${pct(s.ai_resolved?.rate)}（代理口径）` },
    { label: '风险命中', value: s.risk?.count ?? 0, sub: `占比 ${pct(s.risk?.rate)}` },
    { label: '工单', value: s.tickets ?? 0, sub: '转人工时可自动建单' },
    { label: 'AI 首次响应', value: fmtSec(s.first_response?.average_seconds), sub: `样本 ${s.first_response?.measured ?? 0}` },
    { label: '未解决轮数', value: s.unresolved_turns?.average ?? 0, sub: `峰值 ${s.unresolved_turns?.max ?? 0}` },
    { label: '满意度评价', value: s.satisfaction?.collected ?? 0, sub: `评价率 ${pct(s.satisfaction?.rate)}` },
  ]
})

const reasonRows = computed(() => {
  const by = stats.value?.handoff?.by_reason || {}
  return Object.entries(by).map(([k, v]) => ({ reason: REASON_LABELS[k] || k, count: v as number }))
})

const satisfactionRows = computed(() => {
  const by = stats.value?.satisfaction?.by_value || {}
  return Object.entries(by).map(([k, v]) => ({ value: k, count: v as number }))
})

const load = async () => {
  loading.value = true
  try {
    const [from, to] = range.value || []
    const res = await axios.get(API, { params: { from, to } })
    stats.value = res.data?.data || null
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '加载看板失败')
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.page-header h2 { margin: 0 0 6px; }
.toolbar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.form-tip { font-size: 12px; color: var(--el-text-color-secondary); line-height: 1.5; margin-top: 4px; }
.metric { text-align: center; }
.metric-label { font-size: 13px; color: var(--el-text-color-secondary); }
.metric-value { font-size: 26px; font-weight: 600; margin: 6px 0; color: var(--el-text-color-primary); }
.metric-sub { font-size: 12px; color: var(--el-text-color-secondary); }
</style>
