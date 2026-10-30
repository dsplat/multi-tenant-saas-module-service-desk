<template>
  <div class="page">
    <div class="page-header">
      <h2>客服配置台</h2>
      <div class="form-tip">
        配置智能客服的转人工策略、接待态镜像、应答与满意度采集。人设与模型档位请在「AI 数字员工」中修改，本页只展示概要。
      </div>
    </div>

    <!-- 客服 Agent 概要（只读，改动走 Agent 管理） -->
    <el-card shadow="never" style="max-width: 860px; margin-bottom: 16px" v-loading="loading">
      <template #header><span>客服 Agent</span></template>
      <div v-if="agent.configured" class="agent-box">
        <div class="agent-line">
          <strong>{{ agent.name }}</strong>
          <el-tag :type="agent.enabled ? 'success' : 'info'" size="small" style="margin-left: 8px">
            {{ agent.enabled ? '已启用' : '已停用' }}
          </el-tag>
          <el-tag type="warning" size="small" style="margin-left: 6px">role: {{ agent.role }}</el-tag>
        </div>
        <div v-if="agent.persona_preview" class="form-tip">人设预览：{{ agent.persona_preview }}</div>
        <div class="form-tip">人设与模型档位在「AI 数字员工」中修改，避免两个入口校验不一致。</div>
      </div>
      <el-alert
        v-else
        type="info"
        :closable="false"
        show-icon
        :title="agent.hint || '尚未配置客服 Agent，当前使用框架内置提示词与人设'"
      />
    </el-card>

    <!-- 运营参数（可按租户调，写 tenant_settings） -->
    <el-card shadow="never" style="max-width: 860px; margin-bottom: 16px" v-loading="loading">
      <template #header><span>运营参数</span></template>
      <el-form label-width="240px" class="config-form">
        <template v-for="g in GROUPS" :key="g.key">
          <el-divider content-position="left">{{ g.label }}</el-divider>
          <el-form-item v-for="f in g.fields" :key="f.key">
            <template #label>
              {{ f.label }}
              <el-tag size="small" :type="sourceTag(f.key)" style="margin-left: 6px">{{ sourceText(f.key) }}</el-tag>
            </template>
            <el-switch v-if="f.type === 'bool'" v-model="form[f.key]" />
            <el-input-number v-else-if="f.type === 'int'" v-model="form[f.key]" :min="f.min ?? 0" :max="f.max ?? 9999" />
            <el-select
              v-else-if="f.type === 'array'"
              v-model="form[f.key]"
              multiple
              filterable
              allow-create
              default-first-option
              style="width: 100%"
              placeholder="输入选项后回车添加"
            />
            <el-input v-else v-model="form[f.key]" type="textarea" :rows="2" />
            <div v-if="f.tip" class="form-tip">{{ f.tip }}</div>
          </el-form-item>
        </template>
      </el-form>
      <el-alert v-if="loadError" type="error" :closable="false" show-icon title="加载失败" description="无法获取当前配置，保存已禁用。请检查权限或网络后点击「重试」。" style="margin-bottom: 12px" />
      <el-button type="primary" :loading="saving" :disabled="loadError" @click="save">保存改动</el-button>
      <el-button :disabled="saving" @click="load">{{ loadError ? '重试' : '重置' }}</el-button>
      <span v-if="dirtyCount > 0" class="form-tip" style="margin-left: 12px">{{ dirtyCount }} 项已修改（仅提交改动项）</span>
    </el-card>

    <!-- 渠道与对外工具面（只读） -->
    <el-card shadow="never" style="max-width: 860px" v-loading="loading">
      <template #header><span>渠道与对外工具面（只读）</span></template>
      <div class="ro-row">
        <span class="ro-label">客服渠道</span>
        <el-tag v-for="c in channels" :key="c" style="margin-right: 6px">{{ c }}</el-tag>
        <span v-if="!channels.length" class="form-tip">未配置</span>
      </div>
      <div class="ro-row">
        <span class="ro-label">对外工具面</span>
        <el-tag v-for="t in toolSurface" :key="t" type="success" style="margin-right: 6px">{{ t }}</el-tag>
        <span v-if="!toolSurface.length" class="form-tip">未配置（执行咽喉按白名单放行）</span>
      </div>
      <div class="form-tip" style="margin-top: 8px">工具面改动走 user-ai 配置，本页只读展示。</div>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import axios from 'axios'
import { ElMessage } from 'element-plus'

const API = '/api/v1/service-desk/config'

