# M2 确定性定价引擎验收记录（GYTAI-68）

最后验证：2026-09-04（Docker PHP 7.4 + MySQL 8.0）。

## 交付口径

- 金额使用不可变 `Money` 值对象与 bcmath 字符串计算；金额 scale 4、比率/汇率 scale 8，舍入统一为正数 HALF_UP、负数 HALF_AWAY_FROM_ZERO。
- 流水线固定为基础价、选项、配件/服务、价格规则、数量、渠道、手工折扣、费用、未税、税、含税、汇率、三层控制价、审批等级共 14 步。
- 三层策略按“指定客户 → 指定代理商 → 等级+区域 → 等级 → 区域 → 国内/国际 → 产品线 → 公司默认”八级匹配；同级取高优先级，仍并列或价格规则互斥组并列时返回结构化冲突错误。
- 轨迹冻结规范化配置、价格表/条目、策略维度及生效区间、价格/税费规则和汇率来源记录；规范化轨迹生成 SHA-256 `price_hash`。
- 整单取 `none < line < company < forbidden` 中最严格等级；任一行低于公司控制价时 `submittable=false`，`assertSubmittable()` 在服务端硬阻断。
- 开放 API 固定按最低权限脱敏成本、公司控制价和毛利；后台管理员接口按角色裁剪，敏感角色 explain 写审计日志。

## API 示例

请求头需要 FastAdmin API token：`token: <API_TOKEN>`。

```http
POST /api/cpq/v1/prices/calculate
Content-Type: application/json
token: <API_TOKEN>

{
  "date": "2026-09-01",
  "customer_id": 456,
  "agent_id": 12,
  "currency": "CNY",
  "lines": [
    {
      "model_id": 123,
      "quantity": "2",
      "configuration": {"power_level": "high"},
      "manual_discount": "0.95",
      "discount_reason": "年度框架协议"
    }
  ]
}
```

成功响应保留 FastAdmin 外壳；calculate 不带逐行轨迹，explain 使用相同请求并返回脱敏后的 `lines[].price_trace`：

```json
{
  "code": 1,
  "msg": "OK",
  "data": {
    "business_code": "OK",
    "trace_id": "4f85be5b37414ea1a4dc670e",
    "payload": {
      "currency": "CNY",
      "approval_level": "company",
      "submittable": true,
      "block_reasons": [],
      "totals": {"total": "257640.0000"},
      "price_hash": "<64-character-sha256>"
    }
  }
}
```

失败响应使用 HTTP/外壳码 422，并携带稳定业务码、追踪号和结构化维度详情，例如 `CPQ_PRICE_POLICY_MISSING`、`CPQ_PRICE_POLICY_CONFLICT`、`CPQ_PRICE_RULE_CONFLICT`、`CPQ_EXCHANGE_RATE_MISSING`、`CPQ_PRICE_BELOW_COMPANY_FLOOR`。

完整契约与字段见 `docs/cpq/openapi.yaml`。

## 自动化结果

```bash
docker exec sales_cpq-app-1 php /var/www/html/tests/cpq/pricing.php
# M2 pricing engine tests: PASS (145 assertions)
# 100 行报价试算 0.070 秒，目标 < 2 秒

docker exec sales_cpq-app-1 php /var/www/html/tests/cpq/price_channel.php
# M2 price & channel master data tests: PASS (85 assertions)

docker exec sales_cpq-app-1 php /var/www/html/tests/cpq/upgrade.php
# M1+M2 upgrade tests: PASS (33 assertions)
```

此外已通过配置单元/集成、规则静态分析、规则引擎、主数据、PDF、Excel 与队列回归；所有 CPQ PHP 文件完成语法检查。

## 交付方式

本项目按 in-place 约束直接保留在本地 `master` 脏工作树，不创建分支或 PR，因此本任务无 PR 链接。数据库已有部署通过 `php think cpq:upgrade` 应用 `2026090401_m2_pricing_agent_dimension.sql`；空库安装已包含最终结构。
