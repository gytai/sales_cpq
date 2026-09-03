# CPQ 核心业务状态机

> 仓库上下文文档。上游需求：`docs/FastAdmin-CPQ开发功能与实施方案.md` §5。
> 实现位置：`application/common/library/cpq/`（状态机类，M1/M2 逐步落地）。
> 服务端状态迁移一律白名单校验；报价主状态与审批任务状态分开存储，禁止一个字段混用。

## 1. 产品与规则版本状态

适用于：产品系列、产品型号、配置组/选项、配置规则、配置模板、BOM 映射、价格表等所有版本化对象。

```text
DRAFT（草稿）
  ├─ submit ──→ PENDING_APPROVAL（待审批）
  └─ edit ──→ DRAFT（仅草稿可编辑）
PENDING_APPROVAL
  ├─ approve ──→ PUBLISHED（已发布）
  └─ reject  ──→ DRAFT（驳回，回到草稿修订）
PUBLISHED
  └─ 到达 effective_at 截止或被新版本替代 ──→ EXPIRED（已失效）
```

规则：

1. **PUBLISHED 记录不可编辑**——任何变更通过"复制新版本"产生新 DRAFT；
2. 失效（EXPIRED）不删除，历史报价按旧版本快照还原；
3. 发布产生 `content_hash`，同内容重复发布应幂等；
4. 数据库字段 `status`（draft/pending_approval/published/expired）+ `version`（整数递增）。

## 2. 报价状态机

```text
DRAFT
  ├─ submit（无需审批：不低于指导价）──────────→ PENDING_SALES_CONFIRM
  ├─ submit（低于指导价 ≥ 产线底线）──────────→ PENDING_LINE_APPROVAL
  └─ submit（低于产线底线 ≥ 公司底线）────────→ PENDING_LINE_APPROVAL
                                                  └─ line approve ─→ PENDING_COMPANY_APPROVAL

PENDING_SALES_CONFIRM ── confirm ──→ APPROVED
PENDING_LINE_APPROVAL ── approve ──→ APPROVED
PENDING_COMPANY_APPROVAL ── approve ──→ APPROVED

APPROVED ── send ──→ SENT
SENT ── customer accept ──→ CUSTOMER_ACCEPTED
SENT ── customer reject ──→ CUSTOMER_REJECTED

审批中任意节点：
  ├─ reject ──→ REJECTED
  ├─ return ──→ RETURNED（退回修改，草稿态修订）
  └─ withdraw（发起人撤回）──→ WITHDRAWN

APPROVED / SENT：
  ├─ expire ──→ EXPIRED
  ├─ cancel ──→ CANCELLED
  └─ revise ──→ REVISED（生成新版本，旧版只读）
```

规则：

1. 低于公司控制价（company_floor）：DRAFT 不允许提交（前后端同时阻止）；
2. 每次提交触发**最终重算**，提交后价格与配置冻结为版本快照；
3. 审批中修改报价：原任务自动失效（任务状态=CANCELLED_BY_REVISION），必须提交新版本；
4. 撤回仅发起人可操作，且仅审批未完成前；
5. 状态迁移与审批动作日志在同一事务内写入；
6. `cpq_quote.status`（主状态）与 `cpq_approval_task.status`（任务状态：pending/completed/rejected/returned/expired/cancelled）分开存储。

## 3. 配置校验结果（非持久状态，服务输出）

```text
is_valid = true  → errors 为空，可有 warnings（非阻断提示）
is_valid = false → errors 非空（blocking），必须阻止进入价格步骤
```

错误码前缀 `CPQ_CONFIG_*`（REQUIRED / REQUIRES / EXCLUDES / ONE_OF / MIN_MAX / MAX_SELECT / MIN_SELECT / TYPE_INVALID …），前端据 code 定位配置组。

## 4. 异步任务状态（队列，M2+）

```text
PENDING → PROCESSING → SUCCEEDED / FAILED（可重试）→ 重试中 → …
```

- PDF 生成失败可重试，但不得覆盖已成功的正式文件（文件哈希校验）；
- 任务幂等键：business_type + business_id + 请求参数哈希。