// 可编辑项与后端 ServiceDeskSettings::EDITABLE 一一对应（18 项）
interface Field { key: string; label: string; type: 'bool' | 'int' | 'array' | 'string'; tip?: string; min?: number; max?: number }
const GROUPS: { key: string; label: string; fields: Field[] }[] = [
  {
    key: 'handoff', label: '转人工', fields: [
      { key: 'handoff.max_unresolved_turns', label: '连续未命中转人工轮数', type: 'int', min: 1, max: 20, tip: '连续 N 轮未命中知识库后自动转人工' },
      { key: 'handoff.auto_on_no_answer', label: '答不上时自动转人工', type: 'bool', tip: '开＝检索无命中首轮即自动转人工；关＝回复提醒文案，用户输入「转人工」再转' },
      { key: 'handoff.allow_visitor_request', label: '允许用户主动转人工', type: 'bool', tip: '识别「转人工/人工客服」等表述' },
      { key: 'handoff.summary_on_handoff', label: '转人工时刷新会话摘要', type: 'bool', tip: '坐席接手时最需要「这段会话讲了什么」' },
      { key: 'handoff.ticket_enabled', label: '转人工时建工单', type: 'bool', tip: '未解决要有可跟进、可统计的落点' },
      { key: 'handoff.suggest_replies', label: '转人工时生成建议话术', type: 'bool', tip: '每次转人工一次模型调用，可按租户关掉' },
      { key: 'handoff.notify_on_accept', label: '坐席接入时提示用户', type: 'bool', tip: '开＝人工客服在企微客户端接入会话时，自动给用户发一条「已接入、请稍候」提示' },
    ],
  },
  {
    key: 'state_sync', label: '接待态镜像', fields: [
      { key: 'state_sync.enabled', label: '启用接待态镜像', type: 'bool', tip: '接待态由渠道托管，本地只做镜像；一致性以渠道回读为准' },
    ],
  },
  {
    key: 'reply', label: '应答', fields: [
      { key: 'reply.auto_greeting', label: '首次消息自动招呼', type: 'bool', tip: '开＝用户发第一条消息时立即回复招呼语，不等 AI 生成完成' },
      { key: 'reply.greeting_text', label: '招呼语文案', type: 'string', tip: '自动招呼的内容，建议含「正在查询/请稍候」' },
      { key: 'reply.history_turns', label: 'AI 合成历史轮数', type: 'int', min: 0, max: 20, tip: '0 = 不带历史，退化为单轮问答' },
      { key: 'reply.no_answer', label: '答不上提醒文案', type: 'string', tip: '未开启自动转人工时，检索无命中回复此文案；建议含「转人工」引导' },
      { key: 'reply.handoff_notice', label: '转人工排队提示', type: 'string', tip: '转人工（进入待接入池）时给用户的一条提示，建议说明「排队中、请稍候、非工作时间顺延」' },
      { key: 'reply.accept_notice', label: '人工已接入提示', type: 'string', tip: '坐席在企微客户端接入会话时给用户的一条提示，让用户知道已有人接手' },
    ],
  },
  {
    key: 'satisfaction', label: '满意度采集', fields: [
      { key: 'satisfaction.enabled', label: '启用满意度采集', type: 'bool' },
      { key: 'satisfaction.prompt_on_close', label: '会话结束时邀评', type: 'bool', tip: '依赖「结束后用户还能回复」，真机未验证前建议保持关闭' },
      { key: 'satisfaction.options', label: '评价选项', type: 'array', tip: '用户回复命中即记录，默认「满意 / 一般 / 不满意」' },
      { key: 'satisfaction.prompt_text', label: '邀评文案', type: 'string' },
    ],
  },
]

const loading = ref(false)
const saving = ref(false)
const loadError = ref(false)
const agent = ref<any>({ configured: false })
const channels = ref<string[]>([])
const toolSurface = ref<string[]>([])
const settings = ref<Record<string, { value: any; source: string; editable: boolean }>>({})

const form = reactive<Record<string, any>>({})
for (const g of GROUPS) {
  for (const f of g.fields) {
    form[f.key] = f.type === 'bool' ? false : f.type === 'int' ? 0 : f.type === 'array' ? [] : ''
  }
}
let original: Record<string, any> = {}

const sourceText = (key: string) => (settings.value[key]?.source === 'tenant' ? '租户自设' : '部署默认')
const sourceTag = (key: string) => (settings.value[key]?.source === 'tenant' ? 'warning' : 'info')

const dirtyKeys = computed(() =>
  GROUPS.flatMap(g => g.fields.map(f => f.key)).filter(k => JSON.stringify(form[k]) !== JSON.stringify(original[k]))
)
const dirtyCount = computed(() => dirtyKeys.value.length)

const load = async () => {
  loading.value = true
  try {
    const res = await axios.get(API)
    const data = res.data?.data || {}
    settings.value = data.settings || {}
    agent.value = data.agent || { configured: false }
    channels.value = data.channels || []
    toolSurface.value = data.tool_surface || []
    for (const g of GROUPS) {
      for (const f of g.fields) {
        const v = settings.value[f.key]?.value
        if (v !== undefined && v !== null) form[f.key] = v
      }
    }
    original = JSON.parse(JSON.stringify(form))
    loadError.value = false
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '加载配置失败')
    // GET 失败时将 original 同步为当前 form 默认值，阻止全字段标脏误提交
    original = JSON.parse(JSON.stringify(form))
    loadError.value = true
  } finally {
    loading.value = false
  }
}

const save = async () => {
  // 只提交改动项：未动的项保持「部署默认」来源，不被误写成租户自设
  const payload: Record<string, any> = {}
  for (const k of dirtyKeys.value) payload[k] = form[k]

  if (Object.keys(payload).length === 0) {
    ElMessage.info('没有改动')
    return
  }

  saving.value = true
  try {
    await axios.put(API, { settings: payload })
    ElMessage.success('已保存')
    await load()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '保存失败')
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.page-header h2 { margin: 0 0 6px; }
.config-form { margin-bottom: 8px; }
.form-tip { font-size: 12px; color: var(--el-text-color-secondary); line-height: 1.5; margin-top: 4px; }
.agent-box { font-size: 14px; }
.agent-line { display: flex; align-items: center; margin-bottom: 6px; }
.ro-row { display: flex; align-items: center; flex-wrap: wrap; margin-bottom: 10px; font-size: 14px; }
.ro-label { width: 96px; color: var(--el-text-color-secondary); }
</style>
