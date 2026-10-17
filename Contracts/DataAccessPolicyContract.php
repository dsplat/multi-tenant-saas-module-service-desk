<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Contracts;

/**
 * @deprecated 请改实现 {@see ServiceDeskAccessLevelPolicyContract}。
 *             本旧名保留兼容期（框架级 `MultiTenantSaas\Contracts\DataAccessPolicyContract`
 *             已占用 DataAccessPolicyContract 语义 = 行/列范围授权）。
 *             配置键 service-desk.extensions.data_access_policy 不变；
 *             兼容层删除需另立版本升级决策（playbook §10）。
 */
interface DataAccessPolicyContract extends ServiceDeskAccessLevelPolicyContract {}
