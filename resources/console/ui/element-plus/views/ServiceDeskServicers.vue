<template>
  <div class="page">
    <div class="page-header">
      <h2>接待人员</h2>
      <div class="form-tip">
        接待人员名单由渠道（微信客服）持有，框架只做读写代理、不落库。AI 以「智能助手接待」身份回复，不占接待人员席位。
      </div>
    </div>

    <!-- 查询条件 -->
    <el-card shadow="never" style="max-width: 860px; margin-bottom: 16px">
      <el-form label-width="120px">
        <el-form-item label="客服账号 ID">
          <el-input v-model="query.open_kfid" placeholder="open_kfid（必填）" style="max-width: 420px" />
        </el-form-item>
        <el-form-item label="渠道">
          <el-input v-model="query.channel" placeholder="留空用部署默认（wechat-kf）" style="max-width: 420px" />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="loading" @click="loadServicers">查询接待人员</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 名单 -->
    <el-card shadow="never" style="max-width: 860px; margin-bottom: 16px">
      <template #header><span>当前接待人员</span></template>
      <el-table :data="servicers" size="small" v-loading="loading" empty-text="尚未查询或名单为空">
        <el-table-column label="类型" width="90">
          <template #default="{ row }">
            <el-tag size="small" :type="row.type === 'user' ? 'primary' : 'warning'">
              {{ row.type === 'user' ? '成员' : '部门' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="标识">
          <template #default="{ row }">
            {{ row.type === 'user' ? row.userid : `部门 #${row.department_id}` }}
          </template>
        </el-table-column>
        <el-table-column label="状态" width="200">
          <template #default="{ row }">
            <template v-if="row.type === 'user'">
              <el-tag size="small" :type="row.serving ? 'success' : 'info'">{{ row.serving ? '接待中' : '停止接待' }}</el-tag>
              <el-tag v-if="row.suspended" size="small" type="danger" style="margin-left: 6px">已挂起</el-tag>
            </template>
            <span v-else class="form-tip">—</span>
          </template>
        </el-table-column>
      </el-table>
      <div class="form-tip" style="margin-top: 8px">
        官方上限：成员 {{ limits.max_servicers || 2000 }} 人 / 部门 {{ limits.max_departments || 20 }} 个。接待人员须在应用可见范围内。
      </div>
    </el-card>

    <!-- 添加 -->
    <el-card shadow="never" style="max-width: 860px">
      <template #header><span>添加接待人员</span></template>
      <el-form label-width="120px">
        <el-form-item label="成员 userid">
          <el-select
            v-model="add.userids"
            multiple
            filterable
            allow-create
            default-first-option
            style="width: 100%"
            placeholder="输入成员 userid 后回车添加"
          />
        </el-form-item>
        <el-form-item label="部门 ID">
          <el-select
            v-model="add.departments"
            multiple
            filterable
            allow-create
            default-first-option
            style="width: 100%"
            placeholder="输入部门 ID（数字）后回车添加"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="adding" @click="addServicers">添加</el-button>
          <span class="form-tip" style="margin-left: 12px">成员与部门至少提供一个。</span>
        </el-form-item>
      </el-form>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive } from 'vue'
import axios from 'axios'
import { ElMessage } from 'element-plus'

const API = '/api/v1/service-desk/servicers'

const loading = ref(false)
const adding = ref(false)
const servicers = ref<any[]>([])
const limits = ref<Record<string, number>>({})

const query = reactive({ open_kfid: '', channel: '' })
const add = reactive<{ userids: string[]; departments: string[] }>({ userids: [], departments: [] })

const params = () => ({
  open_kfid: query.open_kfid.trim(),
  channel: query.channel.trim() || undefined,
})

const loadServicers = async () => {
  if (!query.open_kfid.trim()) {
    ElMessage.warning('请先填写客服账号 ID（open_kfid）')
    return
  }
  loading.value = true
  try {
    const res = await axios.get(API, { params: params() })
    servicers.value = res.data?.data?.servicers || []
    limits.value = res.data?.data?.limits || {}
  } catch (e: any) {
    servicers.value = []
    ElMessage.error(e?.response?.data?.message || '读取接待人员失败')
  } finally {
    loading.value = false
  }
}

const addServicers = async () => {
  if (!query.open_kfid.trim()) {
    ElMessage.warning('请先填写客服账号 ID（open_kfid）')
    return
  }
  const departments = add.departments.map(d => Number(d)).filter(n => Number.isInteger(n) && n > 0)
  if (add.userids.length === 0 && departments.length === 0) {
    ElMessage.warning('成员与部门至少提供一个')
    return
  }
  adding.value = true
  try {
    await axios.post(API, {
      ...params(),
      userid_list: add.userids,
      department_id_list: departments,
    })
    ElMessage.success('已添加')
    add.userids = []
    add.departments = []
    await loadServicers()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '添加接待人员失败')
  } finally {
    adding.value = false
  }
}
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.page-header h2 { margin: 0 0 6px; }
.form-tip { font-size: 12px; color: var(--el-text-color-secondary); line-height: 1.5; margin-top: 4px; }
</style>
